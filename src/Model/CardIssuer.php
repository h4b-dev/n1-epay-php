<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The issuer of a saved card, as reported by n1co.
 */
final readonly class CardIssuer
{
    private const KNOWN = ['name', 'country'];

    /**
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public ?string $name,
        public ?string $country,
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
        return self::read(Reader::of($data, 'issuer'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            name: $r->nullableString('name'),
            country: $r->nullableString('country'),
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
            'name' => $this->name,
            'country' => $this->country,
        ];

        return $data + $this->extra;
    }
}
