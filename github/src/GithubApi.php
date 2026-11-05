<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Generator;
use Gll\Hub\Contracts\Exceptions\GllHubException;
use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpClient;
use Gll\Hub\Contracts\Http\HttpResponse;
use Gll\Hub\Contracts\Package\Context;

/** What the source asks GitHub, and the only place that knows its URLs. */
final readonly class GithubApi
{
    private const BASE = 'https://api.github.com';
    private const PER_PAGE = 100;
    private const MAX_PAGES = 20;
    private const MAX_DOCS = 100;
    private const JSON = 'application/vnd.github+json';
    private const RAW = 'application/vnd.github.raw+json';

    public function __construct(private HttpClient $http, private string $token)
    {
    }

    public static function for(Context $context, string $credential): self
    {
        $token = (string) $context->credential($credential);
        if ($token === '') {
            throw new InvalidCredentials('A GitHub token is required.');
        }

        return new self($context->http(), $token);
    }

    /** @return iterable<Repository> every public repository of the account, forks included */
    public function repositoriesOf(string $account, string $setting): iterable
    {
        $unknown = new InvalidConfiguration($setting, "GitHub has no account named {$account}.");

        foreach ($this->pages('/users/' . rawurlencode($account) . '/repos?type=owner', $unknown) as $repository) {
            yield Repository::from((array) $repository);
        }
    }

    public function repository(int $id): ?Repository
    {
        $response = $this->get("/repositories/{$id}", self::JSON);

        return $response->status === 404 ? null : Repository::from($this->decoded($response));
    }

    /** @return list<string> from the most used down, as GitHub orders them */
    public function languages(Repository $repository): array
    {
        $response = $this->get("/repos/{$repository->fullName()}/languages", self::JSON);

        return array_map(strval(...), array_keys($this->decoded($response)));
    }

    public function latestReleaseTag(Repository $repository): ?string
    {
        $response = $this->get("/repos/{$repository->fullName()}/releases/latest", self::JSON);
        if ($response->status === 404) {
            return null;
        }
        $tag = $this->decoded($response)['tag_name'] ?? null;

        return is_string($tag) ? $tag : throw new Unavailable("GitHub described the latest release of {$repository->fullName()} without a tag.");
    }

    /** @return list<array<mixed>> */
    public function releases(Repository $repository): array
    {
        return $this->all("/repos/{$repository->fullName()}/releases");
    }

    /** @return list<array<mixed>> */
    public function milestones(Repository $repository): array
    {
        return $this->all("/repos/{$repository->fullName()}/milestones?state=all");
    }

    /** @return list<array<mixed>> the issues and pull requests that have a milestone */
    public function milestoneItems(Repository $repository): array
    {
        return $this->all("/repos/{$repository->fullName()}/issues?state=all&milestone=*");
    }

    public function file(Repository $repository, string $path, string $ref): ?string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $this->text("/repos/{$repository->fullName()}/contents/{$encoded}?ref=" . rawurlencode($ref));
    }

    /** @return array<string, string> the Markdown files of docs/, by path */
    public function docs(Repository $repository, string $ref): array
    {
        $response = $this->get("/repos/{$repository->fullName()}/git/trees/" . rawurlencode($ref) . '?recursive=1', self::JSON);
        // 409 is GitHub's answer for a repository with no commits yet.
        if ($response->status === 404 || $response->status === 409) {
            return [];
        }

        $tree = $this->decoded($response);
        if (($tree['truncated'] ?? false) !== false) {
            throw new Unavailable("The tree of {$repository->fullName()} is too large for GitHub to list whole.");
        }

        $paths = [];
        foreach ((array) ($tree['tree'] ?? []) as $node) {
            $path = (string) ($node['path'] ?? '');
            if (($node['type'] ?? null) === 'blob' && preg_match('~^docs/.+\.md$~i', $path) === 1) {
                $paths[] = $path;
            }
        }
        if (count($paths) > self::MAX_DOCS) {
            throw new Unavailable("docs/ of {$repository->fullName()} has more than " . self::MAX_DOCS . ' Markdown files; stopped rather than read part of them.');
        }

        $docs = [];
        foreach ($paths as $path) {
            $docs[$path] = $this->file($repository, $path, $ref)
                ?? throw new Unavailable("{$path} of {$repository->fullName()} was listed and then not found.");
        }

        return $docs;
    }

    /**
     * Never a partial answer: GitHub may send some data together with its errors, and a list that looks complete
     * would make the core remove what is missing from it.
     *
     * @param array<string, string> $variables
     * @return array<mixed> the "data" of the answer
     */
    public function graphql(string $query, array $variables): array
    {
        $response = $this->http->request('POST', self::BASE . '/graphql', $this->headers(self::JSON) + ['content-type' => 'application/json'], (string) json_encode(['query' => $query, 'variables' => $variables]));
        $answer = $this->decoded($response);

        foreach ((array) ($answer['errors'] ?? []) as $error) {
            if (($error['type'] ?? null) === 'INSUFFICIENT_SCOPES') {
                throw new InvalidCredentials('The GitHub token cannot read Projects: it needs the read:project permission.');
            }
        }
        if (($answer['errors'] ?? []) !== [] || ! is_array($answer['data'] ?? null)) {
            throw new Unavailable('GitHub answered a GraphQL query with errors.');
        }

        return $answer['data'];
    }

    public function readme(Repository $repository, string $ref): ?string
    {
        return $this->text("/repos/{$repository->fullName()}/readme?ref=" . rawurlencode($ref));
    }

    private function text(string $path): ?string
    {
        $response = $this->get($path, self::RAW);
        if ($response->status === 404) {
            return null;
        }
        if (! $response->ok()) {
            throw new Unavailable("GitHub answered {$response->status} for {$path}.");
        }

        return mb_scrub($response->body, 'UTF-8');
    }

    /** @return list<array<mixed>> */
    private function all(string $path): array
    {
        $items = [];
        foreach ($this->pages($path, new Unavailable("GitHub has nothing at {$path}.")) as $item) {
            $items[] = (array) $item;
        }

        return $items;
    }

    /**
     * Stops with an error, never with a shorter list: a list that looks complete
     * would make the core remove what is missing from it.
     */
    private function pages(string $path, GllHubException $whenMissing): Generator
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->get($path . $separator . 'per_page=' . self::PER_PAGE . "&page={$page}", self::JSON);
            if ($response->status === 404) {
                throw $whenMissing;
            }

            yield from $this->decoded($response);

            if (! str_contains((string) $response->header('link'), 'rel="next"')) {
                return;
            }
        }

        throw new Unavailable("{$path} has more than " . self::MAX_PAGES . ' pages; stopped rather than read part of it.');
    }

    private function get(string $path, string $accept): HttpResponse
    {
        return $this->http->get(self::BASE . $path, $this->headers($accept));
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return [
            'accept' => $accept,
            'authorization' => "Bearer {$this->token}",
            'x-github-api-version' => '2022-11-28',
        ];
    }

    /** @return array<mixed> */
    private function decoded(HttpResponse $response): array
    {
        $decoded = $response->ok() ? json_decode($response->body, true) : null;

        return is_array($decoded)
            ? $decoded
            : throw new Unavailable("GitHub answered {$response->status} with something that is not the expected JSON.");
    }
}
