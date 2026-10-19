<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Model\Event;
use N1co\EPay\Model\EventList;

/** Events and their webhook delivery state. */
final class Events
{
    /** @internal */
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Lists the store's events, newest first (`limit` 1-100, default 20; no pagination yet).
     *
     * @param array{type?: string, limit?: int} $params
     * @param array{timeout?: int|float}        $options
     */
    public function list(array $params = [], array $options = []): EventList
    {
        $type = $params['type'] ?? null;
        $limit = $params['limit'] ?? null;
        if ($type !== null && !\is_string($type)) {
            throw new \InvalidArgumentException('type must be a string, one of EventType::ALL.');
        }
        if ($limit !== null && !\is_int($limit)) {
            throw new \InvalidArgumentException('limit must be an integer from 1 to 100.');
        }

        return $this->http->request('GET', '/v1/events', EventList::read(...), query: ['type' => $type, 'limit' => $limit], options: $options);
    }

    /**
     * Retrieves an event with its delivery state.
     *
     * @param array{timeout?: int|float} $options
     */
    public function retrieve(string $id, array $options = []): Event
    {
        return $this->http->request('GET', '/v1/events/' . PathId::encode($id), Event::read(...), options: $options);
    }

    /**
     * Schedules one immediate delivery attempt of the event (202). At most once every 10 seconds
     * per event (RateLimitException otherwise); ConflictException `delivery_in_progress` while
     * one runs.
     *
     * @param array{timeout?: int|float} $options
     */
    public function resend(string $id, array $options = []): Event
    {
        return $this->http->request('POST', '/v1/events/' . PathId::encode($id) . '/resend', Event::read(...), options: $options);
    }
}
