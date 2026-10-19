<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

use N1co\EPay\N1co;

final class TestClient
{
    public const KEY = Spec::SECRET_KEY;

    /**
     * @param array{base_url?: string|null, timeout?: int|float, max_retries?: int, app_info?: array{name: string, version?: string, url?: string}, webhook_secret?: string|null} $options
     */
    public static function make(FakeTransport $transport, array $options = []): N1co
    {
        return new N1co(self::KEY, $options + ['transport' => $transport, 'max_retries' => 0]);
    }
}
