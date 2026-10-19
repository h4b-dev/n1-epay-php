<?php

declare(strict_types=1);

namespace N1co\EPay\Service;

/** @internal */
final class PathId
{
    /** Encodes an object id for a path segment; rejects empty ids early. */
    public static function encode(string $id, string $name = 'id'): string
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException(\sprintf('%s must be a non-empty string.', $name));
        }

        return rawurlencode($id);
    }
}
