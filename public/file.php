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
$fileResponder = new FileResponder($config, $pathGuard);
$thumbnailManager = new ThumbnailManager($pathGuard);

try {
    if ($thumbnailRequested) {
        $thumbnailManager->serve($requestedPath);
    }

    $fileResponder->serve($requestedPath);
} catch (RuntimeException) {
    http_response_code(404);
    exit('Not found');
}
