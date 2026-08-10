<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

use Gcf\Scalar;

/**
 * GCF generic-profile encoder engine: serializes arbitrary PHP values into GCF
 * generic profile text. Ported from gcf-python's generic.py.
 *
 * Flattening of fixed-shape nested objects into path columns (SPEC 7.4.6.1) is
 * always enabled — the Python reference's `no_flatten` opt-out isn't exposed
 * by the target public API, so this port has no equivalent toggle.
 */
final class Writer
{
    private function __construct()
    {
    }

    public static function encode(mixed $data): string
    {
        $out = ['GCF profile=generic'];
        self::encodeRootValue($data, $out);

        return implode("\n", $out)."\n";
    }

    private static function encodeRootValue(mixed $v, array &$out): void
    {
        $c = Value::classify($v);
        if ($c === Value::NULL) {
            $out[] = '=-';
        } elseif ($c === Value::OBJECT) {
            $km = KeyedMap::eligible($v);
            if ($km !== null) {
                self::encodeKeyedMap('', false, $km['keys'], $km['values'], $km['valueFields'], $km['keyLabel'], $out, 0);

                return;
            }
            self::encodeObject($v, $out, 0);
        } elseif ($c === Value::ARR) {
            self::encodeRootArray(Value::toList($v), $out);
        } else {
            $out[] = '='.Scalar::formatScalar($v);
        }
    }

    private static function encodeObject(mixed $d, array &$out, int $depth): void
    {
        $prefix = Value::indent($depth);
        foreach (Value::toAssoc($d) as $key => $value) {
            $fk = Scalar::formatKey((string) $key);
            $c = Value::classify($value);
            if ($c === Value::OBJECT) {
                $km = KeyedMap::eligible($value);
                if ($km !== null) {
                    self::encodeKeyedMap((string) $key, true, $km['keys'], $km['values'], $km['valueFields'], $km['keyLabel'], $out, $depth);
                    continue;
                }
                $out[] = "{$prefix}## {$fk}";
                self::encodeObject($value, $out, $depth + 1);
            } elseif ($c === Value::ARR) {
                self::encodeNamedArray($fk, Value::toList($value), $out, $depth);
            } else {
                $out[] = "{$prefix}{$fk}=".Scalar::formatScalar($value);
            }
        }
    }

    private static function encodeRootArray(array $arr, array &$out): void
    {
        if ($arr === []) {
            $out[] = '## [0]';

            return;
        }
        if (Tabular::allPrimitives($arr)) {
            $vals = implode(',', array_map(static fn (mixed $v): string => Scalar::formatScalar($v, ','), $arr));
            $out[] = '## ['.count($arr).']: '.$vals;

            return;
        }
        $fields = Tabular::fields($arr);
        if ($fields !== null) {
            self::encodeTabular('## ', $arr, $fields, $out, 0);

            return;
        }
        self::encodeExpanded('## ', $arr, $out, 0);
    }

    private static function encodeNamedArray(string $name, array $arr, array &$out, int $depth): void
    {
        $prefix = Value::indent($depth);
        if ($arr === []) {
            $out[] = "{$prefix}## {$name} [0]";

            return;
        }
        if (Tabular::allPrimitives($arr)) {
            $vals = implode(',', array_map(static fn (mixed $v): string => Scalar::formatScalar($v, ','), $arr));
            $out[] = "{$prefix}{$name}[".count($arr).']: '.$vals;

            return;
        }
        $fields = Tabular::fields($arr);
        if ($fields !== null) {
            self::encodeTabular("{$prefix}## {$name} ", $arr, $fields, $out, $depth);

            return;
        }
        self::encodeExpanded("{$prefix}## {$name} ", $arr, $out, $depth);
    }

    // ── Keyed map encoding (SPEC 7.2a) ───────────────────────────────────

    /**
     * Builds the keyed-table header prefix up to the count bracket. $named
     * distinguishes an anonymous root keyed map (`## `) from a named member whose
     * name may itself be the empty string (`## ""`), which format_key quotes so it
     * round-trips as a distinct level rather than collapsing into the anonymous
     * root form (SPEC 7.2a.1).
     */
    private static function keyedHeaderPrefix(string $name, bool $named, int $depth): string
    {
        $prefix = Value::indent($depth);
        if (!$named) {
            return "{$prefix}## ";
        }

        return "{$prefix}## ".Scalar::formatKey($name).' ';
    }

