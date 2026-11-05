<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Closure;
use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Portfolio\Types\Project;

/**
 * Says what gllHub would refuse in a repository's showcase files, by running the rules a sync runs: there is no second
 * copy of them to fall behind. It adds only what a sync cannot see, that a file named by a relative link exists.
 */
final class ShowcaseValidator
{
    private const LANGUAGE_OF_MAIN = ProjectEntry::BASE_LANGUAGE;

    /**
     * @param array<string, string> $files the text of the showcase files that exist, by path
     * @param list<string> $milestones the titles of the repository's milestones
     * @param Closure(string): bool $exists whether a path exists in the repository
     * @return array<string, list<string>> the problems, by the file that has them
     */
    public static function problems(array $files, array $milestones, Closure $exists): array
    {
        $main = $files[ShowcaseRepositories::SHOWCASE] ?? null;
        $problems = [];

        if ($main !== null) {
            $problems[ShowcaseRepositories::SHOWCASE] = self::check($main, null, self::LANGUAGE_OF_MAIN, $milestones, $exists);
        }
        foreach (ShowcaseRepositories::TRANSLATIONS as $language) {
            $path = ShowcaseRepositories::translationFile($language);
            if (! isset($files[$path])) {
                continue;
            }
            $problems[$path] = $main === null
                ? ['gllHub reads a translation only next to ' . ShowcaseRepositories::SHOWCASE . ', which does not exist.']
                : self::check($main, $files[$path], $language, $milestones, $exists);
        }

        return array_filter($problems);
    }

    /**
     * @param list<string> $milestones
     * @param Closure(string): bool $exists
     * @return list<string>
     */
    private static function check(string $main, ?string $translation, string $language, array $milestones, Closure $exists): array
    {
        $links = new RawLinks('validator/validator', 'validator');
        $content = new RepositoryContent(
            repository: new Repository(1, 'validator', 'validator', 'https://example.test/validator', 'main'),
            ref: 'validator',
            latestTag: null,
            languages: [],
            releases: [],
            milestones: array_map(static fn (string $title): array => ['title' => $title], $milestones),
            readme: null,
        );

        try {
            $file = ShowcaseFile::parse($main);
            if ($translation !== null) {
                $own = ShowcaseFile::parse($translation);
                $file = $file->translatedBy($own);
            }
            $entry = ProjectEntry::fromShowcase($content, $file, $language);
        } catch (InvalidShowcase $e) {
            return [$e->getMessage()];
        }

        return [
            ...self::rulesOf($entry),
            ...self::missingFiles($own ?? $file, $links, $exists),
        ];
    }

    /** @return list<string> */
    private static function rulesOf(ExternalEntry $entry): array
    {
        return array_map(
            static fn (string $violation): string => (string) preg_replace('/^entry [^:]+: /', '', $violation),
            EntryRules::violations(ShowcaseRepositories::definition(), [Project::definition()], $entry),
        );
    }

    /**
     * @param Closure(string): bool $exists
     * @return list<string>
     */
    private static function missingFiles(ShowcaseFile $file, RawLinks $links, Closure $exists): array
    {
        $named = [...array_filter([$file->front['cover'] ?? null, $file->front['demo_media'] ?? null], is_string(...)), ...MarkdownImages::links($file->body)];

        $missing = [];
        foreach ($named as $link) {
            $path = $links->repositoryPath($link);
            if ($path !== null && ! $exists($path)) {
                $missing[$path] ??= "\"{$link}\" names {$path}, which is not in the repository.";
            }
        }

        return array_values($missing);
    }
}
