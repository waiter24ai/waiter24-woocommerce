<?php
/**
 * Builds the distributable plugin ZIP for WordPress.org.
 *
 * Run from anywhere:  php bin/build-zip.php
 * Output:             build/waiter24-ai-assistant-for-woocommerce-<version>.zip
 *
 * The archive contains exactly one top-level directory named after the plugin
 * slug — the layout WordPress expects from an uploaded ZIP.
 *
 * Two deliberate choices:
 *
 * 1. An allow-list of files, not an exclude-list, so a new development file
 *    added to the repo can never leak into a release by accident.
 * 2. PHP's ZipArchive rather than PowerShell's Compress-Archive or .NET's
 *    ZipFile::CreateFromDirectory. Both of those store Windows backslashes as
 *    the path separator, which violates the ZIP spec; unzipping such an archive
 *    on a Linux host produces flat files literally named
 *    "plugin-slug\file.php" and the plugin installs broken.
 *
 * @package Waiter24
 */

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$slug = 'waiter24-ai-assistant-for-woocommerce';
$root = dirname(__DIR__);

// Everything the plugin needs at runtime, and nothing else.
$files = [
    "{$slug}.php",
    'uninstall.php',
    'readme.txt',
    'LICENSE',
];

$mainFile = "{$root}/{$slug}.php";

if (!is_file($mainFile)) {
    fwrite(STDERR, "Main plugin file not found: {$mainFile}\n");
    exit(1);
}

// Read the version out of the plugin header, so the ZIP can never be named
// after a version the code does not declare.
$header = (string) file_get_contents($mainFile, false, null, 0, 2048);

if (!preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $header, $matches)) {
    fwrite(STDERR, "Could not read the Version header from the main plugin file.\n");
    exit(1);
}

$version = trim($matches[1]);

echo "Building {$slug} {$version}\n";

$buildDir = "{$root}/build";

if (!is_dir($buildDir) && !mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
    fwrite(STDERR, "Could not create {$buildDir}\n");
    exit(1);
}

$zipPath = "{$buildDir}/{$slug}-{$version}.zip";

if (is_file($zipPath)) {
    unlink($zipPath);
}

// Language files: compiled .mo is what WordPress loads, .po/.pot ship for
// translators.
$languages = [];

foreach (glob("{$root}/languages/*.{po,mo,pot}", GLOB_BRACE) ?: [] as $path) {
    $languages[] = 'languages/' . basename($path);
}

sort($languages);

$zip = new ZipArchive();

if (true !== $zip->open($zipPath, ZipArchive::CREATE)) {
    fwrite(STDERR, "Could not create {$zipPath}\n");
    exit(1);
}

foreach (array_merge($files, $languages) as $relative) {
    $source = "{$root}/{$relative}";

    if (!is_file($source)) {
        $zip->close();
        unlink($zipPath);
        fwrite(STDERR, "Required file missing: {$relative}\n");
        exit(1);
    }

    // Forward slashes only, per the ZIP spec.
    $zip->addFile($source, "{$slug}/{$relative}");
}

$zip->close();

printf("Created %s (%.1f KB)\n\n", $zipPath, filesize($zipPath) / 1024);

echo "Contents:\n";

$verify = new ZipArchive();
$verify->open($zipPath);

for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = $verify->getNameIndex($i);

    if (false !== strpos($name, '\\')) {
        $verify->close();
        fwrite(STDERR, "Backslash in archive path: {$name}\n");
        exit(1);
    }

    echo "  {$name}\n";
}

$verify->close();
