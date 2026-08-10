<?php

declare(strict_types=1);

namespace Gcf;

/**
 * Common scalar grammar for GCF (spec section 2). Ported from gcf-python's scalar.py.
 */
final class Scalar
{
    private const JSON_NUMBER_RE = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/';
    private const NUMERIC_LIKE_RE = '/^[+-]\.?\d|^\.\d|^0\d/';
    private const INLINE_ARRAY_RE = '/\[[^\]]*\]\s*:/';
    private const BARE_KEY_RE = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    private function __construct()
    {
    }

    public static function needsQuote(string $s): bool
    {
        if ($s === '') {
            return true;
        }
        if (in_array($s, ['-', '~', '^', 'true', 'false'], true)) {
            return true;
        }
        // A value shaped like an inline-schema attachment marker (^{...}) would decode
        // as an attachment and lose the string, so it must be quoted (SPEC 2.4).
        if (strlen($s) >= 3 && $s[0] === '^' && $s[1] === '{' && str_ends_with($s, '}')) {
            return true;
        }
        if (preg_match(self::JSON_NUMBER_RE, $s) === 1) {
            return true;
        }
        if (preg_match(self::NUMERIC_LIKE_RE, $s) === 1) {
            return true;
        }
        if ($s[0] === ' ' || str_ends_with($s, ' ')) {
            return true;
        }
        if (in_array($s[0], ['#', '@', '.'], true)) {
            return true;
        }
        if (preg_match(self::INLINE_ARRAY_RE, $s) === 1) {
            return true;
        }

        foreach (self::codepoints($s) as $o) {
            if ($o === 0x22 || $o === 0x5C || $o === 0x7C || $o === 0x2C || $o < 0x20) {
                return true;
            }
            // C1 control characters
            if ($o >= 0x80 && $o <= 0x9F) {
                return true;
            }
            // Unicode whitespace beyond ASCII
            if ($o > 0x7F && (
                $o === 0xA0 || $o === 0x1680 || $o === 0x2028 || $o === 0x2029
                || $o === 0x202F || $o === 0x205F || $o === 0x3000 || $o === 0xFEFF
                || ($o >= 0x2000 && $o <= 0x200A)
            )) {
                return true;
            }
        }

        return false;
    }

    public static function quoteString(string $s): string
    {
        $out = ['"'];
        // Must split on Unicode characters, not bytes: mb_ord() on an isolated
        // UTF-8 continuation byte is invalid input and returns false.
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            $o = self::mbOrd($c);
            $out[] = match (true) {
                $c === '"' => '\\"',
                $c === '\\' => '\\\\',
                $c === "\x08" => '\\b',
                $c === "\x0C" => '\\f',
                $c === "\n" => '\\n',
                $c === "\r" => '\\r',
                $c === "\t" => '\\t',
                $o < 0x20 => sprintf('\\u%04x', $o),
                default => $c,
            };
        }
        $out[] = '"';

