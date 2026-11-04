<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/** The images of a Markdown text, written as Markdown or as an HTML tag. */
final class MarkdownImages
{
    private const IMAGE = '/(!\[[^\]]*\]\(\s*<?)([^)\s>]+)|(<img\b[^>]*?\bsrc=["\'])([^"\']+)/i';

    /** @return list<string> the links, in order of appearance */
    public static function links(string $markdown): array
    {
        preg_match_all(self::IMAGE, $markdown, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): string => $m[4] ?? $m[2], $matches);
    }

    /**
     * Makes relative links absolute, so the text stands on its own, and names them:
     * the core copies them into the media library.
     *
     * @return array{0: string, 1: list<string>} the text, and the URLs that were relative
     */
    public static function absolute(string $markdown, RawLinks $links): array
    {
        $found = [];
        $text = preg_replace_callback(self::IMAGE, static function (array $m) use ($links, &$found): string {
            $prefix = $m[3] ?? $m[1];
            $link = $m[4] ?? $m[2];
            $absolute = $links->absolute($link);
            if ($absolute === null || $absolute === $link) {
                return $m[0];
            }
            $found[] = $absolute;

            return $prefix . $absolute;
        }, $markdown);

        return [$text ?? $markdown, array_values(array_unique($found))];
    }
}
