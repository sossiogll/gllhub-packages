<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/** The fields of a project that come from GitHub, whatever its showcase file says. */
final class ProjectFacts
{
    /** They cannot be written in a showcase file. "images" is filled as media, not as a value. */
    public const KEYS = ['repository', 'languages', 'stars', 'version', 'readme', 'releases', 'milestones', 'docs', 'discussions', 'boards', 'images'];

    /** @return array{0: array<string, mixed>, 1: list<string>} the values, and the relative images found in their text */
    public static function of(RepositoryContent $content): array
    {
        [$readme, $images] = MarkdownImages::absolute($content->readme ?? '', $content->links());
        [$docs, $docImages] = self::docs($content);

        return [
            self::withoutNulls([
                'repository' => $content->repository->url,
                'languages' => $content->languages,
                'stars' => $content->repository->stars,
                'version' => $content->latestTag,
                'readme' => $content->readme === null ? null : $readme,
                'releases' => self::releases($content->releases),
                'milestones' => self::milestones($content->milestones, $content->milestoneItems),
                'docs' => $docs,
                'discussions' => array_map(self::discussion(...), $content->graph->discussions),
                'boards' => array_map(self::board(...), $content->graph->boards),
            ]),
            array_values(array_unique([...$images, ...$docImages])),
        ];
    }

    /** @return array{0: list<array<string, string>>, 1: list<string>} */
    private static function docs(RepositoryContent $content): array
    {
        $docs = [];
        $images = [];
        foreach ($content->docs as $path => $text) {
            [$body, $found] = MarkdownImages::absolute($text, $content->links()->within(dirname($path)));
            array_push($images, ...$found);
            $docs[] = ['path' => $path, 'title' => self::docTitle($path, $text), 'body' => $body];
        }

        return [$docs, $images];
    }

    private static function docTitle(string $path, string $text): string
    {
        return preg_match('/^#[ \t]+(.+?)[ \t]*$/m', $text, $heading) === 1 ? $heading[1] : pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * @param list<array<mixed>> $releases
     * @return list<array<string, mixed>>
     */
    private static function releases(array $releases): array
    {
        $published = array_filter($releases, static fn (array $r): bool => ($r['draft'] ?? true) === false);

        return array_values(array_map(static fn (array $r): array => self::withoutNulls([
            'tag' => $r['tag_name'] ?? null,
            'name' => ($r['name'] ?? '') === '' ? null : $r['name'],
            'url' => $r['html_url'] ?? null,
            'published_at' => $r['published_at'] ?? null,
            'body' => $r['body'] ?? null,
            'prerelease' => $r['prerelease'] ?? null,
        ]), $published));
    }

    /**
     * @param list<array<mixed>> $milestones
     * @param list<array<mixed>> $items the issues and pull requests that have a milestone
     * @return list<array<string, mixed>>
     */
    private static function milestones(array $milestones, array $items): array
    {
        $byMilestone = [];
        foreach ($items as $item) {
            $byMilestone[$item['milestone']['title'] ?? ''][] = self::milestoneItem($item);
        }

        return array_map(static fn (array $m): array => self::withoutNulls([
            'title' => $m['title'] ?? null,
            'description' => $m['description'] ?? null,
            'state' => $m['state'] ?? null,
            'open_items' => $m['open_issues'] ?? null,
            'closed_items' => $m['closed_issues'] ?? null,
            'due_at' => $m['due_on'] ?? null,
            'url' => $m['html_url'] ?? null,
            'items' => $byMilestone[$m['title'] ?? ''] ?? [],
        ]), $milestones);
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function milestoneItem(array $item): array
    {
        $pullRequest = $item['pull_request'] ?? null;

        return self::withoutNulls([
            'number' => $item['number'] ?? null,
            'title' => $item['title'] ?? null,
            'url' => $item['html_url'] ?? null,
            'kind' => $pullRequest === null ? 'issue' : 'pull_request',
            'state' => ($pullRequest['merged_at'] ?? null) !== null ? 'merged' : ($item['state'] ?? null),
        ]);
    }

    /**
     * @param array<mixed> $discussion
     * @return array<string, mixed>
     */
    private static function discussion(array $discussion): array
    {
        $comments = (array) ($discussion['comments']['nodes'] ?? []);

        return self::withoutNulls([
            'number' => $discussion['number'] ?? null,
            'title' => $discussion['title'] ?? null,
            'url' => $discussion['url'] ?? null,
            'author' => $discussion['author']['login'] ?? null,
            'category' => $discussion['category']['name'] ?? null,
            'created_at' => $discussion['createdAt'] ?? null,
            'updated_at' => $discussion['updatedAt'] ?? null,
            'answered' => $discussion['isAnswered'] ?? null,
            'body' => $discussion['body'] ?? null,
            'comments' => array_map(static fn (array $c): array => self::withoutNulls([
                'url' => $c['url'] ?? null,
                'author' => $c['author']['login'] ?? null,
                'created_at' => $c['createdAt'] ?? null,
                'body' => $c['body'] ?? null,
                'reply_count' => $c['replies']['totalCount'] ?? null,
            ]), $comments),
        ]);
    }

    /**
     * @param array<mixed> $board
     * @return array<string, mixed>
     */
    private static function board(array $board): array
    {
        return [
            'title' => $board['title'] ?? '',
            'url' => $board['url'] ?? null,
            'items' => array_values(array_map(self::boardItem(...), array_filter((array) ($board['items'] ?? []), self::isOpenAndPublic(...)))),
        ];
    }

    /**
     * What is finished is already in the milestones. An item of a private repository stays out even when the token
     * can read it: the board is public, the item is not.
     *
     * @param array<mixed> $item
     */
    private static function isOpenAndPublic(array $item): bool
    {
        if (($item['type'] ?? null) === 'DRAFT_ISSUE') {
            return true;
        }

        return ($item['content']['state'] ?? null) === 'OPEN' && ($item['content']['repository']['isPrivate'] ?? true) === false;
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function boardItem(array $item): array
    {
        $content = (array) ($item['content'] ?? []);

        return self::withoutNulls([
            'kind' => match ($item['type'] ?? null) {
                'DRAFT_ISSUE' => 'draft',
                'PULL_REQUEST' => 'pull_request',
                default => 'issue',
            },
            'title' => $content['title'] ?? null,
            'number' => $content['number'] ?? null,
            'url' => $content['url'] ?? null,
            'status' => $item['fieldValueByName']['name'] ?? null,
            'author' => $content['author']['login'] ?? null,
            'by_bot' => isset($content['author']) ? ($content['author']['__typename'] ?? null) === 'Bot' : null,
            'labels' => isset($content['labels']) ? array_column((array) $content['labels']['nodes'], 'name') : null,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }
}
