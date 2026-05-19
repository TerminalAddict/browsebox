<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/FolderPasswordManager.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/ThumbnailManager.php';
require_once dirname(__DIR__) . '/app/View.php';

function browsebox_render_file_unlock_prompt(
    string $requestedPath,
    bool $thumbnailRequested,
    bool $zipRequested,
    bool $forceDownloadRequested,
    string $protectedRoot,
    Csrf $csrf,
    ?string $message = null
): never {
    $folderLabel = $protectedRoot === '' ? '/' : '/' . $protectedRoot;
    $body = ($message !== null ? '<div class="alert alert-danger">' . View::h($message) . '</div>' : '')
        . '<div class="row justify-content-center">'
        . '<div class="col-md-7 col-lg-6">'
        . '<div class="card shadow-sm border-0">'
        . '<div class="card-body p-4">'
        . '<h2 class="h4 mb-2">Password Protected Folder</h2>'
        . '<p class="text-secondary mb-4">Enter the folder password to continue into <strong>' . View::h($folderLabel) . '</strong>.</p>'
        . '<form method="post">'
        . '<input type="hidden" name="action" value="unlock_folder">'
        . '<input type="hidden" name="requested_path" value="' . View::h($requestedPath) . '">'
        . '<input type="hidden" name="thumbnail" value="' . ($thumbnailRequested ? '1' : '0') . '">'
        . '<input type="hidden" name="download_zip" value="' . ($zipRequested ? '1' : '0') . '">'
        . '<input type="hidden" name="force_download" value="' . ($forceDownloadRequested ? '1' : '0') . '">'
        . $csrf->input()
        . '<div class="mb-3">'
        . '<label class="form-label" for="folder_password">Folder Password</label>'
        . '<input class="form-control" id="folder_password" name="folder_password" type="password" autocomplete="current-password" required autofocus>'
        . '</div>'
        . '<div class="d-flex flex-wrap gap-2">'
        . '<button class="btn btn-primary" type="submit">Continue</button>'
        . '<a class="btn btn-outline-secondary" href="./">Back to Home</a>'
        . '</div>'
        . '</form>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>';

    View::renderPage('BrowseBox', $body, 'public');
    exit;
}

$config = new Config(dirname(__DIR__) . '/config/config.php');
$auth = new Auth($config);
$csrf = new Csrf();
$pathGuard = new PathGuard($config->requireString('storage_root'));
$folderPasswordManager = new FolderPasswordManager($pathGuard);
$requestedPath = (string) ($_GET['path'] ?? '');
$thumbnailRequested = isset($_GET['thumbnail']) && $_GET['thumbnail'] === '1';
$zipRequested = isset($_GET['download']) && $_GET['download'] === 'zip';
$forceDownloadRequested = isset($_GET['download']) && $_GET['download'] === '1';
$fileResponder = new FileResponder($config, $pathGuard);
$thumbnailManager = new ThumbnailManager($pathGuard);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'unlock_folder') {
    try {
        $csrf->requireValid($_POST['csrf_token'] ?? null);
        $requestedPath = $pathGuard->normalizeRelativePath((string) ($_POST['requested_path'] ?? ''));
        $thumbnailRequested = (string) ($_POST['thumbnail'] ?? '0') === '1';
        $zipRequested = (string) ($_POST['download_zip'] ?? '0') === '1';
        $forceDownloadRequested = (string) ($_POST['force_download'] ?? '0') === '1';
        $protectedRoot = $folderPasswordManager->protectedRootFor($requestedPath);

        if ($protectedRoot === null) {
            $query = ['path' => $requestedPath];

            if ($thumbnailRequested) {
                $query['thumbnail'] = '1';
            } elseif ($zipRequested) {
                $query['download'] = 'zip';
            } elseif ($forceDownloadRequested) {
                $query['download'] = '1';
            }

            header('Location: file.php?' . http_build_query($query), true, 303);
            exit;
        }

        if (!$folderPasswordManager->unlock($requestedPath, (string) ($_POST['folder_password'] ?? ''))) {
            browsebox_render_file_unlock_prompt($requestedPath, $thumbnailRequested, $zipRequested, $forceDownloadRequested, $protectedRoot, $csrf, 'Incorrect folder password.');
        }

        $query = ['path' => $requestedPath];

        if ($thumbnailRequested) {
            $query['thumbnail'] = '1';
        } elseif ($zipRequested) {
            $query['download'] = 'zip';
        } elseif ($forceDownloadRequested) {
            $query['download'] = '1';
        }

        header('Location: file.php?' . http_build_query($query), true, 303);
        exit;
    } catch (RuntimeException $exception) {
        try {
            $requestedPath = $pathGuard->normalizeRelativePath((string) ($_POST['requested_path'] ?? ''));
        } catch (RuntimeException) {
            $requestedPath = '';
        }

        $protectedRoot = $folderPasswordManager->protectedRootFor($requestedPath) ?? $requestedPath;
        browsebox_render_file_unlock_prompt(
            $requestedPath,
            ((string) ($_POST['thumbnail'] ?? '0') === '1'),
            ((string) ($_POST['download_zip'] ?? '0') === '1'),
            ((string) ($_POST['force_download'] ?? '0') === '1'),
            $protectedRoot,
            $csrf,
            $exception->getMessage()
        );
    }
}

try {
    $normalizedPath = $pathGuard->normalizeRelativePath($requestedPath);
    $protectedRoot = $folderPasswordManager->protectedRootFor($normalizedPath);
    $trustedHtml = $protectedRoot !== null && $folderPasswordManager->isAccessGranted($normalizedPath, $auth->check());

    if (!$folderPasswordManager->isAccessGranted($normalizedPath, $auth->check())) {
        if ($protectedRoot !== null) {
            if ($thumbnailRequested) {
                http_response_code(403);
                exit('Protected resource');
            }

            browsebox_render_file_unlock_prompt($normalizedPath, $thumbnailRequested, $zipRequested, $forceDownloadRequested, $protectedRoot, $csrf);
        }
    }

    if ($thumbnailRequested) {
        $thumbnailManager->serve($normalizedPath);
    }

    if ($zipRequested) {
        $fileResponder->serveDirectoryArchive($normalizedPath);
    }

    $fileResponder->serve($normalizedPath, $forceDownloadRequested, $auth->check(), $trustedHtml);
} catch (RuntimeException $exception) {
    $notFound = in_array($exception->getMessage(), ['Not found', 'Path does not exist.'], true);
    http_response_code($notFound ? 404 : 500);
    exit($notFound ? 'Not found' : 'Unable to serve resource');
}
