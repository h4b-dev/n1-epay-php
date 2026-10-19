<?php

declare(strict_types=1);

// Manual smoke test against a real environment (not run in CI). Prints ids and statuses only:
// never the secret key, Authorization headers or client secrets. Every payment intent it
// creates is canceled, and only the webhook.test event it creates is resent. It refuses the
// production URL unless N1CO_SMOKE_ALLOW_PROD=1.
//
//   N1CO_BASE_URL=https://api-epay.dev.n1co.dev N1CO_SECRET_KEY=sk_... php scripts/smoke.php

use N1co\EPay\Exception\ConflictException;
use N1co\EPay\Exception\InvalidRequestException;
use N1co\EPay\Exception\N1coException;
use N1co\EPay\Exception\NotFoundException;
use N1co\EPay\Exception\RateLimitException;
use N1co\EPay\Model\CardSessionCreated;
use N1co\EPay\Model\Event;
use N1co\EPay\Model\PaymentIntent;
use N1co\EPay\N1co;
use N1co\EPay\ResponseInfo;

require dirname(__DIR__) . '/vendor/autoload.php';

$secretKey = getenv('N1CO_SECRET_KEY');
$baseUrl = getenv('N1CO_BASE_URL');
if (!is_string($secretKey) || $secretKey === '' || !is_string($baseUrl) || $baseUrl === '') {
    fwrite(STDERR, "Set N1CO_SECRET_KEY and N1CO_BASE_URL.\n");
    exit(2);
}
if (rtrim(strtolower(trim($baseUrl)), '/') === N1co::DEFAULT_BASE_URL && getenv('N1CO_SMOKE_ALLOW_PROD') !== '1') {
    fwrite(STDERR, "Refusing to run against production; set N1CO_SMOKE_ALLOW_PROD=1 to do it on purpose.\n");
    exit(2);
}

$n1co = new N1co(trim($secretKey), ['base_url' => $baseUrl, 'app_info' => ['name' => 'n1co-epay-php-smoke']]);
$suffix = bin2hex(random_bytes(6));
$customer = [
    'id' => 'sdk-smoke-' . $suffix,
    'name' => 'Smoke Test',
    'email' => 'sdk-smoke-' . $suffix . '@example.com',
    'phone' => '+50370000000',
];
$failures = 0;

$log = static function (string $operation, string $outcome): void {
    echo str_pad($operation, 38) . ' ' . $outcome . "\n";
};

$describe = static function (\Throwable $error): string {
    if (!$error instanceof N1coException) {
        return $error::class;
    }

    return sprintf('%s %s %s (request %s)', (new \ReflectionClass($error))->getShortName(), $error->status() ?? '-', $error->code(), $error->requestId() ?? '-');
};

/**
 * Runs one step; $run returns what to print.
 *
 * @param \Closure(): string $run
 */
$step = static function (string $operation, \Closure $run) use ($log, $describe, &$failures): bool {
    try {
        $outcome = $run();
        $log($operation, is_string($outcome) ? $outcome : '');

        return true;
    } catch (\Throwable $error) {
        ++$failures;
        $log($operation, 'FAILED: ' . $describe($error));

        return false;
    }
};

/**
 * @param class-string<N1coException> $class
 */
$expectError = static function (string $operation, \Closure $run, string $class) use ($log, $describe, &$failures): void {
    try {
        $run();
        ++$failures;
        $log($operation, 'FAILED: expected ' . $class . ', got a success');
    } catch (\Throwable $error) {
        if ($error instanceof N1coException && $error instanceof $class) {
            $field = $error->details()['field'] ?? null;
            $log($operation, sprintf('%s %d %s', (new \ReflectionClass($error))->getShortName(), $error->status() ?? 0, $error->code()) . (is_string($field) ? ' field=' . $field : '') . ' (expected)');
        } else {
            ++$failures;
            $log($operation, 'FAILED: ' . $describe($error));
        }
    }
};

$status = static fn(?ResponseInfo $info): string => (string) ($info->status ?? '-');

