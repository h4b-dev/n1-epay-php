<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Contract;

use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\Exception\RateLimitException;
use N1co\EPay\Exception\ServiceException;
use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\FakeApiServer;
use N1co\EPay\Tests\Support\Spec;
use PHPUnit\Framework\TestCase;

/** The curl transport over real HTTP: redirects, timeouts, connection errors and retries. */
final class CurlTransportTest extends TestCase
{
    private FakeApiServer $fake;

    protected function setUp(): void
    {
        $this->fake = FakeApiServer::instance();
        $this->fake->reset();
    }

    /**
     * @param array{timeout?: int|float, max_retries?: int} $options
     */
    private function client(array $options = []): N1co
    {
        return new N1co(Spec::SECRET_KEY, $options + ['base_url' => $this->fake->baseUrl]);
    }

    public function testNeverFollowsARedirect(): void
    {
        $this->fake->force(302, null, ['Location' => $this->fake->baseUrl . '/v1/events']);
        try {
            $this->client(['max_retries' => 0])->events->list();
            self::fail('Expected a ServiceException.');
        } catch (ServiceException $e) {
            self::assertSame('unexpected_redirect', $e->code());
            self::assertCount(1, $this->fake->requests());
        }
    }

    public function testStopsReadingABodyOver1MiB(): void
    {
        $this->fake->force(200, ['data' => [], 'pad' => str_repeat('x', 4 * 1024 * 1024)]);
        try {
            $this->client(['max_retries' => 0])->events->list();
            self::fail('Expected a ServiceException.');
        } catch (ServiceException $e) {
            self::assertSame('invalid_response', $e->code());
            self::assertSame(200, $e->status());
        }
    }

    public function testRetriesAGetAfterA503(): void
    {
        $this->fake->force(503, ['error' => ['code' => 'n1co_api_unavailable', 'message' => 'later']], ['Retry-After' => '0']);
        $list = $this->client(['max_retries' => 1])->events->list();
        self::assertCount(1, $list);
        $requests = $this->fake->requests();
        self::assertCount(2, $requests);
        self::assertNotSame($requests[0]['headers']['x-request-id'], $requests[1]['headers']['x-request-id']);
    }

    public function testNeverRetriesAPost(): void
    {
        $this->fake->force(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down']], ['Retry-After' => '0']);
        try {
            $this->client(['max_retries' => 3])->events->resend('evt_RXZlbnRFeGFtcGxlMDAwMDAx');
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(0, $e->retryAfter());
            self::assertCount(1, $this->fake->requests());
        }
    }

    public function testTimesOut(): void
    {
        $this->fake->force(200, ['data' => []], [], 0.6);
        $started = microtime(true);
        try {
            $this->client(['timeout' => 0.2, 'max_retries' => 0])->events->list();
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame('timeout', $e->code());
            self::assertNotNull($e->requestId());
            self::assertLessThan(0.55, microtime(true) - $started);
        }
    }

    public function testReportsAConnectionError(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $client = new N1co(Spec::SECRET_KEY, ['base_url' => 'http://' . $name, 'max_retries' => 0, 'timeout' => 2]);
        try {
            $client->events->list();
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame('connection_error', $e->code());
            self::assertStringNotContainsString(Spec::SECRET_KEY, $e->getMessage());
        }
    }
}
