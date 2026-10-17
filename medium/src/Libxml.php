<?php

declare(strict_types=1);

namespace Gll\Hub\Medium;

/** Runs libxml without letting its warnings escape, and restores the previous mode. */
final class Libxml
{
    /**
     * @template T
     * @param callable(): T $parse
     * @return T
     */
    public static function quietly(callable $parse): mixed
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return $parse();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
