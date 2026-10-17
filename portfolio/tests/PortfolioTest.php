<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Tests;

use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Portfolio\PortfolioPackage;
use PHPUnit\Framework\TestCase;

final class PortfolioTest extends TestCase
{
    public function test_the_package_conforms(): void
    {
        Conformance::check(new PortfolioPackage());
        $this->addToAssertionCount(1);
    }
}
