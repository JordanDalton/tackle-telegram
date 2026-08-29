<?php

use TackleTelegram\Commands\TelegramCommand;

function withChat(string $env, string $id): string
{
    return app(TelegramCommand::class)->withChat($env, $id);
}

it('appends the variable when it is not there yet', function () {
    expect(withChat("APP_NAME=Demo\nAPP_ENV=local\n", '123'))
        ->toBe("APP_NAME=Demo\nAPP_ENV=local\nTACKLE_TELEGRAM_CHATS=123\n");
});

it('adds to the list rather than replacing it', function () {
    // Pairing a second device must not silently revoke the first.
    expect(withChat("TACKLE_TELEGRAM_CHATS=111\nAPP_ENV=local\n", '222'))
        ->toBe("TACKLE_TELEGRAM_CHATS=111,222\nAPP_ENV=local\n");
});

it('leaves the file untouched when the chat is already allowed', function () {
    $env = "TACKLE_TELEGRAM_CHATS=111,222\n";

    expect(withChat($env, '222'))->toBe($env);
});

it('does not disturb the rest of the file', function () {
    $env = "APP_KEY=base64:abc\nTACKLE_TELEGRAM_TOKEN=123:ABC\nTACKLE_TELEGRAM_CHATS=111\nDB_CONNECTION=sqlite\n";

    expect(withChat($env, '222'))
        ->toContain('APP_KEY=base64:abc')
        ->toContain('TACKLE_TELEGRAM_TOKEN=123:ABC')
        ->toContain('DB_CONNECTION=sqlite')
        ->toContain('TACKLE_TELEGRAM_CHATS=111,222');
});

it('copes with a quoted or empty value', function () {
    expect(withChat("TACKLE_TELEGRAM_CHATS=\"111\"\n", '222'))->toContain('111,222');
    expect(withChat("TACKLE_TELEGRAM_CHATS=\n", '222'))->toContain('TACKLE_TELEGRAM_CHATS=222');
});
