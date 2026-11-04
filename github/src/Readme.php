<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/** What a project page takes from a README when the repository has no showcase file. */
final class Readme
{
    private const NOT_PROSE = '/^(?:#|>|```|~~~|[-*+]\s|\d+[.)]\s|\||<|!\[|\[!\[|[-=*_]{3,}\s*$)/';
    private const UNDERLINED_HEADING = '/\R[-=]{2,}\s*$/';
    private const BADGE = '~(?:^https://img\.shields\.io/|badge)~i';

    /** The first block of prose, as one line of plain text. */
    public static function firstParagraph(string $markdown): ?string
    {
        $text = (string) preg_replace(['/<!--.*?-->/s', '/^(```|~~~).*?^\1\s*$/ms'], '', $markdown);

        foreach (preg_split('/\R\s*\R/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '' && preg_match(self::NOT_PROSE, $block) !== 1 && preg_match(self::UNDERLINED_HEADING, $block) !== 1) {
                return self::plain($block);
            }
        }

        return null;
    }

    /** The first image that is not a badge. */
    public static function firstImage(string $markdown): ?string
    {
        foreach (MarkdownImages::links($markdown) as $link) {
            if (preg_match(self::BADGE, $link) !== 1) {
                return $link;
            }
        }

        return null;
    }

    private static function plain(string $block): string
    {
        $text = (string) preg_replace(
            ['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/\*\*|__|\*|`/', '/\s+/'],
            ['', '$1', '', ' '],
            $block,
        );

        return trim($text);
    }
}
