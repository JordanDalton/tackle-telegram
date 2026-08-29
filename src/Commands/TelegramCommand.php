<?php

namespace TackleTelegram\Commands;

use Illuminate\Console\Command;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\SessionLoop;
use TackleTelegram\HttpTelegramApi;
use TackleTelegram\StreamingState;
use TackleTelegram\TelegramTransport;

/**
 * A Tackle coding session you drive from Telegram.
 *
 * The wiring is deliberately the same as `tackle:remote`, because the agent
 * side is the same: SessionLoop over a state directory, RemoteInteraction
 * answering questions through it. The only thing that changes is who is on the
 * other end of the files — a browser there, a chat here.
 */
class TelegramCommand extends Command
{
    protected $signature = 'tackle:telegram
        {--session=telegram : Session name to run under}
        {--chat=            : Chat id to talk to (defaults to the first allowed chat)}
        {--if-configured    : Idle instead of failing when no token or allowed chats are set}
        {--pair             : Print the chat id of whoever messages the bot, for TACKLE_TELEGRAM_CHATS}';

    protected $description = 'Run a Tackle coding session driven from Telegram.';

    public function handle(): int
    {
        $token = (string) config('tackle-telegram.token');
        $allowed = array_values((array) config('tackle-telegram.allowed_chats', []));

        if ($token === '') {
            return $this->unconfigured('No bot token. Get one from @BotFather and set TACKLE_TELEGRAM_TOKEN.');
        }

        if ($this->option('pair')) {
            return $this->pair($token);
        }

        // An empty allowlist is a bot nobody may talk to. Refusing to start is
        // the only honest reading of that: anyone who can message this bot can
        // run code on this machine, so silently accepting everyone would be
        // the worst possible default.
        if ($allowed === []) {
            return $this->unconfigured(
                'No allowed chats. Set TACKLE_TELEGRAM_CHATS to the chat ids permitted to drive this session.',
                'Message your bot, then read the chat id from the update it sends.',
            );
        }

        $session = (string) $this->option('session');
        $chatId = (string) ($this->option('chat') ?: $allowed[0]);

        $state = new StreamingState(
            rtrim((string) config('tackle-remote.storage_path', storage_path('tackle-remote')), '/').'/'.$session,
        );

        $state->putIdentity([
            'api' => 1,
            'name' => (string) config('app.name', 'Laravel'),
            'environment' => $this->laravel->environment(),
            'project' => basename(rtrim(base_path(), '/')),
            'session' => $session,
            'transport' => 'telegram',
        ]);

        // Bind before the agent resolves, so every ConfirmAction and AskUser
        // in the toolset routes to the chat rather than to a terminal nobody
        // is watching.
        $this->laravel->instance(InteractionPolicy::class, new RemoteInteraction(
            $state,
            (int) config('tackle-remote.answer_timeout', 600),
        ));

        $transport = new TelegramTransport(
            new HttpTelegramApi($token),
            $state,
            $chatId,
            $allowed,
            (int) config('tackle-telegram.poll_timeout', 2),
        );

        // Push as the session emits, not only when it goes idle: onIdle never
        // fires during a turn, so the chat would otherwise stay silent for the
        // whole of the work it is meant to be narrating.
        $state->onEmit(fn (string $type) => $transport->flush($type));

        $this->components->info("Listening on Telegram as session '{$session}' — chat {$chatId}.");
        $this->line('<fg=gray>Outbound only: no public URL, no tunnel. Ctrl+C to stop.</>');

        $transport->announce('🧰 Tackle is listening. Send a task.');

        $loop = new SessionLoop(
            $this->laravel->make(CodingAgent::class),
            $this->laravel->make(BudgetTracker::class),
            $this->laravel->make(SessionStore::class),
            $this->laravel->make(ConversationCompactor::class),
            $state,
            $session,
            onIdle: fn () => $transport->pump(),
        );

        $loop->run();

        return self::SUCCESS;
    }

