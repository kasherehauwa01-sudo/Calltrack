<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceRoot = $root . '/app/src/main/java';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
$violations = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'kt') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        throw new RuntimeException('Не удалось прочитать ' . $file->getPathname());
    }

    // Kotlin-литералы с кириллицей записываем через Unicode escape. Так текст не
    // зависит от системной кодировки Gradle/Kotlin daemon на машине сборки.
    foreach (preg_split('/\R/u', $contents) ?: [] as $index => $lineContents) {
        if (preg_match('/"[^"\r\n]*[\x{0400}-\x{04FF}][^"\r\n]*"|\'[^\'\r\n]*[\x{0400}-\x{04FF}][^\'\r\n]*\'/u', $lineContents, $match)) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $violations[] = sprintf('%s:%d: %s', $relative, $index + 1, $match[0]);
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException(
        "Кириллица в Kotlin-литералах может повредиться при сборке. Используйте \\uXXXX:\n"
        . implode("\n", $violations)
    );
}

echo "Kotlin string literals are encoding-safe\n";
