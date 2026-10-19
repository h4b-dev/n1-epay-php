<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Statuses of a saved card.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class CardStatus
{
    public const ACTIVE = 'active';
    public const UNAVAILABLE = 'unavailable';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::ACTIVE,
        self::UNAVAILABLE,
    ];

    private function __construct() {}
}
