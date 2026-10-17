<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use SimpleXMLElement;

/** Reads the RSS 2.0 that Medium serves. Knows nothing of entries or the core. */
final class FeedParser
{
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    /** @return list<FeedItem> */
    public static function parse(string $xml): array
    {
        $rss = Libxml::quietly(static fn () => simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA));

        if ($rss === false || ! isset($rss->channel)) {
            throw new Unavailable('Medium answered with something that is not an RSS feed.');
        }

        $items = [];
        foreach ($rss->channel->item as $item) {
            $guid = trim((string) $item->guid);
            $link = trim((string) $item->link);
            if ($guid === '' || $link === '') {
                continue;
            }

            $items[] = new FeedItem(
                guid: $guid,
                title: trim((string) $item->title),
                link: $link,
                publishedAt: self::date((string) $item->pubDate),
                updatedAt: self::date((string) $item->children(self::ATOM_NS)->updated),
                categories: array_values(array_map('strval', iterator_to_array($item->category, false))),
                html: (string) $item->children(self::CONTENT_NS)->encoded,
            );
        }

        return $items;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
