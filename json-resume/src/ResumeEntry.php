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
 * `skills` on a course, and `levels` (`reading`, `writing`, `listening`, `speaking`) on a language.
 */
final class ResumeEntry
{
    public const SLUG = 'cv';

    private const PARTIAL_DATE = '/^\d{4}(-\d{2}(-\d{2})?)?$/';
    private const LEVELS = ['reading', 'writing', 'listening', 'speaking'];

    /**
     * @param array<mixed> $resume
     * @throws InvalidResume
     */
    public static function from(array $resume, string $language, ?string $pdf, DateTimeImmutable $now): ExternalEntry
    {
        $basics = (array) ($resume['basics'] ?? []);
        $title = self::text($basics['name'] ?? null) ?? '';
        $summary = self::text($basics['label'] ?? null);
        $body = self::text($basics['summary'] ?? null);
        $fields = self::withoutEmpty([
            'work' => array_map(self::work(...), self::items($resume, 'work')),
            'education' => array_map(self::education(...), self::items($resume, 'education')),
            'languages' => array_map(self::language(...), self::items($resume, 'languages')),
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
     * An entry the contract's rules refuse. The core reports it and keeps what it already has, while leaving it out
     * would read as the CV being gone.
     */
    public static function refused(string $language): ExternalEntry
    {
        return new ExternalEntry(externalId: self::SLUG, type: Resume::KEY, title: '', language: $language);
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function work(array $item): array
    {
        return self::withoutEmpty([
            'name' => self::text($item['name'] ?? null),
            'position' => self::text($item['position'] ?? null),
            'start_date' => self::date($item['startDate'] ?? null),
            'end_date' => self::date($item['endDate'] ?? null),
            'employment_type' => self::text($item['employmentType'] ?? null),
            'url' => self::text($item['url'] ?? null),
            'summary' => self::withHighlights(self::text($item['summary'] ?? null), (array) ($item['highlights'] ?? [])),
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
            'institution' => self::text($item['institution'] ?? null),
            'study_type' => self::text($item['studyType'] ?? null),
            'area' => self::text($item['area'] ?? null),
            'start_date' => self::date($item['startDate'] ?? null),
            'end_date' => self::date($item['endDate'] ?? null),
            'skills' => self::keywordGroups($item, 'skills'),
        ]);
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

        $shaped = ['language' => self::text($item['language'] ?? null), 'fluency' => self::text($item['fluency'] ?? null)];
        foreach (self::LEVELS as $skill) {
            $shaped[$skill] = self::text($levels[$skill] ?? null);
        }

        return self::withoutEmpty($shaped);
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
                'name' => self::text($group['name'] ?? null),
                'keywords' => array_values(array_filter(array_map(self::text(...), $keywords), is_string(...))),
            ]);
        }, self::items($parent, $key));
    }

    /** @param array<mixed> $highlights */
    private static function withHighlights(?string $summary, array $highlights): ?string
    {
        $lines = array_map(static fn (string $h): string => "- {$h}", array_values(array_filter($highlights, is_string(...))));
        $parts = array_filter([$summary, implode("\n", $lines)], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private static function date(mixed $value): ?string
    {
        $date = self::text($value);
        if ($date !== null && preg_match(self::PARTIAL_DATE, $date) !== 1) {
            throw new InvalidResume("\"{$date}\" is not a date such as 2023, 2023-12 or 2023-12-01.");
        }

        return $date;
    }

    /**
     * @param array<mixed> $parent
     * @return list<array<mixed>>
     */
    private static function items(array $parent, string $key): array
    {
        $items = $parent[$key] ?? [];
        if (! is_array($items) || ! array_is_list($items) || array_filter($items, is_array(...)) !== $items) {
            throw new InvalidResume("\"{$key}\" is not a list of items.");
        }

        return $items;
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

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
