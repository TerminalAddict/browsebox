<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/FileManager.php';
require_once dirname(__DIR__) . '/app/PublicBrowser.php';
require_once dirname(__DIR__) . '/app/SearchIndex.php';
require_once dirname(__DIR__) . '/app/ThumbnailManager.php';
require_once dirname(__DIR__) . '/app/View.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$auth = new Auth($config);
$csrf = new Csrf();
$pathGuard = new PathGuard($config->requireString('storage_root'));
$fileManager = new FileManager($pathGuard);
$browser = new PublicBrowser($fileManager, $pathGuard, $config);
$fileResponder = new FileResponder($config, $pathGuard);
$searchIndex = new SearchIndex($config, $pathGuard, $fileManager);
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
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$requestedViewMode = (string) ($_COOKIE[$viewCookieName] ?? 'list');
$viewMode = in_array($requestedViewMode, ['list', 'icons'], true) ? $requestedViewMode : 'list';

try {
    $normalizedPath = $pathGuard->normalizeRelativePath((string) $requestedPath);

    if ($searchQuery === '' && $normalizedPath !== '' && $fileManager->exists($normalizedPath)) {
        $resolvedPath = $pathGuard->resolve($normalizedPath, true);

        if (is_file($resolvedPath)) {
            $fileResponder->serve($normalizedPath);
        }

        $indexFile = $browser->directoryIndexFile($normalizedPath);

        if ($indexFile !== null) {
            $fileResponder->serve($indexFile);
        }
    }

    $result = $searchQuery === ''
        ? $browser->browse((string) $requestedPath)
        : [
            'current_path' => $normalizedPath,
            'items' => [],
            'breadcrumbs' => View::breadcrumbs($normalizedPath),
            'search' => $searchIndex->search($searchQuery),
        ];
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
$currentFolderZipHref = $currentPath !== ''
    ? $fileHandlerPrefix . '?path=' . rawurlencode($currentPath) . '&download=zip'
    : '';
$searchActionHref = './';
$searchClearHref = $currentPath === '' ? './' : './';
$rows = '';
$iconCards = '';
$searchResultsHtml = '';
$searchMetaHtml = '';

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
    $folderZipHref = $isDir ? $fileHandlerPrefix . '?path=' . rawurlencode((string) $item['relative_path']) . '&download=zip' : '';
    $linkAttributes = $isDir ? '' : ' target="_blank" rel="noopener"';
    $iconAsset = $assetPrefix . '/file-icons/' . View::publicIconAsset((string) $item['icon'], (string) $item['name']);
    $archiveIconAsset = $assetPrefix . '/file-icons/archive.svg';
    $previewUrl = $imagePreviewUrl($item);
    $hasEntrypoint = $isDir && (bool) ($item['has_entrypoint'] ?? false);
    $sizeLabel = View::formatSize(is_int($item['size']) ? $item['size'] : null);
    $modifiedLabel = View::formatDate(is_int($item['modified']) ? $item['modified'] : null);
    $metaLabel = $isDir ? 'Folder' : $sizeLabel;
    $folderHintHtml = $hasEntrypoint
        ? '<span class="browsebox-entrypoint-actions">'
            . '<span class="browsebox-entrypoint-badge">Web App</span>'
            . '<a class="browsebox-entrypoint-zip-icon" href="' . View::h($folderZipHref) . '" title="Download this web app folder as a ZIP archive" aria-label="Download this web app folder as a ZIP archive">'
                . '<img src="' . View::h($archiveIconAsset) . '" alt="">'
            . '</a>'
        . '</span>'
        : '';
    $overlayHtml = $hasEntrypoint ? '<span class="browsebox-entrypoint-overlay">Web App</span>' : '';
    $previewHtml = $previewUrl !== null
        ? '<img class="browsebox-file-preview" src="' . View::h($previewUrl) . '" alt="">'
        : '<img class="browsebox-file-icon" src="' . View::h($iconAsset) . '" alt="">';

    $rows .= '<tr>'
        . '<td><a class="text-decoration-none fw-semibold browsebox-public-row-link" href="' . View::h($href) . '"' . $linkAttributes . '>'
        . '<img class="browsebox-inline-file-icon" src="' . View::h($iconAsset) . '" alt=""> '
        . View::h($item['name'])
        . ($isDir ? '/' : '')
        . '</a>'
        . ($folderHintHtml !== '' ? '<div class="browsebox-public-row-flags">' . $folderHintHtml . '</div>' : '')
        . '</td>'
        . '<td data-label="Size">' . View::h($sizeLabel) . '</td>'
        . '<td data-label="Modified">' . View::h($modifiedLabel) . '</td>'
        . '</tr>';

    if ($isDir) {
        $iconCards .= '
        <div class="browsebox-public-icon-cell">
            <div class="browsebox-icon-card browsebox-icon-card-shell">
                <a class="browsebox-icon-card-main text-decoration-none" href="' . View::h($href) . '">
                    <div class="browsebox-icon-card-preview">
                        ' . $overlayHtml . '
                        ' . $previewHtml . '
                    </div>
                    <div class="browsebox-icon-card-body">
                        <div class="browsebox-icon-card-name">' . View::h($item['name']) . '/</div>
                        <div class="browsebox-icon-card-meta">' . View::h($metaLabel) . '</div>
                        <div class="browsebox-icon-card-meta">' . View::h($modifiedLabel) . '</div>
                    </div>
                </a>
                ' . ($folderHintHtml !== '' ? '<div class="browsebox-icon-card-flags">' . $folderHintHtml . '</div>' : '') . '
            </div>
        </div>';
        continue;
    }

    $iconCards .= '
    <div class="browsebox-public-icon-cell">
        <a class="browsebox-icon-card text-decoration-none" href="' . View::h($href) . '"' . $linkAttributes . '>
            <div class="browsebox-icon-card-preview">
                ' . $overlayHtml . '
                ' . $previewHtml . '
            </div>
            <div class="browsebox-icon-card-body">
                <div class="browsebox-icon-card-name">' . View::h($item['name']) . '</div>
                <div class="browsebox-icon-card-meta">' . View::h($metaLabel) . '</div>
                <div class="browsebox-icon-card-meta">' . View::h($modifiedLabel) . '</div>
            </div>
        </a>
    </div>';
}

