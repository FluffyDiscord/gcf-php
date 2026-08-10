<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * Ported from gcf-python's generic_delta.py: GenericDeltaPayload.
 */
final class GenericDeltaPayload
{
    /**
     * @param list<string> $fields
     * @param list<array<string, mixed>> $added
     * @param list<array<string, mixed>> $changed
     * @param list<mixed> $removed
     */
    public function __construct(
        public string $key,
        public array $fields,
        public string $baseRoot = '',
        public string $newRoot = '',
        public array $added = [],
        public array $changed = [],
        public array $removed = [],
        public string $tool = '',
        public int $deltaTokens = 0,
        public int $fullTokens = 0,
    ) {
    }
}
