<?php

declare(strict_types=1);

namespace Gll\Hub\Github\Tests;

use Gll\Hub\Github\ShowcaseValidator;
use PHPUnit\Framework\TestCase;

final class ShowcaseValidatorTest extends TestCase
{
    private const MAIN = '.github/showcase.md';
    private const ITALIAN = '.github/showcase.it.md';

    /**
     * @param array<string, string> $files
     * @param list<string> $paths what exists in the repository
     * @param list<string> $milestones
     * @return array<string, list<string>>
     */
    private function problems(array $files, array $paths = [], array $milestones = []): array
    {
        return ShowcaseValidator::problems($files, $milestones, static fn (string $path): bool => in_array($path, $paths, true));
    }

    public function test_a_good_file_has_no_problems(): void
    {
        $file = (string) file_get_contents(__DIR__ . '/fixtures/showcase.md');
        $paths = ['docs/cover.png', 'docs/demo.mp4', 'docs/sketch.png'];

        $this->assertSame([], $this->problems([self::MAIN => $file], $paths, ['v1.0']));
    }

    public function test_a_good_translation_has_no_problems(): void
    {
        $this->assertSame([], $this->problems([
            self::MAIN => "---\nclaim: x\n---\nBody.",
            self::ITALIAN => (string) file_get_contents(__DIR__ . '/fixtures/showcase.it.md'),
        ]));
    }

    public function test_nothing_to_validate_has_no_problems(): void
    {
        $this->assertSame([], $this->problems([]));
    }

    public function test_what_the_contract_refuses_is_reported_all_together_and_without_the_entry_id(): void
    {
        $problems = $this->problems([self::MAIN => "---\ndemo: hologram\nnope: 1\n---\n"])[self::MAIN];

        $text = implode("\n", $problems);
        $this->assertStringContainsString('"claim" is missing', $text);
        $this->assertStringContainsString('"nope"', $text);
        $this->assertStringContainsString('hologram', $text);
        $this->assertStringNotContainsString('entry 1', $text);
    }

    public function test_what_the_parser_refuses_is_reported(): void
    {
        $this->assertStringContainsString('front matter', $this->problems([self::MAIN => 'Just text.'])[self::MAIN][0]);
        $this->assertStringContainsString('demo_widget', $this->problems([self::MAIN => "---\nclaim: x\ndemo: widget\n---\n"])[self::MAIN][0]);
    }

    public function test_a_milestone_must_exist(): void
    {
        $file = "---\nclaim: x\nmilestone: v9\n---\n";

        $this->assertStringContainsString('v9', $this->problems([self::MAIN => $file], [], ['v1'])[self::MAIN][0]);
        $this->assertSame([], $this->problems([self::MAIN => $file], [], ['v9']));
    }

    public function test_a_file_named_by_a_relative_link_must_exist(): void
    {
        $file = "---\nclaim: x\ncover: ./docs/a.png\ndemo: image\ndemo_media: docs/b.png\n---\n![c](docs/c%20d.png) ![a again](docs/a.png) ![far](https://example.test/far.png)";

        $problems = $this->problems([self::MAIN => $file], ['docs/b.png'])[self::MAIN];

        $this->assertCount(2, $problems, 'a file named twice is reported once');
        $this->assertStringContainsString('docs/a.png', $problems[0]);
        $this->assertStringContainsString('docs/c d.png', $problems[1]);
        $this->assertSame([], $this->problems([self::MAIN => $file], ['docs/a.png', 'docs/b.png', 'docs/c d.png']));
    }

    public function test_a_translation_answers_for_its_own_links_and_not_for_the_main_file(): void
    {
        $files = [
            self::MAIN => "---\nclaim: x\ncover: docs/missing.png\n---\n",
            self::ITALIAN => "---\nclaim: y\n---\n![i](docs/it.png)",
        ];

        $problems = $this->problems($files);

        $this->assertCount(1, $problems[self::MAIN]);
        $this->assertCount(1, $problems[self::ITALIAN]);
        $this->assertStringContainsString('docs/it.png', $problems[self::ITALIAN][0]);
    }

    public function test_a_translation_that_changes_what_cannot_be_translated_is_reported_on_itself(): void
    {
        $problems = $this->problems([
            self::MAIN => "---\nclaim: x\n---\n",
            self::ITALIAN => "---\ncolor: \"#000000\"\n---\n",
        ]);

        $this->assertArrayNotHasKey(self::MAIN, $problems);
        $this->assertStringContainsString('color', $problems[self::ITALIAN][0]);
    }

    public function test_a_translation_without_the_main_file_is_reported(): void
    {
        $this->assertStringContainsString(self::MAIN, $this->problems([self::ITALIAN => "---\nclaim: y\n---\n"])[self::ITALIAN][0]);
    }

    public function test_the_fixtures_of_the_action_do_what_the_action_expects(): void
    {
        $good = (string) file_get_contents(__DIR__ . '/fixtures/action/good/.github/showcase.md');
        $bad = (string) file_get_contents(__DIR__ . '/fixtures/action/bad/.github/showcase.md');

        $this->assertSame([], $this->problems([self::MAIN => $good], ['docs/cover.png']));
        $this->assertTrue(is_file(__DIR__ . '/fixtures/action/good/docs/cover.png'));
        $this->assertCount(3, $this->problems([self::MAIN => $bad])[self::MAIN]);
    }
}
