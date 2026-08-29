<?php

namespace TackleTelegram;

use TackleRemote\Support\RemoteState;
use TackleTelegram\Contracts\TelegramApi;
use Throwable;

/**
 * Telegram as a transport for a Tackle session.
 *
 * The interesting thing about this class is how little it does. Tackle Remote
 * already separates the agent loop from the way a human reaches it: SessionLoop
 * pops messages from an inbox and appends events; RemoteInteraction writes a
 * question to a file and waits for an answer to appear beside it. Neither knows
 * what a browser is. So a second transport is not a second implementation of
 * anything — it is a pump between that directory and somewhere else.
 *
 * Telegram in particular is worth the trouble because it is outbound-only.
 * getUpdates is a long poll your machine opens *to* Telegram, so a laptop
 * behind NAT with no public URL, no tunnel and no hosting can be driven from
 * anywhere in the world. Telegram's servers are the relay, and they are free.
 *
 * Two things this deliberately does not do. It does not implement
 * InteractionPolicy — RemoteInteraction already speaks the file protocol, and
 * writing a second one would be duplicating the part that is already right.
 * And it does not trust a chat id it has not been told about; see allows().
 */
class TelegramTransport
{
    /** Telegram rejects messages over 4096 characters. */
    private const MAX_MESSAGE = 3800;

    private int $offset = 0;

    private int $cursor = 0;

    /** The message currently being streamed into, so a turn edits rather than spams. */
    private ?int $streamingMessage = null;

    private string $streamed = '';

    private ?string $offeredQuestion = null;

    /** Whether the events arriving belong to a turn the agent is working on. */
    private bool $inTurn = false;

    /** When this session started; anything older was sent to nobody. */
    private readonly int $startedAt;

    private bool $drained = false;

    private float $lastFlush = 0.0;

    /**
     * @param  list<int|string>  $allowedChats
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly RemoteState $state,
        private readonly int|string $chatId,
        private readonly array $allowedChats,
        private readonly int $pollTimeout = 0,
    ) {
        // Start at the end of the log, not the beginning. events.jsonl
        // outlives the process, so a restarted session was replaying every
        // event of every previous conversation into the chat — dozens of
        // messages, which is both nonsense to read and a fast way to hit
        // Telegram's per-chat rate limit before the new session has said
        // anything of its own.
        $this->cursor = (int) ($state->eventsAfter(0)['cursor'] ?? 0);
        $this->startedAt = time();
    }

    /**
     * One turn of the pump. Called from SessionLoop's idle hook, so it runs
     * between agent turns and never competes with one.
     */
    public function pump(): void
    {
        try {
            $this->flushEvents();
            $this->offerPendingQuestion();
            $this->ingestUpdates();
        } catch (Throwable $e) {
            // A transport that dies takes the session with it. Telegram being
            // briefly unreachable is not a reason to lose an agent mid-task.
            $this->report($e);
        }
    }

    /**
     * Transport trouble goes to the terminal, not into the session.
     *
     * Emitting it was a small disaster: the event triggered a flush, the flush
     * tried Telegram again, and a network blip became a message in the chat
     * about not being able to reach the chat. It is also not news the person
     * on the phone can act on — it belongs where the session is running.
     */
    private function report(Throwable $e): void
    {
        fwrite(STDERR, '[telegram] '.$e->getMessage()."\n");
    }

    /** Opt-in tracing: TACKLE_TELEGRAM_DEBUG=1 to see what the pump is doing. */
    private function trace(string $message): void
    {
        if (getenv('TACKLE_TELEGRAM_DEBUG')) {
            fwrite(STDERR, '[telegram] '.$message."\n");
        }
    }

