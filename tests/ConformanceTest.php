<?php

declare(strict_types=1);

namespace Gcf\Tests;

use Gcf\GcfDecodeException;
use Gcf\Generic\Decoder;
use Gcf\Generic\Encoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every vendored GCF conformance fixture under tests/conformance/ against
 * the generic-profile Encoder/Decoder.
 */
final class ConformanceTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testFixture(string $path): void
    {
        $fixture = self::rawFixture($path);
        $operation = $fixture['operation'];

        match ($operation) {
            'encode' => self::assertSame(
                $fixture['expected'],
                Encoder::encode(self::encoderInput($path)),
            ),
            'decode' => self::assertEquals(
                $fixture['expected'],
                Decoder::decode(self::resolveInputText($fixture)),
            ),
            'error' => self::assertErrorFixture($fixture),
            'roundtrip' => self::assertRoundtripFixture($path, $fixture),
            default => self::fail("Unknown fixture operation: {$operation}"),
        };
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtures(): iterable
    {
        $root = __DIR__.'/conformance';
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $rel = substr($path, strlen($root) + 1);
            yield $rel => [$path];
        }
    }

    private static function assertErrorFixture(array $fixture): void
    {
        try {
            Decoder::decode(self::resolveInputText($fixture));
            self::fail("Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.");
        } catch (GcfDecodeException $e) {
            self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
        }
    }

    /**
     * Fixtures normally carry GCF text in "input". A handful (e.g.
     * errors-v2/024_invalid_utf8.json) need to exercise malformed byte
     * sequences that cannot round-trip through a JSON string literal, so they
     * carry base64-encoded raw bytes in "inputBase64" instead.
     */
    private static function resolveInputText(array $fixture): string
    {
        if (isset($fixture['inputBase64'])) {
            return base64_decode($fixture['inputBase64'], true);
        }

        return $fixture['input'];
    }

    private static function assertRoundtripFixture(string $path, array $fixture): void
    {
        self::assertSame(
            $fixture['expected'],
            Encoder::encode(self::encoderInput($path)),
        );
        self::assertEquals(
            $fixture['input'],
            Decoder::decode($fixture['expected']),
        );
    }

    /**
     * Reads the whole fixture file as a plain associative structure (objects
     * and arrays both become PHP arrays, per json_decode($x, true)). Used for
     * everything except feeding Encoder::encode(), where the object/array
     * distinction on *empty* containers must be preserved (see encoderInput()).
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
     * Builds the PHP value to feed Encoder::encode() for an encode/roundtrip
     * fixture's "input" field.
     *
     * json_decode($x, true) collapses an empty JSON object `{}` and an empty
     * JSON array `[]` to the exact same PHP `[]`, which loses information the
     * encoder fixtures actually depend on (e.g. containers/003_empty_array.json
     * wants array-shaped output, containers/004_empty_object.json wants
     * object-shaped output, for structurally identical `[]` input). To recover
     * that distinction we decode with json_decode($x, false) instead — which
     * naturally represents `{}` as an empty \stdClass, distinct from `[]` — and
     * recursively convert: non-empty objects become associative PHP arrays
     * (Encoder's normal object representation), JSON arrays become PHP list
     * arrays, and empty objects are left as \stdClass (Encoder's documented
     * escape hatch for an explicit empty object).
     */
    private static function encoderInput(string $path): mixed
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read fixture: {$path}");
        }
        $decoded = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);

        return self::preserveObjectShape($decoded->input ?? null);
    }

    private static function preserveObjectShape(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $props = get_object_vars($node);
            if ($props === []) {
                return new \stdClass();
            }
            $out = [];
            foreach ($props as $k => $v) {
                $out[$k] = self::preserveObjectShape($v);
            }

            return $out;
        }
        if (is_array($node)) {
            return array_map(self::preserveObjectShape(...), $node);
        }

        return $node;
    }
}
