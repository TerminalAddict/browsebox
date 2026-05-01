<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
require_once dirname(__DIR__) . '/app/FileManager.php';
require_once dirname(__DIR__) . '/app/SearchIndex.php';
require_once dirname(__DIR__) . '/app/UploadManager.php';
require_once dirname(__DIR__) . '/app/View.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$pathGuard = new PathGuard($config->requireString('storage_root'));
$auth = new Auth($config);
$csrf = new Csrf();
$fileManager = new FileManager($pathGuard);
$searchIndex = new SearchIndex($config, $pathGuard, $fileManager);
$uploadManager = new UploadManager($pathGuard, $config);
$navActionHtml = '';

if ($auth->check()) {
    $navActionHtml = '<form method="post" action=".mgmt" class="browsebox-nav-form">'
        . '<input type="hidden" name="action" value="logout">'
        . $csrf->input()
        . '<button class="nav-link" type="submit">Logout</button>'
        . '</form>';
}

function browsebox_parse_extensions(string $raw): array
{
    $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
    $extensions = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        $part = ltrim($part, '.');

        if (!preg_match('/^[a-z0-9]+$/', $part)) {
            throw new RuntimeException('Extensions may only contain letters and numbers.');
        }

        $extensions[$part] = $part;
    }

    return array_values($extensions);
}

function browsebox_validate_upload_size(string $value): string
{
    $value = strtoupper(trim($value));

    if (!preg_match('/^\d+(K|M|G)?$/', $value)) {
        throw new RuntimeException('Max upload size must look like 2048M, 512M, or 2G.');
    }

    return $value;
}

function browsebox_size_to_bytes(string $value): int
{
    $value = strtoupper(trim($value));

    if (!preg_match('/^(\d+)(K|M|G)?$/', $value, $matches)) {
        throw new RuntimeException('Invalid size value.');
    }

    $bytes = (int) $matches[1];
    $unit = $matches[2] ?? '';

    return match ($unit) {
        'K' => $bytes * 1024,
        'M' => $bytes * 1024 * 1024,
        'G' => $bytes * 1024 * 1024 * 1024,
        default => $bytes,
    };
}

function browsebox_decode_relative_paths(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid dropped file metadata.');
    }

    $paths = [];

    foreach ($decoded as $value) {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Invalid dropped file metadata.');
        }

        $paths[] = $value;
    }

    return $paths;
}

