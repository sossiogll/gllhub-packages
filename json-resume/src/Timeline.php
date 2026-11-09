<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

/**
 * The graph of a CV, written by its author: branches that fork from and merge into one another, and the rows of the
 * log. Nothing is drawn or inferred here; the references between the parts are checked, so a file that points at
 * something missing is refused with its reason instead of being served broken.
 */
final class Timeline
{
    public const TRUNK = 'master';

    /**
     * @param list<string> $ids the ids of the jobs and courses a row may name
     * @return array{branches: list<array<string, string>>, rows: list<array<string, string>>}|null
     * @throws InvalidResume
     */
    public static function from(mixed $timeline, array $ids): ?array
    {
        if ($timeline === null) {
            return null;
        }
        if (! is_array($timeline) || array_is_list($timeline) && $timeline !== []) {
            throw new InvalidResume('"timeline" is not an object with the lists "branches" and "rows".');
        }

        $branches = array_map(self::branch(...), self::list($timeline, 'branches'));
        $rows = array_map(self::row(...), self::list($timeline, 'rows'));

        $names = array_column($branches, 'name');
        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidResume('The branch "' . self::repeated($names) . '" is declared more than once.');
        }
        self::checkBranches($branches, $rows, [...$names, self::TRUNK]);
        self::checkRows($rows, $names, $ids);
        self::checkStarts($branches, $rows);

        return $branches === [] && $rows === [] ? null : ['branches' => $branches, 'rows' => $rows];
    }

    /**
     * @param array<mixed> $timeline
     * @return list<array<mixed>>
     */
    private static function list(array $timeline, string $key): array
    {
        if (! is_array($timeline[$key] ?? null)) {
            throw new InvalidResume("\"timeline\" has no list \"{$key}\".");
        }

        return Values::items($timeline, $key);
    }

    /**
     * @param array<mixed> $branch
     * @return array<string, string>
     */
    private static function branch(array $branch): array
    {
        $values = [
            'name' => Values::text($branch['name'] ?? null),
            'from' => Values::text($branch['from'] ?? null),
            'into' => Values::text($branch['into'] ?? null),
            'start' => Values::date($branch['start'] ?? null),
            'end' => Values::date($branch['end'] ?? null),
        ];
        foreach (['name', 'from', 'start'] as $required) {
            if ($values[$required] === null) {
                throw new InvalidResume("A branch of the timeline has no \"{$required}\".");
            }
        }

        return array_filter($values, static fn (?string $value): bool => $value !== null);
    }

    /**
     * @param array<mixed> $row
     * @return array<string, string>
     */
    private static function row(array $row): array
    {
        $values = [
            'date' => Values::date($row['date'] ?? null),
            'branch' => Values::text($row['branch'] ?? null),
            'role' => Values::text($row['role'] ?? null),
            'message' => Values::text($row['message'] ?? null),
        ];
        if ($values['date'] === null) {
            throw new InvalidResume('A row of the timeline has no "date".');
        }
        if (($values['role'] === null) === ($values['message'] === null)) {
            throw new InvalidResume("The row of {$values['date']} must have a \"role\" or a \"message\", and not both.");
        }
        if ($values['role'] !== null && $values['branch'] === null) {
            throw new InvalidResume("The row of {$values['date']} names a role, so it must say the \"branch\" it is a commit on.");
        }

        return array_filter($values, static fn (?string $value): bool => $value !== null);
    }

    /**
     * @param list<array<string, string>> $branches
     * @param list<array<string, string>> $rows
     * @param list<string> $known
     */
    private static function checkBranches(array $branches, array $rows, array $known): void
    {
        $dates = array_column($rows, 'date');
        foreach ($branches as $branch) {
            foreach (['from', 'into'] as $key) {
                if (isset($branch[$key]) && ! in_array($branch[$key], $known, true)) {
                    throw new InvalidResume("\"{$key}\" of the branch \"{$branch['name']}\" names \"{$branch[$key]}\", which is not a branch.");
                }
            }
            foreach (['start', 'end'] as $key) {
                if (isset($branch[$key]) && ! in_array($branch[$key], $dates, true)) {
                    throw new InvalidResume("\"{$key}\" of the branch \"{$branch['name']}\" is {$branch[$key]}, which is not the date of a row.");
                }
            }
            if (isset($branch['end']) && self::after($branch['start'], $branch['end'])) {
                throw new InvalidResume("The branch \"{$branch['name']}\" starts ({$branch['start']}) after it ends ({$branch['end']}).");
            }
        }
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $branches
     * @param list<string> $ids
     */
    private static function checkRows(array $rows, array $branches, array $ids): void
    {
        foreach ($rows as $row) {
            if (isset($row['role']) && ! in_array($row['role'], $ids, true)) {
                throw new InvalidResume("The row of {$row['date']} names the role \"{$row['role']}\", which is not the id of a job or course.");
            }
            if (isset($row['branch']) && ! in_array($row['branch'], $branches, true)) {
                throw new InvalidResume("The row of {$row['date']} is on \"{$row['branch']}\", which is not a branch.");
            }
        }
    }

    /**
     * @param list<array<string, string>> $branches
     * @param list<array<string, string>> $rows
     */
    private static function checkStarts(array $branches, array $rows): void
    {
        foreach ($branches as $branch) {
            $starts = array_filter($rows, static fn (array $row): bool => $row['date'] === $branch['start']
                && ($row['branch'] ?? null) === $branch['name']
                && isset($row['role']));
            if ($starts === []) {
                throw new InvalidResume("The branch \"{$branch['name']}\" has no role at its start ({$branch['start']}).");
            }
        }
    }

    /** Dates of different precision are compared as far as both go: 2015 is not after 2015-06. */
    private static function after(string $a, string $b): bool
    {
        $length = min(strlen($a), strlen($b));

        return strcmp(substr($a, 0, $length), substr($b, 0, $length)) > 0;
    }

    /** @param list<string> $names */
    private static function repeated(array $names): string
    {
        return (string) array_key_first(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));
    }
}
