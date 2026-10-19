<?php

declare(strict_types=1);

namespace N1co\EPay\Http;

use N1co\EPay\Exception\ConnectionException;

/**
 * Sends one HTTP request. The SDK's default is CurlTransport; tests use a fake.
 *
 * An implementation must not follow redirects, must verify TLS and must honour the request's
 * timeout. A PSR-18 adapter may be added later; the SDK keeps no runtime dependencies.
 */
interface Transport
{
    /**
     * Returns the response, whatever its status.
     *
     * @throws ConnectionException when no response was received (code `connection_error` or `timeout`)
     */
    public function send(Request $request): Response;
}
