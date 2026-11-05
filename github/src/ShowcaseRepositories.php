<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Exceptions\InvalidConfiguration;
use Gll\Hub\Contracts\Package\Capability;
use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\FetchesSingleEntry;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Portfolio\Types\Project;

/**
 * An account's public repositories with the showcase topic. The listing is
 * complete, so a repository that loses the topic or goes private leaves the site.
 */
final class ShowcaseRepositories implements Source, FetchesSingleEntry
{
    public const KEY = 'repositories';
    public const ACCOUNT = 'account';
    public const TOKEN = 'token';

    /** The languages a repository may add with a `showcase.<language>.md`. */
    public const TRANSLATIONS = ['it'];

    private const SHOWCASE = '.github/showcase.md';

    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: self::KEY,
            label: 'GitHub showcase repositories',
            produces: [Project::KEY],
            capabilities: [Capability::SingleFetch],
            instanceSettings: [
                new Setting(self::ACCOUNT, 'GitHub account', SettingType::String, required: true, help: 'Exactly as GitHub writes it: webhooks find this source by that name.'),
            ],
            instanceCredentials: [
                new Credential(self::TOKEN, 'GitHub token', help: 'Read access to public repositories and to Projects (read:project).'),
            ],
            defaultIntervalMinutes: 360,
            description: 'A public repository with the showcase topic becomes a project, from its .github/showcase.md or, without one, its description and README.',
        );
    }

    public function pull(Context $context): iterable
    {
        $api = GithubApi::for($context, self::TOKEN);
        $account = self::account($context);

        foreach ($api->repositoriesOf($account, self::ACCOUNT) as $repository) {
            if ($repository->isShowcaseOf($account)) {
                yield from $this->entries($context, $api, $repository);
            }
        }
    }

    public function fetch(Context $context, string $externalId): ?ExternalEntry
    {
        if (preg_match('/^(\d+)(?::[a-z]{2})?$/', $externalId, $id) !== 1) {
            return null;
        }

        $api = GithubApi::for($context, self::TOKEN);
        $repository = $api->repository((int) $id[1]);
        if ($repository === null || ! $repository->isShowcaseOf(self::account($context))) {
            return null;
        }

        foreach ($this->entries($context, $api, $repository) as $entry) {
            if ($entry->externalId === $externalId) {
                return $entry;
            }
        }

        return null;
    }

    /** @return iterable<ExternalEntry> */
    private function entries(Context $context, GithubApi $api, Repository $repository): iterable
    {
        $content = RepositoryContent::read($api, $repository);
        $showcase = $api->file($repository, self::SHOWCASE, $content->ref);

        if ($showcase === null) {
            yield ProjectEntry::fromReadme($content);

            return;
        }

        yield $this->fromFiles($context, $content, ProjectEntry::BASE_LANGUAGE, $showcase);

        foreach (self::TRANSLATIONS as $language) {
            $translation = $api->file($repository, ".github/showcase.{$language}.md", $content->ref);
            if ($translation !== null) {
                yield $this->fromFiles($context, $content, $language, $showcase, $translation);
            }
        }
    }

    private function fromFiles(Context $context, RepositoryContent $content, string $language, string $showcase, ?string $translation = null): ExternalEntry
    {
        try {
            $file = ShowcaseFile::parse($showcase);
            if ($translation !== null) {
                $file = $file->translatedBy(ShowcaseFile::parse($translation));
            }

            return ProjectEntry::fromShowcase($content, $file, $language);
        } catch (InvalidShowcase $e) {
            $context->logger()->warning('Showcase file refused', [
                'repository' => $content->repository->fullName(),
                'language' => $language,
                'reason' => $e->getMessage(),
            ]);

            return ProjectEntry::refused($content->repository, $language);
        }
    }

    private static function account(Context $context): string
    {
        $account = trim((string) $context->setting(self::ACCOUNT, ''));
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $account) !== 1) {
            throw new InvalidConfiguration(self::ACCOUNT, 'A GitHub account has letters, digits and dashes only.');
        }

        return $account;
    }
}
