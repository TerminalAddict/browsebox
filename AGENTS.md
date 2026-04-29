# AGENTS.md — BrowseBox

## Project name

BrowseBox

## Project summary

BrowseBox is a small self-hosted web file browser and file management app.

It has two separate areas:

- `/` — public file browser
- `/.mgmt` — private authenticated management portal

The public side allows visitors to browse folders and download/open files.

The management side allows the owner to upload, upload entire directories, create folders, rename files/folders, delete files/folders, and organise the public file tree.

BrowseBox is intended for public files only. It is not designed to store private or sensitive files.

## Primary design goals

- Simple to deploy
- Plain PHP
- Filesystem-based
- Domain-agnostic
- Relative-path friendly
- Public browsing separated from management actions
- Management protected by authentication
- Safe path handling
- No database required for the first version

## Preferred stack

Use:

- PHP 8.2+
- Plain PHP
- Apache-compatible `.htaccess`
- Bootstrap 5
- Minimal JavaScript
- Filesystem storage
- JSON user store for the first version

Do not introduce Laravel, Symfony, Composer packages, NPM tooling, or a database unless specifically requested later.

## Deployment assumptions

The web server document root points to:

```text
public/
```

The following directories must not be directly web-accessible:

```text
app/
config/
data/
storage/
scripts/
```

The public files are stored under:

```text
storage/files/
```

## Required directory structure

Use this structure:

```text
browsebox/
├── app/
│   ├── Auth.php
│   ├── Config.php
│   ├── Csrf.php
│   ├── FileManager.php
│   ├── PathGuard.php
│   ├── PublicBrowser.php
│   ├── UploadManager.php
│   └── View.php
│
├── config/
│   └── config.php
│
├── data/
│   ├── users.json
│   └── logs/
│       └── actions.log
│
├── public/
│   ├── index.php
│   ├── file.php
│   ├── mgmt.php
│   ├── .htaccess
│   └── assets/
│       ├── app.css
│       └── app.js
│
├── storage/
│   └── files/
│       └── .gitkeep
│
├── scripts/
│   └── create-admin.php
│
├── README.md
├── AGENTS.md
├── First-Implementation-Slice.md
└── codex-kickoff-prompt.md
```

## Public browser requirements

The public browser starts at `/`.

It must:

- Browse folders under `storage/files`
- Show files and folders
- Show file size
- Show modified date
- Show breadcrumb navigation
- Use relative links
- Open browser-supported files
- Render `.html` and `.htm` files in the browser
- Force download for archive, executable, installer, shell script, and disk image file types
- Prevent path traversal
- Never expose files outside `storage/files`

## Management portal requirements

The management portal is available at:

```text
/.mgmt
```

It must:

- Require login
- Support logout
- Allow file upload
- Allow full folder upload using browser directory upload support
- Allow folder creation
- Allow file/folder rename
- Allow file/folder deletion
- Allow replacing files only with explicit confirmation
- Use POST for destructive actions
- Use CSRF protection for all management POST actions
- Write basic action logs to `data/logs/actions.log`

## Security requirements

Security is important even though the hosted files are public.

The hidden management path is not the security boundary. Authentication is mandatory.

The app must:

- Store password hashes only
- Use PHP `password_hash()` and `password_verify()`
- Use secure session settings
- Use `HttpOnly` session cookies
- Use `SameSite=Strict` session cookies where practical
- Use `Secure` cookies when HTTPS is detected
- Use CSRF tokens on all management POST actions
- Use POST for upload, delete, rename, mkdir, move, and replace actions
- Validate every requested path
- Resolve all file paths inside `storage/files`
- Block path traversal attempts
- Block unsafe uploaded relative paths
- Block uploaded server-side script extensions
- Never execute uploaded files as server-side scripts
- Never expose `app/`, `config/`, `data/`, `storage/`, or `scripts/` directly

Blocked upload extensions for the first version:

```text
php
phtml
phar
cgi
pl
py
rb
asp
aspx
jsp
```

## Public HTML files

BrowseBox intentionally allows public `.html` and `.htm` files to render in the browser.

This is acceptable because only the owner can upload files and all files are public by design.

However, uploaded HTML must not be able to perform management actions without authentication and CSRF tokens.

Management actions must never be possible through simple GET requests.

## Domain-agnostic behaviour

Do not hardcode the domain.

Avoid links like:

```text
https://example.com/path
```

Use relative links wherever possible.

Good examples:

```text
./
../
./.mgmt
?path=software
```

If a base path is required, calculate it from the current request rather than hardcoding a hostname.

## Apache routing requirements

Create `public/.htaccess` with routing similar to:

```apache
RewriteEngine On

RewriteRule ^\.mgmt/?$ mgmt.php [L,QSA]
RewriteRule ^\.mgmt/(.*)$ mgmt.php [L,QSA]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?path=$1 [L,QSA]
```

The exact implementation may be improved, but the external behaviour must remain:

```text
/        public browser
/.mgmt   private management portal
```

## Config requirements

Create `config/config.php` with sensible defaults:

```php
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
        'php',
        'phtml',
        'phar',
        'cgi',
        'pl',
        'py',
        'rb',
        'asp',
        'aspx',
        'jsp',
    ],

    'force_download_extensions' => [
        'zip',
        'tar',
        'gz',
        'tgz',
        '7z',
        'rar',
        'exe',
        'msi',
        'deb',
        'rpm',
        'appimage',
        'dmg',
        'iso',
        'sh',
        'bat',
        'ps1',
    ],
];
```

## Coding style

Use clear, boring, maintainable PHP.

Prefer:

- Small classes
- Explicit validation
- Defensive path handling
- Readable functions
- Minimal magic
- No unnecessary dependencies
- Escape all HTML output
- Keep public and management logic clearly separated

Use `declare(strict_types=1);` where practical.

## Path handling rules

All requested paths must be treated as untrusted.

The app must:

1. Normalize the requested relative path.
2. Reject empty dangerous segments.
3. Reject `..` traversal.
4. Resolve the target path against `storage/files`.
5. Confirm the final resolved path is still inside `storage/files`.

Never trust raw `$_GET`, `$_POST`, uploaded filenames, or uploaded relative paths.

## Management action logging

Write basic management logs to:

```text
data/logs/actions.log
```

Log at least:

- Timestamp
- Username
- Action
- Target path
- Result

Example:

```text
2026-04-29T14:10:00+12:00 paul upload /software/tool.zip success
```

Do not log passwords or sensitive session values.

## First implementation done criteria

The first implementation is complete when:

- `/` renders the public BrowseBox file browser
- Public folder navigation works
- Breadcrumbs work
- File size is displayed
- Modified date is displayed
- Files can be opened or downloaded
- HTML files render in the browser
- ZIPs and executables download
- `/.mgmt` renders the management portal
- Management portal requires login
- Login works
- Logout works
- Admin user can be created using `scripts/create-admin.php`
- Authenticated admin can upload files
- Authenticated admin can upload folders
- Authenticated admin can create folders
- Authenticated admin can rename files/folders
- Authenticated admin can delete files/folders
- CSRF protection is implemented
- Path traversal is blocked
- Server-side script uploads are blocked
- Basic action logging works
- All public and management links are domain-agnostic

## Do not do yet

Do not add these in the first slice unless explicitly requested:

- User roles
- Multiple permission levels
- Database schema
- Public upload
- Private file storage
- Expiring links
- Download counters
- File comments
- Tags
- Search indexing
- Composer dependency tree
- Docker setup
- Laravel or other framework
- Cloud storage backends
- Payment or licensing
