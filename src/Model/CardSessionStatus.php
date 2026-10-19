<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Statuses of a card session.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class CardSessionStatus
{
    public const REQUIRES_CARD = 'requires_card';
    public const COMPLETED = 'completed';
    public const EXPIRED = 'expired';
    public const CANCELED = 'canceled';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::REQUIRES_CARD,
        self::COMPLETED,
        self::EXPIRED,
        self::CANCELED,
    ];

    private function __construct() {}
}
