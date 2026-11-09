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
            'id' => 'example',
            'name' => 'Example Ltd',
            'position' => 'Developer',
            'start_date' => '2023-12',
            'employment_type' => 'full-time',
            'url' => 'https://example.test',
            'summary' => "Built things.\n\n- One\n- Two",
            'skills' => [
                ['name' => 'data engineering', 'keywords' => ['Pipelines', 'Warehouses']],
                ['name' => 'devops', 'keywords' => ['Containers']],
            ],
        ], $entry->fields['work'][0]);
        $this->assertSame([
            'id' => 'university',
            'institution' => 'A University',
            'study_type' => 'Master',
            'area' => 'Computer Engineering',
            'start_date' => '2021',
            'end_date' => '2026-05-01',
            'skills' => [['name' => 'data', 'keywords' => ['Statistics']]],
        ], $entry->fields['education'][0]);
        $this->assertSame([
            ['language' => 'Italian', 'fluency' => 'Native'],
            ['language' => 'English', 'fluency' => 'B1', 'levels' => [
                ['skill' => 'reading', 'level' => 'B1'],
                ['skill' => 'writing', 'level' => 'B2'],
                ['skill' => 'listening', 'level' => 'B1'],
                ['skill' => 'speaking', 'level' => 'B1'],
            ]],
        ], $entry->fields['languages']);
        $this->assertSame([['name' => 'Games', 'keywords' => ['Design', 'Engines']]], $entry->fields['interests']);
    }

    public function test_a_partial_set_of_levels_keeps_the_fixed_order_and_skips_the_missing(): void
    {
        $document = $this->document();
        $document['languages'][1]['levels'] = ['speaking' => 'C1', 'reading' => 'A2'];

        [$entry] = $this->pull(json_encode($document));

        $this->assertSame([['skill' => 'reading', 'level' => 'A2'], ['skill' => 'speaking', 'level' => 'C1']], $entry->fields['languages'][1]['levels']);
        $this->assertSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
    }

    public function test_levels_with_none_of_the_four_skills_or_absent_give_no_levels_field(): void
    {
        foreach (['absent' => null, 'empty' => [], 'unknown skills only' => ['writting' => 'B1']] as $case => $levels) {
            $document = $this->document();
            unset($document['languages'][1]['levels']);
            if ($levels !== null) {
                $document['languages'][1]['levels'] = $levels;
            }

            [$entry] = $this->pull(json_encode($document));

            $this->assertSame(['language' => 'English', 'fluency' => 'B1'], $entry->fields['languages'][1], $case);
        }
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

    public function test_a_document_with_only_the_standard_keys_still_conforms(): void
    {
        $document = $this->document();
        unset($document['work'][0]['employmentType'], $document['work'][0]['skills'], $document['education'][0]['skills'], $document['languages'][1]['levels'], $document['interests']);

        [$entry] = $this->pull(json_encode($document));

        $this->assertSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
        $this->assertArrayNotHasKey('skills', $entry->fields['work'][0]);
        $this->assertArrayNotHasKey('interests', $entry->fields);
    }

    public function test_the_rules_hold_at_every_depth(): void
    {
        foreach ([
            'an employment type off the list' => fn (array &$d) => $d['work'][0]['employmentType'] = 'volunteer',
            'a level off the list' => fn (array &$d) => $d['languages'][1]['levels']['reading'] = 'B3',
            'a group of skills without a name' => fn (array &$d) => $d['work'][0]['skills'][0] = ['keywords' => ['x']],
            'an interest without a name' => fn (array &$d) => $d['interests'][0] = ['keywords' => ['x']],
            'a language without its name' => fn (array &$d) => $d['languages'][0] = ['fluency' => 'Native'],
        ] as $case => $change) {
            $document = $this->document();
            $change($document);

            [$entry] = $this->pull(json_encode($document));

            $this->assertNotSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry), $case);
        }
    }

    public function test_the_timeline_is_carried_as_declared_without_the_empty(): void
    {
        [$entry] = $this->pull();

        $this->assertSame(['branches', 'rows'], array_keys($entry->fields['timeline']));
        $this->assertSame(['name' => 'example', 'from' => 'master', 'start' => '2023-12'], $entry->fields['timeline']['branches'][0]);
        $this->assertSame(
            ['name' => 'feature/side', 'from' => 'school', 'into' => 'school', 'start' => '2019-03', 'end' => '2019-09'],
            $entry->fields['timeline']['branches'][2],
        );
        $this->assertCount(6, $entry->fields['timeline']['rows']);
        $this->assertSame(['date' => '2023-12', 'branch' => 'example', 'role' => 'example'], $entry->fields['timeline']['rows'][0]);
        $this->assertSame(['date' => '2021-01', 'message' => 'feat(age): Upgrade to the new version'], $entry->fields['timeline']['rows'][2]);
        $this->assertSame('side', $entry->fields['work'][1]['id']);
    }

    public function test_a_cv_without_a_timeline_has_none(): void
    {
        $document = $this->document();
        unset($document['timeline']);

        [$entry] = $this->pull(json_encode($document));

        $this->assertArrayNotHasKey('timeline', $entry->fields);
        $this->assertNull($entry->refusal);
    }

    /** @return array<string, array{callable(array<string, mixed>): void, string}> */
    public static function brokenTimelines(): array
    {
        return [
            'an id used twice' => [fn (array &$d) => $d['education'][0]['id'] = 'example', 'The id "example" is used by more than one'],
            'an id used twice, with no timeline' => [function (array &$d): void {
                unset($d['timeline']);
                $d['education'][0]['id'] = 'example';
            }, 'The id "example" is used by more than one'],
            'a timeline that is not an object' => [fn (array &$d) => $d['timeline'] = ['a', 'b'], '"timeline" is not an object'],
            'a timeline that is text' => [fn (array &$d) => $d['timeline'] = 'branches', '"timeline" is not an object'],
            'branches missing' => [function (array &$d): void {
                unset($d['timeline']['branches']);
            }, '"timeline" has no list "branches"'],
            'rows that are not a list' => [fn (array &$d) => $d['timeline']['rows'] = ['date' => '2020'], '"rows" is not a list of items.'],
            'a branch named twice' => [fn (array &$d) => $d['timeline']['branches'][2]['name'] = 'school', 'The branch "school" is declared more than once'],
            'a branch without a name' => [function (array &$d): void {
                unset($d['timeline']['branches'][0]['name']);
            }, 'has no "name"'],
            'a branch forking from nowhere' => [fn (array &$d) => $d['timeline']['branches'][2]['from'] = 'nowhere', '"from" of the branch "feature/side" names "nowhere"'],
            'a branch merging into nowhere' => [fn (array &$d) => $d['timeline']['branches'][2]['into'] = 'nowhere', '"into" of the branch "feature/side" names "nowhere"'],
            'a start that is not a date' => [fn (array &$d) => $d['timeline']['branches'][2]['start'] = 'March 2019', '"March 2019" is not a date'],
            'an end that is not a date' => [fn (array &$d) => $d['timeline']['branches'][2]['end'] = 'later', '"later" is not a date'],
            'a row that is not on a date' => [fn (array &$d) => $d['timeline']['rows'][3]['date'] = 'summer', '"summer" is not a date'],
            'a start that is not the date of a row' => [fn (array &$d) => $d['timeline']['branches'][0]['start'] = '2023-11', '"start" of the branch "example" is 2023-11, which is not the date of a row'],
            'an end that is not the date of a row' => [fn (array &$d) => $d['timeline']['branches'][2]['end'] = '2019-10', '"end" of the branch "feature/side" is 2019-10, which is not the date of a row'],
            'a start after the end' => [function (array &$d): void {
                $d['timeline']['branches'][2]['start'] = '2019-09';
                $d['timeline']['branches'][2]['end'] = '2019-03';
            }, 'starts (2019-09) after it ends (2019-03)'],
            'a role that is not an id' => [fn (array &$d) => $d['timeline']['rows'][0]['role'] = 'missing', 'names the role "missing"'],
            'a row on a branch that is not declared' => [fn (array &$d) => $d['timeline']['rows'][1]['branch'] = 'missing', 'is on "missing", which is not a branch'],
            'a row with a role and a message' => [fn (array &$d) => $d['timeline']['rows'][0]['message'] = 'both', 'must have a "role" or a "message", and not both'],
            'a row with neither' => [function (array &$d): void {
                unset($d['timeline']['rows'][2]['message']);
            }, 'must have a "role" or a "message", and not both'],
            'a branch with no role at its start' => [function (array &$d): void {
                unset($d['timeline']['rows'][4]['role']);
                $d['timeline']['rows'][4]['message'] = 'Not a role';
            }, 'The branch "feature/side" has no role at its start (2019-03)'],
            'a role at the start of a branch on another branch' => [fn (array &$d) => $d['timeline']['rows'][4]['branch'] = 'school', 'The branch "feature/side" has no role at its start'],
        ];
    }

    /** @param callable(array<string, mixed>): void $change */
    #[DataProvider('brokenTimelines')]
    public function test_a_broken_timeline_is_refused_with_its_reason(callable $change, string $reason): void
    {
        $document = $this->document();
        $change($document);

        [$entry] = $this->pull(json_encode($document));

        $this->assertNotNull($entry->refusal);
        $this->assertStringContainsString($reason, $entry->refusal);
    }

    public function test_a_start_may_be_less_precise_than_the_end_without_being_after_it(): void
    {
        $document = $this->document();
        $document['timeline']['branches'][2]['start'] = '2019';
        $document['timeline']['branches'][2]['end'] = '2019-09';
        $document['timeline']['rows'][4]['date'] = '2019';

        [$entry] = $this->pull(json_encode($document));

        $this->assertNull($entry->refusal);
    }

    public function test_the_cv_of_2026_is_accepted_and_conforms(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/fixtures/cv-2026.json');

        $entries = $this->pull($body);

        $this->assertNull($entries[0]->refusal);
        $this->assertCount(9, $entries[0]->fields['timeline']['branches']);
        $this->assertCount(15, $entries[0]->fields['timeline']['rows']);
        Conformance::checkEntries(JsonResumeFile::definition(), [Resume::definition()], $entries);
        $this->assertSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entries[0]));
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
            'keywords not a list' => [['interests' => [['name' => 'x', 'keywords' => 'one, two']]]],
            'levels not a set of named levels' => [['languages' => [['language' => 'English', 'levels' => ['B1', 'B2']]]]],
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
        $this->assertNotNull($entry->refusal);
        $this->assertSame([], EntryRules::violations(JsonResumeFile::definition(), [Resume::definition()], $entry));
        $this->assertSame(['Resume refused'], $logger->messages);
    }

    public function test_a_refused_document_carries_the_reason(): void
    {
        $document = $this->document();
        $document['work'] = 'nothing';

        [$entry] = $this->pull(json_encode($document));

        $this->assertSame('"work" is not a list of items.', $entry->refusal);
        Conformance::checkEntries(JsonResumeFile::definition(), [Resume::definition()], [$entry]);
        $this->addToAssertionCount(1);
    }

    public function test_a_job_without_its_required_values_is_left_to_the_contract(): void
    {
        $document = $this->document();
        $document['work'] = [['name' => 'x']];
        unset($document['timeline']);

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
