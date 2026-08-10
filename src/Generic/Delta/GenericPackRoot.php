<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

use Gcf\Generic\Delta\Internal\CanonicalCell;

/**
 * Canonical pack root for a keyed generic-profile set (gcf-pack-root-v1, generic
 * profile, SPEC Section 10a.3). Ported from gcf-python's generic_delta.py:
 * generic_pack_root.
 *
 * NOT the same algorithm as the graph-profile PackRoot::compute() — this hashes
 * keyed tab-separated field/value records rather than symbol/edge records.
 *
 * Records and fields are sorted by unsigned UTF-8 byte order so every SDK produces
 * an identical hash regardless of row order.
 */
final class GenericPackRoot
{
    private function __construct()
    {
    }

    public static function compute(GenericSet $s): string
    {
        $sortedFields = $s->fields;
        usort($sortedFields, strcmp(...));

        $records = [];
        foreach ($s->rows as $row) {
            $parts = ['R'];
            foreach ($sortedFields as $f) {
                $parts[] = $f;
                $parts[] = CanonicalCell::of($row[$f] ?? null);
            }
            $records[] = implode("\t", $parts)."\n";
        }
        // strcmp is a byte-wise unsigned comparison, matching Python's sort-by-utf8-bytes key.
        usort($records, strcmp(...));

        $canonical = implode('', $records);

        return 'sha256:'.hash('sha256', $canonical);
    }
}
