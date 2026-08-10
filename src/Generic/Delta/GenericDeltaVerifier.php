<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

use Gcf\GcfDecodeException;
use Gcf\Generic\Delta\Internal\CanonicalCell;
use Gcf\Generic\Delta\Internal\KeyIndex;

/**
 * Applies a delta to a base set and verifies the result hashes to the expected
 * new root (SPEC Section 10a.5). Ported from gcf-python's generic_delta.py:
 * verify_generic_delta.
 *
 * Atomic: the whole payload is validated before any mutation; on failure the
 * base is left untouched (nothing is mutated in place; a fresh GenericSet is
 * only constructed after validation passes) and a GcfDecodeException is thrown.
 */
final class GenericDeltaVerifier
{
    private function __construct()
    {
    }

    public static function verify(GenericSet $base, GenericDeltaPayload $d, string $expectedNewRoot): GenericSet
    {
        if (GenericPackRoot::compute($base) !== $d->baseRoot) {
            throw new GcfDecodeException('base_mismatch: base root does not equal delta base_root');
        }
        $baseById = KeyIndex::build($base);

        foreach ($d->removed as $idv) {
            $ident = CanonicalCell::of($idv);
            if (!array_key_exists($ident, $baseById)) {
                throw new GcfDecodeException("delta_invalid: removing identity {$ident} not in base");
            }
        }
        foreach ($d->added as $row) {
            $ident = CanonicalCell::of($row[$d->key] ?? null);
            if (array_key_exists($ident, $baseById)) {
                throw new GcfDecodeException("delta_invalid: adding identity {$ident} that already exists");
            }
        }
        foreach ($d->changed as $row) {
            $ident = CanonicalCell::of($row[$d->key] ?? null);
            if (!array_key_exists($ident, $baseById)) {
                throw new GcfDecodeException("delta_invalid: changing identity {$ident} not in base");
            }
        }

        $work = $baseById;
        foreach ($d->removed as $idv) {
            unset($work[CanonicalCell::of($idv)]);
        }
        foreach ($d->added as $row) {
            $work[CanonicalCell::of($row[$d->key] ?? null)] = $row;
        }
        foreach ($d->changed as $row) {
            $work[CanonicalCell::of($row[$d->key] ?? null)] = $row;
        }

        $result = new GenericSet(key: $base->key, fields: $base->fields, rows: array_values($work), name: $base->name);
        $got = GenericPackRoot::compute($result);
        if ($got !== $expectedNewRoot) {
            throw new GcfDecodeException("root_mismatch: computed {$got}, expected {$expectedNewRoot}");
        }

        return $result;
    }
}
