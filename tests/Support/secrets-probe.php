<?php

declare(strict_types=1);

// Run by SecretsTest in its own PHP process with zend.exception_ignore_args=0: provokes failures
// in every layer and prints full traces and dumps, which must not contain any secret.

use N1co\EPay\Exception\N1coException;
use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\Spec;
use N1co\EPay\Webhook;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dump = static function (string $label, \Throwable $e): void {
    echo "== {$label}: ", $e::class, "\n";
    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        echo $current->getMessage(), "\n", print_r($current->getTrace(), true), "\n";
        if ($current instanceof N1coException) {
            echo print_r($current->headers(), true), print_r($current->details(), true);
        }
    }
};

$intent = Examples::response('POST', '/v1/payment_intents');

// A bad option while constructing the client.
try {
    new N1co(Spec::SECRET_KEY, ['timeout' => 0]);
} catch (\Throwable $e) {
    $dump('constructor', $e);
}

// An API error and an unreadable answer that carries a client secret.
$transport = new FakeTransport(
    FakeTransport::error(401, 'invalid_api_key'),
    FakeTransport::json(201, ['status' => 7] + $intent),
);
$n1co = new N1co(Spec::SECRET_KEY, ['transport' => $transport, 'max_retries' => 0]);
foreach (['events->list' => static fn() => $n1co->events->list(), 'paymentIntents->create' => static fn() => $n1co->paymentIntents->create(['amount' => 1, 'currency' => 'USD', 'customer' => ['id' => 'c', 'name' => 'Ana', 'email' => 'a@example.com', 'phone' => '+50370000000']])] as $label => $call) {
    try {
        $call();
    } catch (\Throwable $e) {
        $dump($label, $e);
    }
}

// A connection error through the real curl transport.
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = $socket === false ? '127.0.0.1:9' : (string) stream_socket_get_name($socket, false);
if ($socket !== false) {
    fclose($socket);
}
try {
    (new N1co(Spec::SECRET_KEY, ['base_url' => 'http://' . $address, 'max_retries' => 0, 'timeout' => 2]))->events->list();
} catch (\Throwable $e) {
    $dump('curl', $e);
}

// Webhooks: a bad signature and a bad tolerance.
foreach ([300, -1] as $tolerance) {
    try {
        Webhook::constructEvent('{}', ['webhook-id' => 'evt_x', 'webhook-timestamp' => (string) time(), 'webhook-signature' => 'v1,AAAA'], Spec::WEBHOOK_SECRET, $tolerance);
    } catch (\Throwable $e) {
        $dump('webhook ' . $tolerance, $e);
    }
}

echo "== dumps\n", print_r($n1co, true), print_r($transport->requests, true);
ob_start();
var_dump($n1co);
echo ob_get_clean();
echo "== done\n";
