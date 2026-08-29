<?php

namespace TackleTelegram\Contracts;

/**
 * The slice of the Telegram Bot API this transport needs.
 *
 * An interface so the pump can be tested without a network or a bot token —
 * the pump is where the security model lives, and that is not something to
 * verify by hand against a live chat.
 */
interface TelegramApi
{
    /**
     * Long-poll for updates since $offset. Returns raw update objects.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeoutSeconds): array;

    /**
     * @param  array<string, mixed>|null  $keyboard  inline_keyboard markup, if any
     * @return int the message id, for editing later
     */
    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): int;

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void;

    /** Stop the button spinner on a tapped inline keyboard. */
    public function answerCallback(string $callbackId, string $text = ''): void;
}