function browsebox_log_action(Config $config, ?string $user, string $action, string $target, string $status): void
{
    $logDir = rtrim($config->requireString('data_root'), '/') . '/logs';
    $logFile = $logDir . '/actions.log';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $line = sprintf(
        "%s %s %s %s %s\n",
        date(DATE_ATOM),
        $user ?? '-',
        $action,
        $target === '' ? '/' : '/' . ltrim($target, '/'),
        $status
    );

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function browsebox_try_sync_search_index(callable $callback): void
{
    try {
        $callback();
    } catch (Throwable) {
    }
}

function browsebox_redirect(string $path, ?string $message = null, string $type = 'success'): never
{
    header('Location: ' . browsebox_management_url($path, $message, $type), true, 303);
    exit;
}

function browsebox_management_url(string $path, ?string $message = null, string $type = 'success'): string
{
    $query = [];

    if ($path !== '') {
        $query['path'] = $path;
    }

    if ($message !== null) {
        $query['message'] = $message;
        $query['type'] = $type;
    }

    $location = '.mgmt';

    if ($query !== []) {
        $location .= '?' . http_build_query($query);
    }

    return $location;
}

function browsebox_is_async_request(): bool
{
    return (isset($_SERVER['HTTP_X_BROWSEBOX_ASYNC']) && $_SERVER['HTTP_X_BROWSEBOX_ASYNC'] === '1')
        || (isset($_GET['async_upload']) && $_GET['async_upload'] === '1')
        || (isset($_POST['async_upload']) && $_POST['async_upload'] === '1');
}

function browsebox_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function browsebox_tree_branch_open(string $nodePath, string $currentPath): bool
{
    if ($nodePath === '') {
        return true;
    }

    return $currentPath === $nodePath || ($currentPath !== '' && str_starts_with($currentPath . '/', $nodePath . '/'));
}

function browsebox_context_item_attributes(
    string $relativePath,
    string $name,
    string $type,
    string $openUrl,
    string $downloadUrl = '',
    string $downloadZipUrl = '',
    string $scope = 'list',
): string {
    $parentPath = '';

    if ($relativePath !== '' && str_contains($relativePath, '/')) {
        $parentPath = (string) dirname($relativePath);
    }

    return ' data-context-row="1"'
        . ' data-context-scope="' . View::h($scope) . '"'
        . ' data-item-path="' . View::h($relativePath) . '"'
        . ' data-item-parent-path="' . View::h($parentPath) . '"'
        . ' data-item-name="' . View::h($name) . '"'
        . ' data-item-type="' . View::h($type) . '"'
        . ' data-item-open-url="' . View::h($openUrl) . '"'
        . ' data-item-download-url="' . View::h($downloadUrl) . '"'
        . ' data-item-download-zip-url="' . View::h($downloadZipUrl) . '"';
}

function browsebox_render_tree_nodes(array $nodes, string $currentPath): string
{
    if ($nodes === []) {
        return '';
    }

    $html = '<ul class="list-unstyled browsebox-tree-list mb-0">';

    foreach ($nodes as $node) {
        $relativePath = (string) ($node['relative_path'] ?? '');
        $name = (string) ($node['name'] ?? '');
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $href = '.mgmt?path=' . rawurlencode($relativePath);
        $rowClass = 'browsebox-tree-row browsebox-move-target' . ($relativePath === $currentPath ? ' is-current' : '');
        $label = View::h(View::icon('folder')) . ' ' . View::h($name);
        $contextAttributes = browsebox_context_item_attributes(
            $relativePath,
            $name,
            'dir',
            $href,
            '',
            'file.php?path=' . rawurlencode($relativePath) . '&download=zip',
            'tree'
        );

        if ($children === []) {
            $html .= '<li class="browsebox-tree-item">'
                . '<div class="' . $rowClass . '" data-move-target="' . View::h($relativePath) . '"' . $contextAttributes . '>'
                . '<span class="browsebox-tree-toggle-spacer" aria-hidden="true"></span>'
                . '<a class="browsebox-tree-link" href="' . View::h($href) . '">' . $label . '</a>'
                . '</div>'
                . '</li>';
            continue;
        }

        $isOpen = browsebox_tree_branch_open($relativePath, $currentPath);
        $openClass = $isOpen ? ' is-open' : '';
        $childId = 'browsebox-tree-' . substr(sha1($relativePath), 0, 12);

        $html .= '<li class="browsebox-tree-item">'
            . '<div class="' . $rowClass . $openClass . '" data-tree-item="' . View::h($relativePath) . '" data-move-target="' . View::h($relativePath) . '" data-move-expand="1"' . $contextAttributes . '>'
            . '<button class="browsebox-tree-summary" type="button" aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-controls="' . View::h($childId) . '" aria-label="Toggle ' . View::h($name) . '" data-tree-toggle>'
            . '<span class="browsebox-tree-toggle" aria-hidden="true"></span>'
            . '</button>'
            . '<a class="browsebox-tree-link" href="' . View::h($href) . '">' . $label . '</a>'
            . '</div>'
            . '<div class="browsebox-tree-children"' . ($isOpen ? '' : ' hidden') . ' id="' . View::h($childId) . '">'
            . browsebox_render_tree_nodes($children, $currentPath)
            . '</div>'
            . '</li>';
    }

    return $html . '</ul>';
}

function browsebox_render_destination_tree_nodes(array $nodes, string $currentPath): string
{
    if ($nodes === []) {
        return '';
    }

    $html = '<ul class="list-unstyled browsebox-tree-list mb-0">';

    foreach ($nodes as $node) {
        $relativePath = (string) ($node['relative_path'] ?? '');
        $name = (string) ($node['name'] ?? '');
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $isOpen = browsebox_tree_branch_open($relativePath, $currentPath);
        $label = View::h(View::icon('folder')) . ' ' . View::h($name);

        if ($children === []) {
            $html .= '<li class="browsebox-tree-item">'
                . '<div class="browsebox-tree-row">'
                . '<span class="browsebox-tree-toggle-spacer" aria-hidden="true"></span>'
                . '<button class="browsebox-tree-link browsebox-destination-option" type="button" data-destination-option="' . View::h($relativePath) . '" data-destination-label="/' . View::h($relativePath) . '">' . $label . '</button>'
                . '</div>'
                . '</li>';
            continue;
        }

        $childId = 'browsebox-destination-tree-' . substr(sha1($relativePath), 0, 12);

        $html .= '<li class="browsebox-tree-item">'
            . '<div class="browsebox-tree-row' . ($isOpen ? ' is-open' : '') . '" data-tree-item="' . View::h($relativePath) . '">'
            . '<button class="browsebox-tree-summary" type="button" aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-controls="' . View::h($childId) . '" aria-label="Toggle ' . View::h($name) . '" data-tree-toggle>'
            . '<span class="browsebox-tree-toggle" aria-hidden="true"></span>'
            . '</button>'
            . '<button class="browsebox-tree-link browsebox-destination-option" type="button" data-destination-option="' . View::h($relativePath) . '" data-destination-label="/' . View::h($relativePath) . '">' . $label . '</button>'
            . '</div>'
            . '<div class="browsebox-tree-children"' . ($isOpen ? '' : ' hidden') . ' id="' . View::h($childId) . '">'
            . browsebox_render_destination_tree_nodes($children, $currentPath)
            . '</div>'
            . '</li>';
    }

    return $html . '</ul>';
}

$currentPath = '';

try {
    $currentPath = $pathGuard->normalizeRelativePath((string) ($_GET['path'] ?? ''));
} catch (RuntimeException) {
    http_response_code(400);
    $currentPath = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        if ($_POST === [] && $_FILES === [] && $contentLength > 0) {
            throw new RuntimeException('The upload request was empty when PHP received it. This usually means the files exceeded PHP upload limits such as post_max_size, upload_max_filesize, or max_file_uploads.');
        }

        $csrf->requireValid($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            $success = $auth->login($username, (string) ($_POST['password'] ?? ''), $remember);
            browsebox_log_action($config, $username, 'login', '', $success ? 'success' : 'failure');

            if (!$success) {
                browsebox_redirect('', 'Invalid username or password.', 'danger');
            }

            browsebox_redirect('', 'Signed in.');
        }

        if (!$auth->check()) {
            throw new RuntimeException('Authentication required.');
        }

        $user = $auth->user();
        $targetPath = $pathGuard->normalizeRelativePath((string) ($_POST['path'] ?? ''));

        switch ($action) {
            case 'logout':
                $auth->logout();
                browsebox_log_action($config, $user, 'logout', '', 'success');
                browsebox_redirect('', 'Signed out.');
            case 'mkdir':
                $created = $fileManager->createDirectory($targetPath, (string) ($_POST['folder_name'] ?? ''));
                browsebox_try_sync_search_index(static fn () => $searchIndex->indexPath($created));
                browsebox_log_action($config, $user, 'mkdir', $created, 'success');
                browsebox_redirect($targetPath, 'Folder created.');
            case 'rename':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $renamed = $fileManager->rename($itemPath, (string) ($_POST['new_name'] ?? ''));
                browsebox_try_sync_search_index(static fn () => $searchIndex->movePath($itemPath, $renamed));
                browsebox_log_action($config, $user, 'rename', $renamed, 'success');
                browsebox_redirect($pathGuard->parent($renamed), 'Item renamed.');
            case 'delete':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $fileManager->delete($itemPath);
                browsebox_try_sync_search_index(static fn () => $searchIndex->removePath($itemPath));
                browsebox_log_action($config, $user, 'delete', $itemPath, 'success');
                browsebox_redirect($targetPath, 'Item deleted.');
            case 'move':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $destinationPath = $pathGuard->normalizeRelativePath((string) ($_POST['destination_path'] ?? ''));
                $moved = $fileManager->move($itemPath, $destinationPath);
                browsebox_try_sync_search_index(static fn () => $searchIndex->movePath($itemPath, $moved));
                browsebox_log_action($config, $user, 'move', $moved, 'success');
                browsebox_redirect($destinationPath, 'Item moved.');
            case 'copy':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $destinationPath = $pathGuard->normalizeRelativePath((string) ($_POST['destination_path'] ?? ''));
                $copied = $fileManager->copy($itemPath, $destinationPath);
                browsebox_try_sync_search_index(static fn () => $searchIndex->indexPath($copied));
                browsebox_log_action($config, $user, 'copy', $copied, 'success');
                browsebox_redirect($destinationPath, 'Item copied.');
            case 'upload':
                $relativePaths = browsebox_decode_relative_paths($_POST['dropped_relative_paths'] ?? null);
                $uploadResult = $uploadManager->uploadManyWithRelativePaths($targetPath, $_FILES['files'] ?? [], $relativePaths);
                $uploaded = (array) ($uploadResult['uploaded'] ?? []);
                $conflicts = (array) ($uploadResult['conflicts'] ?? []);

                browsebox_try_sync_search_index(function () use ($searchIndex, $uploaded): void {
                    foreach ($uploaded as $uploadedPath) {
                        $searchIndex->indexPath((string) $uploadedPath);
                    }
                });

                foreach ($uploaded as $uploadedPath) {
                    browsebox_log_action($config, $user, 'upload', $uploadedPath, 'success');
                }

                foreach ($conflicts as $conflict) {
                    browsebox_log_action($config, $user, 'upload_conflict', (string) ($conflict['destination_relative_path'] ?? $targetPath), 'pending');
                }

                $message = 'Uploaded ' . count($uploaded) . ' file(s).';

                if ($conflicts !== []) {
                    $message .= ' ' . count($conflicts) . ' conflicting file(s) need replace or cancel confirmation below.';
                }

                if (browsebox_is_async_request()) {
                    browsebox_json_response([
                        'ok' => true,
                        'uploaded_count' => count($uploaded),
                        'conflict_count' => count($conflicts),
                        'redirect_url' => browsebox_management_url($targetPath, $message, $conflicts === [] ? 'success' : 'warning'),
                    ]);
                }

                browsebox_redirect($targetPath, $message, $conflicts === [] ? 'success' : 'warning');
            case 'replace_pending_upload':
                $batchId = (string) ($_POST['batch_id'] ?? '');
                $itemId = (string) ($_POST['item_id'] ?? '');
                $replaced = $uploadManager->replacePendingConflict($batchId, $itemId);
                browsebox_try_sync_search_index(static fn () => $searchIndex->indexPath($replaced));
                browsebox_log_action($config, $user, 'replace', $replaced, 'success');
                browsebox_redirect($targetPath, 'Existing file replaced.');
            case 'cancel_pending_upload':
                $batchId = (string) ($_POST['batch_id'] ?? '');
                $itemId = (string) ($_POST['item_id'] ?? '');
                $cancelled = $uploadManager->cancelPendingConflict($batchId, $itemId);
                browsebox_log_action($config, $user, 'upload_cancel', $cancelled, 'success');
                browsebox_redirect($targetPath, 'Conflicting upload cancelled.');
            case 'rebuild_search_index':
                $searchIndex->rebuild();
                browsebox_log_action($config, $user, 'search_rebuild', 'data/search-index.json', 'success');
                browsebox_redirect($currentPath, 'Search index rebuilt.');
            case 'save_config':
                $timezone = trim((string) ($_POST['default_timezone'] ?? ''));

                if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
                    throw new RuntimeException('Invalid timezone.');
                }

                $updatedConfig = $config->all();
                $updatedConfig['default_timezone'] = $timezone;
                $updatedConfig['allow_html_rendering'] = isset($_POST['allow_html_rendering']);
                $updatedConfig['sandbox_public_html'] = isset($_POST['sandbox_public_html']);
                $updatedConfig['max_upload_size'] = browsebox_validate_upload_size((string) ($_POST['max_upload_size'] ?? ''));
                $updatedConfig['blocked_upload_extensions'] = browsebox_parse_extensions((string) ($_POST['blocked_upload_extensions'] ?? ''));
                $updatedConfig['force_download_extensions'] = browsebox_parse_extensions((string) ($_POST['force_download_extensions'] ?? ''));

                $config->write($updatedConfig);
                browsebox_log_action($config, $user, 'config_update', 'config/config.php', 'success');
                browsebox_redirect($currentPath, 'Configuration updated.');
            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (RuntimeException $exception) {
        if ($auth->check()) {
            browsebox_log_action(
                $config,
                $auth->user(),
                (string) ($_POST['action'] ?? 'unknown'),
                (string) ($_POST['item_path'] ?? $_POST['path'] ?? ''),
                'failure'
            );
        }

        if (browsebox_is_async_request()) {
            browsebox_json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 400);
        }

        browsebox_redirect($currentPath, $exception->getMessage(), 'danger');
    }
}

