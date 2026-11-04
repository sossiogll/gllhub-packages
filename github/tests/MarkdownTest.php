<?php

declare(strict_types=1);

namespace Gll\Hub\Github\Tests;

use Gll\Hub\Github\MarkdownImages;
use Gll\Hub\Github\RawLinks;
use Gll\Hub\Github\Readme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private const RAW = 'https://raw.githubusercontent.com/example/pointer/v1';

    /** @return array<string, array{string, ?string}> */
    public static function links(): array
    {
        return [
            'dot' => ['./docs/a.png', self::RAW . '/docs/a.png'],
            'bare' => ['docs/a.png', self::RAW . '/docs/a.png'],
            'from the root' => ['/docs/a.png', self::RAW . '/docs/a.png'],
            'query dropped' => ['docs/a.png?raw=true', self::RAW . '/docs/a.png'],
            'encoded' => ['docs/a b.png', self::RAW . '/docs/a%20b.png'],
            'https stays' => ['https://example.test/a.png', 'https://example.test/a.png'],
            'climbing out' => ['../a.png', null],
            'climbing inside' => ['docs/../../a.png', null],
            'http' => ['http://example.test/a.png', null],
            'protocol relative' => ['//example.test/a.png', null],
            'data' => ['data:image/png;base64,AAAA', null],
            'anchor' => ['#top', null],
            'nothing' => ['./', null],
        ];
    }

    #[DataProvider('links')]
    public function test_a_link_becomes_absolute_or_nothing(string $link, ?string $expected): void
    {
        $this->assertSame($expected, (new RawLinks('example/pointer', 'v1'))->absolute($link));
    }

    public function test_a_ref_with_a_slash_keeps_it(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/example/pointer/release/1.0/a.png',
            (new RawLinks('example/pointer', 'release/1.0'))->absolute('a.png'),
        );
    }

    public function test_only_relative_images_are_rewritten_and_named(): void
    {
        $markdown = '![a](a.png) ![b](https://example.test/b.png) <img alt="c" src="c.png"> ![a again](a.png) ![d](../d.png)';

        [$text, $found] = MarkdownImages::absolute($markdown, new RawLinks('example/pointer', 'v1'));

        $this->assertSame(
            '![a](' . self::RAW . '/a.png) ![b](https://example.test/b.png) <img alt="c" src="' . self::RAW . '/c.png"> ![a again](' . self::RAW . '/a.png) ![d](../d.png)',
            $text,
        );
        $this->assertSame([self::RAW . '/a.png', self::RAW . '/c.png'], $found);
    }

    public function test_the_first_paragraph_is_the_first_prose(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/fixtures/README.md');

        $this->assertSame('A small library that moves a pointer around.', Readme::firstParagraph($readme));
    }

    public function test_an_underlined_heading_is_not_prose(): void
    {
        $this->assertSame('Prose.', Readme::firstParagraph("Title\n=====\n\nProse."));
    }

    public function test_a_readme_without_prose_has_no_paragraph(): void
    {
        $this->assertNull(Readme::firstParagraph("# Title\n\n- item\n"));
        $this->assertNull(Readme::firstParagraph(''));
    }

    public function test_the_first_image_skips_badges(): void
    {
        $readme = "[![b](https://img.shields.io/x.svg)](y)\n![c](https://example.test/ci/badge.svg)\n![shot](docs/shot.png)";

        $this->assertSame('docs/shot.png', Readme::firstImage($readme));
    }

    public function test_a_readme_with_only_badges_has_no_image(): void
    {
        $this->assertNull(Readme::firstImage('![c](https://example.test/ci/badge.svg)'));
    }
}
