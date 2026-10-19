<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Values of a payment intent's `cancellation_reason`.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class CancellationReason
{
    public const REQUESTED = 'requested';
    public const EXPIRED = 'expired';
    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const CHARGE_MISMATCH = 'charge_mismatch';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::REQUESTED,
        self::EXPIRED,
        self::ATTEMPTS_EXHAUSTED,
        self::CHARGE_MISMATCH,
    ];

    private function __construct() {}
}
