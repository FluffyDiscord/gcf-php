<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * Holds the current base and re-anchor state for a producer loop (SPEC Section
 * 10a.8). Ported from gcf-python's generic_delta.py: GenericDeltaSession.
 *
 * Not safe for concurrent use. Call currentFull() to get the initial full
 * payload to transmit, then next() for each subsequent state.
 *
 * GenericDeltaSession introduces NO new wire syntax: every payload it emits is
 * exactly what GenericDeltaEncoder::encodeFull() / ::encodeDelta() produce, and
 * the decoder accepts them cadence-agnostically. N and the size guard are the
 * session's knobs; they are never wire fields.
 */
final class GenericDeltaSession
{
    private GenericSet $base;
    private ReanchorPolicy $policy;
    private int $turn = 0;
    private int $cum = 0; // cumulative delta bytes since the last anchor

    public function __construct(GenericSet $base, private readonly string $tool, ReanchorPolicy $policy)
    {
        if ($policy->mode === ReanchorMode::FixedN && $policy->n <= 0) {
            $policy = new ReanchorPolicy(ReanchorMode::FixedN, Reanchor::DEFAULT_REANCHOR_N);
        }
        $this->base = $base;
        $this->policy = $policy;
    }

    /**
     * The number of next() calls so far (the initial full is turn 0).
     */
    public function turn(): int
    {
        return $this->turn;
    }

    /**
     * Returns the full payload for the current base (GenericDeltaEncoder::encodeFull()).
     * Send this first to establish the base; it is also a valid manual re-anchor.
     */
    public function currentFull(): string
    {
        return GenericDeltaEncoder::encodeFull($this->base, $this->tool);
    }

    private function reanchor(GenericSet $next): string
    {
        $wire = GenericDeltaEncoder::encodeFull($next, $this->tool);
        $this->base = $next;
        $this->cum = 0;

        return $wire;
    }

    /**
     * Advances the session by one turn to $next.
     *
     * Returns [wire, isFull]: the wire to transmit and whether it is a full
     * re-anchor (true) or a delta (false). A schema change forces a full (Section
     * 10a.7). The held base becomes $next either way. The wire is byte-identical
     * to calling GenericDeltaEncoder::encodeFull() / ::encodeDelta() directly. May
     * throw on duplicate identity / no key (propagated from GenericDiff::diff()).
     *
     * @return array{0: string, 1: bool}
     */
    public function next(GenericSet $next): array
    {
        ++$this->turn;

        // Schema change (or a fresh key) cannot be expressed as a delta -> full.
        if ($next->key !== $this->base->key || $this->base->fields !== $next->fields) {
            return [$this->reanchor($next), true];
        }

        $d = GenericDiff::diff($this->base, $next);
        $deltaWire = GenericDeltaEncoder::encodeDelta($d);

        if ($this->policy->mode === ReanchorMode::SizeGuard) {
            $reanchor = $this->cum + self::byteLen($deltaWire) >= self::byteLen(GenericDeltaEncoder::encodeFull($next, $this->tool));
        } else {
            $reanchor = $this->turn % $this->policy->n === 0;
        }

        if ($reanchor) {
            return [$this->reanchor($next), true];
        }

        $this->base = $next;
        $this->cum += self::byteLen($deltaWire);

        return [$deltaWire, false];
    }

    /**
     * PHP strings are byte sequences (our wire text is always UTF-8 encoded), so
     * strlen() already matches Go's/Python's UTF-8 byte length.
     */
    private static function byteLen(string $s): int
    {
        return strlen($s);
    }
}
