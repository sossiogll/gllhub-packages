<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Portfolio\Types\Resume;

/** One CV per instance, in one language: a second language is a second instance, with its own file. */
final class JsonResumeFile implements Source
{
    public const URL = 'url';
    public const LANGUAGE = 'language';
    public const PDF_URL = 'pdf_url';

    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: 'file',
            label: 'JSON Resume file',
            produces: [Resume::KEY],
            instanceSettings: [
                new Setting(self::URL, 'URL of the file', SettingType::Url, required: true, help: 'A public https address that answers with the JSON.'),
                new Setting(self::LANGUAGE, 'Language of the file', SettingType::String, default: 'en'),
                new Setting(self::PDF_URL, 'URL of a PDF', SettingType::Url, help: 'Optional, and public on the site: check what it contains.'),
            ],
            defaultIntervalMinutes: 1440,
            description: 'Reads only the name, headline, introduction, work and education: contact details in the file never reach the site.',
        );
    }

    public function pull(Context $context): iterable
    {
        $url = self::https($context, self::URL) ?? throw new InvalidConfiguration(self::URL, 'The URL of the file is required.');
        $pdf = self::https($context, self::PDF_URL);
        $language = (string) $context->setting(self::LANGUAGE, 'en');

        $response = $context->http()->get($url, ['accept' => 'application/json']);
        if ($response->status === 404) {
            throw new InvalidConfiguration(self::URL, "There is no file at {$url}.");
        }
        if (! $response->ok()) {
            throw new Unavailable("{$url} answered {$response->status}.");
        }

        $resume = json_decode($response->body, true);
        if (! is_array($resume) || array_is_list($resume)) {
            throw new InvalidConfiguration(self::URL, 'The file is not a JSON Resume document.');
        }

        try {
            yield ResumeEntry::from($resume, $language, $pdf, $context->now());
        } catch (InvalidResume $e) {
            $context->logger()->warning('Resume refused', ['reason' => $e->getMessage()]);

            yield ResumeEntry::refused($language, $e->getMessage());
        }
    }

    private static function https(Context $context, string $setting): ?string
    {
        $url = trim((string) $context->setting($setting, ''));
        if ($url === '') {
            return null;
        }
        if (! str_starts_with($url, 'https://')) {
            throw new InvalidConfiguration($setting, 'The address must be https.');
        }

        return $url;
    }
}
