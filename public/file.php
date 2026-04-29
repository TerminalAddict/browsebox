<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$pathGuard = new PathGuard($config->requireString('storage_root'));
$requestedPath = (string) ($_GET['path'] ?? '');
$fileResponder = new FileResponder($config, $pathGuard);

try {
    $fileResponder->serve($requestedPath);
} catch (RuntimeException) {
    http_response_code(404);
    exit('Not found');
}
