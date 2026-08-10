<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

use Gcf\Scalar;

/**
 * Serializes GenericSet (full) and GenericDeltaPayload (delta) to GCF generic-profile
 * delta wire text (SPEC Section 10a.2). Ported from gcf-python's generic_delta.py:
 * encode_generic_full, encode_generic_delta.
 */
final class GenericDeltaEncoder
{
    private function __construct()
    {
    }

    /**
     * Emits a delta-participating full base payload: a `pack_root=`/`key=` header
     * followed by one tabular section carrying every row.
     */
    public static function encodeFull(GenericSet $s, string $tool = ''): string
    {
        $name = $s->name !== '' ? $s->name : 'rows';
        $header = 'GCF profile=generic';
        if ($tool !== '') {
            $header .= " tool={$tool}";
        }
        $header .= ' pack_root='.GenericPackRoot::compute($s)." key={$s->key}";

        $lines = [$header, '## '.$name.' ['.count($s->rows).']{'.self::fieldDecl($s->fields, $s->key).'}'];
        foreach ($s->rows as $row) {
            $lines[] = self::encodeRow($row, $s->fields);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Serializes a delta payload (10a.2). Sections are ordered added/changed/removed;
     * a section with zero rows is omitted entirely (10a.2).
     */
    public static function encodeDelta(GenericDeltaPayload $d): string
    {
        $header = 'GCF profile=generic';
        if ($d->tool !== '') {
            $header .= " tool={$d->tool}";
        }
        $header .= " delta=true base_root={$d->baseRoot} new_root={$d->newRoot} key={$d->key}";
        if ($d->fullTokens > 0) {
            $savings = 100.0 * (1.0 - $d->deltaTokens / $d->fullTokens);
            $header .= ' savings='.sprintf('%.0f', $savings).'%';
        }

        $lines = [$header];
        if ($d->added !== []) {
            $lines[] = '## added ['.count($d->added).']{'.self::fieldDecl($d->fields, $d->key).'}';
            foreach ($d->added as $row) {
                $lines[] = self::encodeRow($row, $d->fields);
            }
        }
        if ($d->changed !== []) {
            $lines[] = '## changed ['.count($d->changed).']{'.self::fieldDecl($d->fields, $d->key).'}';
            foreach ($d->changed as $row) {
                $lines[] = self::encodeRow($row, $d->fields);
            }
        }
        if ($d->removed !== []) {
            $lines[] = '## removed ['.count($d->removed)."]{@{$d->key}}";
            foreach ($d->removed as $idv) {
                $lines[] = Scalar::formatScalar($idv, '|');
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<string> $fields
     */
    private static function fieldDecl(array $fields, string $key): string
    {
        return implode(',', array_map(
            static fn (string $f): string => $f === $key ? '@'.Scalar::formatKey($f) : Scalar::formatKey($f),
            $fields,
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function encodeRow(array $row, array $fields): string
    {
        return implode('|', array_map(
            static fn (string $f): string => Scalar::formatScalar($row[$f] ?? null, '|'),
            $fields,
        ));
    }
}
