<?php

declare(strict_types=1);

final class View
{
    public static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function renderPage(string $title, string $body, string $active = 'public', bool $showNav = false, string $navActionHtml = ''): void
    {
        $titleEscaped = self::h($title);
        $publicClass = $active === 'public' ? 'active' : '';
        $mgmtClass = $active === 'mgmt' ? 'active' : '';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $basePath = rtrim($scriptDir === '/' ? '' : $scriptDir, '/');
        $assetHref = $basePath . '/assets';
        $faviconHref = $assetHref . '/favicon';
        $publicHref = $basePath . '/';
        $mgmtHref = $basePath . '/.mgmt';
        $logoHref = $assetHref . '/ta-bearded.png';
        $navHtml = '';

        if ($showNav) {
            $navActionHtml = $navActionHtml === '' ? '' : $navActionHtml;
            $navHtml = <<<HTML
            <nav class="nav nav-pills browsebox-nav">
                <a class="nav-link {$publicClass}" href="{$publicHref}">Public</a>
                <a class="nav-link {$mgmtClass}" href="{$mgmtHref}">Management</a>
                {$navActionHtml}
            </nav>
HTML;
        }

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$titleEscaped}</title>
    <link rel="apple-touch-icon" sizes="57x57" href="{$faviconHref}/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="{$faviconHref}/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="{$faviconHref}/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="{$faviconHref}/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="{$faviconHref}/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="{$faviconHref}/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="{$faviconHref}/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="{$faviconHref}/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="{$faviconHref}/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="{$faviconHref}/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="{$faviconHref}/android-icon-512x512.png">
    <link rel="icon" type="image/png" sizes="32x32" href="{$faviconHref}/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="{$faviconHref}/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="{$faviconHref}/favicon-16x16.png">
    <link rel="shortcut icon" href="{$basePath}/favicon.ico">
    <link rel="manifest" href="{$assetHref}/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="{$faviconHref}/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{$assetHref}/app.css" rel="stylesheet">
</head>
<body class="browsebox-body">
    <div class="container py-4 py-lg-5">
        <header class="browsebox-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4 mb-4">
            <div class="browsebox-brand d-flex align-items-center gap-3 gap-lg-4">
                <div class="browsebox-logo-wrap">
                    <img class="browsebox-logo" src="{$logoHref}" alt="BrowseBox logo">
                </div>
                <div>
                    <span class="browsebox-kicker">Public Files, Shared Simply</span>
                    <h1 class="h3 mb-1">BrowseBox</h1>
                    <p class="text-secondary mb-0">Public file share and browser - by Paul Willard</p>
                </div>
            </div>
            {$navHtml}
        </header>
        {$body}
    </div>
    <script src="{$assetHref}/app.js"></script>
</body>
</html>
HTML;
    }

    public static function breadcrumbs(string $relativePath): array
    {
        $segments = $relativePath === '' ? [] : explode('/', $relativePath);
        $breadcrumbs = [
            ['label' => 'Home', 'path' => ''],
        ];

        $current = [];

        foreach ($segments as $segment) {
            $current[] = $segment;
            $breadcrumbs[] = [
                'label' => $segment,
                'path' => implode('/', $current),
            ];
        }

        return $breadcrumbs;
    }

    public static function formatSize(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Folder';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%.0f %s' : '%.1f %s', $size, $units[$unit]);
    }

    public static function formatDate(?int $timestamp): string
    {
        return $timestamp ? date('Y-m-d H:i', $timestamp) : '-';
    }

    public static function relativePrefix(string $relativePath): string
    {
        $depth = $relativePath === '' ? 0 : count(explode('/', $relativePath));

        return $depth === 0 ? '' : str_repeat('../', $depth);
    }

    public static function publicFolderHref(string $currentRelativePath, string $name): string
    {
        return rawurlencode($name) . '/';
    }

    public static function publicBreadcrumbHref(string $currentRelativePath, string $targetPath): string
    {
        $currentDepth = $currentRelativePath === '' ? 0 : count(explode('/', $currentRelativePath));
        $targetDepth = $targetPath === '' ? 0 : count(explode('/', $targetPath));
        $up = $currentDepth - $targetDepth;

        if ($targetPath === $currentRelativePath) {
            return './';
        }

        return $up <= 0 ? './' : str_repeat('../', $up);
    }

    public static function publicRelativeHref(string $currentRelativePath, string $targetPath, bool $isDirectory): string
    {
        $currentSegments = $currentRelativePath === '' ? [] : explode('/', $currentRelativePath);
        $targetSegments = $targetPath === '' ? [] : explode('/', $targetPath);
        $shared = 0;
        $maxShared = min(count($currentSegments), count($targetSegments));

        while ($shared < $maxShared && $currentSegments[$shared] === $targetSegments[$shared]) {
            $shared++;
        }

        $up = count($currentSegments) - $shared;
        $remaining = array_slice($targetSegments, $shared);
        $prefix = $up <= 0 ? '' : str_repeat('../', $up);
        $encoded = implode('/', array_map('rawurlencode', $remaining));
        $href = $prefix . $encoded;

        if ($href === '') {
            return './';
        }

        return $isDirectory ? $href . '/' : $href;
    }

    public static function icon(string $type): string
    {
        return match ($type) {
            'folder' => '📁',
            'archive' => '🗜️',
            'image' => '🖼️',
            'html' => '🌐',
            'pdf' => '📄',
            'audio' => '🎵',
            'video' => '🎬',
            default => '📄',
        };
    }

    public static function publicIconAsset(string $type, string $filename = ''): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            $type === 'folder' => 'folder.svg',
            in_array($extension, ['xls', 'xlsx', 'ods', 'csv'], true) => 'spreadsheet.svg',
            in_array($extension, ['doc', 'docx', 'odt', 'rtf'], true) => 'document.svg',
            in_array($extension, ['ppt', 'pptx', 'odp'], true) => 'slides.svg',
            in_array($extension, ['txt', 'md'], true) => 'text.svg',
            in_array($extension, ['php', 'phtml', 'js', 'mjs', 'ts', 'tsx', 'jsx', 'css', 'scss', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'log', 'sh', 'bat', 'ps1', 'py', 'rb', 'pl', 'java', 'c', 'cpp', 'h', 'hpp', 'sql'], true) => 'code.svg',
            $type === 'archive' => 'archive.svg',
            $type === 'html' => 'html.svg',
            $type === 'pdf' => 'pdf.svg',
            $type === 'audio' => 'audio.svg',
            $type === 'video' => 'video.svg',
            $type === 'image' => 'image.svg',
            default => 'file.svg',
        };
    }
}