    /**
     * Print the chat id of whoever messages the bot.
     *
     * There is a chicken and egg otherwise: the session refuses to start until
     * TACKLE_TELEGRAM_CHATS names a chat, so the bot cannot be used to find
     * out what your own chat id is. The documented alternative was to curl
     * getUpdates and read JSON, which is a poor first five minutes.
     *
     * This deliberately does not write anything or act on the message. It
     * listens, reports who it heard from, and leaves the decision with you —
     * the same trust model as the single-use code Tackle Remote prints in the
     * terminal. Anyone who finds your bot can make it print their id here;
     * none of them can make it do anything.
     */
    private function pair(string $token): int
    {
        $api = new HttpTelegramApi($token);

        $this->components->info('Send your bot a message now. Ctrl+C when you have what you need.');
        $this->newLine();

        $offset = 0;
        $seen = [];
        $deadline = time() + 300;

        while (time() < $deadline) {
            foreach ($api->getUpdates($offset, 10) as $update) {
                $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);

                $chat = $update['message']['chat'] ?? null;
                $id = $chat['id'] ?? null;

                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                $who = trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''))
                    ?: (string) ($chat['title'] ?? 'unknown');
                $handle = isset($chat['username']) ? ' @'.$chat['username'] : '';

                $this->line("  <fg=green;options=bold>{$id}</>  {$who}{$handle}  <fg=gray>({$chat['type']})</>");
                $this->line('  <fg=gray>TACKLE_TELEGRAM_CHATS='.implode(',', array_keys($seen)).'</>');
                $this->newLine();

                $this->offerToAllow((string) $id, $who.$handle);
            }
        }

        if ($seen === []) {
            $this->components->warn('Nobody messaged the bot. Open it in Telegram and send anything, then try again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Offer to write the chat into .env, the way key:generate writes APP_KEY.
     *
     * Asking rather than doing, because this is the allowlist — the whole
     * security model — and the id on screen might belong to whoever else found
     * the bot. A human confirming that they recognise the name is the check.
     */
    private function offerToAllow(string $id, string $who): void
    {
        $path = base_path('.env');

        if (! $this->input->isInteractive()) {
            return;
        }

        if (! is_file($path)) {
            $this->components->warn('No .env here — add TACKLE_TELEGRAM_CHATS yourself.');

            return;
        }

        $before = (string) file_get_contents($path);
        $after = $this->withChat($before, $id);

        // Nothing to decide when the answer is already in the file. Asking
        // permission and then reporting that permission was unnecessary
        // answers a question nobody asked.
        if ($after === $before) {
            $this->components->info('Already allowed in .env — nothing to do.');

            return;
        }

        if (! $this->components->confirm("Allow {$who} to drive this project?", false)) {
            return;
        }

        file_put_contents($path, $after);

        $this->components->info('Added to TACKLE_TELEGRAM_CHATS in .env.');
    }

    /**
     * Add a chat id to TACKLE_TELEGRAM_CHATS, leaving the rest of the file
     * alone.
     *
     * Appends to the list rather than replacing it: pairing a second device
     * should not silently revoke the first. Kept as a string transform so the
     * rules are testable without a filesystem.
     */
    public function withChat(string $env, string $id): string
    {
        if (! preg_match('/^TACKLE_TELEGRAM_CHATS=(.*)$/m', $env, $match)) {
            return rtrim($env, "\n")."\nTACKLE_TELEGRAM_CHATS={$id}\n";
        }

        $chats = array_values(array_filter(array_map('trim', explode(',', trim($match[1], " \"'")))));

        if (in_array($id, $chats, true)) {
            return $env;
        }

        $chats[] = $id;

        return (string) preg_replace(
            '/^TACKLE_TELEGRAM_CHATS=.*$/m',
            'TACKLE_TELEGRAM_CHATS='.implode(',', $chats),
            $env,
            1,
        );
    }

    /**
     * Missing configuration: fail loudly when run on purpose, sit quietly when
     * run as one of many.
     *
     * `--if-configured` exists for a dev script, and both kinds punish an
     * exit. `composer dev` runs under `concurrently --kill-others`, which
     * tears the whole environment down the moment any one process ends;
     * `php artisan dev` restarts a crashed process, so an unconfigured bot
     * would spin forever instead. Either way a missing token would ruin the
     * dev environment of everyone who cloned the project without a Telegram
     * setup. Idling is what keeps this safe to list alongside the others.
     */
    private function unconfigured(string $problem, ?string $hint = null): int
    {
        if (! $this->option('if-configured')) {
            $this->components->error($problem);

            if ($hint !== null) {
                $this->line('<fg=gray>'.$hint.'</>');
            }

            return self::FAILURE;
        }

        $this->line('<fg=gray>Telegram not configured — idling so it does not take your other dev processes down with it. '.$problem.'</>');

        // Sleep rather than return: exiting is what would kill the siblings.
        while (true) {
            sleep(3600);
        }
    }
}
