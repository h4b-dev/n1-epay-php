# n1co/epay

Server-side SDK of the n1co ePay API for PHP. It runs on your server with the store's secret key
(`sk_…`). The browser side is n1co.js, which uses only client secrets.

- PHP `^8.2` (tested on 8.2 to 8.5) with `ext-curl` and `ext-json`. No runtime dependencies.
- Typed read-only models; errors as exceptions by status.

## Install

```sh
composer require n1co/epay
```

## Quickstart

```php
use N1co\EPay\N1co;

$n1co = new N1co((string) getenv('N1CO_SECRET_KEY'), [
    'base_url' => getenv('N1CO_BASE_URL') ?: N1co::DEFAULT_BASE_URL,
]);

$intent = $n1co->paymentIntents->create([
    'amount' => 1250, // minor units: 12.50
    'currency' => 'USD',
    'customer' => [
        'id' => 'cust-1001', // your own id
        'name' => 'Ana López',
        'email' => 'ana.lopez@example.com',
        'phone' => '+503 7000-1234',
    ],
    'reference' => 'order-8812',
]);

// Send only $intent->client_secret to the browser. It is shown once.
```

Never send the secret key to a browser.

## Environments

| Environment | `base_url`                                |
| ----------- | ----------------------------------------- |
| Production  | `https://api-epay.n1co.com` (the default) |
| Development | `https://api-epay.dev.n1co.dev`           |

`N1co::DEFAULT_BASE_URL` and `N1co::DEV_BASE_URL` hold them. The production host is to be
confirmed before 1.0.

## Client options