$message = isset($_GET['message']) ? (string) $_GET['message'] : null;
$messageType = (string) ($_GET['type'] ?? 'success');
$alertHtml = $message !== null
    ? '<div class="alert alert-' . View::h($messageType) . '">' . View::h($message) . '</div>'
    : '';

if (!$auth->check()) {
    $body = $alertHtml . '
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Management Login</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="login">
                        ' . $csrf->input() . '
                        <div class="mb-3">
                            <label class="form-label" for="username">Username</label>
                            <input class="form-control" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" id="remember_me" name="remember_me" type="checkbox" value="1">
                            <label class="form-check-label" for="remember_me">Remember me on this device</label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Sign In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>';

    View::renderPage('BrowseBox Management', $body, 'mgmt', false);
    exit;
}

try {
    $items = $fileManager->listDirectory($currentPath);
} catch (RuntimeException $exception) {
    $items = [];
    $alertHtml .= '<div class="alert alert-danger">' . View::h($exception->getMessage()) . '</div>';
}

try {
    $directoryTree = $fileManager->listDirectoryTree();
} catch (RuntimeException) {
    $directoryTree = [];
}

try {
    $pendingConflicts = $uploadManager->listPendingConflicts($currentPath);
} catch (RuntimeException) {
    $pendingConflicts = [];
}

