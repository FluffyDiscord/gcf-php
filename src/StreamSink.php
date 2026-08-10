<?php

declare(strict_types=1);

namespace Gcf;

/**
 * Writes a chunk of text to a streaming sink. Shared by Graph\StreamEncoder
 * and Generic\StreamEncoder, mirroring gcf-python's stream.py/stream_generic.py
 * duck-typed `writer.write(s)` protocol: a $sink is either a PHP stream
 * resource (written via fwrite) or a callable(string $chunk): void.
 */
final class StreamSink
{
    private function __construct()
    {
    }

    public static function write(mixed $sink, string $chunk): void
    {
        if (is_resource($sink)) {
            fwrite($sink, $chunk);

            return;
        }
        if (is_callable($sink)) {
            $sink($chunk);

            return;
        }

        throw new \InvalidArgumentException('sink must be a stream resource or a callable(string $chunk): void');
    }
}
