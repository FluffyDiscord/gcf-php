<?php

declare(strict_types=1);

namespace Gcf\Generic;

use Gcf\Generic\Internal\Writer;

/**
 * Encodes PHP values into GCF generic-profile text (SPEC section 7).
 *
 * Accepts the PHP equivalent of a decoded-JSON value: nested arrays and
 * scalars, matching how `json_decode($x, true)` would produce them. A JSON
 * object is a (possibly non-list) associative array; a JSON array is a list
 * array. An *empty* array is ambiguous between the two, so it is treated as
 * an empty JSON array by default — pass a `\stdClass` instance to encode an
 * explicit empty object.
 */
final class Encoder
{
    private function __construct()
    {
    }

    public static function encode(mixed $data): string
    {
        return Writer::encode($data);
    }
}
