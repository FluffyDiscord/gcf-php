<?php

declare(strict_types=1);

namespace Gcf;

/**
 * Tracks graph-profile symbols already transmitted to a client, so subsequent
 * responses can reference them by ID instead of retransmitting the full
 * declaration. Ported from gcf-python's session.py (Session class +
 * encode_with_session()).
 *
 * The Python port is thread-safe (guards its state with a lock) because a
 * single Python process may serve multiple concurrent tool calls. A PHP
 * request is single-threaded, so that locking is not reproduced here; a
 * Session instance is not safe to share across concurrent PHP processes or
 * requests (persist/restore its state externally instead, e.g. per user
 * session).
 *
 * Python exposes session-aware encoding as a free function,
 * `encode_with_session(payload, session)`. Here it is exposed as the
 * `encode()` instance method below, since a Session is meaningless without a
 * Payload to encode against and vice versa for this call — `$session->encode($payload)`
 * reads the same as the Python call with the argument order flipped.
 */
final class Session
{
    /** @var array<string, int> qualified_name -> session-global ID */
    private array $symbols = [];

    private int $nextId = 0;

    /** Returns true if the symbol has been sent in a previous response. */
    public function transmitted(string $qualifiedName): bool
    {
        return array_key_exists($qualifiedName, $this->symbols);
    }

    /**
     * Returns the session-global ID for a previously transmitted symbol, or -1
     * if it has not been transmitted.
     */
    public function getId(string $qualifiedName): int
    {
        return $this->symbols[$qualifiedName] ?? -1;
    }

    /**
     * Marks symbols as transmitted and assigns session-global IDs. Called
     * internally by encode() after a successful encode to register newly-sent
     * symbols; exposed publicly to mirror the Python API.
     *
     * @param list<Symbol> $symbols
     */
    public function record(array $symbols): void
    {
        foreach ($symbols as $sym) {
            if (!array_key_exists($sym->qualifiedName, $this->symbols)) {
                $this->symbols[$sym->qualifiedName] = $this->nextId;
                ++$this->nextId;
            }
        }
    }

    /** Returns the number of symbols tracked in this session. */
    public function size(): int
    {
        return count($this->symbols);
    }

    /** Clears the session state. */
    public function reset(): void
    {
        $this->symbols = [];
        $this->nextId = 0;
    }

    /**
     * Encodes a graph-profile Payload with session deduplication against this
     * session's tracked state. Ported from gcf-python's encode_with_session()
     * (the non-null-session branch; PHP callers who want the plain no-dedup
     * encode should call Gcf\Graph\Encoder::encode() directly instead of going
     * through a Session).
     *
     * Symbols that were already transmitted in prior calls are emitted as bare
     * references (`@N  # previously transmitted`) instead of full
     * declarations. After encoding, newly-sent symbols are recorded into this
     * session.
     */
    public function encode(Payload $p): string
    {
        $parts = [];

        // Build session-stable ID mapping (matching the reference encoder):
        // previously transmitted symbols keep their existing session-global ID;
        // new symbols get the next available session IDs in payload order.
        // These are NOT per-call local indices, so IDs stay stable across calls
        // within a session (see graph-session conformance fixtures).
        $localIndex = [];
        foreach ($p->symbols as $s) {
            if ($this->transmitted($s->qualifiedName)) {
                $localIndex[$s->qualifiedName] = $this->getId($s->qualifiedName);
            }
        }
        $nextNew = $this->size();
        foreach ($p->symbols as $s) {
            if (!$this->transmitted($s->qualifiedName)) {
                $localIndex[$s->qualifiedName] = $nextNew;
                ++$nextNew;
            }
        }

        // Header with session=true marker. Omit budget/tokens/edges when zero,
        // matching the reference encoder.
        $header = "GCF profile=graph tool={$p->tool}";
        if ($p->tokenBudget > 0) {
            $header .= " budget={$p->tokenBudget}";
        }
        if ($p->tokensUsed > 0) {
            $header .= " tokens={$p->tokensUsed}";
        }
        $header .= ' symbols='.count($p->symbols);
        if ($p->edges !== []) {
            $header .= ' edges='.count($p->edges);
        }
        $header .= ' session=true';
        if ($p->packRoot !== '') {
            $header .= " pack_root={$p->packRoot}";
        }
        $parts[] = $header;

        // Track which symbols are new (need full declaration).
        $newSymbols = [];

        // Group by distance (same approach as Gcf\Graph\Encoder::groupByDistance;
        // duplicated here since that method is private and Encoder.php is not to
        // be modified).
        $groups = self::groupByDistance($p->symbols);
        $groupNames = ['targets', 'related', 'extended'];

        foreach ($groups as [$gDistance, $gSymbols]) {
            if ($gSymbols === []) {
                continue;
            }
            $name = $gDistance < count($groupNames) ? $groupNames[$gDistance] : "distance_{$gDistance}";
            $parts[] = "## {$name}";

            foreach ($gSymbols as $s) {
                $idx = $localIndex[$s->qualifiedName];
                if ($this->transmitted($s->qualifiedName)) {
                    // Bare reference: symbol was sent in a prior response.
                    $parts[] = "@{$idx}  # previously transmitted";
                } else {
                    // Full declaration.
                    $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
                    $score = sprintf('%.2f', $s->score);
                    $parts[] = "@{$idx} {$kind} {$s->qualifiedName} {$score} {$s->provenance}";
                    $newSymbols[] = $s;
                }
            }
        }

        // Edges section (not sorted, unlike the full-snapshot encoder: emitted in
        // payload order, matching the reference encode_with_session()).
        if ($p->edges !== []) {
            $parts[] = '## edges ['.count($p->edges).']';
            foreach ($p->edges as $e) {
                $srcIdx = $localIndex[$e->source] ?? null;
                $tgtIdx = $localIndex[$e->target] ?? null;
                if ($srcIdx === null || $tgtIdx === null) {
                    continue;
                }
                $line = "@{$tgtIdx}<@{$srcIdx} {$e->edgeType}";
                if ($e->status !== '' && $e->status !== 'unchanged') {
                    $line .= " {$e->status}";
                }
                $parts[] = $line;
            }
        }

        // Record all new symbols in the session.
        $this->record($newSymbols);

        return implode("\n", $parts)."\n";
    }

    /**
     * Groups symbols by distance ascending, sorted by score descending within
     * each group (stable). Identical algorithm to
     * Gcf\Graph\Encoder::groupByDistance.
     *
     * @param list<Symbol> $symbols
     *
     * @return list<array{0: int, 1: list<Symbol>}>
     */
    private static function groupByDistance(array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        $ordered = $symbols;
        // usort is stable since PHP 8.0, matching Python's sorted() stability.
        usort($ordered, static function (Symbol $a, Symbol $b): int {
            return $a->distance <=> $b->distance ?: $b->score <=> $a->score;
        });

        $groups = [];
        $currentDistance = null;
        $currentSymbols = [];

        foreach ($ordered as $s) {
            if ($currentDistance === null || $currentDistance !== $s->distance) {
                if ($currentSymbols !== []) {
                    $groups[] = [$currentDistance, $currentSymbols];
                }
                $currentDistance = $s->distance;
                $currentSymbols = [$s];
            } else {
                $currentSymbols[] = $s;
            }
        }

        if ($currentSymbols !== []) {
            $groups[] = [$currentDistance, $currentSymbols];
        }

        return $groups;
    }
}
