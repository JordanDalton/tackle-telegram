<?php

use TackleTelegram\Markdown;

it('converts bold, inline code and fenced blocks', function () {
    expect(Markdown::toTelegramHtml('The headline is **hello** now.'))
        ->toBe('The headline is <b>hello</b> now.')
        ->and(Markdown::toTelegramHtml('Run `npm run dev` first.'))
        ->toBe('Run <code>npm run dev</code> first.')
        ->and(Markdown::toTelegramHtml("Try:\n```php\n\$x = 1;\n```"))
        ->toContain('<pre><code>$x = 1;</code></pre>');
});

it('escapes HTML so a diff cannot break the message', function () {
    // Agent output is full of angle brackets — every Blade and Vue snippet it
    // quotes. Unescaped, Telegram rejects the whole message.
    expect(Markdown::toTelegramHtml('changed <h1> to <h2> & saved'))
        ->toBe('changed &lt;h1&gt; to &lt;h2&gt; &amp; saved');
});

it('escapes inside code spans too', function () {
    expect(Markdown::toTelegramHtml('`<h1 class="x">`'))
        ->toBe('<code>&lt;h1 class="x"&gt;</code>');
});

it('leaves an unclosed marker alone', function () {
    // Mid-stream a message often ends inside an unfinished pair; requiring the
    // closing delimiter is what makes editing a growing message safe.
    expect(Markdown::toTelegramHtml('the headline is **hello'))
        ->toBe('the headline is **hello');
});

it('does not italicise snake_case or globs', function () {
    // Single asterisks and underscores are left alone on purpose: identifiers
    // and patterns are far more common here than emphasis.
    expect(Markdown::toTelegramHtml('found user_id in app/Models/*.php'))
        ->toBe('found user_id in app/Models/*.php');
});

it('links out', function () {
    expect(Markdown::toTelegramHtml('see [the docs](https://example.com/x)'))
        ->toBe('see <a href="https://example.com/x">the docs</a>');
});

it('does not treat markdown inside a code block as markup', function () {
    expect(Markdown::toTelegramHtml("```\n**not bold**\n```"))
        ->toContain('**not bold**');
});
