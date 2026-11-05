<?php

declare(strict_types=1);

namespace Gll\Hub\Github\Tests;

use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpResponse;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Contracts\Testing\FakeHttpClient;
use Gll\Hub\Contracts\Testing\StaticContext;
use Gll\Hub\Github\GithubPackage;
use Gll\Hub\Github\ShowcaseRepositories;
use Gll\Hub\Portfolio\Types\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class ShowcaseRepositoriesTest extends TestCase
{
    private const API = 'https://api.github.com';
    private const LIST = self::API . '/users/example/repos?type=owner&per_page=100&page=1';
    private const REPO = self::API . '/repos/example/pointer';
    private const RAW = 'https://raw.githubusercontent.com/example/pointer';
    private const RAW_ACCEPT = 'application/vnd.github.raw+json';

    public function test_the_package_conforms(): void
    {
        Conformance::check(new GithubPackage());
        $this->addToAssertionCount(1);
    }

    public function test_entries_conform_to_the_project_type(): void
    {
        $withFile = $this->pull($this->github(showcase: $this->fixture('showcase.md'), translation: $this->fixture('showcase.it.md')));
        $withoutFile = $this->pull($this->github());

        Conformance::checkEntries(ShowcaseRepositories::definition(), [Project::definition()], [...$withFile, ...$withoutFile]);
        $this->assertCount(3, [...$withFile, ...$withoutFile]);
    }

    public function test_the_file_gives_the_page_and_github_the_facts(): void
    {
        [$entry] = $this->pull($this->github(showcase: $this->fixture('showcase.md')));

        $this->assertSame('101', $entry->externalId);
        $this->assertSame('project', $entry->type);
        $this->assertSame('pointer', $entry->title);
        $this->assertSame('A pointer that changes shape.', $entry->summary);
        $this->assertSame('en', $entry->language);
        $this->assertSame('github:101', $entry->translationKey);
        $this->assertStringContainsString('The arrow has not changed', (string) $entry->body);
        $this->assertSame('A pointer that knows what it points at.', $entry->fields['claim']);
        $this->assertSame(2022, $entry->fields['since']);
        $this->assertSame('video', $entry->fields['demo']);
        $this->assertSame('It changes shape', $entry->fields['features'][0]['title']);

        $this->assertSame('https://github.com/example/pointer', $entry->fields['repository']);
        $this->assertSame(['TypeScript', 'CSS'], $entry->fields['languages']);
        $this->assertSame(7, $entry->fields['stars']);
        $this->assertSame('v1.2', $entry->fields['version']);
        $this->assertSame('2026-02-03T04:05:06+00:00', $entry->publishedAt?->format('c'));
    }

    public function test_core_and_media_keys_do_not_stay_among_the_fields(): void
    {
        [$entry] = $this->pull($this->github(showcase: $this->fixture('showcase.md')));

        foreach (['title', 'summary', 'cover', 'demo_media', 'related'] as $key) {
            $this->assertArrayNotHasKey($key, $entry->fields);
        }
    }

    public function test_releases_leave_out_drafts_and_milestones_keep_their_progress(): void
    {
        [$entry] = $this->pull($this->github(showcase: $this->fixture('showcase.md')));

        $this->assertSame(['v1.2', 'v1.2-rc'], array_column($entry->fields['releases'], 'tag'));
        $this->assertTrue($entry->fields['releases'][1]['prerelease']);
        $this->assertArrayNotHasKey('name', $entry->fields['releases'][1]);
        $this->assertSame([
            'title' => 'v1.0',
            'state' => 'open',
            'open_items' => 1,
            'closed_items' => 3,
            'url' => 'https://github.com/example/pointer/milestone/1',
        ], $entry->fields['milestones'][0]);
    }

    public function test_it_reads_the_latest_release_and_the_default_branch_without_one(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'));
        [$released] = $this->pull($http);
        $this->assertSame(self::RAW . '/v1.2/docs/cover.png', $released->media[0]->url);
        $this->assertContains(self::REPO . '/contents/.github/showcase.md?ref=v1.2', array_column($http->sent, 'url'));

        $http = $this->github(showcase: $this->fixture('showcase.md'), latest: null);
        [$unreleased] = $this->pull($http);
        $this->assertSame(self::RAW . '/trunk/docs/cover.png', $unreleased->media[0]->url);
        $this->assertArrayNotHasKey('version', $unreleased->fields);
        $this->assertContains(self::REPO . '/contents/.github/showcase.md?ref=trunk', array_column($http->sent, 'url'));
    }

    public function test_relative_images_are_copied_and_the_text_points_at_them(): void
    {
        [$entry] = $this->pull($this->github(showcase: $this->fixture('showcase.md'), readme: $this->fixture('README.md')));

        $this->assertStringContainsString('![a sketch](' . self::RAW . '/v1.2/docs/sketch.png)', (string) $entry->body);
        $this->assertStringContainsString('src="' . self::RAW . '/v1.2/docs/other.png"', $entry->fields['readme']);
        $this->assertStringContainsString('(https://img.shields.io/badge/build-passing-green.svg)', $entry->fields['readme']);

        $byField = [];
        foreach ($entry->media as $media) {
            $byField[$media->field][] = $media->url;
        }
        $this->assertSame([self::RAW . '/v1.2/docs/cover.png'], $byField['cover']);
        $this->assertSame([self::RAW . '/v1.2/docs/demo.mp4'], $byField['demo_media']);
        $this->assertContains(self::RAW . '/v1.2/docs/sketch.png', $byField['images']);
        $this->assertContains(self::RAW . '/v1.2/docs/other.png', $byField['images']);
    }

    public function test_related_names_become_references_by_type_and_name(): void
    {
        [$entry] = $this->pull($this->github(showcase: $this->fixture('showcase.md')));

        $this->assertSame(['Other Project', 'example.org'], array_map(static fn ($r) => $r->slug, $entry->references));
        $this->assertSame('project', $entry->references[0]->type);
        $this->assertSame('related', $entry->references[0]->field);
    }

    public function test_a_translation_keeps_what_it_leaves_out(): void
    {
        [$english, $italian] = $this->pull($this->github(showcase: $this->fixture('showcase.md'), translation: $this->fixture('showcase.it.md')));

        $this->assertSame('101:it', $italian->externalId);
        $this->assertSame('it', $italian->language);
        $this->assertSame($english->translationKey, $italian->translationKey);
        $this->assertSame('Un puntatore che sa cosa indica.', $italian->fields['claim']);
        $this->assertSame('Cambia forma', $italian->fields['features'][0]['title']);
        $this->assertSame('Perché esiste.', $italian->body);
        $this->assertSame($english->fields['decisions'], $italian->fields['decisions']);
        $this->assertSame('pointer', $italian->title);
    }

    public function test_a_translation_changes_only_what_can_be_translated(): void
    {
        $changed = "---\nclaim: Altro.\ncolor: \"#000000\"\nsince: 1999\n---\n";
        $logger = $this->logger();

        [$english, $italian] = $this->pull($this->github(showcase: $this->fixture('showcase.md'), translation: $changed), logger: $logger);

        $this->assertSame([], $this->violations($english));
        $this->assertNotSame([], $this->violations($italian));
        $this->assertSame(['Showcase file refused'], $logger->messages);
    }

    public function test_a_widget_is_named_and_gllhub_does_not_read_the_name(): void
    {
        [$entry] = $this->pull($this->github(showcase: "---\nclaim: x\ndemo: widget\ndemo_widget: any-name\n---\n"));

        $this->assertSame([], $this->violations($entry));
        $this->assertSame('widget', $entry->fields['demo']);
        $this->assertSame('any-name', $entry->fields['demo_widget']);
    }

    public function test_placeholders_in_the_body_are_kept_in_every_language(): void
    {
        $main = "---\nclaim: x\n---\nText.\n\n<!-- gll:demo -->\n\n<!-- gll:widget nome -->\n";
        $translation = "---\nclaim: y\n---\nTesto.\n\n<!-- gll:demo -->\n";

        [$english, $italian] = $this->pull($this->github(showcase: $main, translation: $translation));

        $this->assertSame("Text.\n\n<!-- gll:demo -->\n\n<!-- gll:widget nome -->", $english->body);
        $this->assertSame("Testo.\n\n<!-- gll:demo -->", $italian->body);
    }

    public function test_a_translation_without_the_main_file_is_not_read(): void
    {
        $this->assertSame(['101'], array_column($this->pull($this->github(translation: $this->fixture('showcase.it.md'))), 'externalId'));
    }

    public function test_without_the_file_the_page_comes_from_the_repository_and_its_readme(): void
    {
        [$entry] = $this->pull($this->github(readme: $this->fixture('README.md'), description: 'What it is.'));

        $this->assertSame('pointer', $entry->title);
        $this->assertSame('What it is.', $entry->summary);
        $this->assertSame('What it is.', $entry->fields['claim']);
        $this->assertSame('A small library that moves a pointer around.', $entry->body);
        $this->assertSame('cover', $entry->media[0]->field);
        $this->assertSame(self::RAW . '/v1.2/docs/screen.png', $entry->media[0]->url);
    }

    public function test_without_a_description_the_claim_is_the_first_paragraph_shortened(): void
    {
        $paragraph = str_repeat('long text ', 30);
        [$entry] = $this->pull($this->github(readme: "# t\n\n{$paragraph}", description: null));

        $this->assertNull($entry->summary);
        $this->assertSame(160, mb_strlen($entry->fields['claim']));
        $this->assertStringEndsWith('…', $entry->fields['claim']);
    }

    public function test_a_repository_with_nothing_to_say_is_refused_not_given_an_invented_claim(): void
    {
        [$entry] = $this->pull($this->github(description: null));

        $this->assertArrayNotHasKey('claim', $entry->fields);
        $this->assertNotSame([], $this->violations($entry));
    }

    /** @return array<string, array{string}> */
    public static function invalidFiles(): array
    {
        return [
            'no front matter' => ['Just text.'],
            'broken yaml' => ["---\nclaim: [\n---\n"],
            'a list on top' => ["---\n- claim\n---\n"],
            'title not text' => ["---\nclaim: x\ntitle: [a]\n---\n"],
            'unquoted colour' => ["---\nclaim: x\ncolor: #123456\n---\n"],
            'colour not hex' => ["---\nclaim: x\ncolor: \"red; x: y\"\n---\n"],
            'related not a list' => ["---\nclaim: x\nrelated: other\n---\n"],
            'a path that climbs out' => ["---\nclaim: x\ncover: ../../secret.png\n---\n"],
            'a cover over http' => ["---\nclaim: x\ncover: http://example.test/a.png\n---\n"],
            'a fact written by hand' => ["---\nclaim: x\nstars: 999\n---\n"],
            'an unknown milestone' => ["---\nclaim: x\nmilestone: v9\n---\n"],
            'a widget without a name' => ["---\nclaim: x\ndemo: widget\n---\n"],
            'a widget name not text' => ["---\nclaim: x\ndemo: widget\ndemo_widget: [a]\n---\n"],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_an_invalid_file_is_refused_reported_and_still_seen(string $file): void
    {
        $logger = $this->logger();
        $entries = $this->pull($this->github(showcase: $file), logger: $logger);

        $this->assertSame(['101'], array_column($entries, 'externalId'));
        $this->assertNotSame([], $this->violations($entries[0]));
        $this->assertSame(['Showcase file refused'], $logger->messages);
    }

    /** @return array<string, array{string}> */
    public static function filesTheContractRefuses(): array
    {
        return [
            'no claim' => ["---\ntitle: x\n---\n"],
            'an unknown key' => ["---\nclaim: x\nnope: 1\n---\n"],
            'a curated field' => ["---\nclaim: x\nfeatured: true\n---\n"],
            'a demo off the list' => ["---\nclaim: x\ndemo: hologram\n---\n"],
            'a playground, now a widget' => ["---\nclaim: x\ndemo: playground\n---\n"],
            'a claim too long' => ["---\nclaim: " . 'x' . str_repeat('y', 160) . "\n---\n"],
        ];
    }

    #[DataProvider('filesTheContractRefuses')]
    public function test_what_the_file_gets_wrong_for_the_type_is_left_to_the_contract(string $file): void
    {
        [$entry] = $this->pull($this->github(showcase: $file));

        $this->assertNotSame([], $this->violations($entry));
    }

    public function test_a_broken_translation_does_not_touch_the_main_entry(): void
    {
        [$english, $italian] = $this->pull($this->github(showcase: $this->fixture('showcase.md'), translation: 'not a showcase'));

        $this->assertSame([], $this->violations($english));
        $this->assertSame('101:it', $italian->externalId);
        $this->assertNotSame([], $this->violations($italian));
    }

    public function test_only_public_repositories_with_the_topic_of_this_account_are_kept(): void
    {
        $http = $this->github(listed: [
            $this->repository(),
            $this->repository(['id' => 102, 'name' => 'no-topic', 'topics' => ['other']]),
            $this->repository(['id' => 103, 'name' => 'hidden', 'private' => true]),
            $this->repository(['id' => 104, 'name' => 'unknown-visibility', 'private' => null]),
            $this->repository(['id' => 105, 'name' => 'theirs', 'owner' => ['login' => 'someone']]),
        ]);

        $this->assertSame(['101'], array_column($this->pull($http), 'externalId'));
    }

    public function test_a_fork_with_the_topic_is_kept(): void
    {
        $http = $this->github(listed: [$this->repository(['fork' => true])]);

        $this->assertSame(['101'], array_column($this->pull($http), 'externalId'));
    }

    public function test_every_page_is_read(): void
    {
        $http = $this->github(listed: []);
        $http->respond(self::LIST, new HttpResponse(200, '[]', ['link' => '<x>; rel="next", <y>; rel="last"']));
        $http->respond(self::API . '/users/example/repos?type=owner&per_page=100&page=2', new HttpResponse(200, (string) json_encode([$this->repository()])));

        $this->assertSame(['101'], array_column($this->pull($http), 'externalId'));
    }

    public function test_too_many_pages_are_an_error_not_a_shorter_list(): void
    {
        $http = new FakeHttpClient();
        for ($page = 1; $page <= 20; $page++) {
            $http->respond(self::API . "/users/example/repos?type=owner&per_page=100&page={$page}", new HttpResponse(200, '[]', ['link' => '<x>; rel="next"']));
        }

        $this->expectException(Unavailable::class);
        $this->pull($http);
    }

    public function test_a_listing_that_is_not_json_is_an_error_not_an_empty_list(): void
    {
        $http = (new FakeHttpClient())->respond(self::LIST, new HttpResponse(200, '<html>maintenance</html>'));

        $this->expectException(Unavailable::class);
        $this->pull($http);
    }

    public function test_an_unknown_account_is_a_configuration_error(): void
    {
        $http = (new FakeHttpClient())->respond(self::LIST, new HttpResponse(404, ''));

        try {
            $this->pull($http);
            $this->fail('expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            $this->assertSame(ShowcaseRepositories::ACCOUNT, $e->setting);
        }
    }

    public function test_a_bad_account_name_never_reaches_github(): void
    {
        $http = new FakeHttpClient();

        try {
            $this->pull($http, account: '../users');
            $this->fail('expected InvalidConfiguration');
        } catch (InvalidConfiguration) {
            $this->assertSame([], $http->sent);
        }
    }

    /** @return array<string, array{array<string, string>}> */
    public static function absentTokens(): array
    {
        return ['missing' => [[]], 'empty' => [[ShowcaseRepositories::TOKEN => '']]];
    }

    /** @param array<string, string> $credentials */
    #[DataProvider('absentTokens')]
    public function test_it_does_not_run_without_a_token(array $credentials): void
    {
        $http = new FakeHttpClient();
        $context = new StaticContext([ShowcaseRepositories::ACCOUNT => 'example'], $credentials, $http);

        try {
            iterator_to_array((new ShowcaseRepositories())->pull($context), false);
            $this->fail('expected InvalidCredentials');
        } catch (InvalidCredentials) {
            $this->assertSame([], $http->sent);
        }
    }

    public function test_the_token_goes_only_to_the_api(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'));
        [$entry] = $this->pull($http);

        foreach ($http->sent as $request) {
            $this->assertStringStartsWith(self::API . '/', $request['url']);
            $this->assertSame('Bearer secret-token', $request['headers']['authorization']);
        }
        foreach ($entry->media as $media) {
            $this->assertSame([], $media->headers);
        }
    }

    public function test_files_are_asked_for_raw(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'));
        $this->pull($http);

        foreach ($http->sent as $request) {
            if (str_contains($request['url'], '/contents/') || str_contains($request['url'], '/readme')) {
                $this->assertSame(self::RAW_ACCEPT, $request['headers']['accept']);
            }
        }
    }

    public function test_the_fingerprint_follows_the_content(): void
    {
        $fingerprint = fn (array $repository = [], ?string $showcase = null): ?string => $this->pull(
            $this->github(showcase: $showcase ?? $this->fixture('showcase.md'), listed: [$this->repository($repository)]),
        )[0]->fingerprint;

        $base = $fingerprint();

        $this->assertSame($base, $fingerprint());
        $this->assertNotSame($base, $fingerprint(['stargazers_count' => 8]));
        $this->assertNotSame($base, $fingerprint(showcase: str_replace('Why it exists', 'Why it was made', $this->fixture('showcase.md'))));
        $this->assertSame($base, $fingerprint(['pushed_at' => '2026-12-12T00:00:00Z']));
    }

    public function test_fetch_finds_one_entry_by_its_id(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'), translation: $this->fixture('showcase.it.md'));
        $http->respond(self::API . '/repositories/101', new HttpResponse(200, (string) json_encode($this->repository())));

        $this->assertSame('101', $this->fetch($http, '101')?->externalId);
        $this->assertSame('101:it', $this->fetch($http, '101:it')?->externalId);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function repositoriesThatLeft(): array
    {
        return [
            'lost the topic' => [['topics' => []]],
            'went private' => [['private' => true]],
            'changed owner' => [['owner' => ['login' => 'someone']]],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('repositoriesThatLeft')]
    public function test_fetch_says_gone_for_a_repository_that_left(array $change): void
    {
        $http = (new FakeHttpClient())->respond(self::API . '/repositories/101', new HttpResponse(200, (string) json_encode($this->repository($change))));

        $this->assertNull($this->fetch($http, '101'));
    }

    public function test_fetch_says_gone_for_a_repository_that_no_longer_exists(): void
    {
        $http = (new FakeHttpClient())->respond(self::API . '/repositories/101', new HttpResponse(404, ''));

        $this->assertNull($this->fetch($http, '101'));
    }

    public function test_fetch_says_gone_for_a_translation_that_no_longer_exists(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'));
        $http->respond(self::API . '/repositories/101', new HttpResponse(200, (string) json_encode($this->repository())));

        $this->assertNull($this->fetch($http, '101:it'));
    }

    public function test_fetch_says_gone_for_an_id_it_never_made_without_calling_github(): void
    {
        $http = new FakeHttpClient();

        $this->assertNull($this->fetch($http, '../../user'));
        $this->assertSame([], $http->sent);
    }

    public function test_a_failure_while_reading_a_repository_fails_the_run(): void
    {
        $http = $this->github(showcase: $this->fixture('showcase.md'));
        $http->respond(self::REPO . '/languages', new Unavailable('down'));

        $this->expectException(Unavailable::class);
        $this->pull($http);
    }

    /** @return list<string> */
    private function violations(ExternalEntry $entry): array
    {
        return EntryRules::violations(ShowcaseRepositories::definition(), [Project::definition()], $entry);
    }

    /**
     * A GitHub with one repository, or the ones given, each answering with the same files.
     *
     * @param list<array<string, mixed>>|null $listed
     */
    private function github(
        ?string $showcase = null,
        ?string $translation = null,
        ?string $readme = null,
        ?string $latest = 'v1.2',
        ?array $listed = null,
        ?string $description = 'A pointer.',
    ): FakeHttpClient {
        $json = static fn (mixed $value): HttpResponse => new HttpResponse(200, (string) json_encode($value));
        $file = static fn (?string $text): HttpResponse => $text === null ? new HttpResponse(404, '') : new HttpResponse(200, $text);
        $ref = $latest ?? 'trunk';

        return (new FakeHttpClient())
            ->respond(self::LIST, $json($listed ?? [$this->repository(['description' => $description])]))
            ->respond(self::REPO . '/releases/latest', $latest === null ? new HttpResponse(404, '') : $json(['tag_name' => $latest]))
            ->respond(self::REPO . '/languages', new HttpResponse(200, '{"TypeScript":900,"CSS":100}'))
            ->respond(self::REPO . '/releases?per_page=100&page=1', $json([
                ['tag_name' => 'v1.3-draft', 'draft' => true, 'prerelease' => false],
                ['tag_name' => 'v1.2', 'name' => 'One two', 'html_url' => 'https://github.com/example/pointer/releases/tag/v1.2', 'published_at' => '2026-03-01T00:00:00Z', 'body' => 'Notes.', 'draft' => false, 'prerelease' => false],
                ['tag_name' => 'v1.2-rc', 'name' => '', 'html_url' => 'https://github.com/example/pointer/releases/tag/v1.2-rc', 'published_at' => null, 'body' => null, 'draft' => false, 'prerelease' => true],
            ]))
            ->respond(self::REPO . '/milestones?state=all&per_page=100&page=1', $json([
                ['title' => 'v1.0', 'description' => null, 'state' => 'open', 'open_issues' => 1, 'closed_issues' => 3, 'due_on' => null, 'html_url' => 'https://github.com/example/pointer/milestone/1'],
            ]))
            ->respond(self::REPO . "/readme?ref={$ref}", $file($readme))
            ->respond(self::REPO . "/contents/.github/showcase.md?ref={$ref}", $file($showcase))
            ->respond(self::REPO . "/contents/.github/showcase.it.md?ref={$ref}", $file($translation));
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function repository(array $change = []): array
    {
        return array_replace([
            'id' => 101,
            'name' => 'pointer',
            'owner' => ['login' => 'example'],
            'html_url' => 'https://github.com/example/pointer',
            'default_branch' => 'trunk',
            'description' => 'A pointer.',
            'stargazers_count' => 7,
            'topics' => ['showcase', 'other'],
            'private' => false,
            'fork' => false,
            'created_at' => '2026-02-03T04:05:06Z',
            'pushed_at' => '2026-03-01T00:00:00Z',
        ], $change);
    }

    /** @return list<ExternalEntry> */
    private function pull(FakeHttpClient $http, string $account = 'example', ?AbstractLogger $logger = null): array
    {
        return iterator_to_array((new ShowcaseRepositories())->pull($this->context($http, $account, $logger)), false);
    }

    private function fetch(FakeHttpClient $http, string $externalId): ?ExternalEntry
    {
        return (new ShowcaseRepositories())->fetch($this->context($http), $externalId);
    }

    private function context(FakeHttpClient $http, string $account = 'example', ?AbstractLogger $logger = null): StaticContext
    {
        return new StaticContext(
            [ShowcaseRepositories::ACCOUNT => $account],
            [ShowcaseRepositories::TOKEN => 'secret-token'],
            $http,
            logger: $logger,
        );
    }

    private function logger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/fixtures/{$name}");
    }
}
