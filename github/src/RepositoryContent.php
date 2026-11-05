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
     * @param list<array<mixed>> $milestoneItems the issues and pull requests that have a milestone
     * @param array<string, string> $docs the Markdown files of docs/, by path
     */
    public function __construct(
        public Repository $repository,
        public string $ref,
        public ?string $latestTag,
        public array $languages,
        public array $releases,
        public array $milestones,
        public ?string $readme,
        public array $milestoneItems = [],
        public array $docs = [],
        public RepositoryGraph $graph = new RepositoryGraph(),
    ) {
    }

    public static function read(GithubApi $api, Repository $repository): self
    {
        $tag = $api->latestReleaseTag($repository);
        $ref = $tag ?? $repository->defaultBranch;
        $milestones = $api->milestones($repository);

        return new self(
            repository: $repository,
            ref: $ref,
            latestTag: $tag,
            languages: $api->languages($repository),
            releases: $api->releases($repository),
            milestones: $milestones,
            readme: $api->readme($repository, $ref),
            milestoneItems: $milestones === [] ? [] : $api->milestoneItems($repository),
            docs: $api->docs($repository, $ref),
            graph: RepositoryGraph::read($api, $repository),
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