| Option        | Default                     | Notes                                                  |
| ------------- | --------------------------- | ------------------------------------------------------ |
| `base_url`    | `https://api-epay.n1co.com` | `https` only (`http` for `localhost`, `127.0.0.1`, `[::1]`). |
| `timeout`     | `30`                        | Seconds per attempt. `refunds->create()` waits ≥ 90 s. |
| `max_retries` | `2`                         | GET only, 0 to 10. See [Retries](#retries).            |
| `app_info`    | none                        | `['name' => …, 'version' => …, 'url' => …]`, sent in the `User-Agent`. |
| `transport`   | `CurlTransport`             | Any `N1co\EPay\Http\Transport`.                        |

Every API method takes a last `['timeout' => seconds]` argument.

## Integration flow

1. **Your server creates the intent** with `$n1co->paymentIntents->create([...])` and passes its
   `client_secret` to your page.
2. **n1co.js pays it in the browser** (`confirmPayment`), including 3D Secure when n1co asks.
3. **The `payment_intent.succeeded` webhook is the source of truth.** Fulfil the order there.
   `$n1co->paymentIntents->retrieve($id)` reads the current state.

Cancel an unpaid intent with `$n1co->paymentIntents->cancel($id)`.

## Saved cards

```php
$session = $n1co->cardSessions->create(['customer' => $customer]);
// Browser: n1co.js captures the card with $session->client_secret.
// Then: the card.created webhook, or $n1co->cardSessions->retrieve($session->id)->card

$intent = $n1co->paymentIntents->create([
    'amount' => 1250,
    'currency' => 'USD',
    'customer' => $customer,
    'payment_method' => 'card_…', // your server picks the card, never the page
    'cvc_required' => true,       // n1co.js asks the buyer for the security code
]);
```

### Card update sessions

A reissued card (same number, new expiry) is updated, not saved again:

```php
$session = $n1co->cardSessions->create(['customer' => $customer, 'card' => 'card_…']);
// $session->mode === CardSessionMode::UPDATE. The card keeps its id; you get card.updated.
```

## Refunds

A refund reverses a `succeeded` intent in full. The call waits for n1co (up to about 45 s).

```php
$refund = $n1co->refunds->create([
    'payment_intent' => 'pi_…',
    'reason' => 'Duplicate order',
    'metadata' => ['ticket' => 't-8812'],
]);
// $refund->status: RefundStatus::SUCCEEDED, FAILED or PENDING
$again = $n1co->refunds->retrieve($refund->id);
```

The `refund.succeeded` and `refund.failed` webhooks are the source of truth.

## Events and webhooks

```php
$events = $n1co->events->list(['type' => 'payment_intent.succeeded', 'limit' => 20]);
$event = $n1co->events->retrieve('evt_…');   // with $event->delivery
$n1co->events->resend('evt_…');              // one immediate attempt; at most once every 10 s
$n1co->webhooks->test();                     // sends a webhook.test event to your endpoint
```

### Verify deliveries

Deliveries are signed with [Standard Webhooks](https://www.standardwebhooks.com/). Verify the
**raw body**, before any JSON parsing, with your `whsec_…` secret:

```php
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Model\EventType;
use N1co\EPay\Model\PaymentIntentEventObject;
use N1co\EPay\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'),
        getallheaders(),
        (string) getenv('N1CO_WEBHOOK_SECRET'),
    );
} catch (WebhookSignatureException $e) {
    http_response_code(400);
    exit;
}
if ($event->type === EventType::PAYMENT_INTENT_SUCCEEDED) {
    $intent = $event->data->object; // PaymentIntentEventObject
}
http_response_code(204);
```

It throws `WebhookSignatureException` when a header is missing, the timestamp is more than
5 minutes away (the 4th argument, in seconds, changes it), no signature matches or the body is
not an event envelope. **Answer `400` only for that exception**; any other failure is yours
(answer `5xx`, n1co retries). Answer `2xx` quickly and deduplicate on `$event->id`: delivery is
at least once and not ordered.

`$event->data->object` is a `Card` (`card.created`, `card.updated`), `PaymentIntentEventObject`
(`payment_intent.*`), `Refund` (`refund.*`) or `WebhookTestObject` (`webhook.test`). For a type
this version does not know, or an object that does not fit its model, it is the decoded array;
`$event->data->raw` is always the decoded array.

#### Laravel

```php
// routes/api.php (no CSRF there). In routes/web.php, exclude the path from CSRF instead:
// Laravel 11+: bootstrap/app.php ->withMiddleware(fn (Middleware $m) => $m->validateCsrfTokens(except: ['webhooks/n1co']))
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Webhook;

Route::post('/webhooks/n1co', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(),
            $request->headers->all(),
            (string) config('services.n1co.webhook_secret'),
        );
    } catch (WebhookSignatureException) {
        abort(400);
    }
    // handle $event->type …
    return response()->noContent();
});
```

#### Symfony

```php
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

public function n1co(Request $request): Response
{
    try {
        $event = Webhook::constructEvent($request->getContent(), $request->headers->all(), $this->webhookSecret);
    } catch (WebhookSignatureException) {
        return new Response('', Response::HTTP_BAD_REQUEST);
    }
    // handle $event->type …
    return new Response('', Response::HTTP_NO_CONTENT);
}
```

## Errors

Every exception extends `N1co\EPay\Exception\N1coException`, with `code()` (the API's stable
code), `getMessage()`, `details()` (for example `details()['field']`), `status()`,
`requestId()`, `retryAfter()` and `headers()`. `getCode()` is the HTTP status (0 without one).
Branch on `code()` and `details()`, never on the message.

| Class                       | Status    | Typical `code()`                                                        |
| --------------------------- | --------- | ----------------------------------------------------------------------- |
| `InvalidRequestException`   | 400 (4xx) | `invalid_request` (see `details()['field']`, `details()['code']`)       |
| `AuthenticationException`   | 401, 403  | `invalid_api_key`                                                       |
| `NotFoundException`         | 404       | `payment_intent_not_found`, `refund_not_found`, `card_not_found`, …     |
| `ConflictException`         | 409       | `card_unavailable`, `payment_intent_not_cancelable`, `refund_exists`, … |
| `RateLimitException`        | 429       | `rate_limited` (see `retryAfter()`)                                     |
| `ServiceException`          | 5xx       | `n1co_api_unavailable`, `platform_access_pending`, `invalid_response`   |
| `ConnectionException`       | none      | `connection_error`, `timeout`: the outcome is unknown                   |
| `WebhookSignatureException` | none      | `missing_header`, `invalid_signature`, `timestamp_out_of_tolerance`, …  |

Quote `requestId()` when contacting n1co. Results carry it too: `$result->lastResponse->requestId`.

## Retries

The API has no idempotency keys, so the SDK **never retries a POST**. After a
`ConnectionException` on a POST, read the object (or wait for its webhook) before trying again.

GET requests are retried up to `max_retries` times (default 2) on connection errors, timeouts,
`429` and `5xx`. The SDK waits for `Retry-After` (seconds or an HTTP date, up to 30 s, plus up to
250 ms of jitter; a longer one is thrown to you), otherwise for a jittered backoff (0.25 s,
0.5 s, … up to 4 s). Redirects are never followed.

The secret key, webhook secrets and response bodies are marked `#[\SensitiveParameter]` and
redacted from `var_dump()`/`print_r()`, so they stay out of stack traces and error reports.

## Models

Results are `final readonly` classes with the contract's field names (`$intent->client_secret`,
`$refund->payment_intent`), `fromArray()` and `toArray()`. Fields this version does not know are
kept in `$extra`. Statuses are string constants (`PaymentIntentStatus::SUCCEEDED`), not enums,
so a status added to the API later does not break your code.

## Versioning

| SDK   | API contract |
| ----- | ------------ |
| 0.1.x | 0.10.0       |

`N1co::VERSION` and `N1co::CONTRACT_VERSION` hold them. Until 1.0, a minor version may change
the API surface along with the contract. From 1.0 the SDK follows semantic versioning.

## Development

Needs PHP 8.2+ and Composer (or Docker: `docker run --rm -v "$PWD":/app -w /app php:8.5-cli …`).

```sh
composer update
composer check       # composer.json, style, PHPStan (max), unit and contract tests
composer sync        # copy the API contract into spec/ (EPAY_API_DIR=<API checkout>)
N1CO_BASE_URL=https://api-epay.dev.n1co.dev N1CO_SECRET_KEY=sk_… composer smoke   # refuses production
```

## Support

Questions and incidents: your n1co integration contact. Include the `requestId()`.
