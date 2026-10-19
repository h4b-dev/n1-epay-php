<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Internal\WebhookRequest;
use N1co\EPay\Model\Event;
use N1co\EPay\Webhook;

/** Webhook helpers bound to a client and its `webhook_secret`. */
final class Webhooks
{
    /** @internal */
    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter]
        private readonly ?string $secret = null,
        private readonly WebhookRequest $request = new WebhookRequest(),
    ) {}

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
     * Verifies a delivery with the client's `webhook_secret` and returns its event; see
     * Webhook::constructEvent(). Pass the raw body and the request headers.
     *
     * @param array<array-key, mixed> $headers
     * @param int|null                $tolerance Seconds; null is Webhook::DEFAULT_TOLERANCE.
     *
     * @throws WebhookSignatureException answer it with a 400
     * @throws \LogicException           when the client has no `webhook_secret`
     */
    public function constructEvent(string $payload, array $headers, ?int $tolerance = null): Event
    {
        return Webhook::constructEvent($payload, $headers, $this->secret(), $tolerance ?? Webhook::DEFAULT_TOLERANCE);
    }

    /**
     * Verifies the current request: reads `php://input` (at most 1 MiB) and the headers itself.
     * For plain PHP; with a framework, pass its raw body and headers to constructEvent().
     *
     * @param int|null $tolerance Seconds; null is Webhook::DEFAULT_TOLERANCE.
     *
     * @throws WebhookSignatureException answer it with a 400
     * @throws \LogicException           when the client has no `webhook_secret`
     */
    public function constructEventFromRequest(?int $tolerance = null): Event
    {
        $secret = $this->secret();

        return Webhook::constructEvent($this->request->body(), $this->request->headers(), $secret, $tolerance ?? Webhook::DEFAULT_TOLERANCE);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['secret' => $this->secret === null ? null : 'whsec_[redacted]'];
    }

    private function secret(): string
    {
        return $this->secret ?? throw new \LogicException(
            'No webhook secret is configured: pass the webhook_secret option (whsec_…) to the N1co constructor, or call Webhook::constructEvent() with the secret.',
        );
    }
}
