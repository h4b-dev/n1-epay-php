# 0. PHP server SDK

Status: accepted (0.1.0, unreleased)

## Context

Partners integrate the n1co ePay API from their servers with the store's secret key, and from
the buyer's browser with n1co.js and client secrets. Many partner backends are PHP (plain PHP,
Laravel, Symfony, WordPress plugins). Today they call the API with raw HTTP and verify webhook
signatures by hand. The Node.js SDK (`@n1co/epay`) sets the family's surface and policies; this
SDK mirrors them in idiomatic PHP.

The API contract is the OpenAPI document of the API, version 0.10.0. It has no idempotency
keys, signs webhooks with Standard Webhooks (`whsec_` secrets), answers errors as
`{"error":{"code","message","details"}}`, sends `Retry-After` on `429` and some `503`, and echoes
a valid `X-Request-Id`.

## Scope

In scope:

- The partner operations of the contract (secret key): card sessions (create, retrieve),
  payment intents (create, retrieve, cancel), refunds (create, retrieve), events (list,
  retrieve, resend) and the webhook test.
- Webhook verification (`Webhook::constructEvent()`) and a signing helper for tests.
- Exceptions by status, GET retries, timeouts, typed models.
- A contract fake served by PHP's built-in server for tests, and a manual smoke test against
  development.

Non-goals: `POST /v1/setup` (a one-time platform call with the n1co application's credentials),
the endpoints only n1co.js calls (`/v1/capture` and the payment intent SDK endpoints), PSR-18
(see Decision), and pagination helpers (the API has no cursor pagination yet).

## Decision

### Surface

```php
$n1co = new N1co('sk_…', ['base_url' => …, 'timeout' => 30, 'max_retries' => 2, 'app_info' => [...]]);
$n1co->cardSessions->create() / retrieve();
$n1co->paymentIntents->create() / retrieve() / cancel();
$n1co->refunds->create() / retrieve();
$n1co->events->list() / retrieve() / resend();
$n1co->webhooks->test() / constructEvent();
Webhook::constructEvent(string $payload, array $headers, string $secret, int $tolerance = 300): Event;
Webhook::generateSignature($payload, $id, $timestamp, $secret);
```

Methods take the contract's request bodies as arrays (snake_case, as in the API and its events,
documented as PHPStan array shapes) and return typed models. Every method takes a last
`['timeout' => seconds]`. The key must be `sk_` and 32 characters; the error never echoes it.

### HTTP

`Transport` is a one-method interface (`send(Request): Response`). `CurlTransport` is the default:
`CURLOPT_FOLLOWLOCATION` off (the bearer never reaches another host; a `3xx` is a
`ServiceException` `http_3xx`), TLS peer and host verification on, protocols limited to `https`
(plus `http` for local hosts), `CURLOPT_NOSIGNAL`, an empty `Expect:` header,
`CURLOPT_TIMEOUT_MS` set to the attempt's timeout and `CURLOPT_CONNECTTIMEOUT_MS` to at most 10 s,
one reused handle for keep-alive. Request bodies are JSON objects: an empty body or `metadata`
is sent as `{}`. The base URL is normalised (`scheme://host[:port][/path]`, lower-case scheme and
host) and rejected with control characters or whitespace; errors never echo it.
Every request sends `Authorization: Bearer sk_…`, `Accept` and `Content-Type`
`application/json`, `User-Agent: n1co-epay-php/<version> php/<major.minor>` (plus `app_info`) and
a new UUID v4 `X-Request-Id` per attempt. The default base URL is `https://api-epay.n1co.com`;
`https` is required except for `localhost`, `127.0.0.1` and `[::1]`. The timeout is per attempt (default
30 s); `refunds->create()` uses at least 90 s unless the call sets one.

### Retries

POST is never retried. GET is retried up to `max_retries` (default 2, at most 10) on connection
errors, timeouts, `429` and `5xx`. `Retry-After` (seconds or an HTTP date) is waited plus up to
250 ms of jitter; over 30 s it is thrown to the caller. Without one the backoff is 250-500 ms,
500-1000 ms, … up to 4 s (equal jitter).

### Errors

`N1coException` (abstract, extends `RuntimeException`) exposes `code(): string` (the API code),
`details(): array`, `status(): ?int`, `requestId(): ?string`, `retryAfter(): ?int` and
`headers(): array`; `getCode()` stays the int status (0 without a response). Subclasses follow
the status: `400` and other `4xx` `InvalidRequestException`, `401`/`403`
`AuthenticationException`, `404` `NotFoundException`, `409` `ConflictException`, `429`
`RateLimitException`, `5xx`, `3xx` and unreadable answers `ServiceException`
(`invalid_response`), no response `ConnectionException` (`connection_error`, `timeout`),
`WebhookSignatureException` for rejected deliveries. A body without an error object gets
`http_<status>`. Configuration mistakes (bad key format, options, webhook secret, negative
tolerance) are `InvalidArgumentException`.

### Models

`final readonly` classes in `N1co\EPay\Model` with the contract's property names, a
`fromArray()` named constructor and `toArray()`. Unknown keys are kept in `$extra` and written
back by `toArray()`. Top-level results carry `$lastResponse` (`ResponseInfo`: request id, status,
headers). Statuses, codes and event types are string-constant classes with an `ALL` list, never
backed enums, so a value added by the API passes through. `Event::$data->object` is typed only for the
exact types in `EventType::ALL` (`Card`, `PaymentIntentEventObject`, `Refund`,
`WebhookTestObject`); an unknown type, or an object that no longer fits its model, stays the
decoded array, and `$data->raw` always holds it.
`PaymentIntent` serves both `PaymentIntent` and `PaymentIntentCreated` (`client_secret` is set
only by `create()`); `CardSessionCreated` stays separate because it has no `created_at`. A
response that does not fit its model is a `ServiceException` `invalid_response`, never a
`TypeError`.

