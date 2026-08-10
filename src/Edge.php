<?php

declare(strict_types=1);

namespace Gcf;

/** A directed relationship in a GCF graph-profile payload. Ported from gcf-python's types.py. */
final class Edge
{
    public function __construct(
        public readonly string $source = '',
        public readonly string $target = '',
        public readonly string $edgeType = '',
        public readonly string $status = '',
    ) {
    }
}
