<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot token
    |--------------------------------------------------------------------------
    |
    | From @BotFather. The bot only ever makes outbound requests, so nothing
    | needs a public URL, a tunnel, or a hosted process — a laptop behind NAT
    | can be driven from anywhere.
    |
    */
    'token' => env('TACKLE_TELEGRAM_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Allowed chats
    |--------------------------------------------------------------------------
    |
    | Numeric chat ids permitted to drive this session. Everything else is
    | dropped before its text can reach the agent.
    |
    | This is the entire security model, so treat an empty list as what it is:
    | a bot nobody may talk to. A token is far more discoverable than a pairing
    | code on your LAN, and anyone who can message this bot can run code on the
    | machine hosting it. Message the bot and check the log for your chat id.
    |
    */
    'allowed_chats' => array_filter(explode(',', (string) env('TACKLE_TELEGRAM_CHATS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Long-poll timeout
    |--------------------------------------------------------------------------
    |
    | Seconds Telegram holds a getUpdates request open waiting for something to
    | happen. The pump runs from the session's idle hook, so this is also how
    | long a turn can be delayed before starting — keep it short.
    |
    */
    'poll_timeout' => (int) env('TACKLE_TELEGRAM_POLL_TIMEOUT', 2),

];
