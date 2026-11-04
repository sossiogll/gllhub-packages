<?php

declare(strict_types=1);

namespace Gll\Hub\Github;

use RuntimeException;

/** A showcase file that cannot be read. Never leaves the package: see ShowcaseRepositories. */
final class InvalidShowcase extends RuntimeException
{
}
