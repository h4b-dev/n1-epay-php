<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Model\Refund;

/** Refunds: full reversals of succeeded payment intents. */
final class Refunds
{
    /** The API waits for n1co for up to about 45 seconds on a refund. */
    public const CREATE_TIMEOUT = 90;

    /** @internal */
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Refunds a `succeeded` intent in full. The call waits for n1co, so its timeout is at least
     * 90 seconds unless `$options['timeout']` says otherwise. Never retried automatically: after
     * a ConnectionException read the intent's `refund` or wait for the `refund.*` webhook.
     *
     * @param array{payment_intent: string, reason?: string|null, metadata?: array<string, string>} $params
     * @param array{timeout?: int|float}                                                                  $options
     */
    public function create(array $params, array $options = []): Refund
    {
        return $this->http->request('POST', '/v1/refunds', Refund::read(...), body: $params, options: $options, minTimeout: self::CREATE_TIMEOUT);
    }

    /**
     * Retrieves a refund.
     *
     * @param array{timeout?: int|float} $options
     */
    public function retrieve(string $id, array $options = []): Refund
    {
        return $this->http->request('GET', '/v1/refunds/' . PathId::encode($id), Refund::read(...), options: $options);
    }
}
