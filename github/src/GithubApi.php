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

    public function file(Repository $repository, string $path, string $ref): ?string
    {
        return $this->text("/repos/{$repository->fullName()}/contents/{$path}?ref=" . rawurlencode($ref));
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
        return $this->http->get(self::BASE . $path, [
            'accept' => $accept,
            'authorization' => "Bearer {$this->token}",
            'x-github-api-version' => '2022-11-28',
        ]);
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
