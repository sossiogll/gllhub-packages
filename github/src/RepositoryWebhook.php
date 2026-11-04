<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Webhook\IncomingWebhook;
use Gll\Hub\Contracts\Webhook\SyncRequest;
use Gll\Hub\Contracts\Webhook\WebhookDefinition;
use Gll\Hub\Contracts\Webhook\WebhookReceiver;

/** A repository's signed webhook. It says which project may have changed, never what changed in it. */
final class RepositoryWebhook implements WebhookReceiver
{
    public const SECRET = 'secret';

    private const EVENTS = ['push', 'release', 'milestone'];

    public static function definition(): WebhookDefinition
    {
        return new WebhookDefinition(
            key: 'repository',
            label: 'GitHub repository events',
            refreshes: [ShowcaseRepositories::KEY],
            credentials: [
                new Credential(self::SECRET, 'Webhook secret', help: 'The secret set on each repository\'s webhook, content type application/json.'),
            ],
            description: 'Refreshes a project on a push to the default branch, a release or a milestone.',
        );
    }

    public function verify(Context $context, IncomingWebhook $webhook): bool
    {
        $secret = (string) $context->credential(self::SECRET);
        $signature = $webhook->header('x-hub-signature-256');

        // An empty key would let anyone compute the signature.
        return $secret !== ''
            && $signature !== null
            && hash_equals('sha256=' . hash_hmac('sha256', $webhook->body, $secret), $signature);
    }

    public function handle(Context $context, IncomingWebhook $webhook): array
    {
        if (! in_array($webhook->header('x-github-event'), self::EVENTS, true)) {
            return [];
        }

        $payload = $webhook->json();
        $id = $payload['repository']['id'] ?? null;
        $owner = $payload['repository']['owner']['login'] ?? null;
        if (! is_int($id) || ! is_string($owner)) {
            return [];
        }
        if ($webhook->header('x-github-event') === 'push' && ! self::isDefaultBranch($payload)) {
            return [];
        }

        $languages = [ProjectEntry::BASE_LANGUAGE, ...ShowcaseRepositories::TRANSLATIONS];

        return [new SyncRequest(
            ShowcaseRepositories::KEY,
            [ShowcaseRepositories::ACCOUNT => $owner],
            array_map(static fn (string $language): string => ProjectEntry::externalId($id, $language), $languages),
        )];
    }

    /** @param array<mixed> $payload */
    private static function isDefaultBranch(array $payload): bool
    {
        $branch = $payload['repository']['default_branch'] ?? null;

        return is_string($branch) && ($payload['ref'] ?? null) === "refs/heads/{$branch}";
    }
}
