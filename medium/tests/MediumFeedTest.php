<?php

declare(strict_types=1);

namespace Gll\Hub\Medium\Tests;

use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\RateLimited;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpResponse;
use Gll\Hub\Contracts\Source\BodyFormat;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Contracts\Testing\FakeHttpClient;
use Gll\Hub\Contracts\Testing\StaticContext;
use Gll\Hub\Medium\MediumFeed;
use Gll\Hub\Medium\MediumPackage;
use Gll\Hub\Portfolio\Types\Article;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class MediumFeedTest extends TestCase
{
    private const URL = 'https://medium.com/feed/@example';

    public function test_the_package_conforms(): void
    {
        Conformance::check(new MediumPackage());
        $this->addToAssertionCount(1);
    }

    public function test_entries_conform_to_the_article_type(): void
    {
        Conformance::checkEntries(MediumFeed::definition(), [Article::definition()], $this->pull());
        $this->addToAssertionCount(1);
    }

    public function test_an_item_becomes_an_article(): void
    {
        [$first, $second] = $this->pull();

        $this->assertSame('1a2b3c4d5e6f', $first->externalId);
        $this->assertSame('article', $first->type);
        $this->assertSame('building-a-cursor-that-knows-what-it-points-at', $first->slug);
        $this->assertSame('https://medium.com/@example/building-a-cursor-that-knows-what-it-points-at-1a2b3c4d5e6f', $first->canonicalUrl);
        $this->assertSame($first->canonicalUrl, $first->fields['origin_url']);
        $this->assertSame(['javascript', 'ux'], $first->fields['tags']);
        $this->assertSame(1, $first->fields['reading_minutes']);
        $this->assertSame('The arrow cursor has not changed in decades. This is the story of a small rewrite.', $first->summary);
        $this->assertSame(BodyFormat::Html, $first->bodyFormat);
        $this->assertSame('https://cdn-images-1.medium.com/max/1024/1*example.png', $first->media[0]->url);
        $this->assertSame('2026-10-05T08:30:00+00:00', $first->publishedAt?->format('c'));
        $this->assertSame('2026-10-06T10:00:00+00:00', $first->updatedAt?->format('c'));
        $this->assertSame('it', $first->language);

        $this->assertSame([], $second->media);
        $this->assertArrayNotHasKey('tags', $second->fields);
        $this->assertSame($second->publishedAt, $second->updatedAt);
    }

    public function test_an_item_without_a_link_is_refused_with_its_reason(): void
    {
        $entry = $this->pull()[2];

        $this->assertSame('0123456789ab', $entry->externalId);
        $this->assertSame('article', $entry->type);
        $this->assertSame('it', $entry->language);
        $this->assertSame('the feed item has no link', $entry->refusal);
        Conformance::checkEntries(MediumFeed::definition(), [Article::definition()], [$entry]);
    }

    public function test_an_item_without_a_guid_is_no_entry_and_is_logged(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        $entries = $this->pull(logger: $logger);

        $this->assertSame(['1a2b3c4d5e6f', 'abcdef012345', '0123456789ab'], array_column($entries, 'externalId'));
        $this->assertSame([['Feed item without a guid skipped', ['title' => 'Broken item without a guid']]], $logger->records);
    }

    public function test_the_fingerprint_follows_the_content(): void
    {
        $a = $this->pull()[0]->fingerprint;
        $changed = str_replace('small rewrite', 'larger rewrite', $this->feed());
        $b = $this->pull($changed)[0]->fingerprint;

        $this->assertNotSame($a, $b);
        $this->assertSame($a, $this->pull()[0]->fingerprint);
    }

    public function test_the_username_is_accepted_with_or_without_the_at(): void
    {
        $this->assertSame(self::URL, MediumFeed::feedUrl('example'));
        $this->assertSame(self::URL, MediumFeed::feedUrl(' @example '));
    }

    public function test_a_bad_username_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfiguration::class);
        MediumFeed::feedUrl('not a name/');
    }

    public function test_an_unknown_author_is_a_configuration_error(): void
    {
        try {
            $this->pull(response: new HttpResponse(404, ''));
            $this->fail('expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            $this->assertSame(MediumFeed::USERNAME, $e->setting);
        }
    }

    public function test_a_page_that_is_not_a_feed_is_unavailable(): void
    {
        $this->expectException(Unavailable::class);
        $this->pull('<html><body>Something went wrong</body></html>');
    }

    public function test_an_unexpected_status_is_unavailable(): void
    {
        $this->expectException(Unavailable::class);
        $this->pull(response: new HttpResponse(410, ''));
    }

    public function test_what_the_core_client_throws_is_left_to_the_core(): void
    {
        $this->expectException(RateLimited::class);
        $this->pull(response: new RateLimited(120));
    }

    /** @return list<ExternalEntry> */
    private function pull(?string $xml = null, HttpResponse|\Throwable|null $response = null, ?LoggerInterface $logger = null): array
    {
        $http = (new FakeHttpClient())->respond(self::URL, $response ?? new HttpResponse(200, $xml ?? $this->feed()));
        $context = new StaticContext(settings: ['username' => '@example', 'language' => 'it'], http: $http, logger: $logger);

        return iterator_to_array((new MediumFeed())->pull($context), false);
    }

    private function feed(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/feed.xml');
    }
}
