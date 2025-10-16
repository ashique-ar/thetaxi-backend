<?php

$targetDir = __DIR__ . '/app/Services'; // Your target directory
$outputFile = __DIR__ . '/all_services_combined.txt'; // Output file

if (!is_dir($targetDir)) {
    exit("❌ Directory not found: $targetDir\n");
}

file_put_contents($outputFile, ""); // Reset file

function getPhpFiles($dir): array
{
    $files = [];
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $files = array_merge($files, getPhpFiles($path));
        } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $files[] = $path;
        }
    }
    return $files;
}

$phpFiles = getPhpFiles($targetDir);

foreach ($phpFiles as $file) {
    $relativePath = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file);
    $code = file_get_contents($file);

    file_put_contents($outputFile, "\n\n// ------------------------------\n", FILE_APPEND);
    file_put_contents($outputFile, "// File: $relativePath\n", FILE_APPEND);
    file_put_contents($outputFile, "// ------------------------------\n\n", FILE_APPEND);
    file_put_contents($outputFile, $code, FILE_APPEND);
}

echo "✅ Combined " . count($phpFiles) . " files into: $outputFile\n";
