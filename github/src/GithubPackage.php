<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;

final class GithubPackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'gllhub/github',
            label: 'GitHub',
            version: '0.1.0',
            contracts: '1.1',
            provides: [
                'source' => [ShowcaseRepositories::class],
                'webhook' => [RepositoryWebhook::class],
            ],
            description: "An account's public repositories with the showcase topic, as projects.",
        );
    }
}
