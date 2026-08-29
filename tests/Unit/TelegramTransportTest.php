<?php

use TackleRemote\Support\RemoteState;
use TackleTelegram\Contracts\TelegramApi;
use TackleTelegram\TelegramTransport;
use TackleTelegram\Tests\FakeTelegramApi;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/tackle-telegram-'.uniqid();
    $this->state = new RemoteState($this->dir);
    $this->api = new FakeTelegramApi;
    $this->transport = new TelegramTransport($this->api, $this->state, chatId: 42, allowedChats: [42]);
});

// ---------------------------------------------------------------------------
// The security model
// ---------------------------------------------------------------------------

it('drops a message from a chat it was not told about', function () {
    // A bot token is more discoverable than a LAN pairing code, and anyone who
    // can message this bot can run code on the machine hosting it.
    $this->api->receive(999, 'rm -rf everything');

    $this->transport->pump();

    expect($this->state->popMessage())->toBeNull();
});

it('accepts a message from an allowed chat', function () {
    $this->api->receive(42, 'fix the N+1 in the orders page');

    $this->transport->pump();

    expect($this->state->popMessage()['text'])->toBe('fix the N+1 in the orders page');
});

it('ignores a button tapped from a chat it was not told about', function () {
    $id = $this->state->ask('Run migrate?', ['yes' => 'Yes', 'no' => 'No']);
    $this->api->tap(999, $id.':yes');

    $this->transport->pump();

    expect($this->state->takeAnswer($id))->toBeNull();
});

