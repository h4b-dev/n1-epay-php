<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Model\PaymentIntent;

/** Payment intents: what a customer owes, paid in the browser through n1co.js. */
final class PaymentIntents
{
    /** @internal */
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Creates an intent. Pass `client_secret` (shown only once) to n1co.js. `payment_method`
     * attaches a saved card (a `card_` id, or a card id saved with ePay v3: 24 lowercase
     * hexadecimal characters); `cvc_required` makes n1co.js ask for its security code.
     * `reference` is your order id, sent to n1co as the order id of every charge: one paid intent
     * per reference (`ConflictException` `reference_already_paid`), and one open
     * (`reference_in_use`); both carry `details()['payment_intent']`.
     *
     * @param array{amount: int, currency: string, customer: array{id: string, name: string, email: string, phone: string}, description?: string|null, reference?: string|null, metadata?: array<string, string>|null, billing?: array{country: string, state?: string|null, postal_code?: string|null}|null, page_origins?: list<string>|null, payment_method?: string|null, cvc_required?: bool|null} $params
     * @param array{timeout?: int|float} $options
     */
    public function create(array $params, array $options = []): PaymentIntent
    {
        return $this->http->request('POST', '/v1/payment_intents', PaymentIntent::read(...), body: $params, options: $options);
    }

    /**
     * Retrieves an intent, with its `client_secret` (null for intents created before API 0.12.0), so an
     * open intent can be resumed from a new page.
     *
     * @param array{timeout?: int|float} $options
     */
    public function retrieve(string $id, array $options = []): PaymentIntent
    {
        return $this->http->request('GET', '/v1/payment_intents/' . PathId::encode($id), PaymentIntent::read(...), options: $options);
    }

    /**
     * Cancels an intent that is `requires_payment_method` or `requires_action`.
     *
     * @param array{timeout?: int|float} $options
     */
    public function cancel(string $id, array $options = []): PaymentIntent
    {
        return $this->http->request('POST', '/v1/payment_intents/' . PathId::encode($id) . '/cancel', PaymentIntent::read(...), options: $options);
    }
}
