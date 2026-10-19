<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Model\Event;
use N1co\EPay\Webhook;

/** Webhook helpers bound to a client. */
final class Webhooks
{
    /** @internal */
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Sends a `webhook.test` event to the store's webhook URL (at most 10 per minute).
     *
     * @param array{timeout?: int|float} $options
     */
    public function test(array $options = []): Event
    {
        return $this->http->request('POST', '/v1/webhooks/test', Event::read(...), options: $options);
    }

    /**
     * Verifies a delivery and returns its event; see Webhook::constructEvent().
     *
     * @param array<array-key, mixed> $headers
     */
    public function constructEvent(string $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE): Event
    {
        return Webhook::constructEvent($payload, $headers, $secret, $tolerance);
    }
}
