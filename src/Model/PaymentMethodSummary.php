<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The card of a payment intent's current or last attempt, for display. For a card saved with
 * ePay v3 only `saved` (true) is known: `brand`, `last4`, `exp_month` and `exp_year` are null.
 */
final readonly class PaymentMethodSummary
{
    private const KNOWN = ['brand', 'last4', 'exp_month', 'exp_year', 'saved'];

    /**
     * @param ?string $brand One of CardBrand::ALL, or a value added to the API later; null for a card saved with ePay v3.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public ?string $brand,
        public ?string $last4,
        public ?int $exp_month,
        public ?int $exp_year,
        public bool $saved,
        public array $extra = [],
    ) {}

    /**
     * Builds the object from its JSON form (decoded as an array).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data): self
    {
        return self::read(Reader::of($data, 'payment_method'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            brand: $r->nullableString('brand'),
            last4: $r->nullableString('last4'),
            exp_month: $r->nullableInt('exp_month'),
            exp_year: $r->nullableInt('exp_year'),
            saved: $r->bool('saved'),
            extra: $r->extra(self::KNOWN),
        );
    }

    /**
     * The object in its JSON form, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'saved' => $this->saved,
        ];

        return $data + $this->extra;
    }
}
