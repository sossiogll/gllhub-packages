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

        $path = $this->repositoryPath($link);
        if ($path === null) {
            return null;
        }

        return 'https://raw.githubusercontent.com/' . $this->fullName . '/' . self::encoded($this->ref) . '/' . self::encoded($path);
    }

    /** The path a relative link names inside the repository; null when it names nothing there. */
    public function repositoryPath(string $link): ?string
    {
        $link = trim($link);
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $link) === 1) {
            return null;
        }

        // A link in Markdown is already encoded: "a%20b.png" names the file "a b.png".
        $path = rawurldecode((string) strtok($link, '?#'));
        $segments = self::resolved(str_starts_with($path, '/') ? $path : "{$this->folder}/{$path}");

        return $segments === null || $segments === [] ? null : implode('/', $segments);
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

    private static function encoded(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
