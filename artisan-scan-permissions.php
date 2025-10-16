<?php

$directory = __DIR__; // Laravel project root
$patterns = [
    "/->can\(['\"](.*?)['\"]\)/",                   // $user->can('...')
    "/Gate::allows\(['\"](.*?)['\"]\)/",            // Gate::allows('...')
    "/Gate::denies\(['\"](.*?)['\"]\)/",            // Gate::denies('...')
    "/Gate::check\(['\"](.*?)['\"]\)/",             // Gate::check('...')
    "/@can\(['\"](.*?)['\"]\)/",                    // Blade: @can('...')
    "/@cannot\(['\"](.*?)['\"]\)/",                 // Blade: @cannot('...')
    "/@canany\(\[(.*?)\]\)/",                       // Blade: @canany(['a', 'b'])
    "/Permission::check\(['\"](.*?)['\"]\)/",       // Custom helper
    "/->middleware\(['\"]permission:([^'\"]+)['\"]\)/", // Middleware: permission:perm1|perm2
];

$permissions = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

foreach ($rii as $file) {
    if (!$file->isFile()) continue;

    $filePath = $file->getPathname();
    if (!preg_match('/\.(php|blade\.php)$/', $filePath)) continue;

    $contents = file_get_contents($filePath);

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $contents, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                // Handle multiple permissions like "a|b" or ['a', 'b']
                $delimiters = ['|', ','];
                foreach ($delimiters as $delimiter) {
                    if (str_contains($match, $delimiter)) {
                        $sub = explode($delimiter, $match);
                        foreach ($sub as $s) {
                            $permissions[] = trim(trim($s), "'\" ");
                        }
                        continue 2; // go to next match
                    }
                }

                $permissions[] = trim($match, "'\" ");
            }
        }
    }
}

$uniquePermissions = array_unique($permissions);
sort($uniquePermissions);

// Output
echo "🔐 Permissions found in codebase (" . count($uniquePermissions) . "):\n\n";
foreach ($uniquePermissions as $perm) {
    echo "- $perm\n";
}

echo "\nDone.\n";