$breadcrumbs = View::breadcrumbs($currentPath);
$breadcrumbsHtml = '';
foreach ($breadcrumbs as $index => $crumb) {
    $active = $index === array_key_last($breadcrumbs);

    if ($active) {
        $breadcrumbsHtml .= '<li class="breadcrumb-item active browsebox-move-target" aria-current="page" data-move-target="' . View::h($crumb['path']) . '">' . View::h($crumb['label']) . '</li>';
        continue;
    }

    $crumbQuery = $crumb['path'] === '' ? '' : '?path=' . rawurlencode($crumb['path']);
    $crumbHref = '.mgmt' . $crumbQuery;
    $breadcrumbsHtml .= '<li class="breadcrumb-item browsebox-move-target" data-move-target="' . View::h($crumb['path']) . '"><a href="' . View::h($crumbHref) . '">' . View::h($crumb['label']) . '</a></li>';
}

$rows = '';
foreach ($items as $item) {
    $query = $item['relative_path'] === '' ? '' : '?path=' . rawurlencode($item['relative_path']);
    $openHref = $item['type'] === 'dir'
        ? '.mgmt' . $query
        : 'file.php?path=' . rawurlencode($item['relative_path']);
    $downloadHref = $item['type'] === 'dir'
        ? ''
        : 'file.php?path=' . rawurlencode($item['relative_path']) . '&download=1';
    $downloadZipHref = $item['type'] === 'dir'
        ? 'file.php?path=' . rawurlencode($item['relative_path']) . '&download=zip'
        : '';
    $rowAttributes = ' draggable="true" data-move-item="' . View::h($item['relative_path']) . '" data-rename-row="1"'
        . browsebox_context_item_attributes(
            (string) $item['relative_path'],
            (string) $item['name'],
            (string) $item['type'],
            $openHref,
            $downloadHref,
            $downloadZipHref,
            'list'
        );

    if ($item['type'] === 'dir') {
        $rowAttributes .= ' data-move-target="' . View::h($item['relative_path']) . '" class="browsebox-move-target"';
    }

    $nameHtml = '<div class="browsebox-item-primary" data-rename-view>'
        . '<a class="text-decoration-none fw-semibold" href="' . View::h($openHref) . '">'
        . View::h(View::icon($item['icon'])) . ' ' . View::h($item['name']) . ($item['type'] === 'dir' ? '/' : '')
        . '</a>'
        . '</div>'
        . '<form method="post" class="browsebox-inline-rename d-none" data-rename-form>'
        . '<input type="hidden" name="action" value="rename">'
        . '<input type="hidden" name="path" value="' . View::h($currentPath) . '">'
        . '<input type="hidden" name="item_path" value="' . View::h($item['relative_path']) . '">'
        . $csrf->input()
        . '<input class="form-control form-control-sm" name="new_name" value="' . View::h($item['name']) . '" data-rename-input data-original-name="' . View::h($item['name']) . '" required>'
        . '<button class="btn btn-sm btn-primary" type="submit">Save</button>'
        . '<button class="btn btn-sm btn-outline-secondary" type="button" data-rename-cancel>Cancel</button>'
        . '</form>';

    $rows .= '<tr' . $rowAttributes . '>'
        . '<td data-label="Name">' . $nameHtml . '</td>'
        . '<td data-label="Size">' . View::h(View::formatSize(is_int($item['size']) ? $item['size'] : null)) . '</td>'
        . '<td data-label="Modified">' . View::h(View::formatDate(is_int($item['modified']) ? $item['modified'] : null)) . '</td>'
        . '<td class="text-end" data-label="Actions">
                <button class="btn btn-sm btn-outline-secondary browsebox-row-menu-button" type="button" aria-label="Open item menu" data-context-menu-button>
                    ⋯
                </button>
            </td>'
        . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="4" class="text-secondary">This folder is empty.</td></tr>';
}