        return implode('', $out);
    }

    public static function formatScalar(mixed $v, string $delimiter = ''): string
    {
        if ($v === null) {
            return '-';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return self::formatNumber($v);
        }
        $s = (string) $v;
        if (self::needsQuote($s) || ($delimiter !== '' && str_contains($s, $delimiter))) {
            return self::quoteString($s);
        }

        return $s;
    }

    public static function formatNumber(float $f): string
    {
        if (is_nan($f)) {
            return '0';
        }
        if (is_infinite($f)) {
            return '0';
        }
        if ($f == 0.0) {
            // Negative zero canonicalizes to 0 (SPEC 2.3.1): -0.0 equals 0.0 by value.
            return '0';
        }

        $a = abs($f);
        if ($a >= 1e-6 && $a < 1e21) {
            $s = self::shortestRepr($f);
            if (str_contains($s, 'e') || str_contains($s, 'E')) {
                $s = rtrim(rtrim(self::sprintfC('%.20f', $f), '0'), '.');
            }
            if (str_ends_with($s, '.0') && $f == (int) $f) {
                $s = substr($s, 0, -2);
            }

            return $s;
        }

        $s = self::shortestRepr($f);
        if (!str_contains($s, 'e') && !str_contains($s, 'E')) {
            $s = self::sprintfC('%.17e', $f);
        }
        $s = strtolower($s);
        [$mantissa, $exp] = explode('e', $s, 2);
        $mantissa = rtrim(rtrim($mantissa, '0'), '.');
        $sign = str_starts_with($exp, '-') ? '-' : '+';
        $digits = ltrim(ltrim($exp, '+-'), '0');
        if ($digits === '') {
            $digits = '0';
        }

        return "{$mantissa}e{$sign}{$digits}";
    }

    public static function isBareKey(string $s): bool
    {
        return preg_match(self::BARE_KEY_RE, $s) === 1;
    }

    public static function formatKey(string $s): string
    {
        return self::isBareKey($s) ? $s : self::quoteString($s);
    }

    /**
     * @return string|null|Sentinel|bool|int|float
     */
    public static function parseScalar(string $s, bool $tabularContext = false): mixed
    {
        if ($s === '') {
            return '';
        }
        if ($s[0] === '"') {
            return self::parseQuotedString($s);
        }
        if ($s === '-') {
            return null;
        }
        if ($s === '~') {
            if (!$tabularContext) {
                throw new GcfDecodeException('invalid_missing: ~ outside tabular row cell');
            }

            return Sentinel::Missing;
        }
        if ($s === '^') {
            if (!$tabularContext) {
                throw new GcfDecodeException('invalid_attachment_marker: ^ outside tabular row cell');
            }

            return Sentinel::Attachment;
        }
        if ($s === 'true') {
            return true;
        }
        if ($s === 'false') {
            return false;
        }
        if (preg_match(self::JSON_NUMBER_RE, $s) === 1) {
            $f = (float) $s;
            if (!str_contains($s, '.') && !str_contains($s, 'e') && !str_contains($s, 'E')) {
                if (abs($f) <= 2 ** 53) {
                    return (int) $f;
                }
            }

            return $f;
        }

        return $s;
    }

    public static function parseQuotedString(string $s): string
    {
        if (strlen($s) < 2 || $s[0] !== '"') {
            throw new GcfDecodeException('unterminated_quote');
        }

        $bytes = self::chars($s);
        $n = count($bytes);
        $out = [];
        $i = 1;
        while ($i < $n) {
            $c = $bytes[$i];
            if ($c === '"') {
                if ($i + 1 !== $n) {
                    throw new GcfDecodeException('trailing_characters: after closing quote');
                }

                return implode('', $out);
            }
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    throw new GcfDecodeException('unterminated_quote');
                }
                ++$i;
                $esc = $bytes[$i];
                switch ($esc) {
                    case '"':
                        $out[] = '"';
                        break;
                    case '\\':
                        $out[] = '\\';
                        break;
                    case '/':
                        $out[] = '/';
                        break;
                    case 'b':
                        $out[] = "\x08";
                        break;
                    case 'f':
                        $out[] = "\x0C";
                        break;
                    case 'n':
                        $out[] = "\n";
                        break;
                    case 'r':
                        $out[] = "\r";
                        break;
                    case 't':
                        $out[] = "\t";
                        break;
                    case 'u':
                        if ($i + 4 >= $n) {
                            throw new GcfDecodeException('invalid_escape: incomplete unicode');
                        }
                        $hex = implode('', array_slice($bytes, $i + 1, 4));
                        if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
                            throw new GcfDecodeException("invalid_escape: invalid unicode \\u{$hex}");
                        }
                        $code = hexdec($hex);
                        if ($code >= 0xD800 && $code <= 0xDBFF) {
                            if ($i + 10 >= $n || $bytes[$i + 5] !== '\\' || $bytes[$i + 6] !== 'u') {
                                throw new GcfDecodeException('invalid_surrogate: isolated high surrogate');
                            }
                            $hex2 = implode('', array_slice($bytes, $i + 7, 4));
                            if (preg_match('/^[0-9a-fA-F]{4}$/', $hex2) !== 1) {
                                throw new GcfDecodeException("invalid_surrogate: invalid low surrogate \\u{$hex2}");
                            }
                            $low = hexdec($hex2);
                            if ($low < 0xDC00 || $low > 0xDFFF) {
                                throw new GcfDecodeException('invalid_surrogate: expected low surrogate');
                            }
                            $combined = 0x10000 + (($code - 0xD800) * 0x400) + ($low - 0xDC00);
                            $out[] = mb_chr($combined, 'UTF-8');
                            $i += 11;
                            continue 2;
                        }
                        if ($code >= 0xDC00 && $code <= 0xDFFF) {
                            throw new GcfDecodeException('invalid_surrogate: isolated low surrogate');
                        }
                        $out[] = mb_chr($code, 'UTF-8');
                        $i += 5;
                        continue 2;
                    default:
                        throw new GcfDecodeException("invalid_escape: unknown \\{$esc}");
                }
                ++$i;
                continue;
            }
            // A raw byte < 0x20 is always a genuine ASCII control byte: UTF-8
            // continuation/leading bytes for multibyte characters are always >= 0x80,
            // so this check is safe on the byte-wise split without decoding a codepoint.
            $o = ord($c);
            if ($o < 0x20) {
                throw new GcfDecodeException(sprintf('invalid_escape: unescaped control U+%04x', $o));
            }
            $out[] = $c;
            ++$i;
        }

        throw new GcfDecodeException('unterminated_quote');
    }

    /**
     * @return list<string>
     */
    public static function splitRespectingQuotes(string $s, string $delim): array
    {
        $parts = [];
        $current = [];
        $inQuote = false;
        $escaped = false;
        foreach (self::chars($s) as $c) {
            if ($escaped) {
                $current[] = $c;
                $escaped = false;
                continue;
            }
            if ($c === '\\' && $inQuote) {
                $current[] = $c;
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                $current[] = $c;
                continue;
            }
            if ($c === $delim && !$inQuote) {
                $parts[] = implode('', $current);
                $current = [];
                continue;
            }
            $current[] = $c;
        }
        $parts[] = implode('', $current);

        return $parts;
    }

    /**
     * @return list<string>
     */
    public static function splitFieldDecl(string $s): array
    {
        if (strlen($s) < 2 || $s[0] !== '{') {
            throw new GcfDecodeException("invalid field declaration: {$s}");
        }
        $close = self::findClosingBrace($s);
        if ($close < 0) {
            throw new GcfDecodeException("invalid field declaration: {$s}");
        }
        $inner = substr($s, 1, $close - 1);
        if ($inner === '') {
            return [];
        }
        $raw = self::splitRespectingQuotes($inner, ',');
        $fields = [];
        $seen = [];
        foreach ($raw as $f) {
            $f = trim($f);
            if (strlen($f) >= 2 && $f[0] === '"' && str_ends_with($f, '"')) {
                $name = self::parseQuotedString($f);
            } else {
                if (!self::isBareKey($f)) {
                    throw new GcfDecodeException("invalid field name: {$f}");
                }
                $name = $f;
            }
            if (isset($seen[$name])) {
                throw new GcfDecodeException("duplicate_field_name: {$name}");
            }
            $seen[$name] = true;
            $fields[] = $name;
        }

        return $fields;
    }

    private static function findClosingBrace(string $s): int
    {
        $inQuote = false;
        $escaped = false;
        $chars = self::chars($s);
        foreach ($chars as $i => $c) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($c === '\\' && $inQuote) {
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                continue;
            }
            if ($c === '}' && !$inQuote) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Byte-wise split is safe here: the only characters this parses structurally
     * (", \, comma, brace) are single-byte ASCII and never occur as a UTF-8
     * continuation byte, so no multibyte character can be split apart by it.
     *
     * @return list<string>
     */
    private static function chars(string $s): array
    {
        return str_split($s);
    }

    /**
     * @return list<int> Unicode codepoints, one per grapheme-agnostic UTF-8 character.
     */
    private static function codepoints(string $s): array
    {
        $out = [];
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            $out[] = self::mbOrd($c);
        }

        return $out;
    }

    private static function mbOrd(string $c): int
    {
        return mb_ord($c, 'UTF-8');
    }

    private static function shortestRepr(float $f): string
    {
        // PHP's json_encode with the default serialize_precision=-1 uses the same
        // "shortest round-trippable decimal" algorithm class as Python's repr(float).
        return json_encode($f);
    }

    /**
     * printf's %f/%e specifiers are locale-sensitive (LC_NUMERIC), which would
     * corrupt the decimal separator in locales that use ",". Force the C locale
     * for the duration of the call so output is always "." regardless of host app.
     */
    private static function sprintfC(string $format, float $v): string
    {
        $prev = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'C');
        try {
            return sprintf($format, $v);
        } finally {
            setlocale(LC_NUMERIC, $prev);
        }
    }
}
