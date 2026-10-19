<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Codes of a refund's `last_error`.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class RefundErrorCode
{
    public const REFUND_REJECTED = 'refund_rejected';
    public const PROVIDER_ERROR = 'provider_error';
    public const ORDER_NOT_FOUND = 'order_not_found';
    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::REFUND_REJECTED,
        self::PROVIDER_ERROR,
        self::ORDER_NOT_FOUND,
        self::OUTCOME_UNKNOWN,
    ];

    private function __construct() {}
}
