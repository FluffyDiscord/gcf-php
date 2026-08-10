<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

/**
 * Tabular-eligibility detection for arrays of objects (SPEC 7.4). Ported from
 * gcf-python's generic.py: _tabular_fields, _inline_schema_fields,
 * _shared_array_schema, _all_primitives.
 */
final class Tabular
{
    private function __construct()
    {
    }

    public static function allPrimitives(array $arr): bool
    {
        foreach ($arr as $v) {
            $c = Value::classify($v);
            if ($c === Value::OBJECT || $c === Value::ARR) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $arr
     * @return list<string>|null
     */
    public static function fields(array $arr): ?array
    {
        if ($arr === []) {
            return null;
        }
        $fieldOrder = [];
        $seen = [];
        foreach ($arr as $item) {
            if (!Value::isObject($item)) {
                return null;
            }
            foreach (array_keys(Value::toAssoc($item)) as $k) {
                // PHP normalizes a numeric-looking key ("5") to int; GCF field names
                // are always strings (JSON object keys), so cast it back.
                $k = (string) $k;
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $fieldOrder[] = $k;
                }
            }
        }

        return $fieldOrder !== [] ? $fieldOrder : null;
    }

    /**
     * Checks if a field is eligible for inline schema: all rows share the same flat
     * object shape, 3+ keys, and the first row has it.
     *
     * @param list<mixed> $arr
     * @return list<string>|null
     */
    public static function inlineSchemaFields(array $arr, string $fieldName): ?array
    {
        if ($arr === [] || !array_key_exists($fieldName, Value::toAssoc($arr[0]))) {
            return null;
        }
        $firstVal = Value::toAssoc($arr[0])[$fieldName];
        if (!Value::isObject($firstVal)) {
            return null;
        }

        $canonicalKeys = null;
        foreach ($arr as $item) {
            $assocItem = Value::toAssoc($item);
            if (!array_key_exists($fieldName, $assocItem) || $assocItem[$fieldName] === null) {
                continue;
            }
            $v = $assocItem[$fieldName];
            if (!Value::isObject($v)) {
                return null;
            }
            $assocV = Value::toAssoc($v);
            $keys = array_map(strval(...), array_keys($assocV));
            foreach ($assocV as $val) {
                $c = Value::classify($val);
                if ($c === Value::OBJECT || $c === Value::ARR) {
                    return null;
                }
            }
            if ($canonicalKeys === null) {
                $canonicalKeys = $keys;
            } elseif ($keys !== $canonicalKeys) {
                return null;
            }
        }
        if ($canonicalKeys === null || count($canonicalKeys) < 3) {
            return null;
        }

        return $canonicalKeys;
    }

    /**
     * Checks if an array attachment has the same tabular schema across all rows.
     * The first row must have it; all values must be scalars.
     *
     * @param list<mixed> $arr
     * @return list<string>|null
     */
    public static function sharedArraySchema(array $arr, string $fieldName): ?array
    {
        if ($arr === [] || !array_key_exists($fieldName, Value::toAssoc($arr[0]))) {
            return null;
        }
        $firstVal = Value::toAssoc($arr[0])[$fieldName];
        if (!Value::isArray($firstVal)) {
            return null;
        }

        $canonicalFields = null;
        foreach ($arr as $item) {
            $assocItem = Value::toAssoc($item);
            if (!array_key_exists($fieldName, $assocItem) || $assocItem[$fieldName] === null) {
                continue;
            }
            $v = $assocItem[$fieldName];
            if (!Value::isArray($v)) {
                return null;
            }
            $vList = Value::toList($v);
            $fields = self::fields($vList);
            if ($fields === null) {
                return null;
            }
            // All values in array items must be scalars.
            foreach ($vList as $arrItem) {
                if (!Value::isObject($arrItem)) {
                    return null;
                }
                foreach (Value::toAssoc($arrItem) as $val) {
                    $c = Value::classify($val);
                    if ($c === Value::OBJECT || $c === Value::ARR) {
                        return null;
                    }
                }
            }
            if ($canonicalFields === null) {
                $canonicalFields = $fields;
            } elseif ($fields !== $canonicalFields) {
                return null;
            }
        }

        return $canonicalFields;
    }
}
