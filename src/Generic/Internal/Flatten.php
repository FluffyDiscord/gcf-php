<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

/**
 * Nested-object flattening analysis (SPEC 7.4.6.1, v3.2). Ported from
 * gcf-python's generic.py: _analyze_flattenable, _resolve_key_chain.
 */
final class Flatten
{
    private function __construct()
    {
    }

    /**
     * Analyzes whether a field can be flattened. Returns a list of leaf
     * descriptors (`{path: string, keys: list<string>}`) or null.
     *
     * @param list<mixed> $arr
     * @return list<array{path: string, keys: list<string>}>|null
     */
    public static function analyzeFlattenable(array $arr, string $fieldName, string $parentPath): ?array
    {
        // A field name that is empty or contains ">" cannot be flattened: it would create an
        // ambiguous path column the decoder treats as literal (SPEC 7.4.6.1.3).
        if ($fieldName === '' || str_contains($fieldName, '>')) {
            return null;
        }
        /** @var array<string, string>|null $canonicalShape key -> "scalar"|"nested" */
        $canonicalShape = null;

        foreach ($arr as $item) {
            $assocItem = Value::toAssoc($item);
            if (!array_key_exists($fieldName, $assocItem)) {
                continue;
            }
            // A nested (non-top-level) null cannot be flattened losslessly: its leaves
            // would encode as absent ("~") and unflatten back to a missing key, not None.
            // Bail to the attachment path. A top-level None is fine (emits "-" and
            // reconstructs via the all-null rule), so just skip the row from shape analysis.
            if ($assocItem[$fieldName] === null) {
                if ($parentPath !== '') {
                    return null;
                }
                continue;
            }
            $v = $assocItem[$fieldName];
            if (!Value::isObject($v)) {
                return null;
            }
            $assocV = Value::toAssoc($v);
            // PHP normalizes a numeric-looking key ("5") to int; GCF field/leaf names
            // are always strings (JSON object keys), so cast it back.
            $keys = array_map(strval(...), array_keys($assocV));

            if ($canonicalShape === null) {
                $canonicalShape = [];
                foreach ($keys as $k) {
                    if ($k === '' || str_contains($k, '>')) { // empty/">" -> ambiguous path (SPEC 7.4.6.1.3)
                        return null;
                    }
                    $val = $assocV[$k];
                    $c = Value::classify($val);
                    if ($c === Value::ARR) {
                        return null;
                    }
                    $canonicalShape[$k] = $c === Value::OBJECT ? 'nested' : 'scalar';
                }
            } else {
                if (count($keys) !== count($canonicalShape)) {
                    return null;
                }
                foreach ($keys as $k) {
                    if (!array_key_exists($k, $canonicalShape)) {
                        return null;
                    }
                    $val = $assocV[$k];
                    $expected = $canonicalShape[$k];
                    $c = Value::classify($val);
                    if ($expected === 'scalar') {
                        if ($c === Value::OBJECT || $c === Value::ARR) {
                            return null;
                        }
                    } elseif ($expected === 'nested') {
                        if ($c === Value::ARR) {
                            return null;
                        }
                        if ($c !== Value::NULL && $c !== Value::OBJECT) {
                            return null;
                        }
                    }
                }
            }
        }

        if ($canonicalShape === null) {
            return null;
        }

        $currentPath = $parentPath !== '' ? "{$parentPath}>{$fieldName}" : $fieldName;
        $parentKeys = $parentPath !== '' ? [...explode('>', $parentPath), $fieldName] : [$fieldName];

        $leaves = [];
        foreach ($canonicalShape as $k => $shape) {
            if ($shape === 'scalar') {
                $leaves[] = ['path' => "{$currentPath}>{$k}", 'keys' => [...$parentKeys, $k]];
            } else {
                $subArr = [];
                foreach ($arr as $item) {
                    $assocItem = Value::toAssoc($item);
                    if (!array_key_exists($fieldName, $assocItem) || $assocItem[$fieldName] === null) {
                        $subArr[] = [];
                    } else {
                        $subArr[] = $assocItem[$fieldName];
                    }
                }
                $subLeaves = self::analyzeFlattenable($subArr, $k, $currentPath);
                if ($subLeaves === null || count($subLeaves) === 0) {
                    return null;
                }
                array_push($leaves, ...$subLeaves);
            }
        }

        // Guard: reject if any row has a non-null object with all-null leaves.
        if ($leaves !== []) {
            foreach ($arr as $item) {
                $assocItem = Value::toAssoc($item);
                if (!array_key_exists($fieldName, $assocItem) || $assocItem[$fieldName] === null) {
                    continue;
                }
                $allNull = true;
                foreach ($leaves as $leaf) {
                    [$val, $exists] = self::resolveKeyChain($item, $leaf['keys']);
                    if (!($val === null && $exists)) {
                        $allNull = false;
                        break;
                    }
                }
                if ($allNull) {
                    return null;
                }
            }
        }

        return $leaves;
    }

    /**
     * Traverses an object by key chain. Returns [value, exists].
     *
     * @param list<string> $keys
     * @return array{0: mixed, 1: bool}
     */
    public static function resolveKeyChain(mixed $item, array $keys): array
    {
        if ($keys === [] || !Value::isObject($item)) {
            return [null, false];
        }
        $assoc = Value::toAssoc($item);
        if (!array_key_exists($keys[0], $assoc)) {
            return [null, false];
        }
        $current = $assoc[$keys[0]];
        if ($current === null) {
            return [null, true];
        }
        foreach (array_slice($keys, 1) as $k) {
            if (!Value::isObject($current)) {
                return [null, false];
            }
            $assocCurrent = Value::toAssoc($current);
            if (!array_key_exists($k, $assocCurrent)) {
                return [null, false];
            }
            $current = $assocCurrent[$k];
        }

        return [$current, true];
    }
}
