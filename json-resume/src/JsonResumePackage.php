<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;

final class JsonResumePackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'gllhub/json-resume',
            label: 'JSON Resume',
            version: '0.1.0',
            contracts: '1.1',
            provides: [
                'source' => [JsonResumeFile::class],
            ],
            description: 'A CV from a JSON Resume file at a public URL.',
        );
    }
}
