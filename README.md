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
    'base_url' => getenv('N1CO_BASE_URL') ?: null, // omit in production
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

// Send only $intent->client_secret to the browser.
```

Never send the secret key to a browser.

`reference` is your order id: n1co receives it as the order id of every charge (1 to 150
characters, no control characters, compared ignoring case). A store has one paid intent per
reference, and one open. Creating an intent with a paid reference throws `ConflictException` with
the code `reference_already_paid`, and with a reference that has an open intent,
`reference_in_use`; both carry `details()['payment_intent']`. A canceled or expired intent frees
its reference.

## Environments

Production: omit `base_url` (opens later; the default is the production host). Development: pass
`https://api-epay.dev.n1co.dev`.

The base URL must use `https`. Plain `http` is accepted only for `http://localhost`,
`http://127.0.0.1` and `http://[::1]` (any port), to test against a local API.

`N1co::DEFAULT_BASE_URL` and `N1co::DEV_BASE_URL` hold them; `null` means the default.

## Client options

| Option           | Default             | Notes                                                                                     |
| ---------------- | ------------------- | ----------------------------------------------------------------------------------------- |
| `base_url`       | the production host | See [Environments](#environments).                                                        |
| `timeout`        | `30`                | Seconds per attempt. `refunds->create()` waits ≥ 90 s.                                    |
| `max_retries`    | `2`                 | GET only, 0 to 10. See [Retries](#retries).                                               |
| `app_info`       | none                | `['name' => …, 'version' => …, 'url' => …]`, sent in the `User-Agent`.                    |
| `transport`      | `CurlTransport`     | Any `N1co\EPay\Http\Transport`.                                                           |
| `webhook_secret` | none                | `whsec_…`, for `webhooks->constructEvent()`. See [Verify deliveries](#verify-deliveries). |

Every API method takes a last `['timeout' => seconds]` argument. Ids are checked (`prefix_…`,
for example `pi_…`) before any request; response bodies over 1 MiB are refused.

## Integration flow

1. **Your server creates the intent** with `$n1co->paymentIntents->create([...])` and passes its
   `client_secret` to your page.
2. **n1co.js pays it in the browser** (`confirmPayment`), including 3D Secure when n1co asks.
3. **The `payment_intent.succeeded` webhook is the source of truth.** Fulfil the order there.
   `$n1co->paymentIntents->retrieve($id)` reads the current state.

Cancel an unpaid intent with `$n1co->paymentIntents->cancel($id)`.

To resume an open intent from a new page, retrieve it with the secret key and hand its
`client_secret` to the browser. `retrieve()` returns it (null for intents created before API 0.12.0):

```php
try {
    $intent = $n1co->paymentIntents->create($params);
} catch (ConflictException $e) {
    if ($e->code() !== 'reference_in_use') {
        throw $e; // reference_already_paid: the order is paid, do not charge it again
    }
    $intent = $n1co->paymentIntents->retrieve($e->details()['payment_intent']);
}
// Send only $intent->client_secret to the browser (cancel the intent and create again if it is null).
```

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

`payment_method` also accepts the id of a card you saved with ePay v3 (24 lowercase hexadecimal
characters). Only its shape is checked; n1co decides when it is charged, and an id it does not
know fails the attempt with `last_error->code` `card_unavailable`. Such an intent shows that id in
`card`, and its `payment_method` has `saved === true` with `brand`, `last4`, `exp_month` and
`exp_year` null. A card session cannot update it (`404 card_not_found`): save the card again.

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

Deliveries are signed with [Standard Webhooks](https://www.standardwebhooks.com/). Give the client
your `whsec_…` secret once; the SDK reads the raw body and headers and verifies them. Bodies over
1 MiB are `invalid_payload`.

```php
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Model\EventType;
use N1co\EPay\N1co;

$n1co = new N1co((string) getenv('N1CO_SECRET_KEY'), [
    'webhook_secret' => (string) getenv('N1CO_WEBHOOK_SECRET'),
]);

try {
    $event = $n1co->webhooks->constructEventFromRequest(); // reads php://input and the headers
} catch (WebhookSignatureException) {
    http_response_code(400);
    exit;
}
if ($event->type === EventType::PAYMENT_INTENT_SUCCEEDED) {
    $intent = $event->data->object; // PaymentIntentEventObject
}
http_response_code(204);
```

With a framework, pass its raw body and headers: `$n1co->webhooks->constructEvent($payload, $headers)`.
Both take an optional tolerance in seconds (default 300).

`constructEvent` throws `WebhookSignatureException`, with `code()`:

| `code()`                     | Meaning                                                                       |
| ---------------------------- | ----------------------------------------------------------------------------- |
| `missing_header`             | `webhook-id`, `webhook-timestamp` or `webhook-signature` is missing.          |
| `invalid_timestamp`          | `webhook-timestamp` is not a Unix time in seconds.                            |
| `timestamp_out_of_tolerance` | The timestamp is more than 5 minutes away, either way.                        |
| `invalid_signature`          | No `v1,` signature matches the body.                                          |
| `invalid_payload`            | The body is over 1 MiB, or the signed body is not an n1co ePay event.         |

A client without `webhook_secret` throws `LogicException`, and an unusable secret throws
`InvalidArgumentException`: programming errors, never `WebhookSignatureException`. **Answer
`400` only for `WebhookSignatureException`**; any other failure is yours (answer `5xx`, n1co
retries). Answer `2xx` quickly and deduplicate on `$event->id`: delivery is at least once and
not ordered.

To pass the secret yourself, use the static form:
`Webhook::constructEvent($payload, $headers, $secret, $tolerance = 300)`. It behaves the same.

`$event->data->object` is a `Card` (`card.created`, `card.updated`), `PaymentIntentEventObject`
(`payment_intent.*`), `Refund` (`refund.*`) or `WebhookTestObject` (`webhook.test`). For a type
this version does not know, or an object that does not fit its model, it is the decoded array;
`$event->data->raw` is always the decoded array.

#### Laravel

```php
// app/Providers/AppServiceProvider.php, register()
$this->app->singleton(N1co::class, fn () => new N1co((string) config('services.n1co.secret_key'), [
    'webhook_secret' => (string) config('services.n1co.webhook_secret'),
]));
```

```php
// routes/api.php (no CSRF there). In routes/web.php, exclude the path from CSRF instead:
// Laravel 11+: bootstrap/app.php ->withMiddleware(fn (Middleware $m) => $m->validateCsrfTokens(except: ['webhooks/n1co']))
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\N1co;

Route::post('/webhooks/n1co', function (Request $request, N1co $n1co) {
    try {
        $event = $n1co->webhooks->constructEvent($request->getContent(), $request->headers->all());
    } catch (WebhookSignatureException) {
        abort(400);
    }
    // handle $event->type …
    return response()->noContent();
});
```

#### Symfony

```yaml
# config/services.yaml
N1co\EPay\N1co:
    arguments:
        $secretKey: '%env(N1CO_SECRET_KEY)%'
        $options: { webhook_secret: '%env(N1CO_WEBHOOK_SECRET)%' }
```

```php
use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\N1co;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

public function n1co(Request $request, N1co $n1co): Response
{
    try {
        $event = $n1co->webhooks->constructEvent($request->getContent(), $request->headers->all());
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

| Class                       | HTTP status          | Typical API `code()`                                                | SDK-only `code()`                           |
| --------------------------- | -------------------- | ------------------------------------------------------------------- | ------------------------------------------- |
| `InvalidRequestException`   | 400 (and other 4xx)  | `invalid_request` (see `details()['field']`, `details()['code']`)   |                                             |
| `AuthenticationException`   | 401, 403             | `invalid_api_key`                                                   |                                             |
| `NotFoundException`         | 404                  | `*_not_found` (`payment_intent_not_found`, `refund_not_found`, …)   |                                             |
| `ConflictException`         | 409                  | `card_unavailable`, `reference_in_use`, `refund_exists`, …           |                                             |
| `RateLimitException`        | 429                  | `rate_limited` (see `retryAfter()`)                                 |                                             |
| `ServiceException`          | 5xx, 3xx, unreadable | `n1co_api_unavailable`, `platform_access_pending`, `internal_error` | `unexpected_redirect`, `invalid_response`   |
| `ConnectionException`       | none                 |                                                                     | `connection_error`, `timeout`               |
| `WebhookSignatureException` | none                 |                                                                     | see [Verify deliveries](#verify-deliveries) |

The SDK's own codes:

- `unexpected_redirect`: the API answered `3xx`. Redirects are never followed; check the base URL.
- `invalid_response`: the answer could not be read: not JSON, over 1 MiB, or not the expected
  object.
- `connection_error`, `timeout`: no answer was received. The outcome of a POST is unknown.
- `http_<status>` (for example `http_502`): an error answer without an API error body, from a
  proxy for example. The class still follows the status.

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
| 0.1.x | 0.12.1       |

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