$publicPath = '../' . ($currentPath === '' ? '' : str_replace('%2F', '/', rawurlencode($currentPath)) . '/');
$currentFolderLabel = $currentPath === '' ? 'Home' : basename($currentPath);
$treeRootContextAttributes = browsebox_context_item_attributes('', 'Home', 'dir', '.mgmt', '', '', 'tree');
$treeHtml = '
<div class="browsebox-tree-root-row browsebox-tree-row browsebox-move-target' . ($currentPath === '' ? ' is-current' : '') . '" data-move-target=""' . $treeRootContextAttributes . '>
    <span class="browsebox-tree-toggle-spacer" aria-hidden="true"></span>
    <a class="browsebox-tree-link" href=".mgmt">' . View::h(View::icon('folder')) . ' Home</a>
</div>'
    . browsebox_render_tree_nodes($directoryTree, $currentPath);
$destinationTreeHtml = '
<div class="browsebox-tree-root-row browsebox-tree-row">
    <span class="browsebox-tree-toggle-spacer" aria-hidden="true"></span>
    <button class="browsebox-tree-link browsebox-destination-option" type="button" data-destination-option="" data-destination-label="/">'
        . View::h(View::icon('folder')) . ' Home</button>
</div>'
    . browsebox_render_destination_tree_nodes($directoryTree, $currentPath);
$blockedExtensions = array_map(
    static fn (mixed $value): string => (string) $value,
    (array) $config->get('blocked_upload_extensions', [])
);
$forceDownloadExtensions = array_map(
    static fn (mixed $value): string => (string) $value,
    (array) $config->get('force_download_extensions', [])
);
$configuredUploadSize = (string) $config->get('max_upload_size', '');
$phpUploadMax = (string) ini_get('upload_max_filesize');
$phpPostMax = (string) ini_get('post_max_size');
$phpMaxFileUploads = (int) ini_get('max_file_uploads');
$configuredUploadBytes = browsebox_size_to_bytes($configuredUploadSize);
$phpUploadBytes = browsebox_size_to_bytes($phpUploadMax);
$phpPostBytes = browsebox_size_to_bytes($phpPostMax);
$phpEffectiveBytes = min($phpUploadBytes, $phpPostBytes);
$uploadStatusGood = $configuredUploadBytes === $phpEffectiveBytes;
$uploadStatusClass = $uploadStatusGood ? 'success' : 'danger';
$uploadStatusLabel = $uploadStatusGood ? 'Good' : 'Warning';
$fileCountStatusGood = $phpMaxFileUploads >= 100;
$fileCountStatusClass = $fileCountStatusGood ? 'success' : 'danger';
$fileCountStatusLabel = $fileCountStatusGood ? 'Good' : 'Warning';
try {
    $searchIndexStatus = $searchIndex->status();
} catch (RuntimeException) {
    $searchIndexStatus = [
        'exists' => false,
        'built_at' => null,
        'document_count' => 0,
        'pdf_text_extractor' => 'fallback',
    ];
}
$searchBuiltAtLabel = is_string($searchIndexStatus['built_at'] ?? null) && (string) $searchIndexStatus['built_at'] !== ''
    ? View::formatDate(strtotime((string) $searchIndexStatus['built_at']) ?: null)
    : 'Not built yet';
$searchIndexSectionHtml = '
<section class="browsebox-settings-section">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
        <div>
            <h3 class="h5 mb-1">Search Index</h3>
            <div class="small text-secondary">Search is backed by an on-disk index for filename and readable-file content matches.</div>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="rebuild_search_index">
            <input type="hidden" name="path" value="' . View::h($currentPath) . '">
            ' . $csrf->input() . '
            <button class="btn btn-outline-secondary btn-sm" type="submit">Rebuild Search Index</button>
        </form>
    </div>
    <div class="small mb-1">Last built: <strong>' . View::h($searchBuiltAtLabel) . '</strong></div>
    <div class="small mb-1">Indexed documents: <strong>' . View::h((string) ($searchIndexStatus['document_count'] ?? 0)) . '</strong></div>
    <div class="small mb-0">PDF text extractor: <strong>' . View::h((string) ($searchIndexStatus['pdf_text_extractor'] ?? 'fallback')) . '</strong></div>
    <div class="form-text mt-2">Use this after adding files directly on the server outside BrowseBox.</div>