it('treats an empty allowlist as nobody', function () {
    $closed = new TelegramTransport($this->api, $this->state, chatId: 42, allowedChats: []);

    expect($closed->allows(42))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Carrying the session into the chat
// ---------------------------------------------------------------------------

it('streams assistant text into one message rather than a flood', function () {
    foreach (['Reading ', 'the ', 'orders ', 'controller.'] as $delta) {
        $this->state->emit('text', ['delta' => $delta]);
    }

    $this->transport->pump();

    // One message, edited as it grows — not four messages.
    expect($this->api->sent)->toHaveCount(1)
        ->and(end($this->api->edits)['text'])->toBe('Reading the orders controller.');
});

it('starts a new message for the next turn', function () {
    $this->state->emit('text', ['delta' => 'First answer.']);
    $this->state->emit('turn_done', []);
    $this->state->emit('text', ['delta' => 'Second answer.']);

    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(2)
        ->and($this->api->sent[1]['text'])->toBe('Second answer.');
});

it('reports tool calls, statuses and errors', function () {
    $this->state->emit('tool_call', ['tool' => 'ReadFile', 'arguments' => ['path' => 'app/Models/Order.php']]);
    $this->state->emit('error', ['text' => 'Could not read the file.']);

    $this->transport->pump();

    expect($this->api->transcript())
        ->toContain('🔧 ReadFile app/Models/Order.php')
        ->toContain('⚠️ Could not read the file.');
});

it('does not replay events it has already sent', function () {
    $this->state->emit('status', ['text' => 'Compacting…']);

    $this->transport->pump();
    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Approvals
// ---------------------------------------------------------------------------

it('offers the pending question as tappable buttons, once', function () {
    $id = $this->state->ask('Run php artisan migrate?', ['yes' => 'Yes', 'no' => 'No'], 'This is destructive.');

    $this->transport->pump();
    $this->transport->pump();

    $question = collect($this->api->sent)->firstWhere(fn ($m) => str_starts_with($m['text'], '❓'));

    expect($this->api->sent)->toHaveCount(1)
        ->and($question['text'])->toContain('Run php artisan migrate?')->toContain('This is destructive.')
        ->and($question['keyboard']['inline_keyboard'][0][0])->toBe(['text' => 'Yes', 'callback_data' => $id.':yes']);
});

it('answers the open question when the button is tapped', function () {
    $id = $this->state->ask('Run php artisan migrate?', ['yes' => 'Yes', 'no' => 'No']);
    $this->transport->pump();

    $this->api->tap(42, $id.':yes');
    $this->transport->pump();

    expect($this->state->takeAnswer($id))->toBe('yes')
        ->and($this->api->callbacksAnswered)->toHaveCount(1);
});

it('refuses to answer a question the agent has moved on from', function () {
    $stale = $this->state->ask('Old question?', ['yes' => 'Yes']);
    $this->state->clearQuestion();

    $current = $this->state->ask('Current question?', ['yes' => 'Yes']);

    // By the time someone reaches their phone the agent may be asking
    // something else. Answering the wrong question is worse than missing one.
    $this->api->tap(42, $stale.':yes');
    $this->transport->pump();

    expect($this->state->takeAnswer($current))->toBeNull();
});

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

it('routes /clear to the session rather than the agent', function () {
    $this->api->receive(42, '/clear');

    $this->transport->pump();

    expect($this->state->popMessage()['command'])->toBe('clear');
});

it('survives Telegram being unreachable mid-session', function () {
    $exploding = new class implements TelegramApi
    {
        public function getUpdates(int $offset, int $timeoutSeconds): array
        {
            throw new RuntimeException('Connection reset by peer');
        }

        public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): int
        {
            return 1;
        }

        public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void {}

        public function answerCallback(string $callbackId, string $text = ''): void {}
    };

    $transport = new TelegramTransport($exploding, $this->state, 42, [42]);

    // A transport that dies takes the agent with it.
    $transport->pump();

    $texts = array_column($this->state->eventsAfter(0)['events'], 'text');

    expect(implode(' ', array_filter($texts)))->toContain('Connection reset');
});

// ---------------------------------------------------------------------------
// Commands the chat answers itself
// ---------------------------------------------------------------------------

it('greets /start instead of handing it to the agent', function () {
    // Found on the first live run: /start is how every Telegram user opens
    // every bot, so it is the first message this will ever receive. Forwarded,
    // it produced "Unknown command /start — add .tackle/commands/start.md".
    $this->api->receive(42, '/start');

    $this->transport->pump();

    expect($this->state->popMessage())->toBeNull()
        ->and($this->api->transcript())->toContain('Tackle is listening');
});

it('answers /help from the published command list', function () {
    // The session loop never sees /help either — the browser renders it
    // locally, so a second client has to as well.
    $this->state->putCommands([
        ['name' => 'clear', 'description' => 'Forget the conversation'],
        ['name' => 'deploy-check', 'description' => 'Review changes for deploy risk'],
    ]);

    $this->api->receive(42, '/help');
    $this->transport->pump();

    expect($this->state->popMessage())->toBeNull()
        ->and($this->api->transcript())
        ->toContain('/clear — Forget the conversation')
        ->toContain('/deploy-check — Review changes for deploy risk');
});

it('still has something true to say about /help before the session publishes', function () {
    $this->api->receive(42, '/help');
    $this->transport->pump();

    expect($this->api->transcript())->toContain('/clear');
});

it('leaves every other slash command to the session', function () {
    // Project commands and /compact belong to Tackle, not the transport.
    $this->api->receive(42, '/compact');
    $this->transport->pump();

    expect($this->state->popMessage()['text'])->toBe('/compact');
});

// ---------------------------------------------------------------------------
// Messages that are not text
// ---------------------------------------------------------------------------

it('answers a voice note instead of ignoring it', function () {
    // Found on the first live session against a real bot: a voice note
    // produced complete silence, which is indistinguishable from a crash.
    $this->api->receiveRaw(['message' => ['chat' => ['id' => 42], 'voice' => ['file_id' => 'abc', 'duration' => 7]]]);

    $this->transport->pump();

    expect($this->state->popMessage())->toBeNull()
        ->and($this->api->transcript())->toContain('cannot listen to voice notes yet');
});

it('answers a photo instead of ignoring it', function () {
    $this->api->receiveRaw(['message' => ['chat' => ['id' => 42], 'photo' => [['file_id' => 'abc']]]]);

    $this->transport->pump();

    expect($this->api->transcript())->toContain('cannot read attachments yet');
});

it('stays silent for an unsupported message from a chat it was not told about', function () {
    // The allowlist still comes first: an unlisted chat gets nothing at all,
    // not even a "cannot do that" — which would confirm the bot exists.
    $this->api->receiveRaw(['message' => ['chat' => ['id' => 999], 'voice' => ['file_id' => 'abc']]]);

    $this->transport->pump();

    expect($this->api->sent)->toBe([]);
});
