<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/FileResponder.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/FileManager.php';
require_once dirname(__DIR__) . '/app/PublicBrowser.php';
require_once dirname(__DIR__) . '/app/View.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$auth = new Auth($config);
$pathGuard = new PathGuard($config->requireString('storage_root'));
$fileManager = new FileManager($pathGuard);
$browser = new PublicBrowser($fileManager, $pathGuard, $config);
$fileResponder = new FileResponder($config, $pathGuard);
$showNav = $auth->check();

$requestedPath = $_GET['path'] ?? '';

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
        $showNav
    );
    exit;
}

$currentPath = $result['current_path'];
$prefix = View::relativePrefix($currentPath);
$rows = '';

foreach ($result['items'] as $item) {
    $isDir = $item['type'] === 'dir';
    $href = $isDir
        ? View::publicFolderHref($currentPath, $item['name'])
        : rawurlencode($item['name']);

    $rows .= '<tr>'
        . '<td><a class="text-decoration-none fw-semibold" href="' . View::h($href) . '">'
        . View::h(View::icon($item['icon'])) . ' '
        . View::h($item['name'])
        . ($isDir ? '/' : '')
        . '</a></td>'
        . '<td data-label="Size">' . View::h(View::formatSize(is_int($item['size']) ? $item['size'] : null)) . '</td>'
        . '<td data-label="Modified">' . View::h(View::formatDate(is_int($item['modified']) ? $item['modified'] : null)) . '</td>'
        . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="3" class="text-secondary">This folder is empty.</td></tr>';
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

$body = '
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0">' . $breadcrumbsHtml . '</ol>
        </nav>
        <div class="table-responsive browsebox-public-list">
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
    </div>
</div>';

View::renderPage('BrowseBox', $body, 'public', $showNav);
