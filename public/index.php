<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/FileManager.php';
require_once dirname(__DIR__) . '/app/PublicBrowser.php';
require_once dirname(__DIR__) . '/app/ThumbnailManager.php';
require_once dirname(__DIR__) . '/app/View.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$auth = new Auth($config);
$csrf = new Csrf();
$pathGuard = new PathGuard($config->requireString('storage_root'));
$fileManager = new FileManager($pathGuard);
$browser = new PublicBrowser($fileManager, $pathGuard, $config);
$fileResponder = new FileResponder($config, $pathGuard);
$thumbnailManager = new ThumbnailManager($pathGuard);
$showNav = $auth->check();
$appName = (string) $config->get('app_name', 'BrowseBox');
$viewCookieName = 'browsebox_public_view';
$navActionHtml = '';

if ($showNav) {
    $navActionHtml = '<form method="post" action="./.mgmt" class="browsebox-nav-form">'
        . '<input type="hidden" name="action" value="logout">'
        . $csrf->input()
        . '<button class="nav-link" type="submit">Logout</button>'
        . '</form>';
}

$requestedPath = $_GET['path'] ?? '';
$requestedViewMode = (string) ($_COOKIE[$viewCookieName] ?? 'list');
$viewMode = in_array($requestedViewMode, ['list', 'icons'], true) ? $requestedViewMode : 'list';

try {
    $normalizedPath = $pathGuard->normalizeRelativePath((string) $requestedPath);

    if ($normalizedPath !== '' && $fileManager->exists($normalizedPath)) {
        $resolvedPath = $pathGuard->resolve($normalizedPath, true);

        if (is_file($resolvedPath)) {
            $fileResponder->serve($normalizedPath);
        }

        $indexFile = $browser->directoryIndexFile($normalizedPath);

        if ($indexFile !== null) {
            $fileResponder->serve($indexFile);
        }
    }

    $result = $browser->browse((string) $requestedPath);
} catch (RuntimeException $exception) {
    http_response_code(str_contains($exception->getMessage(), 'exist') ? 404 : 400);
    View::renderPage(
        'BrowseBox',
        '<div class="alert alert-danger mb-0">' . View::h($exception->getMessage()) . '</div>',
        'public',
        $showNav,
        $navActionHtml
    );
    exit;
}

$currentPath = $result['current_path'];
$prefix = View::relativePrefix($currentPath);
$assetPrefix = $prefix . 'assets';
$fileHandlerPrefix = $prefix . 'file.php';
$rows = '';
$iconCards = '';

$imagePreviewUrl = static function (array $item) use ($thumbnailManager, $fileHandlerPrefix): ?string {
    if (($item['type'] ?? '') !== 'file' || ($item['icon'] ?? '') !== 'image') {
        return null;
    }

    $relativePath = (string) ($item['relative_path'] ?? '');
    $extension = strtolower(pathinfo((string) ($item['name'] ?? ''), PATHINFO_EXTENSION));

    if (in_array($extension, ['svg'], true)) {
        return $fileHandlerPrefix . '?path=' . rawurlencode($relativePath);
    }

    if ($thumbnailManager->canGenerateFor($relativePath)) {
        return $fileHandlerPrefix . '?path=' . rawurlencode($relativePath) . '&thumbnail=1';
    }

    return null;
};

