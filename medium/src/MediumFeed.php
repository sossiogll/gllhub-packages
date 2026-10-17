<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Portfolio\Types\Article;

/**
 * An author's feed. Medium serves only the latest ten articles in it, so the
 * source does not list everything: the core keeps what it already has and never
 * removes an article because it left the feed.
 */
final class MediumFeed implements Source
{
    public const USERNAME = 'username';
    public const LANGUAGE = 'language';

    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: 'feed',
            label: 'Medium author feed',
            produces: [Article::KEY],
            instanceSettings: [
                new Setting(self::USERNAME, 'Medium username', SettingType::String, required: true, help: 'With or without the @, e.g. @sossiogll.'),
                new Setting(self::LANGUAGE, 'Language of the articles', SettingType::Enum, default: 'en', options: ['en', 'it']),
            ],
            defaultIntervalMinutes: 60,
            listsEverything: false,
            description: 'Member-only articles arrive as the preview Medium puts in the feed.',
        );
    }

    public function pull(Context $context): iterable
    {
        $url = self::feedUrl((string) $context->setting(self::USERNAME, ''));
        $response = $context->http()->get($url, ['accept' => 'application/rss+xml, application/xml']);

        if ($response->status === 404) {
            throw new InvalidConfiguration(self::USERNAME, "Medium has no feed at {$url}.");
        }
        if (! $response->ok()) {
            throw new Unavailable("Medium answered {$response->status} for {$url}.");
        }

        $language = (string) $context->setting(self::LANGUAGE, 'en');

        foreach (FeedParser::parse($response->body) as $item) {
            yield ArticleEntry::from($item, $language);
        }
    }

    /** The feed of a username, accepted with or without its "@". */
    public static function feedUrl(string $username): string
    {
        $name = ltrim(trim($username), '@');

        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
            throw new InvalidConfiguration(self::USERNAME, 'A Medium username has letters, digits, dots, dashes and underscores only.');
        }

        return "https://medium.com/feed/@{$name}";
    }
}
