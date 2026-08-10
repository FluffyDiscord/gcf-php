<?php

declare(strict_types=1);

namespace Gcf\Tests;

use Gcf\Edge;
use Gcf\Generic\Decoder as GenericDecoder;
use Gcf\Graph\Decoder as GraphDecoder;
use Gcf\Graph\StreamEncoder;
use Gcf\Symbol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the streaming GCF conformance fixtures under tests/conformance/streaming-v2/.
 *
 * Two operations appear in this fixture set:
 *
 *  - "decode": GCF text containing streaming-only syntax (a deferred [?]/[?:]
 *    section count and/or a ##! summary trailer) fed through the existing
 *    full-snapshot Gcf\Generic\Decoder / Gcf\Graph\Decoder — decode is decode
 *    regardless of whether the writer streamed the output, so no separate
 *    streaming decoder exists. The profile= field in the input header picks
 *    which decoder runs.
 *  - "graph-stream-encode": drives Gcf\Graph\StreamEncoder incrementally
 *    (writeSymbol/writeEdge/close) over an in-memory sink and compares the
 *    accumulated text against "expected".
 */
final class StreamingConformanceTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testFixture(string $path): void
    {
        $fixture = self::rawFixture($path);
        $operation = $fixture['operation'];

        match ($operation) {
            'decode' => self::assertDecodeFixture($fixture),
            'graph-stream-encode' => self::assertGraphStreamEncodeFixture($fixture),
            default => self::fail("Unknown fixture operation: {$operation}"),
        };
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtures(): iterable
    {
        $root = __DIR__.'/conformance/streaming-v2';
        $paths = glob("{$root}/*.json") ?: [];
        sort($paths);

        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function assertDecodeFixture(array $fixture): void
    {
        $input = $fixture['input'];
        $firstLine = explode("\n", $input, 2)[0];

        if (str_contains($firstLine, 'profile=graph')) {
            self::assertEquals(
                self::hydratePayload($fixture['expected']),
                GraphDecoder::decode($input),
            );

            return;
        }

        self::assertEquals(
            $fixture['expected'],
            GenericDecoder::decode($input),
        );
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function assertGraphStreamEncodeFixture(array $fixture): void
    {
        $input = $fixture['input'];
        $options = $fixture['options'] ?? [];

        $sink = fopen('php://memory', 'w+');
        try {
            $enc = new StreamEncoder(
                $sink,
                (string) ($input['tool'] ?? ''),
                tokenBudget: (int) ($input['tokenBudget'] ?? 0),
                tokensUsed: (int) ($input['tokensUsed'] ?? 0),
                packRoot: (string) ($input['packRoot'] ?? ''),
                labeledTrailerCounts: (bool) ($options['labeledTrailerCounts'] ?? false),
            );

            foreach ($input['symbols'] ?? [] as $s) {
                $enc->writeSymbol(self::hydrateSymbol($s));
            }
            foreach ($input['edges'] ?? [] as $e) {
                $enc->writeEdge(self::hydrateEdge($e));
            }
            $enc->close();

            rewind($sink);
            $actual = stream_get_contents($sink);
        } finally {
            fclose($sink);
        }

        self::assertSame($fixture['expected'], $actual);
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydratePayload(array $d): \Gcf\Payload
    {
        return new \Gcf\Payload(
            tool: $d['tool'] ?? '',
            tokensUsed: $d['tokensUsed'] ?? 0,
            tokenBudget: $d['tokenBudget'] ?? 0,
            packRoot: $d['packRoot'] ?? '',
            symbols: array_map(self::hydrateSymbol(...), $d['symbols'] ?? []),
            edges: array_map(self::hydrateEdge(...), $d['edges'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateSymbol(array $d): Symbol
    {
        return new Symbol(
            qualifiedName: $d['qualifiedName'] ?? '',
            kind: $d['kind'] ?? '',
            score: (float) ($d['score'] ?? 0.0),
            provenance: $d['provenance'] ?? '',
            distance: $d['distance'] ?? 0,
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateEdge(array $d): Edge
    {
        return new Edge(
            source: $d['source'] ?? '',
            target: $d['target'] ?? '',
            edgeType: $d['edgeType'] ?? '',
            status: $d['status'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawFixture(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read fixture: {$path}");
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
