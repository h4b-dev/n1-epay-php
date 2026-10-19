# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) from 1.0.

## [Unreleased]

### Changed

- A `3xx` answer is a `ServiceException` with the code `unexpected_redirect` (was
  `http_<status>`), as in the Node and .NET SDKs.
- Response bodies over 1 MiB are refused with `ServiceException` `invalid_response`;
  `CurlTransport` stops reading one byte past the limit.
- Ids passed to `retrieve`, `cancel` and `resend` must look like `prefix_…`
  (`^[a-z]+_[A-Za-z0-9_-]+$`); anything else is an `InvalidArgumentException` before any request
  (ids were URL-encoded before).
- `base_url` may be `null`, meaning the production default.
- README: one errors table shape (class, HTTP status, API codes, SDK-only codes), every webhook
  verification code, 256 KiB raw-body caps in the webhook examples, and the environments and
  local `http` hosts stated as in the other SDKs.

## [0.1.0] - Unreleased

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
