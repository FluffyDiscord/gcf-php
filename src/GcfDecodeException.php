<?php

declare(strict_types=1);

namespace Gcf;

/**
 * Thrown for any malformed GCF input. Message text mirrors the Python port's
 * ValueError messages (e.g. "invalid_missing: ...") since conformance fixtures
 * assert on substrings of these messages.
 */
final class GcfDecodeException extends \RuntimeException
{
}
