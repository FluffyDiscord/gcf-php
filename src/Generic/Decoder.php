<?php

declare(strict_types=1);

namespace Gcf\Generic;

use Gcf\Generic\Internal\Reader;

/**
 * Decodes GCF generic-profile text (SPEC section 7) back into PHP values.
 *
 * Returns the PHP equivalent of a decoded-JSON value: associative arrays for
 * JSON objects, list arrays for JSON arrays, matching what `json_decode($x,
 * true)` would produce — including collapsing empty objects and empty arrays
 * to the same `[]`.
 */
final class Decoder
{
    private function __construct()
    {
    }

    public static function decode(string $input): mixed
    {
        return Reader::decode($input);
    }
}
