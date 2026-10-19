<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Card brands.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class CardBrand
{
    public const VISA = 'visa';
    public const MASTERCARD = 'mastercard';
    public const AMEX = 'amex';
    public const UNKNOWN = 'unknown';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::VISA,
        self::MASTERCARD,
        self::AMEX,
        self::UNKNOWN,
    ];

    private function __construct() {}
}
