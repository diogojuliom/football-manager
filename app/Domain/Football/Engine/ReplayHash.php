<?php

namespace App\Domain\Football\Engine;

final class ReplayHash
{
    public static function of(array $data): string
    {
        return hash('sha256', json_encode(self::sort($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sort(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sort($value);
            }
        }

        return $data;
    }
}
