<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * Billing address details of a payment intent.
 */
final readonly class Billing
{
    private const KNOWN = ['country', 'state', 'postal_code'];

    /**
     * @param string|null $state Optional; null when absent.
     * @param string|null $postal_code Optional; null when absent.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $country,
        public ?string $state = null,
        public ?string $postal_code = null,
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
        return self::read(Reader::of($data, 'billing'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            country: $r->string('country'),
            state: $r->has('state') ? $r->nullableString('state') : null,
            postal_code: $r->has('postal_code') ? $r->nullableString('postal_code') : null,
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
            'country' => $this->country,
        ];
        if ($this->state !== null) {
            $data['state'] = $this->state;
        }
        if ($this->postal_code !== null) {
            $data['postal_code'] = $this->postal_code;
        }

        return $data + $this->extra;
    }
}
