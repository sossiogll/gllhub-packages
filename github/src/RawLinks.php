<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

/** Turns a link written in a repository's files into a URL the core can download. */
final readonly class RawLinks
{
    public function __construct(private string $fullName, private string $ref, private string $folder = '')
    {
    }

    /** The links of a file in that folder: a relative path starts there, not at the root. */
    public function within(string $folder): self
    {
        return new self($this->fullName, $this->ref, $folder === '.' ? '' : $folder);
    }

    /**
     * A path starts at the root of the repository, or at the folder given to within(). A leading "/" is always the root.
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

        $path = (string) strtok($link, '?#');
        $path = self::resolved(str_starts_with($path, '/') ? $path : "{$this->folder}/{$path}");
        if ($path === null || $path === []) {
            return null;
        }

        return 'https://raw.githubusercontent.com/' . $this->fullName . '/'
            . implode('/', self::encoded($this->ref)) . '/' . implode('/', array_map(rawurlencode(...), $path));
    }

    /** @return list<string>|null null when the path climbs out of the repository */
    private static function resolved(string $path): ?array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $segments[] = $segment;
            } elseif (array_pop($segments) === null) {
                return null;
            }
        }

        return $segments;
    }

    /** @return list<string> */
    private static function encoded(string $ref): array
    {
        return array_map(rawurlencode(...), explode('/', $ref));
    }
}
