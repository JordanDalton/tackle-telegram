<?php

namespace TackleTelegram\Commands;

use Illuminate\Console\Command;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\RemoteState;
use TackleRemote\Support\SessionLoop;
use TackleTelegram\HttpTelegramApi;
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
        {--chat=            : Chat id to talk to (defaults to the first allowed chat)}';

    protected $description = 'Run a Tackle coding session driven from Telegram.';

    public function handle(): int
    {
        $token = (string) config('tackle-telegram.token');
        $allowed = array_values((array) config('tackle-telegram.allowed_chats', []));

        if ($token === '') {
            $this->components->error('No bot token. Get one from @BotFather and set TACKLE_TELEGRAM_TOKEN.');

            return self::FAILURE;
        }

        // An empty allowlist is a bot nobody may talk to. Refusing to start is
        // the only honest reading of that: anyone who can message this bot can
        // run code on this machine, so silently accepting everyone would be
        // the worst possible default.
        if ($allowed === []) {
            $this->components->error('No allowed chats. Set TACKLE_TELEGRAM_CHATS to the chat ids permitted to drive this session.');
            $this->line('<fg=gray>Message your bot, then read the chat id from the update it sends.</>');

            return self::FAILURE;
        }

        $session = (string) $this->option('session');
        $chatId = (string) ($this->option('chat') ?: $allowed[0]);

        $state = new RemoteState(
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

        $this->components->info("Listening on Telegram as session '{$session}' — chat {$chatId}.");
        $this->line('<fg=gray>Outbound only: no public URL, no tunnel. Ctrl+C to stop.</>');

        $transport->announce('🎣 Tackle is listening. Send a task.');

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
}
