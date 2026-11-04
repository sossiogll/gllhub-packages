<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/**
 * What a project page takes from a repository, at the version it reads: the latest
 * release, or the default branch for a repository without releases.
 */
final readonly class RepositoryContent
{
    /**
     * @param list<string> $languages
     * @param list<array<mixed>> $releases
     * @param list<array<mixed>> $milestones
     */
    public function __construct(
        public Repository $repository,
        public string $ref,
        public ?string $latestTag,
        public array $languages,
        public array $releases,
        public array $milestones,
        public ?string $readme,
    ) {
    }

    public static function read(GithubApi $api, Repository $repository): self
    {
        $tag = $api->latestReleaseTag($repository);
        $ref = $tag ?? $repository->defaultBranch;

        return new self(
            repository: $repository,
            ref: $ref,
            latestTag: $tag,
            languages: $api->languages($repository),
            releases: $api->releases($repository),
            milestones: $api->milestones($repository),
            readme: $api->readme($repository, $ref),
        );
    }

    public function links(): RawLinks
    {
        return new RawLinks($this->repository->fullName(), $this->ref);
    }

    public function hasMilestone(string $title): bool
    {
        return in_array($title, array_column($this->milestones, 'title'), true);
    }
}
