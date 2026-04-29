<?php

return [
    'app_name' => 'BrowseBox',
    'storage_root' => dirname(__DIR__) . '/storage/files',
    'data_root' => dirname(__DIR__) . '/data',
    'management_path' => '/.mgmt',
    'default_timezone' => 'Pacific/Auckland',
    'allow_html_rendering' => true,
    'max_upload_size' => '2048M',
    'blocked_upload_extensions' => [
        'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
    ],
    'force_download_extensions' => [
        'zip', 'tar', 'gz', 'tgz', '7z', 'rar', 'exe', 'msi', 'deb', 'rpm',
        'appimage', 'dmg', 'iso', 'sh', 'bat', 'ps1',
    ],
];