$session = null;
$step('cardSessions.create', static function () use ($n1co, $customer, $status, &$session): string {
    $session = $n1co->cardSessions->create(['customer' => $customer]);

    return sprintf('%s %s mode=%s status=%s', $status($session->lastResponse), $session->id, $session->mode, $session->status);
});
if ($session instanceof CardSessionCreated) {
    $id = $session->id;
    $step('cardSessions.retrieve', static function () use ($n1co, $status, $id): string {
        $s = $n1co->cardSessions->retrieve($id);

        return sprintf('%s %s status=%s', $status($s->lastResponse), $s->id, $s->status);
    });
}

$intent = null;
$step('paymentIntents.create (USD 1.00)', static function () use ($n1co, $customer, $suffix, $status, &$intent): string {
    $intent = $n1co->paymentIntents->create([
        'amount' => 100,
        'currency' => 'USD',
        'customer' => $customer,
        'reference' => 'sdk-smoke-' . $suffix,
        'metadata' => ['source' => 'sdk-smoke'],
    ]);

    return sprintf('%s %s status=%s', $status($intent->lastResponse), $intent->id, $intent->status);
});
if ($intent instanceof PaymentIntent) {
    $id = $intent->id;
    $step('paymentIntents.retrieve', static function () use ($n1co, $status, $id): string {
        $pi = $n1co->paymentIntents->retrieve($id);

        return sprintf('%s %s status=%s', $status($pi->lastResponse), $pi->id, $pi->status);
    });
    $canceled = $step('paymentIntents.cancel', static function () use ($n1co, $status, $id): string {
        $pi = $n1co->paymentIntents->cancel($id);

        return sprintf('%s %s status=%s reason=%s', $status($pi->lastResponse), $pi->id, $pi->status, $pi->cancellation_reason ?? 'null');
    });
    if (!$canceled) {
        $log('cleanup', 'intent ' . $id . ' may still be open');
    }
}

$testEvent = null;
$step('webhooks.test', static function () use ($n1co, $status, &$testEvent): string {
    $testEvent = $n1co->webhooks->test();

    return sprintf('%s %s %s delivery=%s', $status($testEvent->lastResponse), $testEvent->id, $testEvent->type, $testEvent->delivery->status ?? '-');
});

$step('events.list (limit 5)', static function () use ($n1co, $status): string {
    $list = $n1co->events->list(['limit' => 5]);

    return sprintf('%s %d events: %s', $status($list->lastResponse), count($list), implode(', ', array_map(static fn(Event $e): string => $e->type, $list->data)));
});
if ($testEvent instanceof Event) {
    $id = $testEvent->id;
    $step('events.retrieve (the test event)', static function () use ($n1co, $status, $id): string {
        $e = $n1co->events->retrieve($id);

        return sprintf('%s %s %s delivery=%s', $status($e->lastResponse), $e->id, $e->type, $e->delivery->status ?? '-');
    });
    $step('events.resend (the test event)', static function () use ($n1co, $status, $id): string {
        try {
            try {
                $e = $n1co->events->resend($id);
            } catch (ConflictException $error) {
                if ($error->code() !== 'delivery_in_progress') {
                    throw $error;
                }
                sleep(3); // the first delivery of the new event is still running
                $e = $n1co->events->resend($id);
            }

            return sprintf('%s %s delivery=%s', $status($e->lastResponse), $e->id, $e->delivery->status ?? '-');
        } catch (RateLimitException $error) {
            return sprintf('429 rate_limited retryAfter=%s (accepted)', $error->retryAfter() ?? '-');
        }
    });
} else {
    $log('events.retrieve / resend', 'skipped: webhooks.test failed');
}

$safe = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
$unknownRefund = 're_';
foreach (str_split(random_bytes(24)) as $byte) {
    $unknownRefund .= $safe[ord($byte) % 64];
}
$expectError('refunds.retrieve (unknown id)', static fn() => $n1co->refunds->retrieve($unknownRefund), NotFoundException::class);
$expectError('paymentIntents.create (bad currency)', static function () use ($n1co, $customer): void {
    $created = $n1co->paymentIntents->create(['amount' => 100, 'currency' => 'usd', 'customer' => $customer]);
    $n1co->paymentIntents->cancel($created->id); // never left open, should it be accepted
}, InvalidRequestException::class);

echo $failures === 0 ? "Smoke passed.\n" : "Smoke failed: {$failures} step(s).\n";
exit($failures === 0 ? 0 : 1);
