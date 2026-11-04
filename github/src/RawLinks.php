<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/** Turns a link written in a repository's files into a URL the core can download. */
final readonly class RawLinks
{
    public function __construct(private string $fullName, private string $ref)
    {
    }

    /**
     * Relative links start at the root of the repository, wherever the file is.
     *
     * @return string|null null when the link cannot be followed: another scheme, or a path that climbs out
     */
    public function absolute(string $link): ?string
    {
        $link = trim($link);
        if (str_starts_with($link, 'https://')) {
            return $link;
        }
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $link) === 1) {
            return null;
        }

        $path = self::segments((string) strtok($link, '?#'));
        if ($path === [] || in_array('..', $path, true)) {
            return null;
        }

        return 'https://raw.githubusercontent.com/' . $this->fullName . '/'
            . implode('/', self::segments($this->ref)) . '/' . implode('/', $path);
    }

    /** @return list<string> URL-encoded, without the empty and "." ones */
    private static function segments(string $path): array
    {
        $kept = array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.');

        return array_values(array_map(static fn (string $s): string => $s === '..' ? $s : rawurlencode($s), $kept));
    }
}
