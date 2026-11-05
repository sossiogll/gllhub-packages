<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume\Tests;

use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpResponse;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Contracts\Testing\FakeHttpClient;
use Gll\Hub\Contracts\Testing\StaticContext;
use Gll\Hub\JsonResume\JsonResumeFile;
use Gll\Hub\JsonResume\JsonResumePackage;
use Gll\Hub\Portfolio\Types\Resume;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class JsonResumeFileTest extends TestCase
{
    private const URL = 'https://files.example.test/resume.json';

    public function test_the_package_conforms(): void
    {
        Conformance::check(new JsonResumePackage());
        $this->addToAssertionCount(1);
    }

    public function test_the_entry_conforms_to_the_type(): void
    {
        Conformance::checkEntries(JsonResumeFile::definition(), [Resume::definition()], $this->pull());
        $this->addToAssertionCount(1);
    }

    public function test_a_document_becomes_a_cv(): void
    {
        [$entry] = $this->pull();

        $this->assertSame('cv', $entry->slug);
        $this->assertSame('cv', $entry->externalId);
        $this->assertSame('resume', $entry->type);
        $this->assertSame('Ada Example', $entry->title);
        $this->assertSame('Engineer', $entry->summary);
        $this->assertSame('Hello.', $entry->body);
        $this->assertSame('en', $entry->language);
        $this->assertSame('json-resume:cv', $entry->translationKey);
        $this->assertSame('2026-02-03T00:00:00+00:00', $entry->publishedAt?->format('c'));

        $this->assertSame([
            'name' => 'Example Ltd',
            'position' => 'Developer',
            'start_date' => '2023-12',
            'url' => 'https://example.test',
            'summary' => "Built things.\n\n- One\n- Two",
        ], $entry->fields['work'][0]);
        $this->assertSame([
            'institution' => 'A University',
            'study_type' => 'Master',
            'area' => 'Computer Engineering',
            'start_date' => '2021',
            'end_date' => '2026-05-01',
        ], $entry->fields['education'][0]);
    }

    public function test_contact_details_in_the_file_never_reach_the_entry(): void
    {
        $document = $this->document();
        $document['basics'] += ['email' => 'ada@example.test', 'phone' => '+39 000', 'location' => ['address' => 'Somewhere 1'], 'birthDate' => '1990-01-01', 'profiles' => [['username' => 'ada']]];

        $serialized = serialize($this->pull(json_encode($document))[0]);

        foreach (['ada@example.test', '+39 000', 'Somewhere 1', '1990-01-01'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_without_a_date_in_the_file_it_is_published_now(): void
    {
        $document = $this->document();
        unset($document['meta']);

        $this->assertSame('2026-01-01T00:00:00+00:00', $this->pull(json_encode($document))[0]->publishedAt?->format('c'));
    }

    public function test_a_pdf_is_copied_only_when_asked_for(): void
    {
        $this->assertSame([], $this->pull()[0]->media);

        [$entry] = $this->pull(settings: [JsonResumeFile::PDF_URL => 'https://files.example.test/cv.pdf']);

        $this->assertSame('pdf', $entry->media[0]->field);
        $this->assertSame('https://files.example.test/cv.pdf', $entry->media[0]->url);
    }

    public function test_the_language_of_the_instance_is_the_language_of_the_entry(): void
    {
        $this->assertSame('it', $this->pull(settings: [JsonResumeFile::LANGUAGE => 'it'])[0]->language);
    }

    public function test_the_fingerprint_follows_the_content_and_not_the_clock(): void
    {
        $document = $this->document();
        unset($document['meta']);
        $a = $this->pull(json_encode($document))[0]->fingerprint;
        $document['basics']['summary'] = 'Changed.';

        $this->assertNotSame($a, $this->pull(json_encode($document))[0]->fingerprint);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badDocuments(): array
    {
        return [
            'a bad start date' => [['work' => [['name' => 'x', 'position' => 'y', 'startDate' => 'December 2023']]]],
            'a bad end date' => [['education' => [['institution' => 'x', 'studyType' => 'y', 'area' => 'z', 'startDate' => '2020', 'endDate' => 'now']]]],
            'work not a list' => [['work' => 'somewhere']],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('badDocuments')]
    public function test_a_bad_document_is_refused_reported_and_still_seen(array $change): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $http = (new FakeHttpClient())->respond(self::URL, new HttpResponse(200, (string) json_encode(array_replace($this->document(), $change))));
        $context = new StaticContext([JsonResumeFile::URL => self::URL], [], $http, logger: $logger);

        [$entry] = iterator_to_array((new JsonResumeFile())->pull($context), false);

        $this->assertSame('cv', $entry->externalId);
        $this->assertNotSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
        $this->assertSame(['Resume refused'], $logger->messages);
    }

    public function test_a_job_without_its_required_values_is_left_to_the_contract(): void
    {
        $document = $this->document();
        $document['work'] = [['name' => 'x']];

        [$entry] = $this->pull(json_encode($document));

        $this->assertNotSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
    }

    public function test_no_name_is_refused(): void
    {
        $document = $this->document();
        unset($document['basics']['name']);

        [$entry] = $this->pull(json_encode($document));

        $this->assertNotSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
    }

    public function test_an_address_with_no_file_is_a_configuration_error(): void
    {
        try {
            $this->pull(response: new HttpResponse(404, ''));
            $this->fail('expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            $this->assertSame(JsonResumeFile::URL, $e->setting);
        }
    }

    public function test_a_page_that_is_not_a_document_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->pull('<html>');
    }

    public function test_a_failing_server_is_unavailable(): void
    {
        $this->expectException(Unavailable::class);
        $this->pull(response: new HttpResponse(418, ''));
    }

    public function test_an_address_that_is_not_https_never_gets_called(): void
    {
        $http = new FakeHttpClient();
        $context = new StaticContext([JsonResumeFile::URL => 'http://files.example.test/resume.json'], [], $http);

        try {
            iterator_to_array((new JsonResumeFile())->pull($context), false);
            $this->fail('expected InvalidConfiguration');
        } catch (InvalidConfiguration) {
            $this->assertSame([], $http->sent);
        }
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/resume.json'), true);
    }

    /**
     * @param array<string, string> $settings
     * @return list<ExternalEntry>
     */
    private function pull(?string $body = null, ?HttpResponse $response = null, array $settings = []): array
    {
        $http = (new FakeHttpClient())->respond(self::URL, $response ?? new HttpResponse(200, $body ?? (string) json_encode($this->document())));

        return iterator_to_array((new JsonResumeFile())->pull(new StaticContext([JsonResumeFile::URL => self::URL] + $settings, [], $http)), false);
    }
}
