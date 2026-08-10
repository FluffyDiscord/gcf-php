<?php

declare(strict_types=1);

namespace Gcf\Tests;

use Gcf\Components;
use Gcf\DeltaPayload;
use Gcf\Edge;
use Gcf\GcfDecodeException;
use Gcf\Graph\Delta;
use Gcf\Payload;
use Gcf\Session;
use Gcf\Symbol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the graph-profile delta and session conformance fixtures under
 * tests/conformance/graph-delta/ and tests/conformance/graph-session/ against
 * Gcf\Graph\Delta and Gcf\Session, plus the single graph-scoped malformed-delta
 * error fixture (errors-v2/033_malformed_delta.json) that
 * tests/GraphConformanceTest.php deliberately excludes.
 *
 * graph-delta/ fixtures come in two shapes:
 *  - encode_delta style ("input" is a DeltaPayload object, "expected" is GCF
 *    text) -> exercised via Delta::encode().
 *  - decode+verify style ("input" is GCF text plus a "base_snapshot" and
 *    either "expected_snapshot" or "expectedError") -> exercised via
 *    Delta::decode() followed by Delta::verify().
 *
 * graph-session/ fixtures carry a "calls" array instead of a single
 * input/expected pair; each call is run against the SAME Session instance in
 * sequence, simulating a multi-turn conversation, asserting each call's
 * output as we go.
 */
final class GraphDeltaSessionConformanceTest extends TestCase
{
    #[DataProvider('deltaFixtures')]
    public function testDeltaFixture(string $path): void
    {
        $fixture = self::rawFixture($path);

        if (isset($fixture['base_snapshot'])) {
            self::assertVerifyFixture($fixture);

            return;
        }

        self::assertSame(
            $fixture['expected'],
            Delta::encode(self::hydrateDeltaPayload($fixture['input'])),
        );
    }

    #[DataProvider('sessionFixtures')]
    public function testSessionFixture(string $path): void
    {
        $fixture = self::rawFixture($path);
        $session = new Session();

        foreach ($fixture['calls'] as $i => $call) {
            $actual = $session->encode(self::hydratePayload($call['input']));
            self::assertSame($call['expected'], $actual, "{$path} call #{$i}");
        }
    }

    public function testMalformedDeltaSectionIsRejected(): void
    {
        $fixture = self::rawFixture(__DIR__.'/conformance/errors-v2/033_malformed_delta.json');

        try {
            Delta::decode($fixture['input']);
            self::fail("Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.");
        } catch (GcfDecodeException $e) {
            self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function deltaFixtures(): iterable
    {
        $paths = glob(__DIR__.'/conformance/graph-delta/*.json');
        sort($paths);
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sessionFixtures(): iterable
    {
        $paths = glob(__DIR__.'/conformance/graph-session/*.json');
        sort($paths);
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function assertVerifyFixture(array $fixture): void
    {
        $decoded = Delta::decode($fixture['input']);

        $baseSymbols = array_map(self::hydrateSymbol(...), $fixture['base_snapshot']['symbols'] ?? []);
        $baseEdges = array_map(self::hydrateEdge(...), $fixture['base_snapshot']['edges'] ?? []);

        if (isset($fixture['expectedError'])) {
            try {
                Delta::verify(
                    $baseSymbols,
                    $baseEdges,
                    $decoded->removed,
                    $decoded->added,
                    $decoded->removedEdges,
                    $decoded->addedEdges,
                    $decoded->newRoot,
                );
                self::fail(
                    "Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.",
                );
            } catch (GcfDecodeException $e) {
                self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
            }

            return;
        }

        [$symbols, $edges] = Delta::verify(
            $baseSymbols,
            $baseEdges,
            $decoded->removed,
            $decoded->added,
            $decoded->removedEdges,
            $decoded->addedEdges,
            $decoded->newRoot,
        );

        self::assertEquals(
            array_map(self::hydrateSymbol(...), $fixture['expected_snapshot']['symbols'] ?? []),
            $symbols,
        );
        self::assertEquals(
            array_map(self::hydrateEdge(...), $fixture['expected_snapshot']['edges'] ?? []),
            $edges,
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

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateDeltaPayload(array $d): DeltaPayload
    {
        return new DeltaPayload(
            tool: $d['tool'] ?? '',
            baseRoot: $d['baseRoot'] ?? '',
            newRoot: $d['newRoot'] ?? '',
            removed: array_map(self::hydrateSymbol(...), $d['removed'] ?? []),
            added: array_map(self::hydrateSymbol(...), $d['added'] ?? []),
            removedEdges: array_map(self::hydrateEdge(...), $d['removedEdges'] ?? []),
            addedEdges: array_map(self::hydrateEdge(...), $d['addedEdges'] ?? []),
            deltaTokens: $d['deltaTokens'] ?? 0,
            fullTokens: $d['fullTokens'] ?? 0,
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydratePayload(array $d): Payload
    {
        return new Payload(
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
            signature: $d['signature'] ?? '',
            components: isset($d['components']) ? self::hydrateComponents($d['components']) : new Components(),
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
     * @param array<string, mixed> $d
     */
    private static function hydrateComponents(array $d): Components
    {
        return new Components(
            blastRadius: (float) ($d['blastRadius'] ?? 0.0),
            confidence: (float) ($d['confidence'] ?? 0.0),
            recency: (float) ($d['recency'] ?? 0.0),
            distance: (float) ($d['distance'] ?? 0.0),
        );
    }
}
