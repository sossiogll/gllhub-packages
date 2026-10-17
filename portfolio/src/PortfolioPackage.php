<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio;

use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;
use Gll\Hub\Portfolio\Types\Article;
use Gll\Hub\Portfolio\Types\Idea;
use Gll\Hub\Portfolio\Types\Page;
use Gll\Hub\Portfolio\Types\Project;

final class PortfolioPackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'gllhub/portfolio',
            label: 'Portfolio',
            version: '0.1.0',
            contracts: '1.0',
            provides: [
                'content_type' => [Project::class, Idea::class, Article::class, Page::class],
            ],
            description: 'Projects, ideas, articles and pages for a personal site.',
        );
    }
}
