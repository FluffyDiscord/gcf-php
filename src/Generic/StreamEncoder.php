<?php

declare(strict_types=1);

namespace Gcf\Generic;

use Gcf\Scalar;
use Gcf\StreamSink;

/**
 * Writes GCF generic-profile tabular output incrementally as rows arrive
 * (SPEC section 8, 7.2a, 7.4). Ported from gcf-python's stream_generic.py
 * `GenericStreamEncoder`.
 *
 * Zero buffering: each row is written immediately to $sink. A trailer summary
 * is emitted on close() with the final counts.
 *
 * $sink accepts a PHP stream resource (written via fwrite) or a
 * callable(string $chunk): void — see Gcf\StreamSink.
 *
 * Example:
 *
 *     $enc = new StreamEncoder(STDOUT);
 *     $enc->beginArray('employees', ['id', 'name', 'department', 'salary']);
 *     $enc->writeRow([1, 'Alice', 'Engineering', 95000]);
 *     $enc->writeRow([2, 'Bob', 'Sales', 72000]);
 *     $enc->endArray();
 *     $enc->close();
 */
final class StreamEncoder
{
    /** @var list<array{0: string, 1: int}> */
    private array $sections = [];

    /** @var array{name: string, fields: list<string>, count: int}|null */
    private ?array $current = null;

    private ?\Throwable $err = null;

    public function __construct(private readonly mixed $sink)
    {
        $this->writeChunk("GCF profile=generic\n");
    }

    /**
     * Starts a tabular array section with deferred count [?].
     *
     * @param list<string> $fields
     */
    public function beginArray(string $name, array $fields): void
    {
        if ($this->err !== null) {
            return;
        }
        if ($this->current !== null) {
            $this->endArrayLocked();
        }
        // A streaming tabular row has only flat columns; a field name containing
        // ">" is a flattened path the stream cannot represent (SPEC 8.3, 7.4.6).
        // Record the error and surface it at close().
        foreach ($fields as $f) {
            if (str_contains($f, '>')) {
                $this->err = new \InvalidArgumentException(
                    "streaming field name '{$f}' contains '>' (a flattened path is not representable in a streaming row)",
                );

                return;
            }
        }
        $this->writeChunk('## '.Scalar::formatKey($name).' [?]{'.self::formatFieldDecl($fields)."}\n");
        $this->current = ['name' => $name, 'fields' => $fields, 'count' => 0];
    }

    /**
     * Starts a keyed-map section with deferred count [?:] (SPEC 7.2a).
     *
     * $keyLabel is the key column; $valueFields are the value-object fields.
     * Each writeRow() values slice is [keyValue, ...valueFields].
     *
     * @param list<string> $valueFields
     */
    public function beginKeyedMap(string $name, string $keyLabel, array $valueFields): void
    {
        if ($this->err !== null) {
            return;
        }
        if ($this->current !== null) {
            $this->endArrayLocked();
        }
        // A streaming value field name containing ">" is a flattened path a
        // stream cannot represent (SPEC 8.3, 7.4.6). Record and surface at close().
        foreach ($valueFields as $f) {
            if (str_contains($f, '>')) {
                $this->err = new \InvalidArgumentException(
                    "streaming field name '{$f}' contains '>' (a flattened path is not representable in a streaming row)",
                );

                return;
            }
        }
        $fields = [$keyLabel, ...$valueFields];
        $this->writeChunk('## '.Scalar::formatKey($name).' [?:]{'.self::formatFieldDecl($fields)."}\n");
        $this->current = ['name' => $name, 'fields' => $fields, 'count' => 0];
    }

    /**
     * Emits a single pipe-separated row immediately.
     *
     * @param list<mixed> $values
     */
    public function writeRow(array $values): void
    {
        if ($this->current === null) {
            return;
        }
        $parts = array_map(static fn (mixed $v): string => Scalar::formatScalar($v, '|'), $values);
        $this->writeChunk(implode('|', $parts)."\n");
        ++$this->current['count'];
    }

    /** Closes the current array section and records its count. */
    public function endArray(): void
    {
        $this->endArrayLocked();
    }

    /** Emits a key=value line immediately. */
    public function writeKv(string $key, mixed $value): void
    {
        $this->writeChunk("{$key}=".Scalar::formatScalar($value)."\n");
    }

    /** Starts a nested object section (## key). */
    public function writeSection(string $name): void
    {
        if ($this->current !== null) {
            $this->endArrayLocked();
        }
        $this->writeChunk("## {$name}\n");
    }

    /**
     * Emits a primitive array inline: name[N]: val1,val2,val3.
     *
     * @param list<mixed> $values
     */
    public function writeInlineArray(string $name, array $values): void
    {
        $parts = array_map(static fn (mixed $v): string => Scalar::formatScalar($v, '|'), $values);
        $this->writeChunk("{$name}[".count($values).']: '.implode(',', $parts)."\n");
    }

    /**
     * Emits the ##! summary trailer with final counts.
     *
     * @throws \Throwable any error recorded during encoding (e.g. a field name
     *                     containing ">", which is not representable in a flat
     *                     streaming row per SPEC 8.3)
     */
    public function close(): void
    {
        if ($this->err !== null) {
            throw $this->err;
        }
        if ($this->current !== null) {
            $this->endArrayLocked();
        }
        if ($this->sections === []) {
            return;
        }
        $counts = array_map(static fn (array $s): string => (string) $s[1], $this->sections);
        $this->writeChunk('##! summary counts='.implode(',', $counts)."\n");
    }

    private function endArrayLocked(): void
    {
        if ($this->current === null) {
            return;
        }
        $this->sections[] = [$this->current['name'], $this->current['count']];
        $this->current = null;
    }

    /**
     * @param list<string> $fields
     */
    private static function formatFieldDecl(array $fields): string
    {
        return implode(',', array_map(Scalar::formatKey(...), $fields));
    }

    private function writeChunk(string $chunk): void
    {
        StreamSink::write($this->sink, $chunk);
    }
}
