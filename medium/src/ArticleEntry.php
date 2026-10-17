<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

use DOMDocument;
use DOMXPath;
use Gll\Hub\Contracts\Source\BodyFormat;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Gll\Hub\Contracts\Support\Fingerprint;
use Gll\Hub\Portfolio\Types\Article;

/**
 * Turns a feed item into an article entry. The body stays HTML: the core
 * sanitizes and converts it, in one place for every source.
 */
final class ArticleEntry
{
    private const WORDS_PER_MINUTE = 265;
    private const SUMMARY_LENGTH = 200;

    public static function from(FeedItem $item, string $language): ExternalEntry
    {
        $html = self::document($item->html);
        $text = self::clean($html->textContent);
        $canonical = self::withoutQuery($item->link);
        $cover = self::firstImage($html);

        return new ExternalEntry(
            externalId: self::externalId($item),
            type: Article::KEY,
            title: $item->title,
            slug: self::slug($canonical),
            summary: self::summary($html),
            body: $item->html,
            language: $language,
            fields: array_filter([
                'origin_url' => $canonical,
                'reading_minutes' => max(1, (int) ceil(str_word_count($text) / self::WORDS_PER_MINUTE)),
                'tags' => $item->categories ?: null,
            ], static fn (mixed $v): bool => $v !== null),
            media: $cover === null ? [] : [new RemoteMedia('cover', $cover, alt: $item->title)],
            publishedAt: $item->publishedAt,
            updatedAt: $item->updatedAt ?? $item->publishedAt,
            canonicalUrl: $canonical,
            fingerprint: Fingerprint::of($item->title, $item->html, implode(',', $item->categories)),
            bodyFormat: BodyFormat::Html,
        );
    }

    /** Medium's guid is "https://medium.com/p/<id>"; the id is stable across renames. */
    public static function externalId(FeedItem $item): string
    {
        return preg_match('~/p/([0-9a-f]+)$~i', $item->guid, $m) ? strtolower($m[1]) : $item->guid;
    }

    /** "…/@user/my-title-1a2b3c4d5e6f" → "my-title". */
    public static function slug(string $canonical): ?string
    {
        $last = basename((string) parse_url($canonical, PHP_URL_PATH));
        $slug = preg_replace('/-[0-9a-f]{8,16}$/i', '', $last);

        return $slug === '' || $slug === null ? null : $slug;
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function withoutQuery(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }

    private static function document(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        Libxml::quietly(static fn () => $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD));

        return $doc;
    }

    private static function firstImage(DOMDocument $doc): ?string
    {
        foreach ((new DOMXPath($doc))->query('//img[@src]') ?: [] as $img) {
            $src = $img->getAttribute('src');
            if (str_starts_with($src, 'https://')) {
                return $src;
            }
        }

        return null;
    }

    private static function summary(DOMDocument $doc): ?string
    {
        foreach ((new DOMXPath($doc))->query('//p') ?: [] as $p) {
            $text = self::clean($p->textContent);
            if ($text !== '') {
                return mb_strlen($text) <= self::SUMMARY_LENGTH
                    ? $text
                    : rtrim(mb_substr($text, 0, self::SUMMARY_LENGTH - 1)) . '…';
            }
        }

        return null;
    }
}
