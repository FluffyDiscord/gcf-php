<?php

declare(strict_types=1);

namespace Gcf;

/** Input/output structure for graph-profile encoding/decoding. Ported from gcf-python's types.py. */
final class Payload
{
    /**
     * @param list<Symbol> $symbols
     * @param list<Edge> $edges
     */
    public function __construct(
        public readonly string $tool = '',
        public readonly int $tokensUsed = 0,
        public readonly int $tokenBudget = 0,
        public readonly string $packRoot = '',
        public readonly array $symbols = [],
        public readonly array $edges = [],
    ) {
    }
}
