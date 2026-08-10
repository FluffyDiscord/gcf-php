<?php

declare(strict_types=1);

namespace Gcf;

/** Diff between a prior graph-profile pack and the current result. Ported from gcf-python's types.py. */
final class DeltaPayload
{
    /**
     * @param list<Symbol> $removed
     * @param list<Symbol> $added
     * @param list<Edge> $removedEdges
     * @param list<Edge> $addedEdges
     */
    public function __construct(
        public readonly string $tool = '',
        public readonly string $baseRoot = '',
        public readonly string $newRoot = '',
        public readonly array $removed = [],
        public readonly array $added = [],
        public readonly array $removedEdges = [],
        public readonly array $addedEdges = [],
        public readonly int $deltaTokens = 0,
        public readonly int $fullTokens = 0,
    ) {
    }
}
