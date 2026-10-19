<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Statuses of a payment intent.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class PaymentIntentStatus
{
    public const REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    public const REQUIRES_ACTION = 'requires_action';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const CANCELED = 'canceled';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::REQUIRES_PAYMENT_METHOD,
        self::REQUIRES_ACTION,
        self::PROCESSING,
        self::SUCCEEDED,
        self::CANCELED,
    ];

    private function __construct() {}
}
