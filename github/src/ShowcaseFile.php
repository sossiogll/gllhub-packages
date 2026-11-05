<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Portfolio\Types\Project;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A `.github/showcase.md`: YAML front matter, then the free text.
 *
 * Only what the contract's rules cannot see is checked here. A field the type does
 * not have, or a value that does not fit it, is refused by those rules.
 */
final readonly class ShowcaseFile
{
    private const MAX_BYTES = 262144;
    private const FRONT_MATTER = '/\A(?:\xEF\xBB\xBF)?---[ \t]*\R(.*?)^---[ \t]*(?:\R|\z)(.*)\z/ms';
    private const STRINGS = ['title', 'summary', 'cover', 'demo_media', 'demo_widget', 'milestone'];
    private const TRANSLATABLE_CORE_KEYS = ['title', 'summary'];

    /** @param array<string, mixed> $front */
    public function __construct(public array $front, public string $body)
    {
    }

    public static function parse(string $text): self
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidShowcase('the file is larger than ' . self::MAX_BYTES . ' bytes');
        }
        if (preg_match(self::FRONT_MATTER, $text, $parts) !== 1) {
            throw new InvalidShowcase('the file does not start with a front matter between two --- lines');
        }

        try {
            $front = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            throw new InvalidShowcase('the front matter is not valid YAML: ' . $e->getMessage(), 0, $e);
        }
        if (! is_array($front) || array_is_list($front)) {
            throw new InvalidShowcase('the front matter is not a set of named values');
        }

        self::check($front);

        return new self($front, trim($parts[2]));
    }

    /** The file of another language: what it says wins, what it leaves out stays. It says only what can be translated. */
    public function translatedBy(self $translation): self
    {
        $refused = array_diff(array_keys($translation->front), self::translatableKeys());
        if ($refused !== []) {
            throw new InvalidShowcase('a translation cannot change: ' . implode(', ', $refused));
        }

        return new self(
            array_replace($this->front, $translation->front),
            $translation->body === '' ? $this->body : $translation->body,
        );
    }

    /** @return list<string> */
    private static function translatableKeys(): array
    {
        $fields = array_filter(Project::definition()->fields, static fn (Field $f): bool => $f->translatable && ! $f->curated);

        return [...self::TRANSLATABLE_CORE_KEYS, ...array_map(static fn (Field $f): string => $f->key, $fields)];
    }

    /** @param array<string, mixed> $front */
    private static function check(array $front): void
    {
        foreach (self::STRINGS as $key) {
            if (isset($front[$key]) && ! is_string($front[$key])) {
                throw new InvalidShowcase("\"{$key}\" must be text");
            }
        }
        // The colour reaches a stylesheet, and an unquoted # would make it a YAML comment.
        if (array_key_exists('color', $front) && (! is_string($front['color']) || preg_match('/^#[0-9a-f]{6}$/i', $front['color']) !== 1)) {
            throw new InvalidShowcase('"color" must be a quoted hex colour such as "#D4FF3A"');
        }
        if (($front['demo'] ?? null) === 'widget' && ! isset($front['demo_widget'])) {
            throw new InvalidShowcase('"demo: widget" needs "demo_widget", the name of the widget');
        }
        $related = $front['related'] ?? [];
        if (! is_array($related) || ! array_is_list($related) || array_filter($related, is_string(...)) !== $related) {
            throw new InvalidShowcase('"related" must be a list of names');
        }
    }
}
