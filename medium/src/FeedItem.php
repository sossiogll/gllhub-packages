<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

use DateTimeImmutable;

/** One <item> of a Medium feed, as read, before it becomes an entry. */
final readonly class FeedItem
{
    /** @param list<string> $categories */
    public function __construct(
        public string $guid,
        public string $title,
        public string $link,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $updatedAt,
        public array $categories,
        public string $html,
    ) {
    }
}
