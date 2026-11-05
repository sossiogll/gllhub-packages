<?php

declare(strict_types=1);

namespace Gll\Hub\Github\Tests;

use Gll\Hub\Contracts\Testing\StaticContext;
use Gll\Hub\Contracts\Webhook\IncomingWebhook;
use Gll\Hub\Contracts\Webhook\SyncRequest;
use Gll\Hub\Github\RepositoryWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryWebhookTest extends TestCase
{
    private const SECRET = 'a-secret';

    public function test_a_signed_call_is_accepted(): void
    {
        $this->assertTrue($this->verify($this->call('push', ['a' => 1])));
    }

    public function test_a_changed_body_is_refused(): void
    {
        $signed = $this->call('push', ['a' => 1]);
        $tampered = new IncomingWebhook('{"a":2}', $signed->headers);

        $this->assertFalse($this->verify($tampered));
    }

    public function test_a_call_without_a_signature_is_refused(): void
    {
        $this->assertFalse($this->verify(new IncomingWebhook('{}', ['x-github-event' => 'push'])));
    }

    public function test_another_secret_is_refused(): void
    {
        $this->assertFalse($this->verify($this->call('push', ['a' => 1], secret: 'another')));
    }

    public function test_without_a_configured_secret_nothing_is_accepted(): void
    {
        $call = $this->call('push', ['a' => 1], secret: '');
        $webhook = new RepositoryWebhook();

        $this->assertFalse($webhook->verify(new StaticContext(), $call));
        $this->assertFalse($webhook->verify(new StaticContext(credentials: [RepositoryWebhook::SECRET => '']), $call));
    }

    /** @return array<string, array{string}> */
    public static function events(): array
    {
        return ['a release' => ['release'], 'a milestone' => ['milestone'], 'a discussion' => ['discussion'], 'a comment' => ['discussion_comment']];
    }

    #[DataProvider('events')]
    public function test_an_event_names_the_repository_and_its_translations(string $event): void
    {
        [$request, $more] = $this->handle($event, $this->payload()) + [1 => null];

        $this->assertNull($more);
        $this->assertSame('repositories', $request->source);
        $this->assertSame(['account' => 'example'], $request->instance);
        $this->assertSame(['101', '101:it'], $request->externalIds);
    }

    public function test_a_push_to_the_default_branch_counts(): void
    {
        $this->assertCount(1, $this->handle('push', ['ref' => 'refs/heads/trunk'] + $this->payload()));
    }

    public function test_a_push_elsewhere_is_ignored(): void
    {
        $this->assertSame([], $this->handle('push', ['ref' => 'refs/heads/other'] + $this->payload()));
        $this->assertSame([], $this->handle('push', ['ref' => 'refs/tags/trunk'] + $this->payload()));
    }

    public function test_issues_and_pull_requests_count_only_when_their_milestone_changes(): void
    {
        foreach (['issues', 'pull_request'] as $event) {
            $this->assertCount(1, $this->handle($event, ['action' => 'milestoned'] + $this->payload()));
            $this->assertCount(1, $this->handle($event, ['action' => 'demilestoned'] + $this->payload()));
            $this->assertSame([], $this->handle($event, ['action' => 'labeled'] + $this->payload()));
        }
    }

    public function test_other_events_are_ignored(): void
    {
        $this->assertSame([], $this->handle('ping', $this->payload()));
        $this->assertSame([], $this->handle('issues', $this->payload()));
    }

    public function test_a_payload_without_its_repository_asks_for_nothing(): void
    {
        $this->assertSame([], $this->handle('release', []));
        $this->assertSame([], $this->handle('release', ['repository' => ['id' => 'x', 'owner' => ['login' => 'example']]]));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['repository' => ['id' => 101, 'default_branch' => 'trunk', 'owner' => ['login' => 'example']]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<SyncRequest>
     */
    private function handle(string $event, array $payload): array
    {
        return (new RepositoryWebhook())->handle(new StaticContext(), $this->call($event, $payload));
    }

    private function verify(IncomingWebhook $call): bool
    {
        return (new RepositoryWebhook())->verify(new StaticContext(credentials: [RepositoryWebhook::SECRET => self::SECRET]), $call);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $event, array $payload, string $secret = self::SECRET): IncomingWebhook
    {
        $body = (string) json_encode($payload);

        return new IncomingWebhook($body, [
            'x-github-event' => $event,
            'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
        ]);
    }
}
