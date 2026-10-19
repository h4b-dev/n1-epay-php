<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;
use N1co\EPay\ResponseInfo;

/**
 * An event: the envelope delivered to the webhook URL (`id`, `type`, `created_at`, `data`) and,
 * when it comes from the API, its `delivery` state. Webhook::constructEvent() returns it with
 * `delivery` null.
 */
final readonly class Event
{
    private const KNOWN = ['id', 'type', 'created_at', 'data', 'delivery'];

    /**
     * @param string               $type         One of EventType::ALL, or a type added to the API later.
     * @param array<string, mixed> $extra        Fields this SDK version does not know, kept as received.
     * @param ResponseInfo|null    $lastResponse The HTTP response behind this object, when it came from the API.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $created_at,
        public EventData $data,
        public ?EventDelivery $delivery = null,
        public array $extra = [],
        public ?ResponseInfo $lastResponse = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data, ?ResponseInfo $lastResponse = null): self
    {
        return self::read(Reader::of($data, 'event'), $lastResponse);
    }

    /** @internal */
    public static function read(Reader $r, ?ResponseInfo $lastResponse = null): self
    {
        $type = $r->string('type');
        $delivery = $r->has('delivery') ? $r->nullableObject('delivery') : null;

        return new self(
            id: $r->string('id'),
            type: $type,
            created_at: $r->string('created_at'),
            data: EventData::read($r->object('data'), $type),
            delivery: $delivery === null ? null : EventDelivery::read($delivery),
            extra: $r->extra(self::KNOWN),
            lastResponse: $lastResponse,
        );
    }

    /**
     * Builds an event from a signed webhook envelope whose fields drift from this SDK version's
     * model: `id` and `type` as given, `data->object` as the decoded array when it does not fit,
     * `created_at` empty when it is not a string, and an unreadable `delivery` kept in `$extra`.
     *
     * @internal
     *
     * @param array<array-key, mixed> $envelope With a string `id` and `type`.
     */
    public static function fromEnvelope(array $envelope): self
    {
        $type = \is_string($envelope['type'] ?? null) ? $envelope['type'] : '';
        $extra = [];
        foreach ($envelope as $key => $value) {
            if (!\in_array((string) $key, self::KNOWN, true)) {
                $extra[(string) $key] = $value;
            }
        }
        try {
            $data = EventData::read(Reader::of($envelope['data'] ?? null, 'event.data'), $type);
        } catch (\UnexpectedValueException) {
            $raw = [];
            $dataExtra = [];
            foreach (\is_array($envelope['data'] ?? null) ? $envelope['data'] : [] as $key => $value) {
                if ((string) $key === 'object') {
                    foreach (\is_array($value) ? $value : [] as $k => $v) {
                        $raw[(string) $k] = $v;
                    }
                } else {
                    $dataExtra[(string) $key] = $value;
                }
            }
            $data = new EventData($raw, $raw, $dataExtra);
        }
        $delivery = null;
        if (isset($envelope['delivery'])) {
            try {
                $delivery = EventDelivery::read(Reader::of($envelope['delivery'], 'event.delivery'));
            } catch (\UnexpectedValueException) {
                $extra['delivery'] = $envelope['delivery'];
            }
        }

        return new self(
            id: \is_string($envelope['id'] ?? null) ? $envelope['id'] : '',
            type: $type,
            created_at: \is_string($envelope['created_at'] ?? null) ? $envelope['created_at'] : '',
            data: $data,
            delivery: $delivery,
            extra: $extra,
        );
    }

    /**
     * The event in its JSON form; `delivery` only when known.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'created_at' => $this->created_at,
            'data' => $this->data->toArray(),
        ];
        if ($this->delivery !== null) {
            $data['delivery'] = $this->delivery->toArray();
        }

        return $data + $this->extra;
    }
}
