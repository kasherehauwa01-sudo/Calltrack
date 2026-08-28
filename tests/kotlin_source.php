<?php

declare(strict_types=1);

function readKotlinSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return preg_replace_callback(
        '/\\\\u([0-9A-Fa-f]{4})/',
        static fn(array $match): string => (string)json_decode('"\\u' . $match[1] . '"'),
        $source
    ) ?? $source;
}
