<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * What the buyer must do next (`requires_action`): 3D Secure, run by n1co.js.
 */
final readonly class NextAction
{
    private const KNOWN = ['type', 'three_ds'];

    /**
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $type,
        public ThreeDs $three_ds,
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
        return self::read(Reader::of($data, 'next_action'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            type: $r->string('type'),
            three_ds: ThreeDs::read($r->object('three_ds')),
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
            'type' => $this->type,
            'three_ds' => $this->three_ds->toArray(),
        ];

        return $data + $this->extra;
    }
}
