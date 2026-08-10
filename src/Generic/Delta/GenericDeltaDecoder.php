<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

use Gcf\GcfDecodeException;
use Gcf\Generic\Delta\Internal\DeltaWireFormat;
use Gcf\Scalar;

/**
 * Parses GCF generic-profile delta wire text (SPEC Section 10a.2) back into
 * GenericSet (full) / GenericDeltaPayload (delta). Ported from gcf-python's
 * generic_delta.py: decode_generic_full, decode_generic_delta.
 */
final class GenericDeltaDecoder
{
    private function __construct()
    {
    }

    /**
     * Parses a delta-participating full base payload into (GenericSet, pack_root).
     *
     * @return array{0: GenericSet, 1: string}
     */
    public static function decodeFull(string $text): array
    {
        $lines = explode("\n", rtrim($text, "\n"));
        if ($lines === []) {
            throw new GcfDecodeException('empty payload');
        }
        $hdr = DeltaWireFormat::parseHeaderFields($lines[0]);
        if (($hdr['profile'] ?? null) !== 'generic') {
            throw new GcfDecodeException('not a generic payload');
        }

        $s = new GenericSet(key: $hdr['key'] ?? '', fields: [], rows: []);
        $i = 1;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (!str_starts_with($line, '## ')) {
                // Only blank lines, comments, and the ##! summary trailer are valid
                // outside a section; any other line is a surplus row past a declared
                // section count (SPEC Section 13).
                if ($line === '' || str_starts_with($line, '# ') || str_starts_with($line, '##! ')) {
                    ++$i;
                    continue;
                }
                throw new GcfDecodeException("count_mismatch: unexpected content after declared section rows: '{$line}'");
            }
            [$name, $count, $fields, $keyField] = DeltaWireFormat::parseSectionHeader(substr($line, 3));
            $s->name = $name;
            $s->fields = $fields;
            if ($s->key === '') {
                $s->key = $keyField;
            }
            ++$i;
            for ($j = 0; $j < $count; ++$j) {
                if ($i >= $n || str_starts_with($lines[$i], '## ')) {
                    throw new GcfDecodeException("count_mismatch: declared {$count} rows, got {$j}");
                }
                $s->rows[] = DeltaWireFormat::parseRow($lines[$i], $fields);
                ++$i;
            }
        }

        return [$s, $hdr['pack_root'] ?? ''];
    }

    /**
     * Parses a delta payload (10a.2) into a GenericDeltaPayload for application.
     */
    public static function decodeDelta(string $text): GenericDeltaPayload
    {
        $lines = explode("\n", rtrim($text, "\n"));
        if ($lines === []) {
            throw new GcfDecodeException('empty payload');
        }
        $hdr = DeltaWireFormat::parseHeaderFields($lines[0]);
        if (($hdr['profile'] ?? null) !== 'generic') {
            throw new GcfDecodeException('not a generic payload');
        }
        if (($hdr['delta'] ?? null) !== 'true') {
            throw new GcfDecodeException('not a delta payload');
        }

        $d = new GenericDeltaPayload(
            key: $hdr['key'] ?? '',
            fields: [],
            baseRoot: $hdr['base_root'] ?? '',
            newRoot: $hdr['new_root'] ?? '',
            tool: $hdr['tool'] ?? '',
        );
        $i = 1;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (!str_starts_with($line, '## ')) {
                if ($line === '' || str_starts_with($line, '# ') || str_starts_with($line, '##! ')) {
                    ++$i;
                    continue;
                }
                throw new GcfDecodeException("count_mismatch: unexpected content after declared section rows: '{$line}'");
            }
            [$name, $count, $fields, $keyField] = DeltaWireFormat::parseSectionHeader(substr($line, 3));
            if ($d->key === '' && $keyField !== '') {
                $d->key = $keyField;
            }
            if ($d->fields === [] && ($name === 'added' || $name === 'changed')) {
                $d->fields = $fields;
            }
            ++$i;
            if ($name === 'added' || $name === 'changed') {
                $rows = [];
                for ($j = 0; $j < $count; ++$j) {
                    if ($i >= $n || str_starts_with($lines[$i], '## ')) {
                        throw new GcfDecodeException("count_mismatch: declared {$count} rows in ## {$name}, got {$j}");
                    }
                    $rows[] = DeltaWireFormat::parseRow($lines[$i], $fields);
                    ++$i;
                }
                if ($name === 'added') {
                    $d->added = $rows;
                } else {
                    $d->changed = $rows;
                }
            } elseif ($name === 'removed') {
                for ($j = 0; $j < $count; ++$j) {
                    if ($i >= $n || str_starts_with($lines[$i], '## ')) {
                        throw new GcfDecodeException("count_mismatch: declared {$count} identities in ## removed, got {$j}");
                    }
                    $d->removed[] = Scalar::parseScalar($lines[$i], true);
                    ++$i;
                }
            } else {
                throw new GcfDecodeException("delta_invalid: unknown delta section '{$name}'");
            }
        }

        return $d;
    }
}
