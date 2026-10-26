# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) from 1.0.

## [Unreleased]

## [0.1.0] - 2026-10-26

First public release, on contract 0.15.0. The entries below record the changes made while the
SDK was developed, newest first.

### Added

- `N1co::SANDBOX_BASE_URL` (`https://api-epay-sandbox.n1co.shop`), the sandbox environment partners
  integrate against with their sandbox keys. Production stays the default.

### Changed

- Contract 0.15.0 (`N1co::CONTRACT_VERSION`). `client_secret` is renamed: `PaymentIntent::$intent_token`
  (was `$client_secret`) and `CardSessionCreated::$session_token` (was `$client_secret`). The values
  now read `pi_<24>_token_<32>` and `cs_<24>_token_<32>` (the `_secret_` infix is gone). The
  error code `missing_client_secret` is now `missing_intent_token`. The setup's `client_secret`,
  the secret key and the webhook secret do not change. Both fields stay redacted from dumps.
- Contract 0.14.0 (`N1co::CONTRACT_VERSION`). A card's `id` is n1co's payment method id: 24
  lowercase hexadecimal characters, the value ePay v3 answers and takes as `cardId`. The `card_`
  shape is gone from `Card`, card sessions, `payment_method` and the intent's `card`.
  `paymentIntents->create()` no longer answers `404 card_not_found`; `cardSessions->create()` with
  `card` still does.

### Added

- Contract 0.13.0 (`N1co::CONTRACT_VERSION`). `paymentIntents->create()` accepts `save_card`
  (bool, default `true`; `false` registers a new card as single use at n1co and ePay saves
  nothing; a non-boolean answers `InvalidRequestException` `invalid_save_card`).
  `PaymentIntent` and `PaymentIntentEventObject` carry `save_card`.
- Contract 0.12.0 (`N1co::CONTRACT_VERSION`). `payment_method` also takes a card id saved with
  ePay v3 (24 lowercase hexadecimal characters); the intent then shows it in `card`.
- `paymentIntents->create()` answers `ConflictException` `reference_already_paid` or
  `reference_in_use` (both with `details()['payment_intent']`) when the `reference`, your order
  id, is paid or has an open intent. `PaymentIntentErrorCode::REFERENCE_ALREADY_PAID`.
- `paymentIntents->retrieve()` returns `client_secret` (null for intents created before 0.12.0),
  to resume an open intent from a new page.
- `webhook_secret` client option (`whsec_` and base64, checked when present, never echoed) and
  `$n1co->webhooks->constructEventFromRequest()`, which reads `php://input` (at most 1 MiB) and
  the headers (`getallheaders()` or `$_SERVER`) itself.

### Changed

- `PaymentMethodSummary::$brand`, `$last4`, `$exp_month` and `$exp_year` are nullable (null for a
  card saved with ePay v3).
- `PaymentIntent::$client_secret` is also set by `retrieve()`.
- `$n1co->webhooks->constructEvent($payload, $headers, $tolerance)` verifies with the client's
  `webhook_secret` (it took the secret as an argument); without one it throws `LogicException`.
  `Webhook::constructEvent()` still takes the secret.
- Webhook payloads over 1 MiB are rejected with `WebhookSignatureException` `invalid_payload`
  in every form.
- README: webhook examples (plain PHP, Laravel, Symfony) use the configured secret; the manual
  256 KiB body caps are gone.

- A `3xx` answer is a `ServiceException` with the code `unexpected_redirect` (was
  `http_<status>`), as in the Node and .NET SDKs.
- Response bodies over 1 MiB are refused with `ServiceException` `invalid_response`;
  `CurlTransport` stops reading one byte past the limit.
- Ids passed to `retrieve`, `cancel` and `resend` must look like `prefix_…`
  (`^[a-z]+_[A-Za-z0-9_-]+$`); anything else is an `InvalidArgumentException` before any request
  (ids were URL-encoded before).
- `base_url` may be `null`, meaning the production default.
- README: one errors table shape (class, HTTP status, API codes, SDK-only codes), every webhook
  verification code, and the environments and local `http` hosts stated as in the other SDKs.

### First development version (contract 0.10.0)

### Added

- `N1co\EPay\N1co` client for the n1co ePay API contract 0.10.0: `cardSessions` (create,
  retrieve), `paymentIntents` (create, retrieve, cancel), `refunds` (create, retrieve), `events`
  (list, retrieve, resend) and `webhooks->test()`.
- `Webhook::constructEvent()` for Standard Webhooks deliveries (also
  `$n1co->webhooks->constructEvent()`), and `Webhook::generateSignature()` for tests.
- Exceptions by status (`InvalidRequestException`, `AuthenticationException`,
  `NotFoundException`, `ConflictException`, `RateLimitException`, `ServiceException`,
  `ConnectionException`, `WebhookSignatureException`), all extending `N1coException`.
- Retries of GET requests only, honouring `Retry-After`; POST requests are never retried.
- Typed read-only models with `fromArray()`/`toArray()`, unknown fields kept in `$extra`, and
  statuses as string constants.
- A curl transport behind the `Transport` interface; no runtime dependencies.
- Secrets (`#[\SensitiveParameter]`, redacted dumps) kept out of traces and error reports.
