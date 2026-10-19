<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Model\CardSession;
use N1co\EPay\Model\CardSessionCreated;

/** Card sessions authorise the n1co.js card element to save a new card, or update a saved one. */
final class CardSessions
{
    /** @internal */
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Creates a session for a customer. Pass `client_secret` (shown only once) to n1co.js in the
     * buyer's browser. With `card`, the session updates that saved card (`mode` `update`).
     *
     * @param array{customer: array{id: string, name: string, email: string, phone: string}, page_origins?: list<string>|null, card?: string|null} $params
     * @param array{timeout?: int|float}                                                                                                          $options
     */
    public function create(array $params, array $options = []): CardSessionCreated
    {
        return $this->http->request('POST', '/v1/card_sessions', CardSessionCreated::read(...), body: $params, options: $options);
    }

    /**
     * Retrieves a session; once `completed`, `card` is the saved card's id.
     *
     * @param array{timeout?: int|float} $options
     */
    public function retrieve(string $id, array $options = []): CardSession
    {
        return $this->http->request('GET', '/v1/card_sessions/' . PathId::encode($id), CardSession::read(...), options: $options);
    }
}
