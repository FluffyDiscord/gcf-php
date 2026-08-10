<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * Selects a GenericDeltaSession's cadence policy (SPEC Section 10a.8). Ported
 * from gcf-python's generic_delta.py: ReanchorMode.
 */
enum ReanchorMode
{
    /** Re-anchor every N turns. */
    case FixedN;
    /** Re-anchor once cumulative delta reaches the full payload's size. */
    case SizeGuard;
}