    /**
     * Push whatever has happened, now — called as the session emits rather
     * than only when it goes idle.
     *
     * Text is throttled because a turn produces deltas far faster than
     * Telegram will accept edits to one message, and being rate-limited
     * mid-answer is worse than being a second behind. Everything else is
     * pushed immediately: a tool call, a status, an error, or the end of a
     * turn are all things worth seeing the moment they happen, and they
     * arrive at human speed anyway.
     */
    public function flush(string $eventType = ''): void
    {
        $this->trace("flush({$eventType}) cursor={$this->cursor}");

        $urgent = $eventType !== 'text';
        $now = microtime(true);

        if (! $urgent && ($now - $this->lastFlush) < 1.0) {
            return;
        }

        $this->lastFlush = $now;

        try {
            $this->flushEvents();
            $this->offerPendingQuestion();
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    public function announce(string $text): void
    {
        $this->api->sendMessage($this->chatId, $text, null, silent: true);
    }

    /**
     * The whole security model, in one method.
     *
     * A bot token is far more discoverable than a pairing code on a LAN, and
     * anyone who can message this bot can run code on the machine hosting it.
     * So an unlisted chat is not answered, not rate-limited, not asked to
     * authenticate — it is dropped before its text can reach the inbox.
     */
    public function allows(int|string $chatId): bool
    {
        foreach ($this->allowedChats as $allowed) {
            if ((string) $allowed === (string) $chatId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send everything the agent has produced since the last pump.
     *
     * Assistant text arrives as a stream of deltas. Posting each one would be
     * unusable, so a turn's text accumulates into a single message that is
     * edited in place — which is also what makes it read like the terminal.
     */
    private function flushEvents(): void
    {
        $batch = $this->state->eventsAfter($this->cursor);
        $target = (int) ($batch['cursor'] ?? $this->cursor);

        // /clear truncates the log. A cursor left pointing past the new end
        // would swallow everything silently until the file grew back to that
        // length — the bot would simply stop talking, with nothing to show for
        // it. A log shorter than the cursor can only mean it was reset.
        if ($target < $this->cursor) {
            $this->cursor = 0;
            $batch = $this->state->eventsAfter(0);
            $target = (int) ($batch['cursor'] ?? 0);
        }

        $this->trace('flushEvents from='.$this->cursor.' target='.$target.' events='.count((array) ($batch['events'] ?? [])));

        // The cursor used to jump to the end of the batch before a single
        // message had been sent, so anything Telegram refused — a rate limit
        // most of all — was silently skipped and never retried. Advance one
        // event at a time, and only after it has actually gone out.
        //
        // emit() spreads its payload alongside the type rather than nesting
        // it, so an event is a flat array: ['type' => 'text', 'delta' => '…'].
        foreach ((array) ($batch['events'] ?? []) as $event) {
            match ((string) ($event['type'] ?? '')) {
                // The session echoes the prompt as it picks it up, which makes
                // it the moment a turn begins — and the moment the previous
                // message must be closed.
                'user' => $this->beginTurn(),
                'text' => $this->stream((string) ($event['delta'] ?? '')),
                'tool_call' => $this->post('🔧 '.$this->describeToolCall($event)),
                'status' => $this->post('· '.($event['text'] ?? '')),
                'error' => $this->post('⚠️ '.($event['text'] ?? '')),
                'turn_done' => $this->endTurn(),
                // A cleared conversation ends whatever was being written into.
                'cleared' => $this->endTurn(),
                default => null,
            };

            $this->cursor++;
        }

        // Lines the log filtered out (anything unparseable) are absorbed here,
        // so a malformed line cannot stall the cursor forever.
        $this->cursor = max($this->cursor, $target);
    }

    /**
     * A tool call as one readable line. The arguments carry the detail worth
     * seeing — which file, which command — and the tool name alone rarely
     * tells you what the agent is actually doing.
     *
     * @param  array<string, mixed>  $event
     */
    private function describeToolCall(array $event): string
    {
        $tool = (string) ($event['tool'] ?? 'tool');
        $arguments = (array) ($event['arguments'] ?? []);

        foreach (['path', 'command', 'query', 'pattern', 'route', 'model'] as $key) {
            if (! empty($arguments[$key]) && is_scalar($arguments[$key])) {
                return $tool.' '.mb_substr((string) $arguments[$key], 0, 120);
            }
        }

        return $tool;
    }

    private function stream(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        // Assistant prose only ever arrives inside a turn, so seeing it is
        // enough to know one is running even if the echo was missed.
        $this->inTurn = true;

        $this->append($delta);
    }

    /**
     * A tool call, status or error — its own line inside the same message.
     *
     * These used to be separate messages, which meant a single turn buzzed the
     * phone six times to say it had read a file. A turn is one thing that
     * happened, so it is one message: prose and activity interleaved, edited
     * as it grows, exactly as the terminal shows it.
     */
    private function post(string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        // Outside a turn there is nothing to append to. A status that arrives
        // while the session is idle — a restart, a compaction — is its own
        // message, not the opening line of whatever gets asked next.
        if (! $this->inTurn) {
            $this->api->sendMessage($this->chatId, Markdown::toTelegramHtml(rtrim($text)), null, silent: true);

            return;
        }

        $this->append(($this->streamed === '' ? '' : "\n").rtrim($text)."\n");
    }

    /**
     * Begin a turn, and close whatever message came before it.
     *
     * Without this the id of the first message ever sent — a startup status,
     * usually — stayed in hand forever, and every turn afterwards was edited
     * into it. The narration was never missing; it was quietly rewriting a
     * message far up the chat while the file changed underneath. Nothing new
     * appeared, which looked exactly like a transport that had stopped
     * working.
     */
    private function beginTurn(): void
    {
        $this->endTurn();
        $this->inTurn = true;
    }

    /** Finish the current message so the next turn starts a fresh one. */
    private function endTurn(): void
    {
        $this->streamingMessage = null;
        $this->streamed = '';
        $this->inTurn = false;
    }

    private function append(string $text): void
    {
        // A new message once the current one is full, rather than a silent
        // truncation of the agent's answer.
        if (strlen($this->streamed) + strlen($text) > self::MAX_MESSAGE) {
            $this->endTurn();
        }

        $this->streamed .= $text;

        $rendered = Markdown::toTelegramHtml(rtrim($this->streamed));

        if ($rendered === '') {
            return;
        }

        if ($this->streamingMessage === null) {
            // Silent: progress is for glancing at, not for interrupting. Only
            // a question the agent is blocked on earns a notification.
            $this->trace('send '.strlen($rendered).' chars');
            $this->streamingMessage = $this->api->sendMessage($this->chatId, $rendered, null, silent: true);

            return;
        }

        $this->trace('edit '.strlen($rendered).' chars');

        $this->api->editMessage($this->chatId, $this->streamingMessage, $rendered);
    }

    /**
     * Put the agent's pending question in the chat as tappable buttons, once.
     */
    private function offerPendingQuestion(): void
    {
        $question = $this->state->pendingQuestion();

        if ($question === null) {
            $this->offeredQuestion = null;

            return;
        }

        $id = (string) ($question['id'] ?? '');

        if ($id === '' || $id === $this->offeredQuestion) {
            return;
        }

        $this->offeredQuestion = $id;
        $this->endTurn();

        $buttons = [];

        foreach ((array) ($question['options'] ?? []) as $value => $label) {
            $buttons[] = [['text' => (string) $label, 'callback_data' => $id.':'.$value]];
        }

        // The one thing worth a notification: the agent is blocked until you
        // answer, and everything else it might have said can wait.
        $this->api->sendMessage(
            $this->chatId,
            Markdown::toTelegramHtml('❓ '.($question['label'] ?? 'The agent needs a decision.')
                .(($question['hint'] ?? null) ? "\n\n".$question['hint'] : '')),
            $buttons === [] ? null : ['inline_keyboard' => $buttons],
        );
    }

    /**
     * Pull anything the human sent and route it: a tapped button answers the
     * open question, plain text becomes the next task.
     */
    private function ingestUpdates(): void
    {
        $stale = 0;

        foreach ($this->api->getUpdates($this->offset, $this->pollTimeout) as $update) {
            $this->offset = max($this->offset, (int) ($update['update_id'] ?? 0) + 1);

            // Telegram holds undelivered updates for 24 hours. Without this, a
            // session starting up would pull everything said while nobody was
            // listening out of the queue and run it as work — "u there?"
            // becomes a coding task, at your expense. Anything sent before
            // this process existed was sent to nobody, and stays that way.
            if ($this->isStale($update)) {
                $stale++;

                continue;
            }

            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);

                continue;
            }

            $message = $update['message'] ?? null;
            $chatId = $message['chat']['id'] ?? null;

            if ($chatId === null || ! $this->allows($chatId)) {
                continue;
            }

            $text = trim((string) ($message['text'] ?? ''));

            if ($text === '') {
                $this->declineUnsupported((array) $message);

                continue;
            }

            if ($this->handledLocally($text)) {
                continue;
            }

            $text === '/clear'
                ? $this->state->pushCommand('clear')
                : $this->state->pushMessage($text);
        }

        // Say so once, rather than leaving someone to wonder why the thing
        // they sent an hour ago was never answered.
        if ($stale > 0 && ! $this->drained) {
            $this->api->sendMessage(
                $this->chatId,
                'I was not running when you sent '.$stale.' earlier '.($stale === 1 ? 'message' : 'messages')
                .' — they have been skipped rather than acted on. Send anything still relevant again.',
                null,
                silent: true,
            );
        }

        $this->drained = $this->drained || $stale > 0;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function isStale(array $update): bool
    {
        $sentAt = (int) ($update['message']['date'] ?? $update['callback_query']['message']['date'] ?? 0);

        return $sentAt > 0 && $sentAt < $this->startedAt;
    }

    /**
     * Commands the chat answers itself, rather than handing to the agent.
     *
     * /start is not a Tackle idea at all — it is how every Telegram user opens
     * every bot, and the first message this will ever receive. Forwarding it
     * produced "Unknown command /start — add .tackle/commands/start.md", which
     * is accurate and a terrible introduction.
     *
     * /help is the same shape for a different reason: the session loop never
     * sees it either, because the browser UI renders it locally from the
     * published command list. A second client has to do the same.
     */
    private function handledLocally(string $text): bool
    {
        $command = strtolower(strtok(ltrim($text), " \t") ?: '');

        if ($command === '/start') {
            $this->api->sendMessage($this->chatId, implode("\n", [
                '🧰 Tackle is listening.',
                '',
                'Send a task in plain English and I will work on it in the project this session was started from — reading files, editing, running tests.',
                '',
                'Anything destructive asks first, as buttons, right here.',
                '',
                '/help for commands.',
            ]));

            return true;
        }

        if ($command === '/help') {
            $this->api->sendMessage($this->chatId, $this->helpText());

            return true;
        }

        return false;
    }

    /**
     * Say something when a message arrives that cannot be acted on.
     *
     * Found on the first live session: a voice note produced complete silence,
     * because only `text` was ever read. Silence is the worst possible answer
     * here — there is no way to tell it apart from a bot that has crashed, and
     * the sender is left waiting on a session that never heard them.
     *
     * @param  array<string, mixed>  $message
     */
    private function declineUnsupported(array $message): void
    {
        $reply = match (true) {
            isset($message['voice']), isset($message['audio']) => 'I cannot listen to voice notes yet — send that as text.',
            isset($message['photo']), isset($message['document']) => 'I cannot read attachments yet — describe it, or paste the text.',
            isset($message['sticker']) => 'Nice sticker. Send a task as text and I will get to work.',
            default => 'I can only act on text messages.',
        };

        $this->api->sendMessage($this->chatId, $reply, null, silent: true);
    }

    private function helpText(): string
    {
        $lines = ['Commands'];

        foreach ($this->state->commands() as $command) {
            $name = (string) ($command['name'] ?? '');

            if ($name !== '') {
                $lines[] = '/'.$name.' — '.($command['description'] ?? '');
            }
        }

        // Before the session has published its list there is still something
        // true to say.
        if (count($lines) === 1) {
            $lines[] = '/clear — forget the conversation and start fresh';
        }

        $lines[] = '';
        $lines[] = 'Anything else you send is a task for the agent.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $data = (string) ($callback['data'] ?? '');

        if ($chatId === null || ! $this->allows($chatId) || ! str_contains($data, ':')) {
            return;
        }

        [$questionId, $value] = explode(':', $data, 2);
        $pending = $this->state->pendingQuestion();

        // The tap has to answer the question that is actually open. By the
        // time someone reaches their phone the agent may have moved on, and
        // answering a question it is no longer asking is worse than missing
        // one.
        if (($pending['id'] ?? null) !== $questionId) {
            $this->api->answerCallback((string) ($callback['id'] ?? ''), 'That question has already been answered.');

            return;
        }

        $this->state->answer($questionId, $value);
        $this->api->answerCallback((string) ($callback['id'] ?? ''), 'Sent.');
    }
}
