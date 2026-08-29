<?php

namespace TackleTelegram\Tests;

use TackleTelegram\Contracts\TelegramApi;

/**
 * Telegram, in memory. The pump holds the security model, so it has to be
 * testable without a token, a network, or a real chat to watch.
 */
class FakeTelegramApi implements TelegramApi
{
    /** @var list<array{chat: int|string, text: string, keyboard: ?array, silent: bool}> */
    public array $sent = [];

    /** @var list<array{id: int, text: string}> */
    public array $edits = [];

    /** @var list<string> */
    public array $callbacksAnswered = [];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    private int $nextMessageId = 1;

    /** Queue an inbound message as if a person had sent it. */
    public function receive(int|string $chatId, string $text, int $updateId = 0, ?int $sentAt = null): void
    {
        $this->pending[] = [
            'update_id' => $updateId ?: count($this->pending) + 1,
            'message' => ['chat' => ['id' => $chatId], 'text' => $text, 'date' => $sentAt ?? time() + 60],
        ];
    }

    /** Queue an arbitrary update — a voice note, a photo, anything not text. */
    public function receiveRaw(array $update): void
    {
        $this->pending[] = ['update_id' => count($this->pending) + 1] + $update;
    }

    /** Queue a tapped inline button. */
    public function tap(int|string $chatId, string $callbackData, string $callbackId = 'cb-1'): void
    {
        $this->pending[] = [
            'update_id' => count($this->pending) + 1,
            'callback_query' => [
                'id' => $callbackId,
                'data' => $callbackData,
                'message' => ['chat' => ['id' => $chatId]],
            ],
        ];
    }

    public function getUpdates(int $offset, int $timeoutSeconds): array
    {
        $updates = array_values(array_filter(
            $this->pending,
            fn (array $update) => (int) $update['update_id'] >= $offset,
        ));

        $this->pending = [];

        return $updates;
    }

    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, bool $silent = false): int
    {
        $this->sent[] = ['chat' => $chatId, 'text' => $text, 'keyboard' => $keyboard, 'silent' => $silent];

        return $this->nextMessageId++;
    }

    /** Messages that buzzed the phone. */
    public function notifying(): array
    {
        return array_values(array_filter($this->sent, fn (array $m) => ! $m['silent']));
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $this->edits[] = ['id' => $messageId, 'text' => $text];
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->callbacksAnswered[] = $callbackId;
    }

    /** Everything said in the chat, in order — sends and edits together. */
    public function transcript(): string
    {
        return implode("\n", array_column($this->sent, 'text'))."\n".implode("\n", array_column($this->edits, 'text'));
    }
}
