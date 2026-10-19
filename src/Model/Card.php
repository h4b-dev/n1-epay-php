<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * A saved card, as sent in `card.created` and `card.updated` events.
 */
final readonly class Card
{
    private const KNOWN = ['id', 'card_session', 'brand', 'last4', 'exp_month', 'exp_year', 'issuer', 'fingerprint', 'status', 'created_at'];

    /**
     * @param string $brand One of CardBrand::ALL, or a value added to the API later.
     * @param string $status One of CardStatus::ALL, or a value added to the API later.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $id,
        public string $card_session,
        public string $brand,
        public string $last4,
        public int $exp_month,
        public int $exp_year,
        public CardIssuer $issuer,
        public string $fingerprint,
        public string $status,
        public string $created_at,
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
        return self::read(Reader::of($data, 'card'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            id: $r->string('id'),
            card_session: $r->string('card_session'),
            brand: $r->string('brand'),
            last4: $r->string('last4'),
            exp_month: $r->int('exp_month'),
            exp_year: $r->int('exp_year'),
            issuer: CardIssuer::read($r->object('issuer')),
            fingerprint: $r->string('fingerprint'),
            status: $r->string('status'),
            created_at: $r->string('created_at'),
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
            'id' => $this->id,
            'card_session' => $this->card_session,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'issuer' => $this->issuer->toArray(),
            'fingerprint' => $this->fingerprint,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];

        return $data + $this->extra;
    }
}
