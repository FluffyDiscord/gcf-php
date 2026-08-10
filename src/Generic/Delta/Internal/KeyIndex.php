<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta\Internal;

use Gcf\GcfDecodeException;
use Gcf\Generic\Delta\GenericSet;

/**
 * Indexes a GenericSet's rows by canonical identity value. Shared by GenericDiff
 * and GenericDeltaVerifier. Ported from gcf-python's generic_delta.py: _index_by_key.
 */
final class KeyIndex
{
    private function __construct()
    {
    }

    /**
     * @return array<string, array<string, mixed>> canonical identity => row
     */
    public static function build(GenericSet $s): array
    {
        $m = [];
        foreach ($s->rows as $row) {
            $ident = CanonicalCell::of($row[$s->key] ?? null);
            if (array_key_exists($ident, $m)) {
                throw new GcfDecodeException("delta_invalid: duplicate identity {$ident} for key '{$s->key}'");
            }
            $m[$ident] = $row;
        }

        return $m;
    }
}
