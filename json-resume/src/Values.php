<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

/** Reads the plain values of a JSON Resume file, refusing the ones that are not what they claim to be. */
final class Values
{
    private const PARTIAL_DATE = '/^\d{4}(-\d{2}(-\d{2})?)?$/';

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function date(mixed $value): ?string
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
    public static function items(array $parent, string $key): array
    {
        $items = $parent[$key] ?? [];
        if (! is_array($items) || ! array_is_list($items) || array_filter($items, is_array(...)) !== $items) {
            throw new InvalidResume("\"{$key}\" is not a list of items.");
        }

        return $items;
    }
}
