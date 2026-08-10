<?php

declare(strict_types=1);

namespace Gcf;

/** Score breakdown for a Symbol. Ported from gcf-python's types.py. */
final class Components
{
    public function __construct(
        public readonly float $blastRadius = 0.0,
        public readonly float $confidence = 0.0,
        public readonly float $recency = 0.0,
        public readonly float $distance = 0.0,
    ) {
    }
}
