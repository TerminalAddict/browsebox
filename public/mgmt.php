<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/PathGuard.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
require_once dirname(__DIR__) . '/app/FileManager.php';
require_once dirname(__DIR__) . '/app/UploadManager.php';
require_once dirname(__DIR__) . '/app/View.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$pathGuard = new PathGuard($config->requireString('storage_root'));
$auth = new Auth($config);
$csrf = new Csrf();
$fileManager = new FileManager($pathGuard);
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

function browsebox_redirect(string $path, ?string $message = null, string $type = 'success'): never
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

    header('Location: ' . $location, true, 303);
    exit;
}

function browsebox_tree_branch_open(string $nodePath, string $currentPath): bool
{
    if ($nodePath === '') {
        return true;
    }

    return $currentPath === $nodePath || ($currentPath !== '' && str_starts_with($currentPath . '/', $nodePath . '/'));
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

        if ($children === []) {
            $html .= '<li class="browsebox-tree-item">'
                . '<div class="' . $rowClass . '" data-move-target="' . View::h($relativePath) . '">'
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
            . '<div class="' . $rowClass . $openClass . '" data-tree-item="' . View::h($relativePath) . '" data-move-target="' . View::h($relativePath) . '" data-move-expand="1">'
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

$currentPath = '';

try {
    $currentPath = $pathGuard->normalizeRelativePath((string) ($_GET['path'] ?? ''));
} catch (RuntimeException) {
    http_response_code(400);
    $currentPath = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
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
                browsebox_log_action($config, $user, 'mkdir', $created, 'success');
                browsebox_redirect($targetPath, 'Folder created.');
            case 'rename':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $renamed = $fileManager->rename($itemPath, (string) ($_POST['new_name'] ?? ''));
                browsebox_log_action($config, $user, 'rename', $renamed, 'success');
                browsebox_redirect($pathGuard->parent($renamed), 'Item renamed.');
            case 'delete':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $fileManager->delete($itemPath);
                browsebox_log_action($config, $user, 'delete', $itemPath, 'success');
                browsebox_redirect($targetPath, 'Item deleted.');
            case 'move':
                $itemPath = $pathGuard->normalizeRelativePath((string) ($_POST['item_path'] ?? ''), false);
                $destinationPath = $pathGuard->normalizeRelativePath((string) ($_POST['destination_path'] ?? ''));
                $moved = $fileManager->move($itemPath, $destinationPath);
                browsebox_log_action($config, $user, 'move', $moved, 'success');
                browsebox_redirect($destinationPath, 'Item moved.');
            case 'upload':
                $relativePaths = browsebox_decode_relative_paths($_POST['dropped_relative_paths'] ?? null);
                $uploaded = $uploadManager->uploadManyWithRelativePaths($targetPath, $_FILES['files'] ?? [], $relativePaths);

                foreach ($uploaded as $uploadedPath) {
                    browsebox_log_action($config, $user, 'upload', $uploadedPath, 'success');
                }

                browsebox_redirect($targetPath, 'Uploaded ' . count($uploaded) . ' file(s).');
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
    $rowAttributes = ' draggable="true" data-move-item="' . View::h($item['relative_path']) . '" data-rename-row="1"';

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
        . '<td>' . $nameHtml . '</td>'
        . '<td>' . View::h(View::formatSize(is_int($item['size']) ? $item['size'] : null)) . '</td>'
        . '<td>' . View::h(View::formatDate(is_int($item['modified']) ? $item['modified'] : null)) . '</td>'
        . '<td class="text-end">
                <button class="btn btn-sm btn-outline-secondary me-2" type="button" data-rename-toggle>Rename</button>
                <form method="post" class="d-inline" onsubmit="return BrowseBox.confirmDelete(this);">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                    <input type="hidden" name="item_path" value="' . View::h($item['relative_path']) . '">
                    ' . $csrf->input() . '
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
            </td>'
        . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="4" class="text-secondary">This folder is empty.</td></tr>';
}

$publicPath = '../' . ($currentPath === '' ? '' : str_replace('%2F', '/', rawurlencode($currentPath)) . '/');
$currentFolderLabel = $currentPath === '' ? 'Home' : basename($currentPath);
$treeHtml = '
<div class="browsebox-tree-root-row browsebox-tree-row browsebox-move-target' . ($currentPath === '' ? ' is-current' : '') . '" data-move-target="">
    <span class="browsebox-tree-toggle-spacer" aria-hidden="true"></span>
    <a class="browsebox-tree-link" href=".mgmt">' . View::h(View::icon('folder')) . ' Home</a>
</div>'
    . browsebox_render_tree_nodes($directoryTree, $currentPath);
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
$configSummaryHtml = '
<div class="card shadow-sm border-0 mt-4">
    <div class="card-body">
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
    </div>
</div>';

$body = $alertHtml . '
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Management</h2>
        <nav aria-label="breadcrumb" class="browsebox-breadcrumb-wrap">
            <ol class="breadcrumb browsebox-breadcrumb mb-0">' . $breadcrumbsHtml . '</ol>
        </nav>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4 browsebox-folder-pane">
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
        </div>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h3 class="h5 mb-3">Upload Files or Folders</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                    <input type="hidden" name="dropped_relative_paths" value="" data-dropped-relative-paths>
                    ' . $csrf->input() . '
                    <p class="small text-secondary mb-3">Step 1: choose files or a folder. Step 2: click the upload button to start the transfer.</p>
                    <div class="browsebox-dropzone mb-3" data-upload-dropzone>
                        <div class="browsebox-dropzone-title">Drag files or a folder here</div>
                        <div class="browsebox-dropzone-text" data-upload-dropzone-text>Desktop drag and drop works here for files and folders.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Choose files</label>
                        <div class="browsebox-picker">
                            <input class="browsebox-picker-input" id="file_upload" type="file" name="files[]" multiple data-picker-kind="file">
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="file_upload">Choose Files</label>
                            <span class="browsebox-picker-status text-secondary" data-picker-status="file_upload">No file chosen</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Or choose a folder</label>
                        <div class="browsebox-picker">
                            <input class="browsebox-picker-input" id="folder_upload" type="file" name="files[]" webkitdirectory multiple data-picker-kind="folder">
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="folder_upload">Choose Folder</label>
                            <span class="browsebox-picker-status text-secondary" data-picker-status="folder_upload">No folder chosen</span>
                        </div>
                    </div>
                    <button class="btn btn-primary browsebox-upload-submit" type="submit" data-upload-submit disabled>Upload Selected Files or Folder</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h3 class="h5 mb-3">Create Folder</h3>
                <form method="post">
                    <input type="hidden" name="action" value="mkdir">
                    <input type="hidden" name="path" value="' . View::h($currentPath) . '">
                    ' . $csrf->input() . '
                    <div class="mb-3">
                        <input class="form-control" name="folder_name" placeholder="New folder name" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Create</button>
                </form>
            </div>
        </div>' . $configSummaryHtml . '
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body border-bottom py-3">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h3 class="h5 mb-1">' . View::h($currentFolderLabel) . '</h3>
                        <div class="small text-secondary">Drag items onto visible folder rows, the folder tree, or breadcrumbs to move them.</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Modified</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<form method="post" class="d-none" data-move-form>
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="path" value="' . View::h($currentPath) . '" data-move-form-current-path>
    <input type="hidden" name="item_path" value="" data-move-form-item-path>
    <input type="hidden" name="destination_path" value="" data-move-form-destination-path>
    ' . $csrf->input() . '
</form>';

View::renderPage('BrowseBox Management', $body, 'mgmt', true, $navActionHtml);
