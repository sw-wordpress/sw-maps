<?php

/**
 * Checks an unpacked release tree without any build tool: every PHP file parses, the plugin header is complete, the
 * autoloader resolves the plugin's classes and the shipped assets exist. Usage: php check-package.php <plugin-dir>
 */

declare(strict_types=1);

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: check-package.php <plugin-dir>\n");
    exit(2);
}
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$main = (string) file_get_contents($dir . '/sw-maps.php');
foreach (['Plugin Name:', 'Version:', 'Requires at least:', 'Requires PHP:', 'License:', 'Text Domain:       sw-maps'] as $needle) {
    if (!str_contains($main, $needle)) {
        $fail("plugin header lacks {$needle}");
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$count = 0;
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file->getPathname())), $out, $code);
        if ($code !== 0) {
            $fail('syntax error in ' . $file->getPathname());
        }
        ++$count;
    }
}

$loader = require $dir . '/vendor/autoload.php';
$loader->addPsr4('SWMaps\\Core\\', $dir . '/packages/core/src/');
$loader->addPsr4('SWMaps\\WordPress\\', $dir . '/packages/wordpress/src/');
foreach (['SWMaps\\Core\\Domain\\Geometry', 'SWMaps\\Core\\Application\\MapService', 'SWMaps\\WordPress\\Plugin', 'SWMaps\\WordPress\\Persistence\\Schema'] as $class) {
    if (!class_exists($class)) {
        $fail("autoload cannot resolve {$class}");
    }
}
foreach (['assets/admin/app.js', 'assets/frontend/swmaps-viewer.js', 'assets/frontend/swmaps-viewer.css'] as $asset) {
    if (!is_file($dir . '/' . $asset) || filesize($dir . '/' . $asset) < 1000) {
        $fail("asset {$asset} missing or empty");
    }
}
if (str_contains((string) file_get_contents($dir . '/assets/frontend/swmaps-viewer.js'), 'sourceMappingURL')) {
    $fail('the frontend bundle references a source map that is not shipped');
}
// WordPress loads app.js with a version query. A lazy chunk importing the entry without it remounts the app.
$manifest = json_decode((string) file_get_contents($dir . '/assets/admin/.vite/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['src/main.ts']['file'] ?? null) !== 'app.js') {
    $fail('the admin manifest is missing its entry');
}
foreach ($manifest as $chunk) {
    if (in_array('src/main.ts', $chunk['imports'] ?? [], true)) {
        $fail('a lazy chunk imports the versioned admin entry');
    }
}

foreach (['inter', 'jetbrains-mono'] as $font) {
    if (!is_file($dir . '/assets/admin/LICENSE-' . $font . '.txt')) {
        $fail("license for {$font} missing from the release");
    }
}
$adminCss = (string) file_get_contents($dir . '/assets/admin/app.css');
preg_match_all('~url\(\"?([^")]+\.woff2)\"?\)~', $adminCss, $fonts);
if (count(array_unique($fonts[1])) < 5) {
    $fail('the admin stylesheet is missing bundled fonts');
}
foreach ($fonts[1] as $font) {
    if (!str_starts_with($font, './assets/') || !is_file($dir . '/assets/admin/' . $font)) {
        $fail("font {$font} missing from the release");
    }
}

echo "package ok: {$count} PHP files, autoloader and assets verified\n";
