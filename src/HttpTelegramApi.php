<?php

namespace TackleTelegram;

use RuntimeException;
use TackleTelegram\Contracts\TelegramApi;

/**
 * The Bot API over curl. No SDK, no dependency — this speaks four methods.
 *
 * getUpdates is a *long* poll: Telegram holds the connection open until
 * something happens or the timeout expires, which is what makes this cheap to
 * run in a loop and what removes any need for a public URL.
 */
class HttpTelegramApi implements TelegramApi
{
    public function __construct(
        private readonly string $token,
        private readonly int $timeout = 40,
    ) {
        if (trim($this->token) === '') {
            throw new RuntimeException('No Telegram bot token. Get one from @BotFather and set TACKLE_TELEGRAM_TOKEN.');
        }
    }

    public function getUpdates(int $offset, int $timeoutSeconds): array
    {
        $response = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeoutSeconds,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeoutSeconds + 10);

        return array_values((array) ($response['result'] ?? []));
    }

    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, bool $silent = false): int
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_notification' => $silent];

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }

        return (int) ($this->call('sendMessage', $payload)['result']['message_id'] ?? 0);
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }

        // Editing to identical text is an error Telegram raises and nobody
        // needs to hear about.
        $this->call('editMessageText', $payload, ignoreErrors: true);
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text], ignoreErrors: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload, ?int $timeout = null, bool $ignoreErrors = false): array
    {
        $handle = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            if ($ignoreErrors) {
                return [];
            }

            throw new RuntimeException("Could not reach Telegram ({$method}): {$error}");
        }

        $decoded = json_decode((string) $body, true);

        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            if ($ignoreErrors) {
                return [];
            }

            // Telegram hands each update to exactly one caller, and answers a
            // second concurrent poller with 409. That is not a failure to
            // report as a refusal — it is the single most confusing thing that
            // can happen to this package, and it has an exact cause.
            if ((int) ($decoded['error_code'] ?? 0) === 409) {
                throw new RuntimeException(
                    'Another process is already polling this bot. Telegram delivers each message to only one '
                    .'poller, so stop the other session (or the other --pair) before starting this one.'
                );
            }

            throw new RuntimeException("Telegram refused {$method}: ".(is_array($decoded) ? (string) ($decoded['description'] ?? $body) : (string) $body));
        }

        return $decoded;
    }
}
