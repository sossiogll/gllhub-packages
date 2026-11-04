<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use DateTimeImmutable;
use Gll\Hub\Contracts\Exceptions\Unavailable;

/** A repository as GitHub describes it. */
final readonly class Repository
{
    public const TOPIC = 'showcase';

    /** @param list<string> $topics */
    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
        public string $url,
        public string $defaultBranch,
        public ?string $description = null,
        public int $stars = 0,
        public array $topics = [],
        public bool $private = false,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $pushedAt = null,
    ) {
    }

    /** @param array<mixed> $data */
    public static function from(array $data): self
    {
        $id = $data['id'] ?? null;
        $owner = $data['owner']['login'] ?? null;
        $name = $data['name'] ?? null;
        $url = $data['html_url'] ?? null;
        $branch = $data['default_branch'] ?? null;

        if (! is_int($id) || ! is_string($owner) || ! is_string($name) || ! is_string($url) || ! is_string($branch)) {
            throw new Unavailable('GitHub described a repository without its id, owner, name, URL or default branch.');
        }

        $description = trim((string) ($data['description'] ?? ''));

        return new self(
            id: $id,
            owner: $owner,
            name: $name,
            url: $url,
            defaultBranch: $branch,
            description: $description === '' ? null : $description,
            stars: (int) ($data['stargazers_count'] ?? 0),
            topics: array_values(array_filter((array) ($data['topics'] ?? []), is_string(...))),
            // Absent counts as private: only what GitHub says is public reaches the site.
            private: ($data['private'] ?? true) !== false,
            createdAt: self::date($data['created_at'] ?? null),
            pushedAt: self::date($data['pushed_at'] ?? null),
        );
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }

    public function isShowcaseOf(string $account): bool
    {
        return ! $this->private
            && strcasecmp($this->owner, $account) === 0
            && in_array(self::TOPIC, $this->topics, true);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? (DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: null) : null;
    }
}
