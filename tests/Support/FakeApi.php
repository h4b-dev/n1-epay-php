<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

/**
 * A fake of the n1co ePay API built from spec/openapi.yaml, served by PHP's built-in server
 * (tests/Contract/router.php). It serves every partner operation, checks the key, path ids,
 * query parameters and request bodies against the contract, answers with the contract's
 * examples and validates each answer against the response schema, so the SDK is tested against
 * the contract rather than against itself.
 *
 * State shared with the tests lives in files of a state directory: the recorded requests, the
 * forced replies and the contract violations of the fake's own answers.
 */
final class FakeApi
{
    /** @var array<array-key, mixed> */
    private readonly array $spec;
    private readonly SchemaValidator $validator;

    public function __construct(private readonly string $stateDir)
    {
        $spec = json_decode((string) file_get_contents($stateDir . '/spec.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->spec = \is_array($spec) ? $spec : [];
        $this->validator = new SchemaValidator($this->spec);
    }

    /**
     * Handles one request and returns the answer.
     *
     * @param array<string, string> $headers Names in lower case.
     *
     * @return array{status: int, headers: array<string, string>, body: string, delay: float}
     */
    public function handle(string $method, string $uri, array $headers, string $body): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $search = (string) parse_url($uri, PHP_URL_QUERY);
        $match = $this->match($method, $path);
        $this->append('requests.jsonl', [
            'operationId' => $match['op']['id'] ?? null,
            'method' => $method,
            'path' => $path,
            'search' => $search,
            'headers' => $headers,
            'body' => $body,
        ]);
        $requestId = preg_match('/^[A-Za-z0-9._-]{1,128}$/', $headers['x-request-id'] ?? '') === 1 ? $headers['x-request-id'] : 'generated-by-fake';
        $forced = $this->shiftForced();
        if ($forced !== null) {
            $delay = $forced['delay'] ?? 0;

            return $this->send($requestId, \is_int($forced['status'] ?? null) ? $forced['status'] : 500, $forced['body'] ?? null, Spec::map($forced['headers'] ?? null), \is_int($delay) || \is_float($delay) ? (float) $delay : 0.0);
        }
        if ($match === null) {
            return $this->error($requestId, 404, 'not_found');
        }
        $op = $match['op'];
        if (($headers['authorization'] ?? '') !== 'Bearer ' . Spec::SECRET_KEY) {
            $unauthorized = Spec::deref(Spec::at('#/components/responses/Unauthorized', $this->spec), $this->spec);
            $content = Spec::map(Spec::map($unauthorized['content'] ?? null)['application/json'] ?? null);

            return $this->send($requestId, 401, Spec::examples($content)[0] ?? null, ['WWW-Authenticate' => 'Bearer realm="n1co ePay API"']);
        }
        $ids = [];
        foreach ($op['params'] as $index => [$name, $schema]) {
            $value = rawurldecode($match['groups'][$index + 1] ?? '');
            if ($this->validator->validate($value, $schema) !== null) {
                return $this->error($requestId, 404, $op['notFoundCode']);
            }
            $ids[$name] = $value;
        }
        foreach ($search === '' ? [] : explode('&', $search) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($name);
            $value = rawurldecode($value);
            if (!isset($op['query'][$name])) {
                return $this->error($requestId, 400, 'invalid_request', ['field' => $name, 'code' => 'unknown_parameter']);
            }
            $typed = preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value;
            if ($this->validator->validate($typed, $op['query'][$name]) !== null) {
                return $this->error($requestId, 400, 'invalid_request', ['field' => $name, 'code' => 'invalid_' . $name]);
            }
        }
        $input = null;
        if ($op['body'] !== null) {
            if (!str_starts_with($headers['content-type'] ?? '', 'application/json')) {
                return $this->error($requestId, 415, 'unsupported_media_type');
            }
            try {
                $input = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->error($requestId, 400, 'invalid_request');
            }
            $violation = $this->validator->validate($input, $op['body']);
            if ($violation !== null) {
                return $this->error($requestId, 400, 'invalid_request', self::fieldError($violation));
            }
        } elseif ($body !== '') {
            $this->append('violations.jsonl', ['violation' => $op['id'] . ': unexpected request body']);
        }

        $answer = $this->answer($op, $ids, Spec::map($input));
        $violation = $this->validator->validate($answer, $op['response']);
        if ($violation !== null) {
            $this->append('violations.jsonl', ['violation' => $op['id'] . ': answer violates the contract at ' . implode('.', $violation['path']) . ' (' . $violation['keyword'] . ')']);

            return $this->error($requestId, 500, 'internal_error');
        }

        return $this->send($requestId, $op['status'], $answer);
    }

    /**
     * @param array<array-key, mixed> $extra
     *
     * @return array{status: int, headers: array<string, string>, body: string, delay: float}
     */
    private function send(string $requestId, int $status, mixed $payload, array $extra = [], float $delay = 0.0): array
    {
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Request-Id' => $requestId];
        foreach (Spec::map($extra) as $name => $value) {
            $headers[$name] = \is_string($value) ? $value : '';
        }

        return ['status' => $status, 'headers' => $headers, 'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'delay' => $delay];
    }

    /**
     * @param array<string, string> $details
     *
     * @return array{status: int, headers: array<string, string>, body: string, delay: float}
     */
    private function error(string $requestId, int $status, string $code, array $details = []): array
    {
        $error = ['code' => $code, 'message' => 'fake: ' . $code];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $this->send($requestId, $status, ['error' => $error]);
    }

    /**
     * @return array{op: array{id: string, params: list<array{string, array<array-key, mixed>}>, query: array<string, array<array-key, mixed>>, body: array<array-key, mixed>|null, status: int, example: array<string, mixed>, response: array<array-key, mixed>, notFoundCode: string}, groups: list<string>}|null
     */
    private function match(string $method, string $path): ?array
    {
        foreach (Spec::partnerOperations($this->spec) as $candidate) {
            if ($candidate['method'] !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('/\\\{[^}]+\\\}/', '([^/]+)', preg_quote($candidate['path'], '#')) . '$#';
            if (preg_match($pattern, $path, $groups) === 1) {
                return ['op' => $this->compile($candidate['id'], $method, $candidate['path']), 'groups' => array_values($groups)];
            }
        }

        return null;
    }

    /**
     * @return array{id: string, params: list<array{string, array<array-key, mixed>}>, query: array<string, array<array-key, mixed>>, body: array<array-key, mixed>|null, status: int, example: array<string, mixed>, response: array<array-key, mixed>, notFoundCode: string}
     */
    private function compile(string $id, string $method, string $template): array
    {
        $item = Spec::map(Spec::map($this->spec['paths'] ?? null)[$template] ?? null);
        $op = Spec::operation($method, $template, $this->spec);
        $parameters = array_map(
            fn(mixed $p): array => Spec::deref($p, $this->spec),
            array_merge(\is_array($item['parameters'] ?? null) ? $item['parameters'] : [], \is_array($op['parameters'] ?? null) ? $op['parameters'] : []),
        );
        $params = [];
        $query = [];
        foreach ($parameters as $parameter) {
            $schema = \is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];
            $name = (string) Spec::string($parameter['name'] ?? null);
            if (($parameter['in'] ?? null) === 'path') {
                $params[] = [$name, $schema];
            } elseif (($parameter['in'] ?? null) === 'query') {
                $query[$name] = $schema;
            }
        }
        $body = null;
        if (isset($op['requestBody'])) {
            $requestBody = Spec::deref($op['requestBody'], $this->spec);
            $body = Spec::map(Spec::map(Spec::map($requestBody['content'] ?? null)['application/json'] ?? null)['schema'] ?? null);
        }
        $responses = Spec::map($op['responses'] ?? null);
        // The success answer: the 2xx with an example (createRefund documents 200 and 201), else the first.
        $successes = array_values(array_filter(array_keys($responses), static fn(string $code): bool => str_starts_with($code, '2')));
        $status = $successes[0];
        foreach ($successes as $code) {
            if (Spec::examples($this->content($responses[$code])) !== []) {
                $status = $code;
                break;
            }
        }
        $content = $this->content($responses[$status]);
        $notFound = isset($responses['404']) ? Spec::deref($responses['404'], $this->spec) : [];
        $notFoundCode = preg_match('/`([a-z_]+)`/', (string) Spec::string($notFound['description'] ?? null), $m) === 1 ? $m[1] : 'not_found';

        return [
            'id' => $id,
            'params' => $params,
            'query' => $query,
            'body' => $body,
            'status' => (int) $status,
            'example' => Spec::map(Spec::examples($content)[0] ?? null),
            'response' => Spec::map($content['schema'] ?? null),
            'notFoundCode' => $notFoundCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(mixed $response): array
    {
        return Spec::map(Spec::map(Spec::deref($response, $this->spec)['content'] ?? null)['application/json'] ?? null);
    }

    /**
     * The contract's example for the operation, or one derived from a sibling's, with the
     * request's ids.
     *
     * @param array{id: string, example: array<string, mixed>} $op
     * @param array<string, string>                              $ids
     * @param array<string, mixed>                               $input
     *
     * @return array<string, mixed>
     */
    private function answer(array $op, array $ids, array $input): array
    {
        $example = fn(string $id): array => $this->compile($id, ...$this->locate($id))['example'];
        $intent = static function () use ($example): array {
            $intent = $example('createPaymentIntent');
            unset($intent['client_secret']);

            return $intent;
        };
        $event = static fn(): array => $example('resendEvent');

        switch ($op['id']) {
            case 'createCardSession':
                return array_merge($op['example'], [
                    'page_origins' => $input['page_origins'] ?? null,
                ], isset($input['card']) ? ['mode' => 'update', 'card' => $input['card']] : ['mode' => 'capture', 'card' => null]);
            case 'getCardSession':
                $session = $example('createCardSession');
                unset($session['client_secret']);

                return array_merge($session, ['id' => $ids['id'], 'created_at' => '2026-10-16T15:04:05Z']);
            case 'createPaymentIntent':
                $created = $op['example'];
                foreach (['amount', 'currency', 'description', 'reference', 'metadata', 'page_origins'] as $key) {
                    if (\array_key_exists($key, $input)) {
                        $created[$key] = $input[$key];
                    }
                }
                if (\is_string($input['payment_method'] ?? null) && preg_match('/^[0-9a-f]{24}$/', $input['payment_method']) === 1) {
                    // A card saved with ePay v3: only its id is known (the contract's v3Card example).
                    $created = array_merge($created, [
                        'card' => $input['payment_method'],
                        'payment_method' => ['brand' => null, 'last4' => null, 'exp_month' => null, 'exp_year' => null, 'saved' => true],
                        'cvc_required' => false,
                    ]);
                } elseif (!isset($input['payment_method'])) {
                    $created = array_merge($created, ['card' => null, 'payment_method' => null, 'cvc_required' => false]);
                }

                return $created;
            case 'getPaymentIntent':
                return array_merge($intent(), ['id' => $ids['id'], 'client_secret' => $example('createPaymentIntent')['client_secret']]);
            case 'cancelPaymentIntent':
                return array_merge($intent(), [
                    'id' => $ids['id'],
                    'status' => 'canceled',
                    'cancellation_reason' => 'requested',
                    'attempts_remaining' => 0,
                    'canceled_at' => '2026-10-17T15:10:00Z',
                ]);
            case 'createRefund':
                return array_merge($op['example'], ['payment_intent' => $input['payment_intent'] ?? null]);
            case 'getRefund':
                return array_merge($example('createRefund'), ['id' => $ids['id']]);
            case 'listEvents':
                return ['data' => [$event()]];
            case 'getEvent':
            case 'resendEvent':
                return array_merge($event(), ['id' => $ids['id']]);
            case 'testWebhook':
                return array_merge($event(), ['type' => 'webhook.test', 'data' => ['object' => ['message' => 'test']]]);
            default:
                return $op['example'];
        }
    }

    /**
     * @return array{string, string}
     */
    private function locate(string $operationId): array
    {
        foreach (Spec::partnerOperations($this->spec) as $op) {
            if ($op['id'] === $operationId) {
                return [$op['method'], $op['path']];
            }
        }

        throw new \LogicException("Unknown operation {$operationId}.");
    }

    /**
     * @param array{path: list<string>, keyword: string, param: string} $violation
     *
     * @return array{field: string, code: string}
     */
    private static function fieldError(array $violation): array
    {
        $path = $violation['path'];
        if ($violation['keyword'] === 'additionalProperties') {
            return ['field' => implode('.', [...$path, $violation['param']]), 'code' => 'unknown_field'];
        }
        if ($violation['keyword'] === 'required') {
            return ['field' => implode('.', [...$path, $violation['param']]), 'code' => 'missing_' . $violation['param']];
        }
        $last = $path === [] ? 'body' : $path[\count($path) - 1];

        return ['field' => implode('.', $path), 'code' => 'invalid_' . $last];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shiftForced(): ?array
    {
        $file = $this->stateDir . '/forced.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }
        flock($handle, LOCK_EX);
        $queue = json_decode((string) stream_get_contents($handle), true) ?? [];
        $first = \is_array($queue) && $queue !== [] ? array_shift($queue) : null;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(\is_array($queue) ? array_values($queue) : [], JSON_THROW_ON_ERROR));
        flock($handle, LOCK_UN);
        fclose($handle);

        return \is_array($first) ? Spec::map($first) : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function append(string $name, array $record): void
    {
        file_put_contents($this->stateDir . '/' . $name, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
