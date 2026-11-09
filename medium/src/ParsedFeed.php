<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

/** What a feed holds: the items that have a guid, and the titles of those that have none and so cannot be told apart. */
final readonly class ParsedFeed
{
    /**
     * @param list<FeedItem> $items
     * @param list<string> $unidentified the title of each item with no guid, empty when it has none
     */
    public function __construct(
        public array $items,
        public array $unidentified,
    ) {
    }
}
