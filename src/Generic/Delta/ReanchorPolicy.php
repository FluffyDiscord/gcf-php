<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * Selects when a GenericDeltaSession re-anchors (SPEC Section 10a.8). Ported
 * from gcf-python's generic_delta.py: ReanchorPolicy.
 *
 * Construct via Reanchor::fixedN() or Reanchor::sizeGuard() rather than directly.
 */
final class ReanchorPolicy
{
    public function __construct(
        public readonly ReanchorMode $mode = ReanchorMode::FixedN,
        public readonly int $n = 0, // turns between anchors; FixedN only
    ) {
    }
}
