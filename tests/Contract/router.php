<?php

declare(strict_types=1);

// Router of the fake n1co ePay API under PHP's built-in server; see tests/Support/FakeApi.php.

use N1co\EPay\Tests\Support\FakeApi;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$stateDir = getenv('N1CO_FAKE_STATE');
if (!is_string($stateDir) || $stateDir === '') {
    http_response_code(500);

    return true;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower((string) $name)] = is_string($value) ? $value : '';
}
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';

$answer = (new FakeApi($stateDir))->handle($method, $uri, $headers, (string) file_get_contents('php://input'));
if ($answer['delay'] > 0) {
    usleep((int) ($answer['delay'] * 1_000_000));
}
http_response_code($answer['status']);
foreach ($answer['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $answer['body'];

return true;
