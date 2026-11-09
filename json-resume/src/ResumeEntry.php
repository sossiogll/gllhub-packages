<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

use DateTimeImmutable;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Gll\Hub\Contracts\Support\Fingerprint;
use Gll\Hub\Portfolio\Types\Resume;
use Throwable;

/**
 * Turns a JSON Resume document into an entry. It takes what it names and nothing else, so an email or a phone number in
 * the file cannot reach the site by accident.
 *
 * A few keys are not in the JSON Resume schema and are read when present: `employmentType` and `skills` on a job,
 * `skills` on a course, and `levels` on a language, an object keyed by
 * skill (`reading`, `writing`, `listening`, `speaking`) that becomes a list of `{ skill, level }` in that order.
 *
 * `id` on a job or a course names it for the `timeline`, a top-level object that declares the CV's graph of branches
 * and rows (see Timeline). The ids are unique among jobs and courses, with or without a timeline.
 */
final class ResumeEntry
{
    public const SLUG = 'cv';

    /**
     * @param array<mixed> $resume
     * @throws InvalidResume
     */
    public static function from(array $resume, string $language, ?string $pdf, DateTimeImmutable $now): ExternalEntry
    {
        $basics = (array) ($resume['basics'] ?? []);
        $title = Values::text($basics['name'] ?? null) ?? '';
        $summary = Values::text($basics['label'] ?? null);
        $body = Values::text($basics['summary'] ?? null);
        $work = array_map(self::work(...), Values::items($resume, 'work'));
        $education = array_map(self::education(...), Values::items($resume, 'education'));
        $fields = self::withoutEmpty([
            'work' => $work,
            'education' => $education,
            'timeline' => Timeline::from($resume['timeline'] ?? null, self::ids([...$work, ...$education])),
            'languages' => array_map(self::language(...), Values::items($resume, 'languages')),
            'interests' => self::keywordGroups($resume, 'interests'),
        ]);

        return new ExternalEntry(
            externalId: self::SLUG,
            type: Resume::KEY,
            title: $title,
            slug: self::SLUG,
            summary: $summary,
            body: $body,
            language: $language,
            // Translations of a CV are instances of this source, one per file.
            translationKey: 'json-resume:' . self::SLUG,
            fields: $fields,
            media: $pdf === null ? [] : [new RemoteMedia('pdf', $pdf, alt: $title)],
            publishedAt: self::modifiedAt($resume) ?? $now,
            fingerprint: Fingerprint::of($title, $summary, $body, (string) json_encode($fields), $pdf),
        );
    }

    /**
     * An entry its source refuses, with the reason. The core reports the reason and keeps what it already has, while
     * leaving it out would read as the CV being gone.
     */
    public static function refused(string $language, string $reason): ExternalEntry
    {
        return new ExternalEntry(externalId: self::SLUG, type: Resume::KEY, title: '', language: $language, refusal: $reason);
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function work(array $item): array
    {
        return self::withoutEmpty([
            'id' => Values::text($item['id'] ?? null),
            'name' => Values::text($item['name'] ?? null),
            'position' => Values::text($item['position'] ?? null),
            'start_date' => Values::date($item['startDate'] ?? null),
            'end_date' => Values::date($item['endDate'] ?? null),
            'employment_type' => Values::text($item['employmentType'] ?? null),
            'url' => Values::text($item['url'] ?? null),
            'summary' => self::withHighlights(Values::text($item['summary'] ?? null), (array) ($item['highlights'] ?? [])),
            'skills' => self::keywordGroups($item, 'skills'),
        ]);
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function education(array $item): array
    {
        return self::withoutEmpty([
            'id' => Values::text($item['id'] ?? null),
            'institution' => Values::text($item['institution'] ?? null),
            'study_type' => Values::text($item['studyType'] ?? null),
            'area' => Values::text($item['area'] ?? null),
            'start_date' => Values::date($item['startDate'] ?? null),
            'end_date' => Values::date($item['endDate'] ?? null),
            'skills' => self::keywordGroups($item, 'skills'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        $ids = array_values(array_filter(array_column($items, 'id'), is_string(...)));
        $repeated = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));
        if ($repeated !== []) {
            throw new InvalidResume('The id "' . $repeated[0] . '" is used by more than one job or course.');
        }

        return $ids;
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function language(array $item): array
    {
        $levels = $item['levels'] ?? [];
        if (! is_array($levels) || ($levels !== [] && array_is_list($levels))) {
            throw new InvalidResume('"levels" of a language is not a set of named levels.');
        }

        $list = [];
        foreach (Resume::LANGUAGE_SKILLS as $skill) {
            $level = Values::text($levels[$skill] ?? null);
            if ($level !== null) {
                $list[] = ['skill' => $skill, 'level' => $level];
            }
        }

        return self::withoutEmpty([
            'language' => Values::text($item['language'] ?? null),
            'fluency' => Values::text($item['fluency'] ?? null),
            'levels' => $list,
        ]);
    }

    /**
     * @param array<mixed> $parent
     * @return list<array<string, mixed>>
     */
    private static function keywordGroups(array $parent, string $key): array
    {
        return array_map(static function (array $group): array {
            $keywords = $group['keywords'] ?? [];
            if (! is_array($keywords) || ! array_is_list($keywords)) {
                throw new InvalidResume('"keywords" is not a list of words.');
            }

            return self::withoutEmpty([
                'name' => Values::text($group['name'] ?? null),
                'keywords' => array_values(array_filter(array_map(Values::text(...), $keywords), is_string(...))),
            ]);
        }, Values::items($parent, $key));
    }

    /** @param array<mixed> $highlights */
    private static function withHighlights(?string $summary, array $highlights): ?string
    {
        $lines = array_map(static fn (string $h): string => "- {$h}", array_values(array_filter($highlights, is_string(...))));
        $parts = array_filter([$summary, implode("\n", $lines)], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /** @param array<mixed> $resume */
    private static function modifiedAt(array $resume): ?DateTimeImmutable
    {
        $value = $resume['meta']['lastModified'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Leaves out what has no value, an empty list included, so the entry says only what the file says.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