</section>';
$pendingConflictsRows = '';
$pendingModalHtml = '';

foreach ($pendingConflicts as $conflict) {
    $destinationPath = (string) ($conflict['destination_relative_path'] ?? '');
    $relativeUploadPath = (string) ($conflict['relative_upload_path'] ?? '');
    $createdAt = (string) ($conflict['created_at'] ?? '');
    $createdAtLabel = $createdAt !== '' ? View::formatDate(strtotime($createdAt) ?: null) : '-';

    $pendingConflictsRows .= '
    <div class="browsebox-pending-conflict">
        <div class="browsebox-pending-conflict-copy">
            <div class="browsebox-pending-conflict-title">' . View::h($relativeUploadPath) . '</div>
            <div class="browsebox-pending-conflict-path">Existing target: /' . View::h($destinationPath) . '</div>
            <div class="browsebox-pending-conflict-meta">Staged at ' . View::h($createdAtLabel) . '</div>
        </div>
        <div class="browsebox-pending-conflict-actions">
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="replace_pending_upload">
                <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                <input type="hidden" name="batch_id" value="' . View::h((string) ($conflict['batch_id'] ?? '')) . '">
                <input type="hidden" name="item_id" value="' . View::h((string) ($conflict['item_id'] ?? '')) . '">
                ' . $csrf->input() . '
                <button class="btn btn-sm btn-danger" type="submit">Replace Existing</button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="cancel_pending_upload">
                <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                <input type="hidden" name="batch_id" value="' . View::h((string) ($conflict['batch_id'] ?? '')) . '">
                <input type="hidden" name="item_id" value="' . View::h((string) ($conflict['item_id'] ?? '')) . '">
                ' . $csrf->input() . '
                <button class="btn btn-sm btn-outline-secondary" type="submit">Cancel This Upload</button>
            </form>
        </div>
    </div>';
}

$pendingConflictsHtml = '';
$pendingModalHtml = '
<dialog class="browsebox-modal" data-pending-modal' . ($pendingConflictsRows === '' ? '' : ' data-pending-modal-autoshow="1"') . '>
    <form method="dialog" class="browsebox-modal-backdrop" data-pending-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1">Pending Replacements</h3>
                <div class="small text-secondary">Review conflicting uploads and choose whether to replace existing files or cancel just those uploads.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-pending-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            <div class="alert alert-warning mb-0">Choosing <strong>Replace Existing</strong> will overwrite the current file. Choosing <strong>Cancel This Upload</strong> keeps the current file and discards only that conflicting upload.</div>
            <div class="browsebox-pending-conflicts">' . $pendingConflictsRows . '</div>
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-pending-modal-close>Close</button>
        </div>
    </div>
</dialog>';
$configSectionHtml = '
<section class="browsebox-settings-section">
    <h3 class="h5 mb-3">Configuration</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_config">
        <input type="hidden" name="path" value="' . View::h($currentPath) . '">
        ' . $csrf->input() . '
        <div class="mb-3">
            <label class="form-label" for="default_timezone">Default timezone</label>
            <input class="form-control form-control-sm" id="default_timezone" name="default_timezone" value="' . View::h((string) $config->get('default_timezone', '')) . '" required>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="allow_html_rendering" name="allow_html_rendering" ' . ((bool) $config->get('allow_html_rendering', false) ? 'checked' : '') . '>
            <label class="form-check-label" for="allow_html_rendering">Allow HTML rendering</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="sandbox_public_html" name="sandbox_public_html" ' . ((bool) $config->get('sandbox_public_html', false) ? 'checked' : '') . '>
            <label class="form-check-label" for="sandbox_public_html">Sandbox public HTML</label>
            <div class="form-text">Safer when enabled, but some HTML projects will break because browser storage and same-origin requests are restricted.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="max_upload_size">Max upload size</label>
            <input class="form-control form-control-sm" id="max_upload_size" name="max_upload_size" value="' . View::h((string) $config->get('max_upload_size', '')) . '" required>
        </div>
        <div class="alert alert-' . View::h($uploadStatusClass) . ' small py-2">
            <div class="fw-semibold mb-1">PHP upload size check: ' . View::h($uploadStatusLabel) . '</div>
            <div>BrowseBox config: <code>' . View::h($configuredUploadSize) . '</code></div>
            <div>PHP <code>upload_max_filesize</code>: <code>' . View::h($phpUploadMax) . '</code></div>
            <div>PHP <code>post_max_size</code>: <code>' . View::h($phpPostMax) . '</code></div>
            <div>Effective PHP limit: <code>' . View::h(View::formatSize($phpEffectiveBytes)) . '</code></div>
        </div>
        <div class="alert alert-' . View::h($fileCountStatusClass) . ' small py-2">
            <div class="fw-semibold mb-1">PHP file count check: ' . View::h($fileCountStatusLabel) . '</div>
            <div>PHP <code>max_file_uploads</code>: <code>' . View::h((string) $phpMaxFileUploads) . '</code></div>
            <div>Folder uploads with many files may fail or be truncated if this is too low.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="blocked_upload_extensions">Blocked upload extensions</label>
            <textarea class="form-control form-control-sm" id="blocked_upload_extensions" name="blocked_upload_extensions" rows="3">' . View::h(implode(', ', $blockedExtensions)) . '</textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="force_download_extensions">Force download extensions</label>
            <textarea class="form-control form-control-sm" id="force_download_extensions" name="force_download_extensions" rows="3">' . View::h(implode(', ', $forceDownloadExtensions)) . '</textarea>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Save Configuration</button>
    </form>
