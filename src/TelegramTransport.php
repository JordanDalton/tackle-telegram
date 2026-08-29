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

    /**
     * @param  list<int|string>  $allowedChats
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly RemoteState $state,
        private readonly int|string $chatId,
        private readonly array $allowedChats,
        private readonly int $pollTimeout = 0,
    ) {}

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
            $this->state->emit('status', ['text' => 'Telegram transport error: '.$e->getMessage()]);
        }
    }

    public function announce(string $text): void
    {
        $this->api->sendMessage($this->chatId, $text);
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
        $this->cursor = (int) ($batch['cursor'] ?? $this->cursor);

        // emit() spreads its payload alongside the type rather than nesting
        // it, so an event is a flat array: ['type' => 'text', 'delta' => '…'].
        foreach ((array) ($batch['events'] ?? []) as $event) {
            match ((string) ($event['type'] ?? '')) {
                'text' => $this->stream((string) ($event['delta'] ?? '')),
                'tool_call' => $this->post('🔧 '.$this->describeToolCall($event)),
                'status' => $this->post('· '.($event['text'] ?? '')),
                'error' => $this->post('⚠️ '.($event['text'] ?? '')),
                'turn_done' => $this->endTurn(),
                default => null,
            };
        }
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

        $this->streamed .= $delta;

        // A new message once the current one is full, rather than a silent
        // truncation of the agent's answer.
        if (strlen($this->streamed) > self::MAX_MESSAGE) {
            $this->endTurn();
            $this->streamed = $delta;
        }

        if ($this->streamingMessage === null) {
            $this->streamingMessage = $this->api->sendMessage($this->chatId, $this->streamed);

            return;
        }

        $this->api->editMessage($this->chatId, $this->streamingMessage, $this->streamed);
    }

    private function endTurn(): void
    {
        $this->streamingMessage = null;
        $this->streamed = '';
    }

    private function post(string $text): void
    {
        // Anything that is not assistant prose ends the streamed message, so
        // the transcript stays in the order things actually happened.
        $this->endTurn();

        if (trim($text) !== '') {
            $this->api->sendMessage($this->chatId, $text);
        }
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

        $this->api->sendMessage(
            $this->chatId,
            '❓ '.($question['label'] ?? 'The agent needs a decision.')
                .(($question['hint'] ?? null) ? "\n\n".$question['hint'] : ''),
            $buttons === [] ? null : ['inline_keyboard' => $buttons],
        );
    }

    /**
     * Pull anything the human sent and route it: a tapped button answers the
     * open question, plain text becomes the next task.
     */
    private function ingestUpdates(): void
    {
        foreach ($this->api->getUpdates($this->offset, $this->pollTimeout) as $update) {
            $this->offset = max($this->offset, (int) ($update['update_id'] ?? 0) + 1);

            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);

                continue;
            }

            $message = $update['message'] ?? null;
            $chatId = $message['chat']['id'] ?? null;
            $text = trim((string) ($message['text'] ?? ''));

            if ($chatId === null || ! $this->allows($chatId) || $text === '') {
                continue;
            }

            if ($this->handledLocally($text)) {
                continue;
            }

            $text === '/clear'
                ? $this->state->pushCommand('clear')
                : $this->state->pushMessage($text);
        }
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
