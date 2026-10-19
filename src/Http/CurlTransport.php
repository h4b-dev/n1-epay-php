<?php

declare(strict_types=1);

namespace N1co\EPay\Http;

use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\N1co;

/**
 * The default Transport, on ext-curl. It never follows redirects (the secret key must not be
 * sent to another host), verifies TLS certificates and host names, allows plain http only for
 * local hosts, and limits every attempt to the request's timeout (connecting to at most 10 s).
 * One handle is reused so connections are kept alive.
 */
final class CurlTransport implements Transport
{
    private const MAX_CONNECT_TIMEOUT_MS = 10_000;

    private ?\CurlHandle $handle = null;

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        $handle = $this->handle ??= curl_init();
        if ($handle === false) {
            throw new ConnectionException('Could not initialise curl; the request was not sent.', 'connection_error', requestId: $request->requestId());
        }
        curl_reset($handle);

        $responseHeaders = [];
        $onHeader = static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                $responseHeaders = []; // a new response (after 100 Continue)
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
            }

            return \strlen($line);
        };
        curl_setopt_array($handle, self::options($request, $onHeader));

        $body = curl_exec($handle);
        if (!\is_string($body)) {
            $errno = curl_errno($handle);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;

            throw new ConnectionException(
                $timedOut
                    ? \sprintf('The request to the n1co ePay API timed out after %s s; its outcome is unknown.', rtrim(rtrim(number_format($request->timeout, 3, '.', ''), '0'), '.'))
                    : \sprintf('Could not reach the n1co ePay API (curl error %d: %s); the request\'s outcome is unknown.', $errno, curl_strerror($errno) ?? 'unknown'),
                $timedOut ? 'timeout' : 'connection_error',
                requestId: $request->requestId(),
            );
        }

        return new Response(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
    }

    /**
     * The curl options of a request.
     *
     * @internal
     *
     * @param \Closure(\CurlHandle, string): int $onHeader
     *
     * @return array<int, mixed>
     */
    public static function options(#[\SensitiveParameter] Request $request, \Closure $onHeader): array
    {
        $headers = ['Expect:']; // no 100-continue round trip
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $timeoutMs = max(1, (int) ceil($request->timeout * 1000));
        $host = parse_url($request->url, PHP_URL_HOST);
        $local = \is_string($host) && N1co::isLocalHost($host);

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::MAX_CONNECT_TIMEOUT_MS, $timeoutMs),
            CURLOPT_HEADERFUNCTION => $onHeader,
        ];
        $protocolsStr = \defined('CURLOPT_PROTOCOLS_STR') ? \constant('CURLOPT_PROTOCOLS_STR') : null; // PHP 8.3+, libcurl 7.85+
        if (\is_int($protocolsStr)) {
            $options[$protocolsStr] = $local ? 'https,http' : 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = $local ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS;
        }
        if ($request->method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $request->method;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        return $options;
    }
}
