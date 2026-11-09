<?php

declare(strict_types=1);

namespace Gll\Hub\Github\Tests;

use Closure;
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
        $milestone = $entry->fields['milestones'][0];
        unset($milestone['items']);
        $this->assertSame([
            'title' => 'v1.0',
            'state' => 'open',
            'open_items' => 1,
            'closed_items' => 3,
            'url' => 'https://github.com/example/pointer/milestone/1',
        ], $milestone);
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
        $this->assertNotNull($italian->refusal);
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
            'discussions written by hand' => ["---\nclaim: x\ndiscussions: []\n---\n"],
            'boards written by hand' => ["---\nclaim: x\nboards: []\n---\n"],
            'docs written by hand' => ["---\nclaim: x\ndocs: []\n---\n"],
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
        $this->assertNotNull($entries[0]->refusal);
        $this->assertSame([], $this->violations($entries[0]));
        $this->assertSame(['Showcase file refused'], $logger->messages);
    }

    public function test_a_refused_file_carries_the_reason_the_parser_gave(): void
    {
        [$entry] = $this->pull($this->github(showcase: "---\nclaim: x\nstars: 999\n---\n"));

        $this->assertSame('these come from GitHub and cannot be written in the file: stars', $entry->refusal);
        Conformance::checkEntries(ShowcaseRepositories::definition(), [Project::definition()], [$entry]);
        $this->addToAssertionCount(1);
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
        $this->assertNotNull($italian->refusal);
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

    public function test_a_milestone_lists_its_items_and_says_which_were_merged(): void
    {
        [$entry] = $this->pull($this->github());

        $items = $entry->fields['milestones'][0]['items'];
        $this->assertSame([5, 6, 7], array_column($items, 'number'));
        $this->assertSame(['issue', 'pull_request', 'pull_request'], array_column($items, 'kind'));
        $this->assertSame(['closed', 'merged', 'closed'], array_column($items, 'state'));
    }

    public function test_docs_are_read_at_the_version_with_their_images(): void
    {
        $http = $this->github();
        $http->respond(self::REPO . '/git/trees/v1.2?recursive=1', new HttpResponse(200, (string) json_encode(['truncated' => false, 'tree' => [
            ['path' => 'README.md', 'type' => 'blob'],
            ['path' => 'docs', 'type' => 'tree'],
            ['path' => 'docs/a.md', 'type' => 'blob'],
            ['path' => 'docs/adr/no heading.md', 'type' => 'blob'],
            ['path' => 'docs/logo.png', 'type' => 'blob'],
        ]])));
        $http->respond(self::REPO . '/contents/docs/a.md?ref=v1.2', new HttpResponse(200, "# The title\n\n![x](x.png) [other](adr/no%20heading.md)"));
        $http->respond(self::REPO . '/contents/docs/adr/no%20heading.md?ref=v1.2', new HttpResponse(200, 'Plain.'));

        [$entry] = $this->pull($http);

        $this->assertSame(['docs/a.md', 'docs/adr/no heading.md'], array_column($entry->fields['docs'], 'path'));
        $this->assertSame(['The title', 'no heading'], array_column($entry->fields['docs'], 'title'));
        $this->assertStringContainsString('![x](' . self::RAW . '/v1.2/docs/x.png)', $entry->fields['docs'][0]['body']);
        $this->assertStringContainsString('(adr/no%20heading.md)', $entry->fields['docs'][0]['body']);
        $this->assertContains(self::RAW . '/v1.2/docs/x.png', array_map(static fn ($m) => $m->url, $entry->media));
        $this->assertSame([], $this->violations($entry));
    }

    public function test_a_tree_github_cut_short_or_too_many_docs_are_an_error_not_a_shorter_list(): void
    {
        $truncated = $this->github();
        $truncated->respond(self::REPO . '/git/trees/v1.2?recursive=1', new HttpResponse(200, '{"truncated":true,"tree":[]}'));
        try {
            $this->pull($truncated);
            $this->fail('expected Unavailable');
        } catch (Unavailable) {
            $this->addToAssertionCount(1);
        }

        $paths = array_map(static fn (int $i): array => ['path' => "docs/{$i}.md", 'type' => 'blob'], range(1, 101));
        $tooMany = $this->github();
        $tooMany->respond(self::REPO . '/git/trees/v1.2?recursive=1', new HttpResponse(200, (string) json_encode(['truncated' => false, 'tree' => $paths])));
        $this->expectException(Unavailable::class);
        $this->pull($tooMany);
    }

    public function test_discussions_come_with_their_comments_read_to_the_end(): void
    {
        $graph = static function (?string $body): HttpResponse {
            $query = (string) json_decode((string) $body, true)['query'];
            if (str_contains($query, 'query MoreDiscussions')) {
                return self::data(['repository' => ['discussions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [self::discussion(2)]]]]);
            }
            if (str_contains($query, 'query MoreComments')) {
                return self::data(['node' => ['comments' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [self::comment('second')]]]]);
            }

            $first = self::discussion(1);
            $first['comments']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'c'];

            return self::data(['repository' => [
                'hasDiscussionsEnabled' => true,
                'discussions' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'd'], 'nodes' => [$first]],
                'projectsV2' => ['nodes' => []],
            ]]);
        };

        [$entry] = $this->pull($this->github(graph: $graph));

        $this->assertSame([1, 2], array_column($entry->fields['discussions'], 'number'));
        $discussion = $entry->fields['discussions'][0];
        $this->assertSame('Question 1', $discussion['title']);
        $this->assertSame('someone', $discussion['author']);
        $this->assertSame('Q&A', $discussion['category']);
        $this->assertTrue($discussion['answered']);
        $this->assertSame(['first', 'second'], array_column($discussion['comments'], 'body'));
        $this->assertSame(2, $discussion['comments'][0]['reply_count']);
        $this->assertSame([], $this->violations($entry));
    }

    public function test_a_repository_with_discussions_off_has_none(): void
    {
        $graph = self::data(['repository' => [
            'hasDiscussionsEnabled' => false,
            'discussions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [self::discussion(1)]],
            'projectsV2' => ['nodes' => []],
        ]]);

        [$entry] = $this->pull($this->github(graph: $graph));

        $this->assertSame([], $entry->fields['discussions']);
    }

    public function test_only_public_open_boards_and_what_is_open_on_them_reach_the_site(): void
    {
        $item = static fn (string $type, array $content, ?string $status = 'Todo'): array => ['type' => $type, 'fieldValueByName' => $status === null ? null : ['name' => $status], 'content' => $content];
        $pull = static fn (int $n, string $state, bool $private = false, string $by = 'User'): array => [
            'number' => $n, 'title' => "PR {$n}", 'url' => "https://github.com/example/pointer/pull/{$n}", 'state' => $state,
            'author' => ['login' => 'someone', '__typename' => $by], 'repository' => ['isPrivate' => $private], 'labels' => ['nodes' => [['name' => 'fix']]],
        ];
        $board = static fn (string $title, bool $public, bool $closed, array $items): array => [
            'id' => $title, 'title' => $title, 'url' => "https://github.com/users/example/projects/{$title}", 'public' => $public, 'closed' => $closed,
            'items' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => $items],
        ];
        $graph = self::data(['repository' => [
            'hasDiscussionsEnabled' => false,
            'discussions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
            'projectsV2' => ['nodes' => [
                $board('private', false, false, [$item('PULL_REQUEST', $pull(1, 'OPEN'))]),
                $board('closed', true, true, [$item('PULL_REQUEST', $pull(2, 'OPEN'))]),
                $board('public', true, false, [
                    $item('PULL_REQUEST', $pull(3, 'OPEN', by: 'Bot'), 'In progress'),
                    $item('PULL_REQUEST', $pull(4, 'MERGED')),
                    $item('PULL_REQUEST', $pull(5, 'OPEN', private: true)),
                    $item('ISSUE', ['number' => 6, 'title' => 'Issue', 'url' => 'https://github.com/example/pointer/issues/6', 'state' => 'OPEN', 'author' => null, 'repository' => ['isPrivate' => false], 'labels' => ['nodes' => []]], null),
                    $item('DRAFT_ISSUE', ['title' => 'A draft']),
                    $item('REDACTED', []),
                ]),
            ]],
        ]]);

        [$entry] = $this->pull($this->github(graph: $graph));

        $this->assertSame(['public'], array_column($entry->fields['boards'], 'title'));
        $items = $entry->fields['boards'][0]['items'];
        $this->assertSame(['PR 3', 'Issue', 'A draft'], array_column($items, 'title'));
        $this->assertSame(['pull_request', 'issue', 'draft'], array_column($items, 'kind'));
        $this->assertSame('In progress', $items[0]['status']);
        $this->assertTrue($items[0]['by_bot']);
        $this->assertSame(['fix'], $items[0]['labels']);
        $this->assertArrayNotHasKey('status', $items[1]);
        $this->assertArrayNotHasKey('author', $items[1]);
        $this->assertArrayNotHasKey('url', $items[2]);
        $this->assertSame([], $this->violations($entry));
    }

    public function test_a_board_is_read_to_its_end(): void
    {
        $open = static fn (string $title): array => ['type' => 'DRAFT_ISSUE', 'content' => ['title' => $title]];
        $graph = static function (?string $body) use ($open): HttpResponse {
            if (str_contains((string) json_decode((string) $body, true)['query'], 'query MoreItems')) {
                return self::data(['node' => ['items' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [$open('later')]]]]);
            }

            return self::data(['repository' => ['hasDiscussionsEnabled' => false, 'projectsV2' => ['nodes' => [[
                'id' => 'b', 'title' => 'Board', 'public' => true, 'closed' => false,
                'items' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'x'], 'nodes' => [$open('first')]],
            ]]]]]);
        };

        [$entry] = $this->pull($this->github(graph: $graph));

        $this->assertSame(['first', 'later'], array_column($entry->fields['boards'][0]['items'], 'title'));
    }

    public function test_a_token_that_cannot_read_projects_says_so(): void
    {
        $graph = new HttpResponse(200, '{"data":{"repository":{"projectsV2":null}},"errors":[{"type":"INSUFFICIENT_SCOPES","message":"no"}]}');

        $this->expectException(InvalidCredentials::class);
        $this->pull($this->github(graph: $graph));
    }

    public function test_a_partial_graphql_answer_is_an_error_not_an_empty_list(): void
    {
        $graph = new HttpResponse(200, '{"data":{"repository":{"hasDiscussionsEnabled":true,"discussions":{"nodes":[]}}},"errors":[{"type":"SOMETHING","message":"no"}]}');

        $this->expectException(Unavailable::class);
        $this->pull($this->github(graph: $graph));
    }

    public function test_a_repository_the_graph_does_not_describe_is_an_error(): void
    {
        $this->expectException(Unavailable::class);
        $this->pull($this->github(graph: self::data(['repository' => null])));
    }

    private static function graph(): HttpResponse
    {
        return self::data(['repository' => [
            'hasDiscussionsEnabled' => false,
            'discussions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
            'projectsV2' => ['nodes' => []],
        ]]);
    }

    /** @param array<string, mixed> $data */
    private static function data(array $data): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode(['data' => $data]));
    }

    /** @return array<string, mixed> */
    private static function discussion(int $number): array
    {
        return [
            'id' => "d{$number}", 'number' => $number, 'title' => "Question {$number}", 'url' => "https://github.com/example/pointer/discussions/{$number}",
            'body' => 'Why?', 'createdAt' => '2026-03-01T00:00:00Z', 'updatedAt' => '2026-03-02T00:00:00Z', 'isAnswered' => true,
            'category' => ['name' => 'Q&A'], 'author' => ['login' => 'someone'],
            'comments' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [self::comment('first')]],
        ];
    }

    /** @return array<string, mixed> */
    private static function comment(string $body): array
    {
        return ['url' => 'https://github.com/example/pointer/discussions/1#c', 'body' => $body, 'createdAt' => '2026-03-03T00:00:00Z', 'author' => null, 'replies' => ['totalCount' => 2]];
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
        HttpResponse|Closure|null $graph = null,
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
            ->respond(self::REPO . "/contents/.github/showcase.it.md?ref={$ref}", $file($translation))
            ->respond(self::REPO . '/issues?state=all&milestone=*&per_page=100&page=1', $json([
                ['number' => 5, 'title' => 'Fix it', 'html_url' => 'https://github.com/example/pointer/issues/5', 'state' => 'closed', 'milestone' => ['title' => 'v1.0']],
                ['number' => 6, 'title' => 'Merged', 'html_url' => 'https://github.com/example/pointer/pull/6', 'state' => 'closed', 'milestone' => ['title' => 'v1.0'], 'pull_request' => ['merged_at' => '2026-03-01T00:00:00Z']],
                ['number' => 7, 'title' => 'Dropped', 'html_url' => 'https://github.com/example/pointer/pull/7', 'state' => 'closed', 'milestone' => ['title' => 'v1.0'], 'pull_request' => ['merged_at' => null]],
            ]))
            ->respond(self::REPO . "/git/trees/{$ref}?recursive=1", new HttpResponse(404, ''))
            ->respond(self::API . '/graphql', $graph ?? self::graph());
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
