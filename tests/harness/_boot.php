<?php
/**
 * Boots the shared plugin-testing harness with *this checkout's* Headcount classes.
 *
 * The harness only has Headcount as a module mounted by the Showtime bundle, which vendors
 * its own copy under the same namespace. Prepending this repo's classes to Composer's class
 * map makes Showtime mount the code under test instead — in this process only; the harness
 * itself is untouched.
 *
 * Run from the harness web root (`/var/www/html`), e.g.
 *     /tmp/hxl.sh php /var/www/craft-headcount/tests/harness/graphql.php
 */

$headcountSrc = dirname(__DIR__, 2) . '/src/';

$loader = require getcwd() . '/vendor/autoload.php';
$map = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($headcountSrc, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_contains($path, '/templates/') || str_contains($path, '/translations/')) {
        continue;
    }
    $map['justinholtweb\\headcount\\' . str_replace('/', '\\', substr($path, strlen($headcountSrc), -4))] = $path;
}
$loader->addClassMap($map);
$loader->addPsr4('justinholtweb\\headcount\\', $headcountSrc, true);

require getcwd() . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

Craft::$app->getPlugins()->loadPlugins();

if (!str_starts_with((new ReflectionClass(justinholtweb\headcount\Headcount::class))->getFileName(), $headcountSrc)) {
    fwrite(STDERR, "Headcount was not loaded from $headcountSrc\n");
    exit(2);
}

if (justinholtweb\headcount\Headcount::getInstance() === null) {
    fwrite(STDERR, "Headcount is not mounted in the harness (is Showtime installed?)\n");
    exit(2);
}
