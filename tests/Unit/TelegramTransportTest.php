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

it('reports a transport failure to the terminal, not into the chat', function () {
    // Emitting it was a small disaster: the event triggered a flush, the flush
    // tried Telegram again, and a network blip became a message in the chat
    // about not being able to reach the chat.
    $exploding = new class implements TelegramApi
    {
        public function getUpdates(int $offset, int $timeoutSeconds): array
        {
            throw new RuntimeException('Connection timed out');
        }

        public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, bool $silent = false): int
        {
            return 1;
        }

        public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void {}

        public function answerCallback(string $callbackId, string $text = ''): void {}
    };

    (new TelegramTransport($exploding, $this->state, 42, [42]))->pump();

    $texts = array_column($this->state->eventsAfter(0)['events'], 'text');

    expect(implode(' ', array_filter($texts)))->not->toContain('Connection timed out');
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
        ->and($question['keyboard']['inline_keyboard'][0][0])->toBe(['text' => 'Yes', 'callback_data' => $id.':yes'])
        // The one message that earns a notification.
        ->and($question['silent'])->toBeFalse();
});

it('keeps a whole turn in one message, and keeps the phone quiet', function () {
    // A turn used to arrive as six separate messages — six notifications to
    // say it had read a file. A turn is one thing that happened.
    $this->state->emit('text', ['delta' => 'Let me update that file.']);
    $this->state->emit('tool_call', ['tool' => 'SearchCode', 'arguments' => ['query' => 'headline']]);
    $this->state->emit('tool_call', ['tool' => 'EditFile', 'arguments' => ['path' => 'Welcome.vue']]);
    $this->state->emit('text', ['delta' => 'Done!']);

    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(1)
        ->and($this->api->notifying())->toBe([]);

    $final = end($this->api->edits)['text'];

    expect($final)
        ->toContain('Let me update that file.')
        ->toContain('SearchCode headline')
        ->toContain('EditFile Welcome.vue')
        ->toContain('Done!');
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

// ---------------------------------------------------------------------------
// Pushing while the turn is still running
// ---------------------------------------------------------------------------

it('pushes a tool call the moment it happens', function () {
    // SessionLoop's onIdle never fires during a turn, so a transport hung off
    // it stays silent for the whole of the work it is meant to narrate. Live,
    // the file edit landed and the browser hot-reloaded before the chat had
    // said a word.
    $this->state->emit('tool_call', ['tool' => 'EditFile', 'arguments' => ['path' => 'Welcome.vue']]);

    $this->transport->flush('tool_call');

    expect($this->api->transcript())->toContain('EditFile Welcome.vue');
});

it('throttles streamed text rather than editing on every delta', function () {
    $this->state->emit('text', ['delta' => 'Reading ']);
    $this->transport->flush('text');

    $before = count($this->api->sent) + count($this->api->edits);

    // A turn produces deltas far faster than Telegram accepts edits to one
    // message, and being rate-limited mid-answer is worse than lagging.
    $this->state->emit('text', ['delta' => 'the file.']);
    $this->transport->flush('text');

    expect(count($this->api->sent) + count($this->api->edits))->toBe($before);
});

it('never throttles anything that is not text', function () {
    $this->state->emit('status', ['text' => 'one']);
    $this->transport->flush('status');
    $this->state->emit('error', ['text' => 'two']);
    $this->transport->flush('error');

    expect($this->api->transcript())->toContain('one')->toContain('two');
});

// ---------------------------------------------------------------------------
// Markdown
// ---------------------------------------------------------------------------

it('renders the agent markdown Telegram would otherwise show raw', function () {
    // The first real turn ended with **"hello, telegram"** — asterisks and all.
    $this->state->emit('status', ['text' => 'Done! The headline now reads **"hello, telegram"**.']);

    $this->transport->flush('status');

    expect($this->api->transcript())
        ->toContain('<b>"hello, telegram"</b>')
        ->not->toContain('**');
});

// ---------------------------------------------------------------------------
// The event cursor
// ---------------------------------------------------------------------------

it('does not replay the previous conversation when a session restarts', function () {
    // events.jsonl outlives the process. A restarted session was dumping every
    // event of every earlier conversation into the chat — nonsense to read,
    // and a fast way to hit Telegram's rate limit before saying anything new.
    $this->state->emit('text', ['delta' => 'from an old session']);
    $this->state->emit('turn_done', []);

    $fresh = new TelegramTransport($this->api, $this->state, 42, [42]);
    $fresh->pump();

    expect($this->api->sent)->toBe([]);
});

it('retries an event Telegram refused instead of skipping it', function () {
    // The cursor used to jump to the end of the batch before anything had been
    // sent, so a rate-limited message was lost for good.
    $refusing = new class($this->api) implements TelegramApi
    {
        public bool $failing = true;

        public function __construct(public $inner) {}

        public function getUpdates(int $offset, int $timeoutSeconds): array
        {
            return [];
        }

        public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, bool $silent = false): int
        {
            if ($this->failing) {
                throw new RuntimeException('Too Many Requests: retry after 30');
            }

            return $this->inner->sendMessage($chatId, $text, $keyboard, $silent);
        }

        public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void {}

        public function answerCallback(string $callbackId, string $text = ''): void {}
    };

    $transport = new TelegramTransport($refusing, $this->state, 42, [42]);
    $this->state->emit('status', ['text' => 'something worth seeing']);

    $transport->pump();
    expect($this->api->sent)->toBe([]);

    $refusing->failing = false;
    $transport->pump();

    expect($this->api->transcript())->toContain('something worth seeing');
});

// ---------------------------------------------------------------------------
// Turn boundaries
// ---------------------------------------------------------------------------

it('does not edit a turn into the message that came before it', function () {
    // The bug that looked like a dead transport: the id of the first message
    // ever sent stayed in hand forever, so every later turn was edited into
    // it. Nothing new appeared in the chat while the files changed anyway.
    $this->state->emit('status', ['text' => 'Resumed session "telegram" (4 messages).']);
    $this->transport->pump();

    $this->state->emit('user', ['text' => 'update the headline']);
    $this->state->emit('text', ['delta' => 'Done!']);
    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(2)
        ->and($this->api->sent[0]['text'])->toContain('Resumed session')
        ->and($this->api->sent[1]['text'])->toContain('Done!');
});

it('gives an idle status its own message rather than gluing it to the next turn', function () {
    $this->state->emit('status', ['text' => 'one']);
    $this->state->emit('status', ['text' => 'two']);

    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(2)
        ->and($this->api->edits)->toBe([]);
});

it('keeps everything inside a single turn together', function () {
    $this->state->emit('user', ['text' => 'do the thing']);
    $this->state->emit('text', ['delta' => 'Working. ']);
    $this->state->emit('tool_call', ['tool' => 'EditFile', 'arguments' => ['path' => 'a.php']]);
    $this->state->emit('text', ['delta' => 'Done.']);
    $this->state->emit('turn_done', []);

    $this->transport->pump();

    expect($this->api->sent)->toHaveCount(1);

    $final = end($this->api->edits)['text'];

    expect($final)->toContain('Working.')->toContain('EditFile a.php')->toContain('Done.');
});

it('keeps talking after /clear truncates the log', function () {
    // /clear resets events.jsonl. A cursor left past the new end would swallow
    // everything until the file grew back to that length — the bot would just
    // stop, with nothing to show for it.
    foreach (range(1, 6) as $i) {
        $this->state->emit('status', ['text' => "old {$i}"]);
    }

    $this->transport->pump();
    $before = count($this->api->sent);

    $this->state->clearEvents();
    $this->state->emit('status', ['text' => 'after the clear']);

    $this->transport->pump();

    expect(count($this->api->sent))->toBeGreaterThan($before)
        ->and($this->api->transcript())->toContain('after the clear');
});

it('does not act on messages sent while nobody was listening', function () {
    // Telegram holds undelivered updates for 24 hours. Without this, starting
    // a session pulls everything said to a dead bot out of the queue and runs
    // it as work — "u there?" becomes a coding task, at your expense.
    $this->api->receive(42, 'u there?', sentAt: time() - 3600);

    $this->transport->pump();

    expect($this->state->popMessage())->toBeNull()
        ->and($this->api->transcript())->toContain('was not running');
});

it('still acts on messages sent to a live session', function () {
    $this->api->receive(42, 'fix the bug', sentAt: time() + 5);

    $this->transport->pump();

    expect($this->state->popMessage()['text'])->toBe('fix the bug');
});

it('puts consecutive tool calls on adjacent lines, not in separate paragraphs', function () {
    // Seen live on the Slack transport: a blank line between two EditFile
    // calls read as a message boundary.
    $this->state->emit('user', ['text' => 'do it']);
    $this->state->emit('text', ['delta' => 'Working.']);
    $this->state->emit('tool_call', ['tool' => 'EditFile', 'arguments' => ['path' => 'a.php']]);
    $this->state->emit('tool_call', ['tool' => 'EditFile', 'arguments' => ['path' => 'b.php']]);
    $this->state->emit('text', ['delta' => 'Done.']);

    $this->transport->pump();

    expect(end($this->api->edits)['text'])
        ->toBe("Working.\n🔧 EditFile a.php\n🔧 EditFile b.php\nDone.")
        ->and($this->api->sent)->toHaveCount(1);
});
