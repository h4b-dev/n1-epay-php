<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Statuses of an event's webhook delivery.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class DeliveryStatus
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::PENDING,
        self::DELIVERED,
        self::FAILED,
    ];

    private function __construct() {}
}
