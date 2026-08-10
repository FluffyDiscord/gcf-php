<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta\Internal;

use Gcf\GcfDecodeException;
use Gcf\Scalar;

/**
 * Shared low-level parsing for the generic-profile delta wire grammar (SPEC
 * Section 10a.2). Ported from gcf-python's generic_delta.py: _parse_header_fields,
 * _parse_count, _split_delta_field_decl, _parse_section_header, _parse_row.
 *
 * Used by both GenericDeltaDecoder::decodeFull() and ::decodeDelta().
 */
final class DeltaWireFormat
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaderFields(string $header): array
    {
        $m = [];
        foreach (preg_split('/\s+/', trim($header), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (str_contains($tok, '=')) {
                [$k, $v] = explode('=', $tok, 2);
                if ($k !== '') {
                    $m[$k] = $v;
                }
            }
        }

        return $m;
    }

    public static function parseCount(string $s): int
    {
        if ($s === '0') {
            return 0;
        }
        if ($s === '' || $s[0] === '0' || ctype_digit($s) === false) {
            throw new GcfDecodeException("invalid_count: {$s}");
        }

        return (int) $s;
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    public static function splitDeltaFieldDecl(string $decl): array
    {
        if (strlen($decl) < 2 || $decl[0] !== '{' || !str_ends_with($decl, '}')) {
            throw new GcfDecodeException("invalid field declaration: {$decl}");
        }
        $inner = substr($decl, 1, -1);
        if ($inner === '') {
            return [[], ''];
        }

        $fields = [];
        $keyField = '';
        foreach (Scalar::splitRespectingQuotes($inner, ',') as $raw) {
            $f = trim($raw);
            $isKey = false;
            if ($f !== '' && $f[0] === '@') {
                $f = substr($f, 1);
                $isKey = true;
            }
            if (strlen($f) >= 2 && $f[0] === '"' && str_ends_with($f, '"')) {
                $f = Scalar::parseQuotedString($f);
            }
            if ($isKey) {
                $keyField = $f;
            }
            $fields[] = $f;
        }

        return [$fields, $keyField];
    }

    /**
     * @return array{0: string, 1: int, 2: list<string>, 3: string}
     */
    public static function parseSectionHeader(string $content): array
    {
        $bi = strpos($content, ' [');
        if ($bi === false) {
            throw new GcfDecodeException("delta_invalid: section header without count: '{$content}'");
        }
        $name = trim(substr($content, 0, $bi));
        $rest = substr($content, $bi + 1); // "[N]{...}"
        if ($rest === '' || $rest[0] !== '[') {
            throw new GcfDecodeException("delta_invalid: malformed section header: '{$content}'");
        }
        $close = strpos($rest, ']');
        if ($close === false) {
            throw new GcfDecodeException("delta_invalid: unterminated count: '{$content}'");
        }
        $count = self::parseCount(substr($rest, 1, $close - 1));
        [$fields, $keyField] = self::splitDeltaFieldDecl(substr($rest, $close + 1));

        return [$name, $count, $fields, $keyField];
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function parseRow(string $line, array $fields): array
    {
        $cells = Scalar::splitRespectingQuotes($line, '|');
        if (count($cells) !== count($fields)) {
            throw new GcfDecodeException(
                'delta_invalid: row has '.count($cells).' cells, expected '.count($fields).": '{$line}'",
            );
        }
        $row = [];
        foreach ($fields as $i => $f) {
            $row[$f] = Scalar::parseScalar($cells[$i], true);
        }

        return $row;
    }
}
