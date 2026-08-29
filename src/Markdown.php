<?php

namespace TackleTelegram;

/**
 * The agent's markdown, as Telegram HTML.
 *
 * Telegram will not render markdown unless told to, which is why a finished
 * turn arrived reading **"hello, telegram"** with the asterisks intact. The
 * fix is the same one the terminal renderer uses: convert what the model
 * already wrote, rather than asking it to write something else.
 *
 * HTML rather than MarkdownV2, for two reasons. MarkdownV2 reserves more than
 * a dozen characters that must be escaped everywhere — in prose, in file
 * paths, in code — and one missed underscore rejects the whole message. And
 * its bold is a single asterisk, so the model's `**bold**` would not render
 * even after all that. HTML needs three characters escaped and nothing else.
 *
 * Every conversion requires a closing delimiter, which is what makes this safe
 * mid-stream: a message being edited as text arrives will often end inside an
 * unfinished `**`, and an unclosed marker simply stays literal until its
 * partner shows up.
 */
class Markdown
{
    public static function toTelegramHtml(string $markdown): string
    {
        $blocks = [];

        // Fenced code first, so nothing inside it is treated as markup.
        $text = (string) preg_replace_callback(
            '/```[a-zA-Z0-9_+-]*\n(.*?)```/s',
            function (array $match) use (&$blocks) {
                $blocks[] = '<pre><code>'.self::escape(rtrim($match[1])).'</code></pre>';

                return "\0block".(count($blocks) - 1)."\0";
            },
            $markdown,
        );

        $text = (string) preg_replace_callback(
            '/`([^`\n]+)`/',
            function (array $match) use (&$blocks) {
                $blocks[] = '<code>'.self::escape($match[1]).'</code>';

                return "\0block".(count($blocks) - 1)."\0";
            },
            $text,
        );

        $text = self::escape($text);

        // Bold only. Single asterisks and underscores are left alone on
        // purpose: snake_case identifiers and glob patterns are far more
        // common in this output than emphasis, and italicising half a
        // variable name is worse than not italicising anything.
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<b>$1</b>', $text);

        $text = (string) preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            '<a href="$2">$1</a>',
            $text,
        );

        foreach ($blocks as $index => $block) {
            $text = str_replace("\0block{$index}\0", $block, $text);
        }

        return $text;
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
