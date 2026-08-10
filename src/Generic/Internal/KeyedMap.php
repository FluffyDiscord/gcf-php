<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

use Gcf\GcfDecodeException;

/**
 * GCF keyed-tabular map encoding (SPEC 7.2a). Ported from gcf-python's keyed_map.py.
 *
 * A JSON object whose values are all objects forming a losslessly-tabular set is
 * encoded as a keyed table `## [N:]{key,...}`: the shared value fields are declared
 * once in a header and each member is one positional row prefixed by its key. This
 * is the object-valued analogue of Section 7.4 tabular array encoding. It is
 * canonical (default-on): eligible maps always encode as keyed tables, with no
 * option to disable.
 */
final class KeyedMap
{
    private function __construct()
    {
    }

    /**
     * Reports whether an object is a keyed map of objects that should render as a
     * keyed table `## [N:]{key,...}` (SPEC 7.2a.1). Returns the ordered member keys,
     * the corresponding value objects, the ordered value-field union, and the
     * key-column label, or null when the object is not eligible.
     *
     * @return array{keys: list<string>, values: list<mixed>, valueFields: list<string>, keyLabel: string}|null
     */
    public static function eligible(mixed $m): ?array
    {
        if (!Value::isObject($m)) {
            return null;
        }

        $assoc = Value::toAssoc($m);
        // PHP normalizes numeric-looking array/property keys ("123") to int; cast back
        // to string since GCF member and field names are always strings (SPEC keys are
        // JSON object keys, never numbers).
        $keys = array_map(strval(...), array_keys($assoc));
        $values = array_values($assoc);

        // A keyed map requires at least two members: the form factors the shared value
        // fields into one header, which only pays off across multiple members. A
        // single-member map yields a one-row table the same size as a section, so keying
        // it would change canonical output for every nested single-member object (e.g.
        // `{"data": {...}}` wrappers) with no benefit. Single-member objects use ordinary
        // encoding; a single-key wrapper of a multi-member map therefore defers, and the
        // inner map is keyed at its own level (SPEC 7.2a.1).
        if (count($keys) < 2) {
            return null;
        }

        // Every value must be an object; build the ordered field union.
        $seen = [];
        $valueFields = [];
        foreach ($values as $v) {
            if (!Value::isObject($v)) {
                return null; // non-object value
            }
            foreach (array_keys(Value::toAssoc($v)) as $f) {
                $f = (string) $f;
                if (!isset($seen[$f])) {
                    $seen[$f] = true;
                    $valueFields[] = $f;
                }
            }
        }
        if ($valueFields === []) {
            return null; // all-empty value objects
        }

        // A keyed header needs at least one value field that can be a tabular column.
        // A field name containing ">" cannot be a column (SPEC 7.4.6.1.4); if every
        // value field contains ">", the keyed form would have only the key column,
        // which is invalid. Such a map uses Section 7.2 section encoding instead, the
        // object analogue of an array falling back to expanded form.
        $anyPlain = false;
        foreach ($valueFields as $f) {
            if (!str_contains($f, '>')) {
                $anyPlain = true;
                break;
            }
        }
        if (!$anyPlain) {
            return null;
        }

        // Key-column label: "key", made unique by prepending "_" on collision.
        $keyLabel = 'key';
        while (isset($seen[$keyLabel])) {
            $keyLabel = '_'.$keyLabel;
        }

        return ['keys' => $keys, 'values' => $values, 'valueFields' => $valueFields, 'keyLabel' => $keyLabel];
    }

    /**
     * Reconstructs the map from decoded keyed-table rows: the first declared field
     * is the member key; the remaining fields form the value object (SPEC 7.2a.4).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function rowsToMap(array $rows, array $fields): array
    {
        if (count($fields) < 2) {
            throw new GcfDecodeException('keyed_map: header must declare at least two fields');
        }
        $keyLabel = $fields[0];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                throw new GcfDecodeException('keyed_map: row is not an object');
            }
            if (!array_key_exists($keyLabel, $r)) {
                throw new GcfDecodeException("keyed_map: row missing key column '{$keyLabel}'");
            }
            $kv = $r[$keyLabel];
            $ks = is_string($kv) ? $kv : self::stringify($kv);
            if (array_key_exists($ks, $out)) {
                throw new GcfDecodeException("keyed_map: duplicate member key '{$ks}'");
            }
            $value = $r;
            unset($value[$keyLabel]);
            $out[$ks] = $value;
        }

        return $out;
    }

    private static function stringify(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'True' : 'False',
            default => (string) $v,
        };
    }
}
