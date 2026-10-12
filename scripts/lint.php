<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
foreach (['src', 'tests', 'examples', 'scripts'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        echo CmdStan\Process::run([PHP_BINARY, '-l', $file->getPathname()])['stdout'];
    }
}