</section>';

$settingsModalHtml = '
<dialog class="browsebox-modal browsebox-settings-modal" data-settings-modal>
    <form method="dialog" class="browsebox-modal-backdrop" data-settings-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1">Settings</h3>
                <div class="small text-secondary">Configuration and search maintenance for BrowseBox.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-settings-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            ' . $configSectionHtml . '
            ' . $searchIndexSectionHtml . '
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-settings-modal-close>Close</button>
        </div>
    </div>
</dialog>';

$body = $alertHtml . '
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Management</h2>
        <nav aria-label="breadcrumb" class="browsebox-breadcrumb-wrap">
            <ol class="breadcrumb browsebox-breadcrumb mb-0">' . $breadcrumbsHtml . '</ol>
        </nav>
    </div>
    <div class="d-flex flex-wrap gap-2 align-self-start">
        <button class="btn btn-outline-secondary btn-sm browsebox-settings-trigger" type="button" data-actions-modal-open data-browsebox-tooltip="Upload files, upload folders, or create a folder">
            ' . View::h(View::icon('folder')) . ' ' . View::h(View::icon('settings')) . '
        </button>
        <button class="btn btn-outline-secondary btn-sm browsebox-settings-trigger" type="button" data-settings-modal-open data-browsebox-tooltip="Open BrowseBox settings and search-index tools">
            ' . View::h(View::icon('settings')) . ' Settings
        </button>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-xl border-0 mb-4 browsebox-folder-pane">
            <div class="card-body p-0">
                <div class="d-flex align-items-center justify-content-between gap-3 px-3 py-3 border-bottom">
                    <div>
                        <h3 class="h5 mb-1">Folders</h3>
                        <div class="small text-secondary">Drag onto any folder in this tree.</div>
                    </div>
                    <a class="btn btn-outline-secondary btn-sm" href=".mgmt">Home</a>
                </div>
                <div class="browsebox-tree-scroll p-3">' . $treeHtml . '</div>
            </div>
        </div>' . $pendingConflictsHtml . '
    </div>
    <div class="col-lg-8">
        <div class="card shadow-xl border-0 browsebox-current-pane" data-conditional-sticky data-upload-dropzone>
            <div class="card-body border-bottom py-3">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h3 class="h5 mb-1">' . View::h($currentFolderLabel) . '</h3>
                        <div class="small text-secondary">Drag items onto visible folder rows, the folder tree, or breadcrumbs to move them. You can also drop files or folders anywhere on this card to upload into this folder.</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive browsebox-mgmt-list">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Modified</th>
                                <th class="text-end">Menu</th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<form method="post" class="d-none" data-transfer-form>
    <input type="hidden" name="action" value="move" data-transfer-form-action>
    <input type="hidden" name="path" value="' . View::h($currentPath) . '" data-transfer-form-current-path>
    <input type="hidden" name="item_path" value="" data-transfer-form-item-path>
    <input type="hidden" name="destination_path" value="" data-transfer-form-destination-path>
    ' . $csrf->input() . '
</form>
<form method="post" class="d-none" data-delete-form>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="path" value="' . View::h($currentPath) . '" data-delete-form-current-path>
    <input type="hidden" name="item_path" value="" data-delete-form-item-path>
    ' . $csrf->input() . '
</form>
<div class="browsebox-context-menu" data-context-menu hidden>
    <button class="browsebox-context-menu-item" type="button" data-context-action="open">Open</button>
    <div class="browsebox-context-menu-separator" role="separator" data-context-separator="open"></div>
    <button class="browsebox-context-menu-item" type="button" data-context-action="rename">Rename</button>
    <button class="browsebox-context-menu-item" type="button" data-context-action="delete">Delete</button>
    <div class="browsebox-context-menu-separator" role="separator"></div>
    <button class="browsebox-context-menu-item" type="button" data-context-action="move">Move To…</button>
    <button class="browsebox-context-menu-item" type="button" data-context-action="copy">Copy To…</button>
    <div class="browsebox-context-menu-separator" role="separator"></div>
    <button class="browsebox-context-menu-item" type="button" data-context-action="download">Download</button>
    <button class="browsebox-context-menu-item" type="button" data-context-action="download_zip">Download ZIP</button>
