<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;

final class MediumPackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'gllhub/medium',
            label: 'Medium',
            version: '0.1.0',
            contracts: '1.0',
            provides: [
                'source' => [MediumFeed::class],
            ],
            description: "An author's latest Medium articles, from their RSS feed.",
        );
    }
}
