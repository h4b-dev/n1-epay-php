<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Modes of a card session (`mode`).
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class CardSessionMode
{
    public const CAPTURE = 'capture';
    public const UPDATE = 'update';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::CAPTURE,
        self::UPDATE,
    ];

    private function __construct() {}
}
