<?php

declare(strict_types=1);

namespace Gcf;

/** A node in a GCF graph-profile payload. Ported from gcf-python's types.py. */
final class Symbol
{
    public function __construct(
        public readonly string $qualifiedName = '',
        public readonly string $kind = '',
        public readonly float $score = 0.0,
        public readonly string $provenance = '',
        public readonly int $distance = 0,
        public readonly string $signature = '',
        public readonly Components $components = new Components(),
    ) {
    }
}
