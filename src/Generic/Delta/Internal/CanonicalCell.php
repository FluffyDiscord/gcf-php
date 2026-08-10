<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta\Internal;

use Gcf\Scalar;

/**
 * Canonicalizes one cell value for the pack-root record and for identity/equality
 * comparisons (SPEC Section 10a.3). Ported from gcf-python's generic_delta.py:
 * _canonical_cell.
 *
 * Decoupled from the wire cell encoder (Scalar::formatScalar): collision-free and
 * record-safe, not round-trippable. Typed literals stay bare (null is `-`, booleans
 * true/false, numbers canonical); strings are ALWAYS quoted so they cannot collide
 * with a typed literal and any tab/newline inside is escaped.
 */
final class CanonicalCell
{
    private function __construct()
    {
    }

    public static function of(mixed $v): string
    {
        if ($v === null) {
            return '-';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return Scalar::formatNumber((float) $v);
        }
        if (is_string($v)) {
            return Scalar::quoteString($v);
        }

        return Scalar::quoteString((string) $v);
    }
}
