<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\Http\Request;
use N1co\EPay\Http\Response;
use N1co\EPay\Http\Transport;

/** A Transport that answers from a queue and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|ConnectionException> */
    private array $queue;

    public function __construct(Response|ConnectionException ...$queue)
    {
        $this->queue = array_values($queue);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string>   $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, array $details = [], array $headers = []): Response
    {
        $error = ['code' => $code, 'message' => 'fake: ' . $code];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json($status, ['error' => $error], $headers);
    }

    public static function connectionError(string $code = 'connection_error'): ConnectionException
    {
        return new ConnectionException('fake: ' . $code, $code);
    }

    public function push(Response|ConnectionException ...$replies): void
    {
        foreach ($replies as $reply) {
            $this->queue[] = $reply;
        }
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $reply = array_shift($this->queue);
        if ($reply === null) {
            throw new \LogicException('FakeTransport has no reply queued.');
        }
        if ($reply instanceof ConnectionException) {
            throw $reply;
        }

        return $reply;
    }

    public function last(): Request
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }
}
