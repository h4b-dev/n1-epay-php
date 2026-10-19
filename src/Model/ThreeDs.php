<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The 3D Secure step of a `next_action`.
 */
final readonly class ThreeDs
{
    private const KNOWN = ['url', 'authentication_id', 'expires_at'];

    /**
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $url,
        public string $authentication_id,
        public string $expires_at,
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
        return self::read(Reader::of($data, 'three_ds'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            url: $r->string('url'),
            authentication_id: $r->string('authentication_id'),
            expires_at: $r->string('expires_at'),
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
            'url' => $this->url,
            'authentication_id' => $this->authentication_id,
            'expires_at' => $this->expires_at,
        ];

        return $data + $this->extra;
    }
}
