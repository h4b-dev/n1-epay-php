<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

/** @internal */
final class PathId
{
    /** Checks an object id (`^[a-z]+_[A-Za-z0-9_-]+$`) before any request; it is then safe in a path. */
    public static function encode(string $id, string $name = 'id'): string
    {
        if (preg_match('/^[a-z]+_[A-Za-z0-9_-]+\z/', $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s must look like prefix_… (for example pi_…).', $name));
        }

        return $id;
    }
}
