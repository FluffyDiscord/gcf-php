<?php

declare(strict_types=1);

namespace Gcf;

/**
 * GCF graph-profile pack root (gcf-pack-root-v1, SPEC Section 10.2).
 * Ported from gcf-python's packroot.py.
 */
final class PackRoot
{
    private function __construct()
    {
    }

    /**
     * Canonical pack root for a graph snapshot (gcf-pack-root-v1, graph profile, 10.2).
     *
     * Symbol and edge records are sorted independently by unsigned UTF-8 byte order,
     * then concatenated (all symbols, then all edges) and hashed with SHA-256. Two
     * implementations given the same logical graph MUST produce the same result.
     *
     * @param list<Symbol> $symbols
     * @param list<Edge> $edges
     */
    public static function compute(array $symbols, array $edges): string
    {
        $symRecords = [];
        foreach ($symbols as $s) {
            $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
            $score = Scalar::formatNumber($s->score);
            $symRecords[] = "S\t{$kind}\t{$s->qualifiedName}\t{$score}\t{$s->provenance}\t{$s->distance}\n";
        }

        $symKindMap = [];
        foreach ($symbols as $s) {
            $symKindMap[$s->qualifiedName] = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
        }

        $edgeRecords = [];
        foreach ($edges as $e) {
            $srcKind = $symKindMap[$e->source] ?? '';
            $tgtKind = $symKindMap[$e->target] ?? '';
            $edgeRecords[] = "E\t{$srcKind}\t{$e->source}\t{$tgtKind}\t{$e->target}\t{$e->edgeType}\n";
        }

        // strcmp is a byte-wise unsigned comparison, matching Python's sort-by-utf8-bytes key.
        usort($symRecords, strcmp(...));
        usort($edgeRecords, strcmp(...));

        $canonical = implode('', $symRecords) . implode('', $edgeRecords);

        return 'sha256:' . hash('sha256', $canonical);
    }
}
