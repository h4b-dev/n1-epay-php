<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\N1co;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function testTheChangelogHasTheVersion(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertStringContainsString('## [' . N1co::VERSION . ']', $changelog);
    }

    public function testComposerJsonHasNoRuntimeDependencies(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        self::assertSame(['ext-curl', 'ext-json', 'php'], self::sorted(array_map('strval', array_keys($composer['require']))));
        self::assertSame('n1co/epay', $composer['name'] ?? null);
        self::assertSame('proprietary', $composer['license'] ?? null);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
