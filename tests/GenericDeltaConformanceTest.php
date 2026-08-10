<?php

declare(strict_types=1);

namespace Gcf\Tests;

use Gcf\GcfDecodeException;
use Gcf\Generic\Delta\GenericDeltaDecoder;
use Gcf\Generic\Delta\GenericDeltaEncoder;
use Gcf\Generic\Delta\GenericDeltaPayload;
use Gcf\Generic\Delta\GenericDeltaSession;
use Gcf\Generic\Delta\GenericDeltaVerifier;
use Gcf\Generic\Delta\GenericPackRoot;
use Gcf\Generic\Delta\GenericSet;
use Gcf\Generic\Delta\Reanchor;
use Gcf\Generic\Delta\ReanchorPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the vendored GCF conformance fixtures under tests/conformance/generic-delta/,
 * generic-delta-session/, and generic-pack-root/ against the generic-profile delta
 * codec (GenericDiff, GenericPackRoot, GenericDeltaEncoder/Decoder, GenericDeltaVerifier,
 * GenericDeltaSession). Follows the same fixture-runner pattern as ConformanceTest.
 */
final class GenericDeltaConformanceTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testFixture(string $path): void
    {
        $fixture = self::readFixture($path);
        $operation = $fixture['operation'];

        match ($operation) {
            'generic-pack-root' => self::assertSame(
                $fixture['expected'],
                GenericPackRoot::compute(self::buildSet($fixture['input'])),
            ),
            'generic-delta' => self::assertDeltaEncodeFixture($fixture),
            'generic-delta-verify' => self::assertDeltaVerifyFixture($fixture),
            'generic-delta-decode' => self::assertDeltaDecodeFixture($fixture),
            'generic-delta-session' => self::assertDeltaSessionFixture($fixture),
            default => self::fail("Unknown fixture operation: {$operation}"),
        };
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtures(): iterable
    {
        $root = __DIR__.'/conformance';
        foreach (['generic-delta', 'generic-delta-session', 'generic-pack-root'] as $dir) {
            $full = $root.'/'.$dir;
            $paths = [];
            foreach (new \DirectoryIterator($full) as $file) {
                if ($file->isFile() && $file->getExtension() === 'json') {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths);
            foreach ($paths as $path) {
                $rel = $dir.'/'.basename($path);
                yield $rel => [$path];
            }
        }
    }

    // --- operation handlers ---

    private static function assertDeltaEncodeFixture(array $fixture): void
    {
        $in = $fixture['input'];
        $d = new GenericDeltaPayload(
            key: $in['key'],
            fields: $in['fields'],
            baseRoot: $in['baseRoot'] ?? '',
            newRoot: $in['newRoot'] ?? '',
            added: $in['added'] ?? [],
            changed: $in['changed'] ?? [],
            removed: $in['removed'] ?? [],
            tool: $in['tool'] ?? '',
            deltaTokens: $in['deltaTokens'] ?? 0,
            fullTokens: $in['fullTokens'] ?? 0,
        );
        self::assertSame($fixture['expected'], GenericDeltaEncoder::encodeDelta($d));
    }

    private static function assertDeltaVerifyFixture(array $fixture): void
    {
        $in = $fixture['input'];
        $base = self::buildSet($in['base']);
        $delta = self::buildDeltaPayload($in['delta']);
        $expectedNewRoot = $in['expectedNewRoot'];

        if (isset($fixture['expectedError'])) {
            try {
                GenericDeltaVerifier::verify($base, $delta, $expectedNewRoot);
                self::fail("Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.");
            } catch (GcfDecodeException $e) {
                self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
            }

            return;
        }

        $result = GenericDeltaVerifier::verify($base, $delta, $expectedNewRoot);
        self::assertSame($fixture['expected'], GenericPackRoot::compute($result));
    }

    private static function assertDeltaDecodeFixture(array $fixture): void
    {
        $in = $fixture['input'];

        if (isset($fixture['expectedError'])) {
            try {
                $d = GenericDeltaDecoder::decodeDelta($in['wire']);
                // Decoding alone may succeed for some error fixtures; verifying
                // against the base is where a count/shape mismatch would have
                // already been raised, so if we got here without an exception
                // during decode, still allow verify to raise it.
                $base = self::buildSet($in['base']);
                GenericDeltaVerifier::verify($base, $d, $in['expectedNewRoot']);
                self::fail("Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.");
            } catch (GcfDecodeException $e) {
                self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
            }

            return;
        }

        $d = GenericDeltaDecoder::decodeDelta($in['wire']);
        $base = self::buildSet($in['base']);
        $result = GenericDeltaVerifier::verify($base, $d, $in['expectedNewRoot']);
        self::assertSame($fixture['expected'], GenericPackRoot::compute($result));
    }

    private static function assertDeltaSessionFixture(array $fixture): void
    {
        $in = $fixture['input'];
        $base = self::buildSet($in['base']);
        $tool = $in['tool'] ?? '';
        $policy = self::buildPolicy($in['policy']);
        $session = new GenericDeltaSession($base, $tool, $policy);

        self::assertSame($fixture['expected']['initialFull'], $session->currentFull());

        foreach ($in['updates'] as $idx => $update) {
            $nextSet = self::buildSet($update);
            [$wire, $isFull] = $session->next($nextSet);
            $emission = $fixture['expected']['emissions'][$idx];
            self::assertSame($emission['wire'], $wire, "emission #{$idx} wire mismatch");
            self::assertSame($emission['isFull'], $isFull, "emission #{$idx} isFull mismatch");
        }
    }

    // --- fixture -> domain object builders ---

    private static function buildSet(array $data): GenericSet
    {
        return new GenericSet(
            key: $data['key'] ?? '',
            fields: $data['fields'] ?? [],
            rows: $data['rows'] ?? [],
            name: $data['name'] ?? 'rows',
        );
    }

    private static function buildDeltaPayload(array $data): GenericDeltaPayload
    {
        return new GenericDeltaPayload(
            key: $data['key'],
            fields: $data['fields'],
            baseRoot: $data['baseRoot'] ?? '',
            newRoot: $data['newRoot'] ?? '',
            added: $data['added'] ?? [],
            changed: $data['changed'] ?? [],
            removed: $data['removed'] ?? [],
            tool: $data['tool'] ?? '',
            deltaTokens: $data['deltaTokens'] ?? 0,
            fullTokens: $data['fullTokens'] ?? 0,
        );
    }

    private static function buildPolicy(array $policy): ReanchorPolicy
    {
        return match ($policy['mode']) {
            'fixedN' => Reanchor::fixedN($policy['n'] ?? 0),
            'sizeGuard' => Reanchor::sizeGuard(),
            default => throw new \RuntimeException("Unknown reanchor policy mode: {$policy['mode']}"),
        };
    }

    private static function readFixture(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read fixture: {$path}");
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
