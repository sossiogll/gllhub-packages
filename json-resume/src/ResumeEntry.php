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
 */
final class ResumeEntry
{
    public const SLUG = 'cv';

    private const PARTIAL_DATE = '/^\d{4}(-\d{2}(-\d{2})?)?$/';

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
        $fields = [
            'work' => array_map(self::work(...), self::items($resume, 'work')),
            'education' => array_map(self::education(...), self::items($resume, 'education')),
        ];

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
     * @return array<string, string>
     */
    private static function work(array $item): array
    {
        return self::withoutNulls([
            'name' => self::text($item['name'] ?? null),
            'position' => self::text($item['position'] ?? null),
            'start_date' => self::date($item['startDate'] ?? null),
            'end_date' => self::date($item['endDate'] ?? null),
            'url' => self::text($item['url'] ?? null),
            'summary' => self::withHighlights(self::text($item['summary'] ?? null), (array) ($item['highlights'] ?? [])),
        ]);
    }

    /**
     * @param array<mixed> $item
     * @return array<string, string>
     */
    private static function education(array $item): array
    {
        return self::withoutNulls([
            'institution' => self::text($item['institution'] ?? null),
            'study_type' => self::text($item['studyType'] ?? null),
            'area' => self::text($item['area'] ?? null),
            'start_date' => self::date($item['startDate'] ?? null),
            'end_date' => self::date($item['endDate'] ?? null),
        ]);
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
     * @param array<mixed> $resume
     * @return list<array<mixed>>
     */
    private static function items(array $resume, string $key): array
    {
        $items = $resume[$key] ?? [];
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
     * @param array<string, ?string> $values
     * @return array<string, string>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (?string $v): bool => $v !== null);
    }
}