    /**
     * Emits a keyed table for a map of objects. Routes through encodeTabular
     * with the keyed bracket so nested-value handling (flatten/inline/attachment/
     * null/absent) is inherited unchanged. $name is empty for a root/anonymous map.
     *
     * @param list<string> $keys
     * @param list<mixed> $values
     * @param list<string> $valueFields
     */
    private static function encodeKeyedMap(
        string $name, bool $named, array $keys, array $values, array $valueFields,
        string $keyLabel, array &$out, int $depth,
    ): void {
        self::encodeKeyedMapWithPrefix(
            self::keyedHeaderPrefix($name, $named, $depth), $keys, $values, $valueFields, $keyLabel, $out, $depth,
        );
    }

    /**
     * Emits `<headerPrefix>[N:]{...}` and the keyed rows, reusing encodeTabular.
     * Each value object is augmented with the key column and encoded as a tabular
     * row; the key column is declared first.
     *
     * @param list<string> $keys
     * @param list<mixed> $values
     * @param list<string> $valueFields
     */
    private static function encodeKeyedMapWithPrefix(
        string $headerPrefix, array $keys, array $values, array $valueFields,
        string $keyLabel, array &$out, int $depth,
    ): void {
        $fields = [$keyLabel, ...$valueFields];
        $arr = [];
        foreach ($keys as $i => $k) {
            $aug = Value::toAssoc($values[$i]);
            $aug[$keyLabel] = $k;
            $arr[] = $aug;
        }
        self::encodeTabular($headerPrefix, $arr, $fields, $out, $depth, true);
    }

