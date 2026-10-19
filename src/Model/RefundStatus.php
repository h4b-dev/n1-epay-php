<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Statuses of a refund.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class RefundStatus
{
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::PENDING,
        self::SUCCEEDED,
        self::FAILED,
    ];

    private function __construct() {}
}
