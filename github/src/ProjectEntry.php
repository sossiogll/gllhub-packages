<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\ExternalRef;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Gll\Hub\Contracts\Support\Fingerprint;
use Gll\Hub\Portfolio\Types\Project;

/** Builds project entries from a repository and, when it has one, its showcase file. */
final class ProjectEntry
{
    public const BASE_LANGUAGE = 'en';

    private const CLAIM_LENGTH = 160;
    private const CORE_OR_MEDIA_KEYS = ['title', 'summary', 'cover', 'demo_media', 'related'];

    public static function externalId(int $repositoryId, string $language): string
    {
        return $language === self::BASE_LANGUAGE ? (string) $repositoryId : "{$repositoryId}:{$language}";
    }

    public static function fromShowcase(RepositoryContent $content, ShowcaseFile $file, string $language): ExternalEntry
    {
        $front = $file->front;

        $facts = array_intersect(array_keys($front), ProjectFacts::KEYS);
        if ($facts !== []) {
            throw new InvalidShowcase('these come from GitHub and cannot be written in the file: ' . implode(', ', $facts));
        }
        if (isset($front['milestone']) && ! $content->hasMilestone($front['milestone'])) {
            throw new InvalidShowcase("the repository has no milestone named \"{$front['milestone']}\"");
        }

        [$body, $images] = MarkdownImages::absolute($file->body, $content->links());

        return self::entry(
            content: $content,
            language: $language,
            title: $front['title'] ?? $content->repository->name,
            summary: $front['summary'] ?? $content->repository->description,
            body: $body === '' ? null : $body,
            fields: array_diff_key($front, array_flip(self::CORE_OR_MEDIA_KEYS)),
            media: [...self::declaredMedia($content, $front), ...self::images($images)],
            references: array_map(
                static fn (string $name): ExternalRef => new ExternalRef('related', type: Project::KEY, slug: $name),
                $front['related'] ?? [],
            ),
        );
    }

    /** No showcase file: the repository's description, the README's first paragraph and first image. */
    public static function fromReadme(RepositoryContent $content): ExternalEntry
    {
        $readme = $content->readme ?? '';
        $description = $content->repository->description;
        $paragraph = Readme::firstParagraph($readme);
        $image = Readme::firstImage($readme);
        $cover = $image === null ? null : $content->links()->absolute($image);
        $claim = $description ?? $paragraph;

        return self::entry(
            content: $content,
            language: self::BASE_LANGUAGE,
            title: $content->repository->name,
            summary: $description,
            body: $paragraph,
            fields: $claim === null ? [] : ['claim' => self::shortened($claim)],
            media: $cover === null ? [] : [new RemoteMedia('cover', $cover, alt: $content->repository->name)],
            references: [],
        );
    }

    /**
     * An entry the contract's rules refuse. The core reports it and keeps what it
     * already has, while leaving it out would read as the project being gone.
     */
    public static function refused(Repository $repository, string $language): ExternalEntry
    {
        return new ExternalEntry(
            externalId: self::externalId($repository->id, $language),
            type: Project::KEY,
            title: '',
            language: $language,
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<RemoteMedia> $media
     * @param list<ExternalRef> $references
     */
    private static function entry(RepositoryContent $content, string $language, string $title, ?string $summary, ?string $body, array $fields, array $media, array $references): ExternalEntry
    {
        $repository = $content->repository;
        [$facts, $factImages] = ProjectFacts::of($content);
        $fields = [...$fields, ...$facts];
        $media = [...$media, ...self::images($factImages)];

        return new ExternalEntry(
            externalId: self::externalId($repository->id, $language),
            type: Project::KEY,
            title: $title,
            summary: $summary,
            body: $body,
            language: $language,
            // Translations are told apart across every source of the same type, not only within this one.
            translationKey: "github:{$repository->id}",
            fields: $fields,
            media: $media,
            references: $references,
            publishedAt: $repository->createdAt,
            updatedAt: $repository->pushedAt,
            canonicalUrl: $repository->url,
            fingerprint: Fingerprint::of(
                $title,
                $summary,
                $body,
                (string) json_encode($fields),
                implode("\n", array_map(static fn (RemoteMedia $m): string => "{$m->field} {$m->url}", $media)),
                implode("\n", array_map(static fn (ExternalRef $r): string => (string) $r->slug, $references)),
            ),
        );
    }

    /**
     * @param array<string, mixed> $front
     * @return list<RemoteMedia>
     */
    private static function declaredMedia(RepositoryContent $content, array $front): array
    {
        $media = [];
        foreach (['cover', 'demo_media'] as $field) {
            if (! isset($front[$field])) {
                continue;
            }
            $url = $content->links()->absolute($front[$field])
                ?? throw new InvalidShowcase("\"{$field}\" is not a relative path inside the repository or an https URL");
            $media[] = new RemoteMedia($field, $url);
        }

        return $media;
    }

    /**
     * @param list<string> $urls
     * @return list<RemoteMedia>
     */
    private static function images(array $urls): array
    {
        return array_map(static fn (string $url): RemoteMedia => new RemoteMedia('images', $url), $urls);
    }

    private static function shortened(string $text): string
    {
        return mb_strlen($text) <= self::CLAIM_LENGTH
            ? $text
            : rtrim(mb_substr($text, 0, self::CLAIM_LENGTH - 1)) . '…';
    }
}