    private static function encodeTabular(
        string $headerPrefix, array $arr, array $fields, array &$out, int $depth, bool $keyed = false,
    ): void {
        $prefix = Value::indent($depth);

        // Phase 0: analyze fields for flattening.
        $flattenMap = [];
        foreach ($fields as $f) {
            $leaves = Flatten::analyzeFlattenable($arr, $f, '');
            if ($leaves !== null && count($leaves) > 0) {
                $flattenMap[$f] = $leaves;
            }
        }

        // Fields whose names contain ">" must not appear as tabular columns
        // because the decoder would interpret them as flattened path columns.
        // Track them for per-row attachment emission (spec rule 7.4.6.1.4).
        $gtFields = [];
        foreach ($fields as $f) {
            if (!isset($flattenMap[$f]) && str_contains($f, '>')) {
                $gtFields[$f] = true;
            }
        }

        // Build expanded column list.
        $columns = [];
        foreach ($fields as $f) {
            if (isset($gtFields[$f])) {
                continue;
            }
            if (isset($flattenMap[$f])) {
                foreach ($flattenMap[$f] as $leaf) {
                    $columns[] = ['header' => Scalar::formatKey($leaf['path']), 'type' => 'flat', 'field' => $f, 'keys' => $leaf['keys']];
                }
            } else {
                $columns[] = ['header' => Scalar::formatKey($f), 'type' => 'original', 'field' => $f, 'keys' => []];
            }
        }

        // If all fields were excluded (all contain ">"), fall back to expanded.
        if ($columns === []) {
            self::encodeExpanded($headerPrefix, $arr, $out, $depth);

            return;
        }

        // Pre-compute inline schemas and shared array schemas (skip flattened fields).
        $inlineSchemas = [];
        $sharedArrSchemas = [];
        foreach ($fields as $f) {
            if (isset($flattenMap[$f])) {
                continue;
            }
            $ifs = Tabular::inlineSchemaFields($arr, $f);
            if ($ifs !== null) {
                $inlineSchemas[$f] = $ifs;
            }
            $sas = Tabular::sharedArraySchema($arr, $f);
            if ($sas !== null) {
                $sharedArrSchemas[$f] = $sas;
            }
        }

        $headerFields = implode(',', array_map(static fn (array $col): string => $col['header'], $columns));
        $br = $keyed ? ':]' : ']';
        $out[] = "{$headerPrefix}[".count($arr)."{$br}{{$headerFields}}";

        foreach ($arr as $i => $item) {
            $assocItem = Value::toAssoc($item);
            $cells = [];
            /** @var list<array{0: string, 1: mixed, 2: bool, 3: list<string>|null}> $attachments */
            $attachments = [];
            $rowHasAttachment = false;

            foreach ($columns as $col) {
                if ($col['type'] === 'flat') {
                    $keys = $col['keys'];
                    if (!array_key_exists($keys[0], $assocItem)) {
                        $cells[] = '~';
                    } else {
                        $topVal = $assocItem[$keys[0]];
                        if ($topVal === null) {
                            $cells[] = '-';
                        } else {
                            [$val, $exists] = Flatten::resolveKeyChain($item, $keys);
                            if (!$exists) {
                                $cells[] = '~';
                            } elseif ($val === null) {
                                $cells[] = '-';
                            } else {
                                $cells[] = Scalar::formatScalar($val, '|');
                            }
                        }
                    }
                    continue;
                }

                $f = $col['field'];
                if (!array_key_exists($f, $assocItem)) {
                    $cells[] = '~';
                    continue;
                }
                $v = $assocItem[$f];
                if ($v === null) {
                    $cells[] = '-';
                    continue;
                }
                $vc = Value::classify($v);
                if ($vc === Value::OBJECT || $vc === Value::ARR) {
                    $ifs = $inlineSchemas[$f] ?? null;
                    if ($ifs !== null && $vc === Value::OBJECT) {
                        if ($i === 0) {
                            $fmtIf = implode(',', array_map(Scalar::formatKey(...), $ifs));
                            $cells[] = "^{{$fmtIf}}";
                        } else {
                            $cells[] = '^';
                        }
                        $attachments[] = [$f, $v, true, $ifs];
                    } else {
                        $cells[] = '^';
                        $attachments[] = [$f, $v, false, null];
                    }
                    $rowHasAttachment = true;
                } else {
                    $cells[] = Scalar::formatScalar($v, '|');
                }
            }

            // Emit fields with ">" in their names as per-row attachments.
            foreach ($fields as $f) {
                if (!isset($gtFields[$f])) {
                    continue;
                }
                if (!array_key_exists($f, $assocItem)) {
                    continue;
                }
                $rowHasAttachment = true;
                $attachments[] = [$f, $assocItem[$f], false, null];
            }

            $row = implode('|', $cells);
            if ($rowHasAttachment) {
                $out[] = "{$prefix}@{$i} {$row}";
            } else {
                $out[] = "{$prefix}{$row}";
            }

            foreach ($attachments as [$attName, $attVal, $isInline, $inlineFields]) {
                $fk = Scalar::formatKey($attName);
                if ($isInline && $inlineFields) {
                    // Inline: single pipe-delimited row, no prefix, no indent.
                    $assocAtt = Value::toAssoc($attVal);
                    $vals = implode('|', array_map(
                        static fn (string $k): string => !array_key_exists($k, $assocAtt) ? '~' : Scalar::formatScalar($assocAtt[$k], '|'),
                        $inlineFields,
                    ));
                    $out[] = "{$prefix}{$vals}";

                    continue;
                }
                $attClass = Value::classify($attVal);
                if ($attClass === Value::ARR) {
                    $attList = Value::toList($attVal);
                    $sas = $sharedArrSchemas[$attName] ?? null;
                    if ($sas !== null && $i > 0) {
                        self::encodeAttachmentArrayShared($prefix, $fk, $attList, $out, $depth + 2, $sas);
                    } else {
                        self::encodeAttachmentArray($prefix, $fk, $attList, $out, $depth + 2);
                    }
                } elseif ($attClass === Value::OBJECT) {
                    $km = KeyedMap::eligible($attVal);
                    if ($km !== null) {
                        self::encodeKeyedMapWithPrefix(
                            "{$prefix}.{$fk} ", $km['keys'], $km['values'], $km['valueFields'], $km['keyLabel'], $out, $depth + 2,
                        );
                    } else {
                        $out[] = "{$prefix}.{$fk} {}";
                        self::encodeObject($attVal, $out, $depth + 2);
                    }
                } else {
                    // Scalar attachment (e.g. field names containing ">").
                    if ($attVal === null) {
                        $out[] = "{$prefix}.{$fk} =-";
                    } else {
                        $out[] = "{$prefix}.{$fk} =".Scalar::formatScalar($attVal);
                    }
                }
            }
        }
    }

