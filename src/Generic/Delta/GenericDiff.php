<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

use Gcf\GcfDecodeException;
use Gcf\Generic\Delta\Internal\CanonicalCell;
use Gcf\Generic\Delta\Internal\KeyIndex;

/**
 * Computes the delta from a base GenericSet to a next GenericSet: the blessed
 * producer path (SPEC Section 10a). Ported from gcf-python's generic_delta.py:
 * diff_generic_sets.
 *
 * Centralizes the keyed-diff invariants: identity uniqueness, added-not-in-base,
 * changed-must-exist, whole-row replacement, unchanged rows omitted. Output is
 * sorted by identity for reproducibility (10a.6). A schema change or a missing
 * key raises (caller must send full, 10a.7).
 */
final class GenericDiff
{
    private function __construct()
    {
    }

    public static function diff(GenericSet $base, GenericSet $next): GenericDeltaPayload
    {
        if ($next->key === '') {
            throw new GcfDecodeException('delta_invalid: no identity key');
        }
        if ($next->key !== $base->key || $base->fields !== $next->fields) {
            throw new GcfDecodeException('delta_invalid: schema change (send full)');
        }

        $baseById = KeyIndex::build($base);
        $nextById = KeyIndex::build($next);

        $added = [];
        $changed = [];
        $removed = [];

        foreach ($nextById as $ident => $row) {
            $brow = $baseById[$ident] ?? null;
            if ($brow === null) {
                $added[] = $row;
            } elseif (!self::rowsEqual($brow, $row, $next->fields)) {
                $changed[] = $row;
            }
            // equal rows are omitted (silence = "keep it", 10a.5)
        }
        foreach ($baseById as $ident => $brow) {
            if (!array_key_exists($ident, $nextById)) {
                $removed[] = $brow[$next->key] ?? null;
            }
        }

        $byIdentity = static fn (array $a, array $b): int => strcmp(
            CanonicalCell::of($a[$next->key] ?? null),
            CanonicalCell::of($b[$next->key] ?? null),
        );
        usort($added, $byIdentity);
        usort($changed, $byIdentity);
        usort($removed, static fn (mixed $a, mixed $b): int => strcmp(CanonicalCell::of($a), CanonicalCell::of($b)));

        return new GenericDeltaPayload(
            key: $next->key,
            fields: $next->fields,
            baseRoot: GenericPackRoot::compute($base),
            newRoot: GenericPackRoot::compute($next),
            added: $added,
            changed: $changed,
            removed: $removed,
        );
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param list<string> $fields
     */
    private static function rowsEqual(array $a, array $b, array $fields): bool
    {
        foreach ($fields as $f) {
            if (CanonicalCell::of($a[$f] ?? null) !== CanonicalCell::of($b[$f] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