</div>
<iframe name="browsebox-upload-target" class="d-none" tabindex="-1" aria-hidden="true" data-upload-target-frame></iframe>
<dialog class="browsebox-modal" data-actions-modal>
    <form method="dialog" class="browsebox-modal-backdrop" data-actions-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1">Add Or Create</h3>
                <div class="small text-secondary">Upload files, upload folders, or create a new folder in the current location.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-actions-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            <section class="browsebox-settings-section">
                <form method="post" enctype="multipart/form-data" id="browsebox-upload-form" data-upload-form class="browsebox-upload-toolbar">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                    <input type="hidden" name="dropped_relative_paths" value="" data-dropped-relative-paths>
                    ' . $csrf->input() . '
                    <div class="browsebox-upload-toolbar-title">Add To This Folder</div>
                    <div class="browsebox-upload-toolbar-row">
                        <div class="browsebox-picker">
                            <input class="browsebox-picker-input" id="file_upload" type="file" name="files[]" multiple data-picker-kind="file">
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="file_upload">Choose Files</label>
                            <span class="browsebox-picker-status text-secondary" data-picker-status="file_upload">No file chosen</span>
                        </div>
                        <div class="browsebox-picker">
                            <input class="browsebox-picker-input" id="folder_upload" type="file" name="files[]" webkitdirectory multiple data-picker-kind="folder">
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="folder_upload">Choose Folder</label>
                            <span class="browsebox-picker-status text-secondary" data-picker-status="folder_upload">No folder chosen</span>
                        </div>
                    </div>
                </form>
            </section>
            <section class="browsebox-settings-section">
                <form method="post" class="browsebox-upload-toolbar browsebox-create-folder-toolbar">
                    <input type="hidden" name="action" value="mkdir">
                    <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                    ' . $csrf->input() . '
                    <div class="browsebox-upload-toolbar-title">Create Folder</div>
                    <div class="browsebox-create-folder-row">
                        <input class="form-control form-control-sm" name="folder_name" placeholder="New folder name" required>
                        <button class="btn btn-primary btn-sm" type="submit">Create</button>
                    </div>
                </form>
            </section>
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-actions-modal-close>Close</button>
        </div>
    </div>
</dialog>
<dialog class="browsebox-modal browsebox-destination-modal" data-destination-modal>
    <form method="dialog" class="browsebox-modal-backdrop" data-destination-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1" data-destination-modal-title>Move Item</h3>
                <div class="small text-secondary" data-destination-modal-subtitle>Select a destination folder, then confirm the action.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-destination-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            <div class="browsebox-modal-field">
                <div class="browsebox-modal-label">Item</div>
                <div class="browsebox-modal-value" data-destination-modal-item>-</div>
            </div>
            <div class="browsebox-modal-field">
                <div class="browsebox-modal-label">Destination</div>
                <div class="browsebox-modal-value" data-destination-modal-selection>Choose a folder below.</div>
            </div>
            <div class="browsebox-destination-browser">' . $destinationTreeHtml . '</div>
            <div class="alert alert-danger d-none mb-0" data-destination-modal-error></div>
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-destination-modal-close>Cancel</button>
            <button class="btn btn-primary" type="button" data-destination-modal-confirm disabled>Choose Destination</button>
        </div>
    </div>
</dialog>
<dialog class="browsebox-modal" data-delete-modal>
    <form method="dialog" class="browsebox-modal-backdrop" data-delete-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1">Delete Item</h3>
                <div class="small text-secondary">This action cannot be undone.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-delete-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            <div class="alert alert-warning mb-0">
                You are about to delete <strong data-delete-modal-item-label>-</strong>.
            </div>
            <div class="small text-secondary">If this is a folder, everything inside it will be deleted too.</div>
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-delete-modal-close>Cancel</button>
            <button class="btn btn-danger" type="button" data-delete-modal-confirm>Delete</button>
        </div>
    </div>
</dialog>
<dialog class="browsebox-modal" data-upload-modal>
    <form method="dialog" class="browsebox-modal-backdrop" data-upload-modal-close></form>
    <div class="browsebox-modal-card">
        <div class="browsebox-modal-header">
            <div>
                <h3 class="h5 mb-1" data-upload-modal-title>Ready to Upload</h3>
                <div class="small text-secondary" data-upload-modal-subtitle>Review the selected files before starting the upload.</div>
            </div>
            <button class="btn-close" type="button" aria-label="Close" data-upload-modal-close></button>
        </div>
        <div class="browsebox-modal-body">
            <div class="browsebox-modal-field">
                <div class="browsebox-modal-label">Destination</div>
                <div class="browsebox-modal-value" data-upload-modal-destination>/' . View::h($currentPath) . '</div>
            </div>
            <div class="browsebox-modal-field">
                <div class="browsebox-modal-label">Selected Items</div>
                <div class="browsebox-upload-selection" data-upload-modal-selection></div>
            </div>
            <div class="alert alert-danger d-none mb-0" data-upload-modal-error></div>
            <div class="alert alert-info d-none mb-0" data-upload-modal-progress>Uploading now. Keep this window open while BrowseBox transfers your files.</div>
        </div>
        <div class="browsebox-modal-actions">
            <button class="btn btn-outline-secondary" type="button" data-upload-modal-cancel>Cancel</button>
            <button class="btn btn-primary browsebox-upload-submit" type="submit" form="browsebox-upload-form" data-upload-submit disabled>Upload Selected Files or Folder</button>
        </div>
    </div>
</dialog>
' . $settingsModalHtml . '
' . $pendingModalHtml;

View::renderPage('BrowseBox Management', $body, 'mgmt', true, $navActionHtml);
