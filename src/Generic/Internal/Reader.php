<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

use Gcf\GcfDecodeException;
use Gcf\Scalar;
use Gcf\Sentinel;

/**
 * GCF generic-profile decoder engine: parses GCF generic-profile text back to
 * PHP values. Ported from gcf-python's decode_generic.py.
 *
 * Only `profile=generic` is supported — the graph profile (and delta/session/
 * streaming features built on top of it) are out of scope for this port.
 */
final class Reader
{
    private function __construct()
    {
    }

    public static function decode(string $inputText): mixed
    {
        // GCF text is always UTF-8 (SPEC section 2); PHP strings are plain byte
        // sequences with no inherent encoding, unlike Python's str, so this has to be
        // checked explicitly rather than falling out of the string type itself.
        if (!mb_check_encoding($inputText, 'UTF-8')) {
            throw new GcfDecodeException('invalid_utf8: input is not valid UTF-8');
        }

        $inputText = rtrim($inputText, "\n\r");
        if ($inputText === '') {
            throw new GcfDecodeException('missing_header: empty input');
        }

        $lines = explode("\n", $inputText);
        $header = rtrim($lines[0], "\r");
        if (!str_starts_with($header, 'GCF ')) {
            throw new GcfDecodeException('missing_header: first line does not begin with GCF');
        }

        $profile = self::parseHeaderProfile($header);
        if ($profile !== 'generic') {
            throw new GcfDecodeException("unknown_profile: {$profile}");
        }

        $contentLines = [];
        $summaryLine = '';
        $deferredCount = 0;
        foreach (array_slice($lines, 1) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            $len = strlen($line);
            for ($j = 0; $j < $len; ++$j) {
                $c = $line[$j];
                if ($c === "\t") {
                    throw new GcfDecodeException('tab_indentation: tabs in leading whitespace');
                }
                if ($c !== ' ') {
                    break;
                }
            }
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '# ')) {
                continue;
            }
            if (str_starts_with($trimmed, '##! ')) {
                $summaryLine = $trimmed;
                continue;
            }
            if (str_starts_with($trimmed, '## ') && (str_contains($trimmed, '[?]') || str_contains($trimmed, '[?:]'))) {
                ++$deferredCount;
            }
            $contentLines[] = $line;
        }

        if ($summaryLine !== '' && $deferredCount > 0) {
            self::validateSummaryCounts($summaryLine, $deferredCount, $contentLines);
        }

        if ($contentLines === []) {
            return [];
        }

        $first = ltrim($contentLines[0]);

        if (str_starts_with($first, '=')) {
            if (count($contentLines) > 1) {
                throw new GcfDecodeException('trailing_characters: extra lines after root scalar');
            }

            return self::sentinelToNull(Scalar::parseScalar(substr($first, 1)));
        }

        if (str_starts_with($first, '## [')) {
            [$arr, $consumed] = self::parseArrayFromHeader($contentLines, 0, 0, substr($first, 3));
            // A root array or keyed map spans the whole document, so any structural line
            // past the consumed rows is a surplus item, not sibling content. The row loop
            // stops at the declared count, so the count assert only catches the deficit
            // case; surplus is caught here (SPEC Section 13: a mismatch, fewer OR more
            // items than declared, is an error).
            if ($consumed < count($contentLines)) {
                throw new GcfDecodeException('count_mismatch: declared count is fewer than the rows present');
            }

            return $arr;
        }

        $result = [];
        self::parseObjectBody($contentLines, 0, 0, $result);

        return $result;
    }

    private static function sentinelToNull(mixed $v): mixed
    {
        return $v instanceof Sentinel ? null : $v;
    }

    private static function parseHeaderProfile(string $header): string
    {
        $parts = preg_split('/\s+/', $header, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) < 2) {
            throw new GcfDecodeException('missing_profile');
        }
        $seen = [];
        $profile = '';
        foreach (array_slice($parts, 1) as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) {
                throw new GcfDecodeException("malformed_header_field: {$p}");
            }
            $key = substr($p, 0, $eq);
            if (isset($seen[$key])) {
                throw new GcfDecodeException("duplicate_header_field: {$key}");
            }
            $seen[$key] = true;
            if ($key === 'profile') {
                $profile = substr($p, $eq + 1);
            }
        }
        if ($profile === '') {
            throw new GcfDecodeException('missing_profile');
        }

        return $profile;
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $out
     */
    private static function parseObjectBody(array $lines, int $start, int $depth, array &$out): int
    {
        $ind = str_repeat('  ', $depth);
        $i = $start;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if ($depth > 0 && !str_starts_with($line, $ind)) {
                break;
            }
            $content = $depth > 0 ? substr($line, strlen($ind)) : $line;
            if ($content !== '' && $content[0] === ' ') {
                throw new GcfDecodeException('invalid_indent: indentation increases by more than one level');
            }

            if (str_starts_with($content, '## ')) {
                $hdr = substr($content, 3);
                $bi = self::findBracketStart($hdr);
                if ($bi >= 0) {
                    $name = self::parseKeyFromHeader(substr($hdr, 0, $bi));
                    self::checkDup($out, $name);
                    [$arr, $consumed] = self::parseArrayFromHeader($lines, $i, $depth, substr($hdr, $bi));
                    $out[$name] = $arr;
                    $i += $consumed;
                    continue;
                }
                $name = self::parseKeyFromHeader($hdr);
                self::checkDup($out, $name);
                ++$i;
                $nested = [];
                $consumed = self::parseObjectBody($lines, $i, $depth + 1, $nested);
                $out[$name] = $nested;
                $i += $consumed;
                continue;
            }

            // Key=value. Check before inline array so bracket patterns in quoted
            // values (e.g. text="ERR[404]: Not Found") are not misinterpreted.
            $eqIdx = self::findKvSplit($content);
            if ($eqIdx > 0) {
                $name = self::parseKeyFromHeader(substr($content, 0, $eqIdx));
                self::checkDup($out, $name);
                $out[$name] = self::sentinelToNull(Scalar::parseScalar(substr($content, $eqIdx + 1)));
                ++$i;
                continue;
            }

            // Inline array (e.g. items[3]: a,b,c). Only reached if no = found.
            if (!str_starts_with($content, '@') && !str_starts_with($content, '##')) {
                $bracketIdx = strpos($content, '[');
                if ($bracketIdx !== false && $bracketIdx > 0) {
                    $rest = substr($content, $bracketIdx);
                    $closeIdx = strpos($rest, ']');
                    if ($closeIdx !== false) {
                        $after = substr($rest, $closeIdx + 1);
                        if (str_starts_with($after, ': ') || $after === ':') {
                            $name = self::parseKeyFromHeader(substr($content, 0, $bracketIdx));
                            self::checkDup($out, $name);
                            [$arr] = self::parseArrayFromHeader($lines, $i, $depth, $rest);
                            $out[$name] = $arr;
                            ++$i;
                            continue;
                        }
                    }
                }
            }

            // An object-body line that is not a `## ` section, a `key=value` field, or
            // an inline array is not valid content and MUST NOT be silently skipped
            // (that dropped data, a lossless round-trip hole). A pipe-delimited line is
            // a stray positional inline body with no eligible `^` cell (SPEC 16.5,
            // orphan_inline_attachment); any other unrecognized line is likewise rejected.
            if (str_contains($content, '|')) {
                throw new GcfDecodeException("orphan_inline_attachment: {$content}");
            }
            throw new GcfDecodeException("invalid_line: unexpected content in object body: '{$content}'");
        }

        return $i - $start;
    }

    private static function findKvSplit(string $s): int
    {
        if ($s === '') {
            return -1;
        }
        $n = strlen($s);
        if ($s[0] === '"') {
            $i = 1;
            while ($i < $n) {
                if ($s[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($s[$i] === '"') {
                    return $i + 1 < $n && $s[$i + 1] === '=' ? $i + 1 : -1;
                }
                ++$i;
            }

            return -1;
        }
        $eqIdx = strpos($s, '=');
        if ($eqIdx === false) {
            return -1;
        }
        $bracketIdx = strpos($s, '[');
        if ($bracketIdx !== false && $bracketIdx < $eqIdx) {
            return -1;
        }

        return $eqIdx;
    }

    private static function parseKeyFromHeader(string $s): string
    {
        $s = trim($s);
        if (strlen($s) >= 2 && $s[0] === '"') {
            return Scalar::parseQuotedString($s);
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function checkDup(array $d, string $key): void
    {
        if (array_key_exists($key, $d)) {
            throw new GcfDecodeException("duplicate_key: {$key}");
        }
    }

    /**
     * @param list<string> $lines
     * @return array{0: mixed, 1: int}
     */
    private static function parseArrayFromHeader(array $lines, int $headerLine, int $depth, string $bracketPart): array
    {
        $bp = ltrim($bracketPart);
        if (!str_starts_with($bp, '[')) {
            throw new GcfDecodeException('invalid_count');
        }
        $close = strpos($bp, ']');
        if ($close === false) {
            throw new GcfDecodeException('invalid_count');
        }
        $countStr = substr($bp, 1, $close - 1);
        $after = substr($bp, $close + 1);

        // A keyed map is marked by `:` after the count inside the bracket (`[N:]`).
        // The decoder reconstructs a JSON object, not an array (SPEC 7.2a.2).
        $keyed = str_ends_with($countStr, ':');
        if ($keyed) {
            $countStr = substr($countStr, 0, -1);
            if (!str_starts_with($after, '{')) {
                throw new GcfDecodeException('keyed_map: missing field declaration');
            }
        }

        $count = -1;
        if ($countStr !== '?') {
            $count = self::parseCount($countStr);
        }

        // A keyed map has at least one member; an empty object is encoded per
        // Section 7.7, never as [0:] (SPEC 7.2a.4).
        if ($keyed && $count === 0) {
            throw new GcfDecodeException('keyed_map: zero count [0:] is invalid (an empty object uses Section 7.7)');
        }

        if ($count === 0 && !str_starts_with($after, '{') && !str_starts_with($after, ':')) {
            return [[], 1];
        }

        if (str_starts_with($after, ': ') || $after === ':') {
            $valsStr = str_starts_with($after, ': ') ? substr($after, 2) : '';
            if ($valsStr === '') {
                if ($count >= 0 && $count !== 0) {
                    throw new GcfDecodeException("count_mismatch: declared {$count}, got 0");
                }

                return [[], 1];
            }
            $vals = Scalar::splitRespectingQuotes($valsStr, ',');
            if ($count >= 0 && count($vals) !== $count) {
                throw new GcfDecodeException("count_mismatch: declared {$count}, got ".count($vals));
            }

            return [array_map(
                static fn (string $v): mixed => self::sentinelToNull(Scalar::parseScalar(trim($v))),
                $vals,
            ), 1];
        }

        if (str_starts_with($after, '{')) {
            $braceEnd = self::findClosingBrace($after);
            if ($braceEnd < 0) {
                throw new GcfDecodeException('invalid field declaration');
            }
            $fields = Scalar::splitFieldDecl(substr($after, 0, $braceEnd + 1));
            [$rows, $consumed] = self::parseTabularBody($lines, $headerLine + 1, $depth, $fields, $count);
            if ($count >= 0 && count($rows) !== $count) {
                throw new GcfDecodeException("count_mismatch: declared {$count}, got ".count($rows));
            }
            if ($keyed) {
                return [KeyedMap::rowsToMap($rows, $fields), $consumed + 1];
            }

            return [$rows, $consumed + 1];
        }

        [$items, $consumed] = self::parseExpandedBody($lines, $headerLine + 1, $depth);
        if ($count >= 0 && count($items) !== $count) {
            throw new GcfDecodeException("count_mismatch: declared {$count}, got ".count($items));
        }

        return [$items, $consumed + 1];
    }

    /**
     * Finds " [" (the named-array count bracket) that is OUTSIDE any quoted name,
     * so a quoted section/key name containing " [" (e.g. `## "a [1] b"`) is not
     * misread as a named-array header. Mirrors findClosingBrace's quote tracking.
     */
    private static function findBracketStart(string $s): int
    {
        $inQuote = false;
        $escaped = false;
        $n = strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $c = $s[$i];
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
            if (!$inQuote && $c === ' ' && $i + 1 < $n && $s[$i + 1] === '[') {
                return $i;
            }
        }

        return -1;
    }

    private static function findClosingBrace(string $s): int
    {
        $inQuote = false;
        $escaped = false;
        $n = strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $c = $s[$i];
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
     * @return array{0: string, 1: string}
     */
    private static function parseAttachmentName(string $rest): array
    {
        $n = strlen($rest);
        if ($n > 0 && $rest[0] === '"') {
            $j = 1;
            while ($j < $n) {
                if ($rest[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($rest[$j] === '"') {
                    $name = Scalar::parseQuotedString(substr($rest, 0, $j + 1));

                    return [$name, substr($rest, $j + 1)];
                }
                ++$j;
            }

            return ['', $rest];
        }
        $sp = strpos($rest, ' ');
        if ($sp !== false) {
            return [substr($rest, 0, $sp), substr($rest, $sp)];
        }

        return [$rest, ''];
    }

    /**
     * Returns [name, value, consumed, parsedFields].
     *
     * @param list<string> $lines
     * @param array<string, list<string>> $sharedSchemas
     * @return array{0: string, 1: mixed, 2: int, 3: list<string>|null}
     */
    private static function parseAttachment(array $lines, int $lineIdx, string $rest, int $depth, array $sharedSchemas): array
    {
        [$name, $afterName] = self::parseAttachmentName($rest);
        if ($name === '' && !str_starts_with($rest, '""')) {
            throw new GcfDecodeException("invalid attachment: {$rest}");
        }
        $afterName = ltrim($afterName);

        if (str_starts_with($afterName, '{}')) {
            $nested = [];
            $consumed = self::parseObjectBody($lines, $lineIdx + 1, $depth, $nested);

            return [$name, $nested, $consumed + 1, null];
        }

        if (str_starts_with($afterName, '[')) {
            $cb = strpos($afterName, ']');
            if ($cb === false) {
                throw new GcfDecodeException('invalid_count: missing ]');
            }
            $afterClose = substr($afterName, $cb + 1);

            // [N]{fields}: has its own schema.
            if (str_starts_with($afterClose, '{')) {
                $endBrace = self::findClosingBrace($afterClose);
                $parsedFields = null;
                if ($endBrace >= 0) {
                    try {
                        $parsedFields = Scalar::splitFieldDecl(substr($afterClose, 0, $endBrace + 1));
                    } catch (GcfDecodeException) {
                        // Best-effort schema capture; malformed declarations fall through
                        // to the standard parse below, mirroring the Python `except Exception: pass`.
                    }
                }
                [$arr, $consumed] = self::parseArrayFromHeader($lines, $lineIdx, $depth, $afterName);

                return [$name, $arr, $consumed, $parsedFields];
            }

            // [N]: inline primitive array: don't use shared schema.
            if (str_starts_with($afterClose, ': ') || $afterClose === ':') {
                [$arr, $consumed] = self::parseArrayFromHeader($lines, $lineIdx, $depth, $afterName);

                return [$name, $arr, $consumed, null];
            }

            // [N] without {fields}: check for shared schema.
            if (isset($sharedSchemas[$name])) {
                $sf = $sharedSchemas[$name];
                $countStr = substr($afterName, 1, $cb - 1);
                $count = $countStr === '?' ? -1 : (int) $countStr;
                if ($count === 0) {
                    return [$name, [], 1, null];
                }
                // Peek: if next line starts with @, it's expanded.
                $useShared = true;
                $nextIdx = $lineIdx + 1;
                $ind = str_repeat('  ', $depth);
                if ($nextIdx < count($lines)) {
                    $nc = $lines[$nextIdx];
                    if ($depth > 0 && str_starts_with($nc, $ind)) {
                        $nc = substr($nc, strlen($ind));
                    }
                    if (str_starts_with(ltrim($nc), '@')) {
                        $useShared = false;
                    }
                }
                if ($useShared) {
                    [$rows, $consumed] = self::parseTabularBody($lines, $lineIdx + 1, $depth, $sf, $count);
                    if ($count >= 0 && count($rows) !== $count) {
                        throw new GcfDecodeException("count_mismatch: declared {$count}, got ".count($rows));
                    }

                    return [$name, $rows, $consumed + 1, null];
                }
            }

            // No shared schema: standard parsing.
            [$arr, $consumed] = self::parseArrayFromHeader($lines, $lineIdx, $depth, $afterName);

            return [$name, $arr, $consumed, null];
        }

        // Scalar: =value (field names containing ">" excluded from tabular columns).
        if (str_starts_with($afterName, '=')) {
            $valStr = substr($afterName, 1);
            $parsed = Scalar::parseScalar($valStr, true);
            if ($parsed === Sentinel::Missing) {
                return [$name, null, 1, null];
            }

            return [$name, $parsed, 1, null];
        }

        throw new GcfDecodeException("invalid attachment form: {$afterName}");
    }

    /**
     * Reconstructs nested objects from flat > path columns.
     *
     * @param array<string, list<string>> $pathColumns
     * @param array<string, mixed> $flatValues
     * @param array<string, true> $flatAbsent
     * @return array<string, mixed>
     */
    private static function unflattenPaths(array $pathColumns, array $flatValues, array $flatAbsent): array
    {
        $groups = [];
        $groupOrder = [];
        foreach ($pathColumns as $fieldName => $paths) {
            if ($paths === []) {
                continue;
            }
            $top = $paths[0];
            if (!isset($groups[$top])) {
                $groups[$top] = [];
                $groupOrder[] = $top;
            }
            $groups[$top][] = $fieldName;
        }

        $result = [];

        foreach ($groupOrder as $top) {
            $fieldNames = $groups[$top];
            $allAbsent = true;
            foreach ($fieldNames as $f) {
                if (!isset($flatAbsent[$f])) {
                    $allAbsent = false;
                    break;
                }
            }
            $allNull = true;
            foreach ($fieldNames as $f) {
                if (isset($flatAbsent[$f]) || array_key_exists($f, $flatValues) && $flatValues[$f] !== null) {
                    $allNull = false;
                    break;
                }
            }

            if ($allAbsent) {
                continue;
            }
            if ($allNull) {
                $result[$top] = null;
                continue;
            }

            foreach ($fieldNames as $fieldName) {
                if (isset($flatAbsent[$fieldName])) {
                    continue;
                }
                $paths = $pathColumns[$fieldName];
                $val = $flatValues[$fieldName] ?? null;

                $current = &$result;
                $lastKey = $paths[count($paths) - 1];
                foreach (array_slice($paths, 0, -1) as $k) {
                    if (!array_key_exists($k, $current)) {
                        $current[$k] = [];
                    }
                    $current = &$current[$k];
                }
                $current[$lastKey] = $val;
                unset($current);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $fields
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function parseTabularBody(array $lines, int $start, int $depth, array $fields, int $expectedCount): array
    {
        $ind = str_repeat('  ', $depth);
        $rows = [];
        $i = $start;
        $n = count($lines);

        // Detect path columns: fields containing ">".
        $pathColumnMap = [];
        foreach ($fields as $f) {
            if (str_contains($f, '>')) {
                $parts = explode('>', $f);
                // Only treat as a path column if all segments are non-empty.
                // A literal key like ">" would split into ["", ""].
                $allNonEmpty = true;
                foreach ($parts as $p) {
                    if ($p === '') {
                        $allNonEmpty = false;
                        break;
                    }
                }
                if ($allNonEmpty) {
                    $pathColumnMap[$f] = $parts;
                }
            }
        }

        // Track inline schemas and shared array schemas.
        $inlineSchemas = [];
        $sharedArraySchemas = [];

        while ($i < $n) {
            $line = $lines[$i];
            if ($depth > 0 && !str_starts_with($line, $ind)) {
                break;
            }
            $content = $depth > 0 ? substr($line, strlen($ind)) : $line;
            if (str_starts_with($content, '## ') || str_starts_with($content, '##!')) {
                break;
            }
            if ($content !== '' && $content[0] === ' ') {
                break;
            }

            // Strip @N prefix (must be @digits).
            $rowData = $content;
            $rowHasId = false;
            if (str_starts_with($rowData, '@')) {
                $sp = strpos($rowData, ' ');
                if ($sp !== false && $sp > 0) {
                    $idStr = substr($rowData, 1, $sp - 1);
                    if ($idStr !== '' && ctype_digit($idStr)) {
                        $rowData = substr($rowData, $sp + 1);
                        $rowHasId = true;
                    }
                }
            }

            $vals = Scalar::splitRespectingQuotes($rowData, '|');
            if (count($vals) !== count($fields)) {
                throw new GcfDecodeException('row_width_mismatch: expected '.count($fields).', got '.count($vals));
            }

            // Parse cells.
            $cellValues = [];
            $traditionalAttFields = [];
            $inlineAttFields = [];
            $inlineAttOrder = [];
            $missingFields = [];

            // Collect path column values for unflattening.
            $flatValues = [];
            $flatAbsent = [];

            foreach ($fields as $j => $f) {
                $cellVal = $vals[$j];

                // Path columns: store values for later unflattening.
                if (isset($pathColumnMap[$f])) {
                    $parsed = Scalar::parseScalar($cellVal, true);
                    if ($parsed === Sentinel::Missing) {
                        $flatAbsent[$f] = true;
                    } else {
                        $flatValues[$f] = $parsed;
                    }
                    continue;
                }

                // Check for ^{fields} inline schema declaration.
                if (str_starts_with($cellVal, '^{') && str_ends_with($cellVal, '}')) {
                    $ifs = Scalar::splitFieldDecl(substr($cellVal, 1));
                    $inlineSchemas[$f] = $ifs;
                    $inlineAttFields[] = $f;
                    $inlineAttOrder[] = $f;
                    continue;
                }

                $parsed = Scalar::parseScalar($cellVal, true);
                if ($parsed === Sentinel::Missing) {
                    $missingFields[$f] = true;
                } elseif ($parsed === Sentinel::Attachment) {
                    if (isset($inlineSchemas[$f])) {
                        $inlineAttFields[] = $f;
                        $inlineAttOrder[] = $f;
                    } else {
                        $traditionalAttFields[] = $f;
                    }
                } else {
                    $cellValues[$f] = $parsed;
                }
            }
            ++$i;

            // Parse attachments in line order.
            $allAttFields = [...$traditionalAttFields, ...$inlineAttFields];
            $attachmentValues = [];

            if ($rowHasId) {
                $inlineIdx = 0;

                // Columns that carry a `^` marker cell in this row legitimately expect
                // a `.field` body. Any other `.field` is an orphan (Section 16.5) unless
                // its name contains `>` (the flatten-fallback attachment, Section 7.4.6.1.4).
                $expectedAtt = array_fill_keys([...$traditionalAttFields, ...$inlineAttFields], true);

                while ($i < $n) {
                    $aLine = $lines[$i];
                    $aContent = null;
                    if ($depth === 0 || str_starts_with($aLine, $ind)) {
                        $aContent = $depth > 0 ? substr($aLine, strlen($ind)) : $aLine;
                    } else {
                        break;
                    }
                    if ($aContent === null) {
                        break;
                    }

                    // Handle v2 indented attachments: strip one extra indent level.
                    if (!str_starts_with($aContent, '.') && str_starts_with($aContent, '  .')) {
                        $aContent = substr($aContent, 2);
                    }

                    // Line starts with ".": traditional or prefixed inline.
                    if (str_starts_with($aContent, '.')) {
                        $rest = substr($aContent, 1);
                        [$attName, $afterName] = self::parseAttachmentName($rest);
                        $afterNameStripped = ltrim($afterName);

                        // Orphan attachment: a `.field` with no matching `^` cell in this
                        // row is only legitimate for a `>`-named field (Section 7.4.6.1.4).
                        // Any other unmatched attachment is rejected rather than silently
                        // injected as an undeclared extra field, which would decode to a
                        // record no encoder produces (Section 16.5, lossless round-trip).
                        if (!isset($expectedAtt[$attName]) && !str_contains($attName, '>')) {
                            throw new GcfDecodeException("orphan_attachment: {$attName}");
                        }

                        // Prefixed inline data.
                        $ifs = $inlineSchemas[$attName] ?? null;
                        if ($ifs !== null && !str_starts_with($afterNameStripped, '{}') && !str_starts_with($afterNameStripped, '[')) {
                            $inlineVals = Scalar::splitRespectingQuotes($afterNameStripped, '|');
                            if (count($inlineVals) !== count($ifs)) {
                                throw new GcfDecodeException("inline_width_mismatch: {$attName} expected ".count($ifs).', got '.count($inlineVals));
                            }
                            $obj = [];
                            foreach ($ifs as $k => $inf) {
                                $p = Scalar::parseScalar($inlineVals[$k], true);
                                if ($p !== Sentinel::Missing) {
                                    $obj[$inf] = $p;
                                }
                            }
                            if (array_key_exists($attName, $attachmentValues)) {
                                throw new GcfDecodeException("duplicate_attachment: {$attName}");
                            }
                            $attachmentValues[$attName] = $obj;
                            ++$i;
                            continue;
                        }

                        // Traditional attachment.
                        [$attNameT, $attVal, $consumed, $parsedFields] = self::parseAttachment(
                            $lines, $i, $rest, $depth + 2, $sharedArraySchemas,
                        );
                        if ($rows === [] && $parsedFields !== null) {
                            $sharedArraySchemas[$attNameT] = $parsedFields;
                        }
                        if (array_key_exists($attNameT, $attachmentValues)) {
                            throw new GcfDecodeException("duplicate_attachment: {$attNameT}");
                        }
                        $attachmentValues[$attNameT] = $attVal;
                        $i += $consumed;
                        continue;
                    }

                    // No-prefix line: positional inline data.
                    $foundInline = false;
                    $nextInlineField = '';
                    while ($inlineIdx < count($inlineAttOrder)) {
                        $candidate = $inlineAttOrder[$inlineIdx];
                        if (!array_key_exists($candidate, $attachmentValues)) {
                            $nextInlineField = $candidate;
                            $foundInline = true;
                            break;
                        }
                        ++$inlineIdx;
                    }
                    if (!$foundInline) {
                        break;
                    }

                    $ifs = $inlineSchemas[$nextInlineField];
                    $inlineVals = Scalar::splitRespectingQuotes($aContent, '|');
                    if (count($inlineVals) !== count($ifs)) {
                        throw new GcfDecodeException("inline_width_mismatch: {$nextInlineField} expected ".count($ifs).', got '.count($inlineVals));
                    }
                    $obj = [];
                    foreach ($ifs as $k => $inf) {
                        $p = Scalar::parseScalar($inlineVals[$k], true);
                        if ($p !== Sentinel::Missing) {
                            $obj[$inf] = $p;
                        }
                    }
                    $attachmentValues[$nextInlineField] = $obj;
                    ++$inlineIdx;
                    ++$i;
                }

                foreach ($allAttFields as $f) {
                    if (!array_key_exists($f, $attachmentValues)) {
                        throw new GcfDecodeException("missing_attachment: {$f}");
                    }
                }

                // Check for extra attachment lines after all fields resolved (duplicate).
                if ($i < $n) {
                    $extraLine = $lines[$i];
                    $extraContent = '';
                    if ($depth === 0 || str_starts_with($extraLine, $ind)) {
                        $extraContent = $depth > 0 ? substr($extraLine, strlen($ind)) : $extraLine;
                    }
                    if (!str_starts_with($extraContent, '.') && str_starts_with($extraContent, '  .')) {
                        $extraContent = substr($extraContent, 2);
                    }
                    if (str_starts_with($extraContent, '.')) {
                        [$extraName] = self::parseAttachmentName(substr($extraContent, 1));
                        if (array_key_exists($extraName, $attachmentValues)) {
                            throw new GcfDecodeException("duplicate_attachment: {$extraName}");
                        }
                    }
                }
            }

            // Reconstruct the row in declared field-union order. A flattened group is
            // emitted at the position of its first path column, so the nested object
            // reappears where the original field was, not appended at the end (SPEC
            // 7.4.6.1 step 7 and the key-order preservation requirement, SPEC 52, 931).
            $nested = $pathColumnMap !== [] ? self::unflattenPaths($pathColumnMap, $flatValues, $flatAbsent) : [];
            $emittedGroups = [];
            $row = [];
            foreach ($fields as $f) {
                if (isset($pathColumnMap[$f])) {
                    $top = $pathColumnMap[$f][0];
                    if (isset($emittedGroups[$top])) {
                        continue;
                    }
                    $emittedGroups[$top] = true;
                    if (array_key_exists($top, $nested)) { // omitted when the whole group is absent
                        $row[$top] = $nested[$top];
                    }
                    continue;
                }
                if (isset($missingFields[$f])) {
                    continue;
                }
                if (array_key_exists($f, $cellValues)) {
                    $row[$f] = $cellValues[$f];
                } elseif (array_key_exists($f, $attachmentValues)) {
                    $row[$f] = $attachmentValues[$f];
                }
            }
            // Also add any orphan attachment values (fields excluded from column list, e.g. ">" fields).
            foreach ($attachmentValues as $k => $v) {
                if (!array_key_exists($k, $row)) {
                    $row[$k] = $v;
                }
            }

            $rows[] = $row;

            if ($expectedCount >= 0 && count($rows) >= $expectedCount) {
                break;
            }
        }

        return [$rows, $i - $start];
    }

    /**
     * @param list<string> $lines
     * @return array{0: list<mixed>, 1: int}
     */
    private static function parseExpandedBody(array $lines, int $start, int $depth): array
    {
        $ind = str_repeat('  ', $depth);
        $items = [];
        $i = $start;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            if ($depth > 0 && !str_starts_with($line, $ind)) {
                break;
            }
            $content = $depth > 0 ? substr($line, strlen($ind)) : $line;
            if (str_starts_with($content, '## ') || str_starts_with($content, '##!')) {
                break;
            }
            if (!str_starts_with($content, '@')) {
                break;
            }
            $sp = strpos($content, ' ');
            if ($sp === false) {
                break;
            }

            $idStr = substr($content, 1, $sp - 1);
            $trimmedId = trim($idStr);
            if (preg_match('/^[+-]?\d+$/', $trimmedId) === 1) {
                $itemId = (int) $trimmedId;
                if ($itemId !== count($items)) {
                    throw new GcfDecodeException('invalid_item_id: expected @'.count($items).", got @{$idStr}");
                }
            }
            // Otherwise: a non-numeric id silently skips validation, mirroring the
            // Python reference's swallowed ValueError from int(id_str).

            $marker = substr($content, $sp + 1);

            if (str_starts_with($marker, '=')) {
                $items[] = self::sentinelToNull(Scalar::parseScalar(substr($marker, 1)));
                ++$i;
                continue;
            }
            if (str_starts_with($marker, '{}')) {
                $nested = [];
                ++$i;
                $consumed = self::parseObjectBody($lines, $i, $depth + 1, $nested);
                $items[] = $nested;
                $i += $consumed;
                continue;
            }
            if (str_starts_with($marker, '[')) {
                [$arr, $consumed] = self::parseArrayFromHeader($lines, $i, $depth + 1, $marker);
                $items[] = $arr;
                $i += $consumed;
                continue;
            }
            break;
        }

        return [$items, $i - $start];
    }

    private static function parseCount(string $s): int
    {
        if ($s === '0') {
            return 0;
        }
        if ($s === '' || $s[0] === '0') {
            throw new GcfDecodeException("invalid_count: {$s}");
        }
        if (preg_match('/^-?[0-9]+$/', $s) !== 1) {
            throw new GcfDecodeException("invalid_count: {$s}");
        }
        $n = (int) $s;
        if ((string) $n !== $s) {
            throw new GcfDecodeException("invalid_count: {$s}");
        }

        return $n;
    }

    /**
     * @param list<string> $contentLines
     */
    private static function validateSummaryCounts(string $summaryLine, int $deferredCount, array $contentLines): void
    {
        $countsStr = '';
        foreach (preg_split('/\s+/', $summaryLine, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (str_starts_with($p, 'counts=')) {
                $countsStr = substr($p, 7);
                break;
            }
        }
        if ($countsStr === '') {
            return;
        }
        $countVals = explode(',', $countsStr);
        if (count($countVals) !== $deferredCount) {
            throw new GcfDecodeException(
                'count_mismatch: summary has '.count($countVals)." count entries but {$deferredCount} deferred sections",
            );
        }
        $actualCounts = [];
        $inDeferred = false;
        $currentCount = 0;
        foreach ($contentLines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '## ') && (str_contains($trimmed, '[?]') || str_contains($trimmed, '[?:]'))) {
                if ($inDeferred) {
                    $actualCounts[] = $currentCount;
                }
                $inDeferred = true;
                $currentCount = 0;
                continue;
            }
            if (str_starts_with($trimmed, '## ')) {
                if ($inDeferred) {
                    $actualCounts[] = $currentCount;
                    $inDeferred = false;
                }
                continue;
            }
            if ($inDeferred && !str_starts_with($trimmed, ' ') && !str_starts_with($trimmed, '.')) {
                ++$currentCount;
            }
        }
        if ($inDeferred) {
            $actualCounts[] = $currentCount;
        }
        foreach ($countVals as $idx => $cv) {
            if (preg_match('/^-?\d+$/', $cv) !== 1) {
                throw new GcfDecodeException("count_mismatch: invalid count value '{$cv}'");
            }
            $declared = (int) $cv;
            if ($idx < count($actualCounts) && $declared !== $actualCounts[$idx]) {
                throw new GcfDecodeException(
                    "count_mismatch: section {$idx} declared {$declared} in summary, actual {$actualCounts[$idx]}",
                );
            }
        }
    }
}
