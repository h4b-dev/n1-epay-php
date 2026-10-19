<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;
use N1co\EPay\ResponseInfo;

/**
 * A payment intent. `client_secret` is set in the answers of paymentIntents->create() (always a string) and
 * paymentIntents->retrieve() (null for intents created before API 0.12.0); it is null in the other answers.
 * `card` is a `card_` id or a card id saved with ePay v3 (24 lowercase hexadecimal characters), as sent.
 */
final readonly class PaymentIntent
{
    private const KNOWN = ['id', 'object', 'status', 'amount', 'currency', 'description', 'reference', 'metadata', 'billing', 'page_origins', 'card', 'payment_method', 'cvc_required', 'next_action', 'last_error', 'attempts_remaining', 'authorization_code', 'order_id', 'cancellation_reason', 'refund', 'created_at', 'updated_at', 'succeeded_at', 'canceled_at', 'expires_at', 'client_secret'];

    /**
     * @param string $status One of PaymentIntentStatus::ALL, or a value added to the API later.
     * @param array<string, string> $metadata
     * @param list<string>|null $page_origins
     * @param ?string $cancellation_reason One of CancellationReason::ALL, or a value added to the API later.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     * @param ResponseInfo|null $lastResponse The HTTP response behind this object, when it came from the API.
     */
    public function __construct(
        public string $id,
        public string $object,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $description,
        public ?string $reference,
        public array $metadata,
        public ?Billing $billing,
        public ?array $page_origins,
        public ?string $card,
        public ?PaymentMethodSummary $payment_method,
        public bool $cvc_required,
        public ?NextAction $next_action,
        public ?PaymentIntentError $last_error,
        public int $attempts_remaining,
        public ?string $authorization_code,
        public ?string $order_id,
        public ?string $cancellation_reason,
        public ?string $refund,
        public string $created_at,
        public string $updated_at,
        public ?string $succeeded_at,
        public ?string $canceled_at,
        public string $expires_at,
        public ?string $client_secret = null,
        public array $extra = [],
        public ?ResponseInfo $lastResponse = null,
    ) {}

    /**
     * Builds the object from its JSON form (decoded as an array).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data, ?ResponseInfo $lastResponse = null): self
    {
        return self::read(Reader::of($data, 'payment_intent'), $lastResponse);
    }

    /** @internal */
    public static function read(Reader $r, ?ResponseInfo $lastResponse = null): self
    {
        return new self(
            id: $r->string('id'),
            object: $r->string('object'),
            status: $r->string('status'),
            amount: $r->int('amount'),
            currency: $r->string('currency'),
            description: $r->nullableString('description'),
            reference: $r->nullableString('reference'),
            metadata: $r->stringMap('metadata'),
            billing: ($o = $r->nullableObject('billing')) === null ? null : Billing::read($o),
            page_origins: $r->nullableStringList('page_origins'),
            card: $r->nullableString('card'),
            payment_method: ($o = $r->nullableObject('payment_method')) === null ? null : PaymentMethodSummary::read($o),
            cvc_required: $r->bool('cvc_required'),
            next_action: ($o = $r->nullableObject('next_action')) === null ? null : NextAction::read($o),
            last_error: ($o = $r->nullableObject('last_error')) === null ? null : PaymentIntentError::read($o),
            attempts_remaining: $r->int('attempts_remaining'),
            authorization_code: $r->nullableString('authorization_code'),
            order_id: $r->nullableString('order_id'),
            cancellation_reason: $r->nullableString('cancellation_reason'),
            refund: $r->nullableString('refund'),
            created_at: $r->string('created_at'),
            updated_at: $r->string('updated_at'),
            succeeded_at: $r->nullableString('succeeded_at'),
            canceled_at: $r->nullableString('canceled_at'),
            expires_at: $r->string('expires_at'),
            client_secret: $r->has('client_secret') ? $r->nullableString('client_secret') : null,
            extra: $r->extra(self::KNOWN),
            lastResponse: $lastResponse,
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
            'object' => $this->object,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'reference' => $this->reference,
            'metadata' => $this->metadata,
            'billing' => $this->billing?->toArray(),
            'page_origins' => $this->page_origins,
            'card' => $this->card,
            'payment_method' => $this->payment_method?->toArray(),
            'cvc_required' => $this->cvc_required,
            'next_action' => $this->next_action?->toArray(),
            'last_error' => $this->last_error?->toArray(),
            'attempts_remaining' => $this->attempts_remaining,
            'authorization_code' => $this->authorization_code,
            'order_id' => $this->order_id,
            'cancellation_reason' => $this->cancellation_reason,
            'refund' => $this->refund,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'succeeded_at' => $this->succeeded_at,
            'canceled_at' => $this->canceled_at,
            'expires_at' => $this->expires_at,
        ];
        if ($this->client_secret !== null) {
            $data['client_secret'] = $this->client_secret;
        }

        return $data + $this->extra;
    }
}
