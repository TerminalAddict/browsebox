<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/ThumbnailManager.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$pathGuard = new PathGuard($config->requireString('storage_root'));
$requestedPath = (string) ($_GET['path'] ?? '');
$thumbnailRequested = isset($_GET['thumbnail']) && $_GET['thumbnail'] === '1';
$zipRequested = isset($_GET['download']) && $_GET['download'] === 'zip';
$fileResponder = new FileResponder($config, $pathGuard);
$thumbnailManager = new ThumbnailManager($pathGuard);

try {
    if ($thumbnailRequested) {
        $thumbnailManager->serve($requestedPath);
    }

    if ($zipRequested) {
        $fileResponder->serveDirectoryArchive($requestedPath);
    }

    $fileResponder->serve($requestedPath);
} catch (RuntimeException $exception) {
    $notFound = in_array($exception->getMessage(), ['Not found', 'Path does not exist.'], true);
    http_response_code($notFound ? 404 : 500);
    exit($notFound ? 'Not found' : 'Unable to serve resource');
}