if ($searchQuery === '' && $rows === '') {
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
$currentFolderActionHtml = $currentPath !== ''
    ? '<a class="browsebox-current-folder-zip-link" href="' . View::h($currentFolderZipHref) . '">'
        . '<span class="browsebox-current-folder-zip-kicker">Folder Action</span>'
        . '<span class="browsebox-current-folder-zip-label">Download This Folder ZIP</span>'
    . '</a>'
    : '';
$searchFormHtml = '
<form class="browsebox-public-search mb-3" method="get" action="' . View::h($searchActionHref) . '" role="search">
    <div class="browsebox-public-search-box">
        <input class="form-control" type="search" name="q" value="' . View::h($searchQuery) . '" placeholder="Search filenames and readable file contents">
        <button class="btn btn-primary" type="submit">Search</button>'
        . ($searchQuery !== '' ? '<a class="btn btn-outline-secondary" href="' . View::h($searchClearHref) . '">Clear</a>' : '') . '
    </div>
    <div class="form-text">Filename search supports partial and close matches. Content search covers text, HTML, and indexed PDF text.</div>
</form>';

if ($searchQuery !== '') {
    $search = is_array($result['search'] ?? null) ? $result['search'] : ['results' => [], 'count' => 0, 'built_at' => null];
    $searchResults = is_array($search['results'] ?? null) ? $search['results'] : [];
    $builtAtLabel = is_string($search['built_at'] ?? null) && $search['built_at'] !== ''
        ? View::formatDate(strtotime((string) $search['built_at']) ?: null)
        : 'Not built yet';
    $searchMetaHtml = '<div class="browsebox-search-meta mb-3">'
        . '<div><strong>' . View::h((string) ($search['count'] ?? 0)) . '</strong> result(s) for <strong>' . View::h($searchQuery) . '</strong><div class="text-secondary small">Searching all public files and folders.</div></div>'
        . '<div class="text-secondary">Index updated: ' . View::h($builtAtLabel) . '</div>'
        . '</div>';

    if ($searchResults === []) {
        $searchResultsHtml = '<div class="alert alert-secondary mb-0">No matches found. If you recently added files outside BrowseBox, rebuild the search index in <code>/.mgmt</code>.</div>';
    } else {
        foreach ($searchResults as $searchResult) {
            $isDirectory = ($searchResult['type'] ?? '') === 'dir';
            $resultPath = (string) ($searchResult['path'] ?? '');
            $resultHref = View::publicRelativeHref($currentPath, $resultPath, $isDirectory);
            $resultFolder = $resultPath === '' || !str_contains($resultPath, '/') ? '' : (string) dirname($resultPath);
            $folderHref = View::publicRelativeHref($currentPath, $resultFolder, true);
            $resultIconAsset = $assetPrefix . '/file-icons/' . View::publicIconAsset((string) ($searchResult['icon'] ?? 'file'), (string) ($searchResult['name'] ?? ''));
            $pathLabel = $resultPath === '' ? '/' : '/' . $resultPath;
            $snippetHtml = ($searchResult['snippet'] ?? null) !== null
                ? '<div class="browsebox-search-snippet">' . View::h((string) $searchResult['snippet']) . '</div>'
                : '';
            $resultActionsHtml = $isDirectory
                ? '<a class="browsebox-search-open" href="' . View::h($resultHref) . '">Open folder</a>'
                : '<a class="browsebox-search-open" href="' . View::h($resultHref) . '" target="_blank" rel="noopener">Open file</a>'
                    . '<a class="browsebox-search-folder" href="' . View::h($folderHref) . '">Open folder</a>';

            $searchResultsHtml .= '<article class="browsebox-search-result">'
                . '<div class="browsebox-search-result-main">'
                . '<img class="browsebox-search-result-icon" src="' . View::h($resultIconAsset) . '" alt="">'
                . '<div class="browsebox-search-result-copy">'
                . '<div class="browsebox-search-result-title"><a href="' . View::h($resultHref) . '"' . ($isDirectory ? '' : ' target="_blank" rel="noopener"') . '>' . View::h((string) ($searchResult['name'] ?? '')) . ($isDirectory ? '/' : '') . '</a></div>'
                . '<div class="browsebox-search-result-path">' . View::h($pathLabel) . '</div>'
                . $snippetHtml
                . '</div>'
                . '</div>'
                . '<div class="browsebox-search-result-side">'
                . '<div class="browsebox-search-result-meta">' . View::h(View::formatSize(is_int($searchResult['size'] ?? null) ? $searchResult['size'] : null)) . '</div>'
                . '<div class="browsebox-search-result-meta">' . View::h(View::formatDate(is_int($searchResult['modified'] ?? null) ? $searchResult['modified'] : null)) . '</div>'
                . '<div class="browsebox-search-result-actions">' . $resultActionsHtml . '</div>'
                . '</div>'
                . '</article>';
        }
    }
}

$body = '
<div class="card shadow-sm border-0 browsebox-public-browser">
    <div class="card-body p-4">
        ' . $searchFormHtml . '
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <nav aria-label="breadcrumb" class="mb-0 browsebox-public-breadcrumb-wrap">
                <div class="browsebox-public-breadcrumb-label">Location</div>
                <ol class="breadcrumb mb-0 browsebox-public-breadcrumb">' . $breadcrumbsHtml . '</ol>
            </nav>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                ' . ($searchQuery === '' ? $currentFolderActionHtml : '') . '
                <div class="btn-group browsebox-view-toggle" role="group" aria-label="Public view mode">
                    <button class="btn btn-sm ' . View::h($listButtonClass) . '" type="button" data-public-view-toggle="list">List View</button>
                    <button class="btn btn-sm ' . View::h($iconButtonClass) . '" type="button" data-public-view-toggle="icons">Icon View</button>
                </div>
            </div>
        </div>
        ' . ($searchQuery !== '' ? $searchMetaHtml . '<div class="browsebox-search-results">' . $searchResultsHtml . '</div>' : '<div class="table-responsive browsebox-public-list' . $listViewClass . '">
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
        </div>') . '
    </div>
</div>';

View::renderPage($appName, $body, 'public', $showNav, $navActionHtml);
