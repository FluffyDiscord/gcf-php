<?php

declare(strict_types=1);

namespace Gcf\Graph;

use Gcf\Constants;
use Gcf\Payload;
use Gcf\Symbol;

/**
 * Encodes a graph-profile Payload into GCF text (SPEC section 16.1).
 * Ported from gcf-python's encode.py.
 */
final class Encoder
{
    private function __construct()
    {
    }

    public static function encode(Payload $p): string
    {
        $parts = [];

        // Group symbols by distance (sorted by score descending within each group),
        // then assign local IDs in output order so they are sequential in the wire
        // (SPEC 16.1).
        $groups = self::groupByDistance($p->symbols);
        $symIndex = [];
        $nextId = 0;
        foreach ($groups as [, $gSymbols]) {
            foreach ($gSymbols as $s) {
                $symIndex[$s->qualifiedName] = $nextId;
                ++$nextId;
            }
        }

        // Count valid edges (both endpoints in symbol index).
        $validEdges = 0;
        foreach ($p->edges as $e) {
            if (isset($symIndex[$e->source]) && isset($symIndex[$e->target])) {
                ++$validEdges;
            }
        }

        // Header line (SPEC 16.1): omit budget/tokens/edges when zero, matching the
        // reference encoder.
        $header = "GCF profile=graph tool={$p->tool}";
        if ($p->tokenBudget > 0) {
            $header .= " budget={$p->tokenBudget}";
        }
        if ($p->tokensUsed > 0) {
            $header .= " tokens={$p->tokensUsed}";
        }
        $header .= ' symbols='.count($p->symbols);
        if ($validEdges > 0) {
            $header .= " edges={$validEdges}";
        }
        if ($p->packRoot !== '') {
            $header .= " pack_root={$p->packRoot}";
        }
        $parts[] = $header;

        $groupNames = ['targets', 'related', 'extended'];

        foreach ($groups as [$gDistance, $gSymbols]) {
            if ($gSymbols === []) {
                continue;
            }
            $name = $gDistance < count($groupNames) ? $groupNames[$gDistance] : "distance_{$gDistance}";
            $parts[] = "## {$name}";

            foreach ($gSymbols as $s) {
                $idx = $symIndex[$s->qualifiedName];
                $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
                $score = sprintf('%.2f', $s->score);
                $parts[] = "@{$idx} {$kind} {$s->qualifiedName} {$score} {$s->provenance}";
            }
        }

        // Edges section. Order edges by source ID then target ID (then edge type
        // for parallel edges) so the wire is canonical regardless of the order
        // edges were provided (SPEC 16.1). Edge reordering is decode-invariant
        // (edges are a set) and does not affect pack_root, which sorts edge records
        // independently.
        if ($p->edges !== []) {
            $resolved = [];
            foreach ($p->edges as $e) {
                $srcIdx = $symIndex[$e->source] ?? null;
                $tgtIdx = $symIndex[$e->target] ?? null;
                if ($srcIdx === null || $tgtIdx === null) {
                    continue;
                }
                $resolved[] = [$srcIdx, $tgtIdx, $e->edgeType, $e->status];
            }
            usort($resolved, static function (array $a, array $b): int {
                return $a[0] <=> $b[0] ?: ($a[1] <=> $b[1] ?: $a[2] <=> $b[2]);
            });
            $edgeLines = [];
            foreach ($resolved as [$srcIdx, $tgtIdx, $edgeType, $status]) {
                $line = "@{$tgtIdx}<@{$srcIdx} {$edgeType}";
                if ($status !== '' && $status !== 'unchanged') {
                    $line .= " {$status}";
                }
                $edgeLines[] = $line;
            }
            $parts[] = '## edges ['.count($edgeLines).']';
            array_push($parts, ...$edgeLines);
        }

        return implode("\n", $parts)."\n";
    }

    /**
     * Groups symbols by distance ascending, sorted by score descending within each
     * group (stable), matching the reference encoder so IDs are assigned canonically.
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
