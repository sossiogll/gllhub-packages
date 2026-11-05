<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Exceptions\Unavailable;

/**
 * What GitHub gives only through GraphQL: discussions and the Projects a repository is linked to. One query covers
 * an ordinary repository; a list that goes on is read to its end, never cut.
 */
final readonly class RepositoryGraph
{
    private const MAX_PAGES = 20;

    private const COMMENT = 'url body createdAt author { login } replies { totalCount }';
    private const DISCUSSION = 'id number title url body createdAt updatedAt isAnswered category { name } author { login }
        comments(first: 100) { totalCount pageInfo { hasNextPage endCursor } nodes { ' . self::COMMENT . ' } }';
    private const LABELS = 'labels(first: 20) { nodes { name } }';
    private const ITEM = 'type fieldValueByName(name: "Status") { ... on ProjectV2ItemFieldSingleSelectValue { name } }
        content { ... on DraftIssue { title }
            ... on Issue { number title url state author { login __typename } repository { isPrivate } ' . self::LABELS . ' }
            ... on PullRequest { number title url state author { login __typename } repository { isPrivate } ' . self::LABELS . ' } }';

    private const REPOSITORY = 'query Repository($owner: String!, $name: String!) { repository(owner: $owner, name: $name) {
        hasDiscussionsEnabled
        discussions(first: 50, orderBy: {field: UPDATED_AT, direction: DESC}) { pageInfo { hasNextPage endCursor } nodes { ' . self::DISCUSSION . ' } }
        projectsV2(first: 20) { nodes { id title url public closed items(first: 100) { pageInfo { hasNextPage endCursor } nodes { ' . self::ITEM . ' } } } }
    } }';
    private const MORE_DISCUSSIONS = 'query MoreDiscussions($owner: String!, $name: String!, $after: String) { repository(owner: $owner, name: $name) {
        discussions(first: 50, after: $after, orderBy: {field: UPDATED_AT, direction: DESC}) { pageInfo { hasNextPage endCursor } nodes { ' . self::DISCUSSION . ' } }
    } }';
    private const MORE_COMMENTS = 'query MoreComments($id: ID!, $after: String) { node(id: $id) { ... on Discussion {
        comments(first: 100, after: $after) { pageInfo { hasNextPage endCursor } nodes { ' . self::COMMENT . ' } }
    } } }';
    private const MORE_ITEMS = 'query MoreItems($id: ID!, $after: String) { node(id: $id) { ... on ProjectV2 {
        items(first: 100, after: $after) { pageInfo { hasNextPage endCursor } nodes { ' . self::ITEM . ' } }
    } } }';

    /**
     * @param list<array<mixed>> $discussions
     * @param list<array<mixed>> $boards the public, open Projects, each with all its items in `items`
     */
    public function __construct(public array $discussions = [], public array $boards = [])
    {
    }

    public static function read(GithubApi $api, Repository $repository): self
    {
        $names = ['owner' => $repository->owner, 'name' => $repository->name];
        $data = $api->graphql(self::REPOSITORY, $names)['repository'] ?? null;
        if (! is_array($data)) {
            throw new Unavailable("GitHub described {$repository->fullName()} without its discussions and Projects.");
        }

        return new self(self::discussions($api, $names, $data), self::boards($api, $data));
    }

    /**
     * @param array<string, string> $names
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function discussions(GithubApi $api, array $names, array $data): array
    {
        if (($data['hasDiscussionsEnabled'] ?? false) !== true) {
            return [];
        }

        $first = (array) ($data['discussions'] ?? []);
        $discussions = [
            ...(array) ($first['nodes'] ?? []),
            ...self::rest($api, self::MORE_DISCUSSIONS, $names, ['repository', 'discussions'], (array) ($first['pageInfo'] ?? [])),
        ];

        foreach ($discussions as $i => $discussion) {
            $comments = (array) ($discussion['comments'] ?? []);
            $discussions[$i]['comments']['nodes'] = [
                ...(array) ($comments['nodes'] ?? []),
                ...self::rest($api, self::MORE_COMMENTS, ['id' => (string) $discussion['id']], ['node', 'comments'], (array) ($comments['pageInfo'] ?? [])),
            ];
        }

        return $discussions;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function boards(GithubApi $api, array $data): array
    {
        $boards = [];
        foreach ((array) ($data['projectsV2']['nodes'] ?? []) as $board) {
            // Only what is public reaches the site, whatever the token can see.
            if (($board['public'] ?? false) !== true || ($board['closed'] ?? true) !== false) {
                continue;
            }

            $items = (array) ($board['items'] ?? []);
            $board['items'] = [
                ...(array) ($items['nodes'] ?? []),
                ...self::rest($api, self::MORE_ITEMS, ['id' => (string) $board['id']], ['node', 'items'], (array) ($items['pageInfo'] ?? [])),
            ];
            $boards[] = $board;
        }

        return $boards;
    }

    /**
     * @param array<string, string> $variables
     * @param list<string> $path where the list is in the answer
     * @param array<mixed> $pageInfo of the page already read
     * @return list<array<mixed>>
     */
    private static function rest(GithubApi $api, string $query, array $variables, array $path, array $pageInfo): array
    {
        $nodes = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if (($pageInfo['hasNextPage'] ?? false) !== true) {
                return $nodes;
            }

            $connection = $api->graphql($query, $variables + ['after' => (string) $pageInfo['endCursor']]);
            foreach ($path as $key) {
                $connection = (array) ($connection[$key] ?? []);
            }
            array_push($nodes, ...(array) ($connection['nodes'] ?? []));
            $pageInfo = (array) ($connection['pageInfo'] ?? []);
        }

        throw new Unavailable('A list on GitHub has more than ' . self::MAX_PAGES . ' pages; stopped rather than read part of it.');
    }
}
