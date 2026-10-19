<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The contract tests start the fake API themselves (FakeApiServer::instance()); the unit suite
// needs no server.