    private static function encodeAttachmentArray(string $attPrefix, string $fk, array $arr, array &$out, int $depth): void
    {
        if ($arr === []) {
            $out[] = "{$attPrefix}.{$fk} [0]";
        } elseif (Tabular::allPrimitives($arr)) {
            $vals = implode(',', array_map(static fn (mixed $v): string => Scalar::formatScalar($v, ','), $arr));
            $out[] = "{$attPrefix}.{$fk} [".count($arr).']: '.$vals;
        } else {
            $fields = Tabular::fields($arr);
            if ($fields !== null) {
                self::encodeTabular("{$attPrefix}.{$fk} ", $arr, $fields, $out, $depth);
            } else {
                self::encodeExpanded("{$attPrefix}.{$fk} ", $arr, $out, $depth);
            }
        }
    }

    /**
     * @param list<string> $sharedFields
     */
    private static function encodeAttachmentArrayShared(
        string $attPrefix, string $fk, array $arr, array &$out, int $depth, array $sharedFields,
    ): void {
        if ($arr === []) {
            $out[] = "{$attPrefix}.{$fk} [0]";

            return;
        }
        if (Tabular::allPrimitives($arr)) {
            $vals = implode(',', array_map(static fn (mixed $v): string => Scalar::formatScalar($v, ','), $arr));
            $out[] = "{$attPrefix}.{$fk} [".count($arr).']: '.$vals;

            return;
        }
        $fields = Tabular::fields($arr);
        if ($fields !== null && $fields === $sharedFields) {
            // Omit {fields}, use shared schema.
            $prefix = Value::indent($depth);
            $out[] = "{$attPrefix}.{$fk} [".count($arr).']';
            foreach ($arr as $item) {
                $assocItem = Value::toAssoc($item);
                $cells = [];
                foreach ($sharedFields as $f) {
                    if (!array_key_exists($f, $assocItem)) {
                        $cells[] = '~';
                    } elseif ($assocItem[$f] === null) {
                        $cells[] = '-';
                    } else {
                        $cells[] = Scalar::formatScalar($assocItem[$f], '|');
                    }
                }
                $out[] = $prefix.implode('|', $cells);
            }

            return;
        }
        // Fields don't match: fall back to full encoding.
        self::encodeAttachmentArray($attPrefix, $fk, $arr, $out, $depth);
    }

    private static function encodeExpanded(string $headerPrefix, array $arr, array &$out, int $depth): void
    {
        $prefix = Value::indent($depth);
        $out[] = "{$headerPrefix}[".count($arr).']';
        foreach ($arr as $i => $item) {
            $c = Value::classify($item);
            if ($c === Value::OBJECT) {
                $km = KeyedMap::eligible($item);
                if ($km !== null) {
                    self::encodeKeyedMapWithPrefix(
                        "{$prefix}@{$i} ", $km['keys'], $km['values'], $km['valueFields'], $km['keyLabel'], $out, $depth + 1,
                    );
                    continue;
                }
                $out[] = "{$prefix}@{$i} {}";
                self::encodeObject($item, $out, $depth + 1);
            } elseif ($c === Value::ARR) {
                self::encodeExpandedArrayItem($prefix, (int) $i, Value::toList($item), $out, $depth);
            } else {
                $out[] = "{$prefix}@{$i} =".Scalar::formatScalar($item);
            }
        }
    }

    private static function encodeExpandedArrayItem(string $prefix, int $idx, array $arr, array &$out, int $depth): void
    {
        if ($arr === []) {
            $out[] = "{$prefix}@{$idx} [0]";
        } elseif (Tabular::allPrimitives($arr)) {
            $vals = implode(',', array_map(static fn (mixed $v): string => Scalar::formatScalar($v, ','), $arr));
            $out[] = "{$prefix}@{$idx} [".count($arr).']: '.$vals;
        } else {
            $fields = Tabular::fields($arr);
            if ($fields !== null) {
                self::encodeTabular("{$prefix}@{$idx} ", $arr, $fields, $out, $depth + 1);
            } else {
                self::encodeExpanded("{$prefix}@{$idx} ", $arr, $out, $depth + 1);
            }
        }
    }
}
