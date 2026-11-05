<?php

declare(strict_types=1);

namespace Gll\Hub\JsonResume;

use RuntimeException;

/** A document that cannot be accepted. Never leaves the package: see JsonResumeFile. */
final class InvalidResume extends RuntimeException
{
}