### Webhooks

`Webhook::constructEvent()` takes the raw body, a header map (names in any case, values as
strings or arrays of strings, as `getallheaders()`, Laravel and Symfony give them; other values
count as missing) and the secret with or without `whsec_`. It checks the three `webhook-*`
headers, the timestamp against the tolerance in both directions, compares every `v1,` entry
with `hash_equals` and builds the `Event`. Missing or malformed headers, a bad signature or a
body that is not an envelope (an object with string `id` and `type` and an object `data`) is a
`WebhookSignatureException`; once the signature passes, fields that drift from the models never
reject the event. Partners answer `400` only for that exception.

### Secrets

The secret key, webhook secrets, response bodies and requests are `#[\SensitiveParameter]`;
`HttpClient`, `Request` and the internal `Reader` redact themselves in `__debugInfo()`. A test
runs failures in every layer with `zend.exception_ignore_args=0` and checks that traces, dumps,
messages and `headers()` carry no secret key, webhook secret or client secret.

### Tooling

Composer, PHPUnit (`^11.5 || ^12.5 || ^13.4`: PHPUnit 12 needs PHP 8.3, so 8.2 runs 11.5),
PHPStan at `max` with strict rules and no baseline, PHP CS Fixer (PER-CS 3.0), `symfony/yaml`
for the tests and `scripts/sync-spec.php` only. No `composer.lock` is committed (a library);
CI resolves the newest dependencies per PHP version.

## Why

- **Same family, same semantics:** a partner who knows the Node SDK finds the same services,
  names, exceptions and retry policy; the contract's field names avoid a mapping layer that
  would drift.
- **No runtime dependencies:** `ext-curl` and `ext-json` ship with nearly every PHP build;
  nothing for partners to audit or to conflict with their framework's Guzzle or PSR-18 client.
  A PSR-18 adapter can come later as a `Transport` without changing the surface.
- **Readonly models over arrays:** IDE completion and PHPStan types for every field, while
  `$extra` and string constants keep old SDK versions working with a newer API.
- **No POST retries:** a duplicated payment intent or refund costs more than a failed call.

## Consequences

- A `ConnectionException` on a POST leaves the outcome unknown; partners read the object or
  wait for its webhook. The README says so.
- The SDK tracks the contract: `composer sync`, then the spec tests say which schema lacks a
  model, which model lacks a field, which enum changed, and the contract test fails when the
  contract adds a partner operation the SDK does not cover. `N1co::CONTRACT_VERSION` is compared
  with the spec.
- Results use snake_case properties, like the webhooks partners already handle.

## Verification

- `composer validate --strict`, `php-cs-fixer check`, `phpstan analyse` (max), `phpunit` on PHP
  8.2, 8.3, 8.4 and 8.5, `composer audit`; the same in CI (`shivammathur/setup-php` matrix).
- Unit tests with a fake transport: each service's method, path, query and body; error mapping
  per status and code; GET-only retries with `Retry-After` (seconds and HTTP date) and bounded
  jitter; timeouts and the refund floor; webhooks (reference vector, valid, wrong secret,
  modified body, old and future timestamps, several signatures, header arrays and case,
  missing or malformed headers, bad payloads); forward-compatible statuses and unknown fields;
  every schema the partner operations use has a model, every model has every field, every key a
  model requires is in the schema's `required`, every spec example round-trips through `fromArray()`/`toArray()`, and the constants match the enums.
- Contract tests: `php -S` serves a fake built from `spec/openapi.yaml`, started
  on demand by the contract tests that answers every partner operation with the contract's examples, checks the key,
  path ids, query and request bodies against the schemas and validates its own answers. They
  run through `CurlTransport`, including a redirect that must not be followed, a timeout and a
  refused connection.
- Smoke against development (`scripts/smoke.php`, refuses production unless
  `N1CO_SMOKE_ALLOW_PROD=1`): card session create and retrieve, intent create, retrieve and
  cancel, the webhook test, events list, retrieve and resend of that test event, an unknown
  refund (`NotFoundException`) and a bad currency (`InvalidRequestException`).

## Publishing

Packagist reads the GitHub repository; there is no publish step in CI.

1. The first release: on packagist.org, while signed in with an account of the n1co
   organisation, submit `https://github.com/h4b-dev/n1-epay-php`. Submitting the first
   `n1co/*` package claims the `n1co` vendor for that account; later packages need a maintainer
   of it.
2. Updates: install the Packagist GitHub App on the repository (or add the Packagist webhook to
   it). Every pushed tag `vX.Y.Z` then becomes a version; `dev-main` follows the branch.
3. A release: set the date in `CHANGELOG.md` and `N1co::VERSION`, merge, tag `vX.Y.Z`. The CI
   `release-check` job fails when the tag, the changelog heading and the constant disagree.

If the repository is still private then, Packagist needs it public (or Private Packagist) before the
first submission.

## Open questions

- The production host: `https://api-epay.n1co.com` is assumed and must be confirmed before 1.0.
- Whether to add an idempotency key to the API; with one, POST requests could be retried
  safely and the SDK would send it.
- Cursor pagination for `events->list()`, and an auto-paging helper once the API has it.
- A PSR-18 `Transport` adapter, if partners ask for one.
- Whether partners want camelCase properties; for now the SDK keeps the contract's names.
