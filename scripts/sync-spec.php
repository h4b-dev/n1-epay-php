<?php

declare(strict_types=1);

// Copies the API's OpenAPI document into spec/openapi.yaml. The committed copy is what the
// tests use, so the repository builds from it alone. Set EPAY_API_DIR to a checkout of the API
// repository (default: a sibling checkout, ../n1-epay-api).
//
//   composer sync            (or: php scripts/sync-spec.php)

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$apiDir = getenv('EPAY_API_DIR');
$apiDir = is_string($apiDir) && $apiDir !== '' ? $apiDir : $root . '/../n1-epay-api';
$source = $apiDir . '/docs/openapi.yaml';
$target = $root . '/spec/openapi.yaml';

if (!is_file($source)) {
    fwrite(STDERR, "No OpenAPI document at {$source}. Set EPAY_API_DIR to a checkout of the API repository.\n");
    exit(1);
}

$spec = Yaml::parseFile($source);
if (!is_array($spec) || !isset($spec['openapi'], $spec['info']) || !is_array($spec['info']) || !is_string($spec['info']['version'] ?? null)) {
    fwrite(STDERR, "{$source} is not an OpenAPI document.\n");
    exit(1);
}

if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o755, true)) {
    fwrite(STDERR, "Cannot create spec/.\n");
    exit(1);
}
if (!copy($source, $target)) {
    fwrite(STDERR, "Cannot write {$target}.\n");
    exit(1);
}

$openapi = is_scalar($spec['openapi']) ? (string) $spec['openapi'] : '?';
echo "spec/openapi.yaml <- OpenAPI {$openapi}, API version {$spec['info']['version']}\n";
echo "Update N1co::CONTRACT_VERSION and the models when it changed; the tests say what is missing.\n";