foreach ($result['items'] as $item) {
    $isDir = $item['type'] === 'dir';
    $href = $isDir
        ? View::publicFolderHref($currentPath, $item['name'])
        : rawurlencode($item['name']);
    $linkAttributes = $isDir ? '' : ' target="_blank" rel="noopener"';
    $iconAsset = $assetPrefix . '/file-icons/' . View::publicIconAsset((string) $item['icon'], (string) $item['name']);
    $previewUrl = $imagePreviewUrl($item);
    $hasEntrypoint = $isDir && (bool) ($item['has_entrypoint'] ?? false);
    $sizeLabel = View::formatSize(is_int($item['size']) ? $item['size'] : null);
    $modifiedLabel = View::formatDate(is_int($item['modified']) ? $item['modified'] : null);
    $metaLabel = $isDir ? 'Folder' : $sizeLabel;
    $folderHintHtml = $hasEntrypoint ? '<span class="browsebox-entrypoint-badge">Web App</span>' : '';
    $overlayHtml = $hasEntrypoint ? '<span class="browsebox-entrypoint-overlay">Web App</span>' : '';
    $previewHtml = $previewUrl !== null
        ? '<img class="browsebox-file-preview" src="' . View::h($previewUrl) . '" alt="">'
        : '<img class="browsebox-file-icon" src="' . View::h($iconAsset) . '" alt="">';

    $rows .= '<tr>'
        . '<td><a class="text-decoration-none fw-semibold browsebox-public-row-link" href="' . View::h($href) . '"' . $linkAttributes . '>'
        . '<img class="browsebox-inline-file-icon" src="' . View::h($iconAsset) . '" alt=""> '
        . View::h($item['name'])
        . ($isDir ? '/' : '')
        . $folderHintHtml
        . '</a></td>'
        . '<td data-label="Size">' . View::h($sizeLabel) . '</td>'
        . '<td data-label="Modified">' . View::h($modifiedLabel) . '</td>'
        . '</tr>';

    $iconCards .= '
    <div class="browsebox-public-icon-cell">
        <a class="browsebox-icon-card text-decoration-none" href="' . View::h($href) . '"' . $linkAttributes . '>
            <div class="browsebox-icon-card-preview">
                ' . $overlayHtml . '
                ' . $previewHtml . '
            </div>
            <div class="browsebox-icon-card-body">
                <div class="browsebox-icon-card-name">' . View::h($item['name']) . ($isDir ? '/' : '') . '</div>
                <div class="browsebox-icon-card-meta">' . View::h($metaLabel) . '</div>
                <div class="browsebox-icon-card-meta">' . View::h($modifiedLabel) . '</div>
            </div>
        </a>
    </div>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="3" class="text-secondary">This folder is empty.</td></tr>';
    $iconCards = '<div class="text-secondary">This folder is empty.</div>';
}

$breadcrumbsHtml = '';

foreach ($result['breadcrumbs'] as $index => $crumb) {
    $active = $index === array_key_last($result['breadcrumbs']);

    if ($active) {
        $breadcrumbsHtml .= '<li class="breadcrumb-item active" aria-current="page">' . View::h($crumb['label']) . '</li>';
        continue;
    }

    $breadcrumbsHtml .= '<li class="breadcrumb-item"><a href="'
        . View::h(View::publicBreadcrumbHref($currentPath, $crumb['path']))
        . '">'
        . View::h($crumb['label'])
        . '</a></li>';
}

$listButtonClass = $viewMode === 'list' ? 'btn-primary' : 'btn-outline-secondary';
$iconButtonClass = $viewMode === 'icons' ? 'btn-primary' : 'btn-outline-secondary';
$listViewClass = $viewMode === 'list' ? '' : ' d-none';
$iconViewClass = $viewMode === 'icons' ? '' : ' d-none';

$body = '
<div class="card shadow-sm border-0 browsebox-public-browser">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <nav aria-label="breadcrumb" class="mb-0 browsebox-public-breadcrumb-wrap">
                <div class="browsebox-public-breadcrumb-label">Location</div>
                <ol class="breadcrumb mb-0 browsebox-public-breadcrumb">' . $breadcrumbsHtml . '</ol>
            </nav>
            <div class="btn-group browsebox-view-toggle" role="group" aria-label="Public view mode">
                <button class="btn btn-sm ' . View::h($listButtonClass) . '" type="button" data-public-view-toggle="list">List View</button>
                <button class="btn btn-sm ' . View::h($iconButtonClass) . '" type="button" data-public-view-toggle="icons">Icon View</button>
            </div>
        </div>
        <div class="table-responsive browsebox-public-list' . $listViewClass . '">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Modified</th>
                    </tr>
                </thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>
        <div class="browsebox-public-icons' . $iconViewClass . '">
            <div class="browsebox-public-icon-grid">' . $iconCards . '</div>
        </div>
    </div>
</div>';

View::renderPage($appName, $body, 'public', $showNav, $navActionHtml);
