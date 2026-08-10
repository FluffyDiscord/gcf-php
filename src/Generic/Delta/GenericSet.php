<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * A keyed record set: the unit generic-profile delta operates on (SPEC Section 10a).
 * Ported from gcf-python's generic_delta.py: GenericSet.
 *
 * Rows are order-agnostic (set semantics); `fields` carries the declared column
 * order for the wire form; `key` names the identity column (the `@id` / `key=`);
 * `name` is the tabular section name for a full payload.
 */
final class GenericSet
{
    /**
     * @param list<string> $fields
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public string $key,
        public array $fields,
        public array $rows,
        public string $name = 'rows',
    ) {
    }
}
