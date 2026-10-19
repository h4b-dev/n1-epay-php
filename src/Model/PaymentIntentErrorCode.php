<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Codes of a payment intent's `last_error`.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class PaymentIntentErrorCode
{
    public const CARD_DECLINED = 'card_declined';
    public const CARD_REJECTED = 'card_rejected';
    public const CARD_UNAVAILABLE = 'card_unavailable';
    public const CARD_UPDATE_FAILED = 'card_update_failed';
    public const BILLING_REQUIRED = 'billing_required';
    public const INVALID_REQUEST = 'invalid_request';
    public const AUTHENTICATION_FAILED = 'authentication_failed';
    public const AUTHENTICATION_EXPIRED = 'authentication_expired';
    public const PROVIDER_ERROR = 'provider_error';
    public const OUTCOME_UNKNOWN = 'outcome_unknown';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const AMOUNT_MISMATCH = 'amount_mismatch';
    public const REFERENCE_ALREADY_PAID = 'reference_already_paid';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::CARD_DECLINED,
        self::CARD_REJECTED,
        self::CARD_UNAVAILABLE,
        self::CARD_UPDATE_FAILED,
        self::BILLING_REQUIRED,
        self::INVALID_REQUEST,
        self::AUTHENTICATION_FAILED,
        self::AUTHENTICATION_EXPIRED,
        self::PROVIDER_ERROR,
        self::OUTCOME_UNKNOWN,
        self::CURRENCY_MISMATCH,
        self::AMOUNT_MISMATCH,
        self::REFERENCE_ALREADY_PAID,
    ];

    private function __construct() {}
}
