<?php

declare(strict_types=1);

namespace Gcf;

/**
 * Singleton markers used inside tabular row cells.
 * Mirrors gcf-python's _Missing/_Attachment sentinel classes (scalar.py).
 */
enum Sentinel
{
    case Missing;
    case Attachment;
}
