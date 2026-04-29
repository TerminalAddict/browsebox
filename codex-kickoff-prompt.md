# BrowseBox Codex Kickoff Prompt

You are working on a new project called BrowseBox.

BrowseBox is a small self-hosted PHP web file browser with a public browsing area and a private management portal.

## High-level goal

Build the first working implementation of BrowseBox.

The app has two main areas:

```text
/        public file browser
/.mgmt   private management portal
```

The public side allows anyone to browse and download/open public files.

The management side is authentication protected and allows the owner to upload files, upload entire folders, create folders, rename files/folders, and delete files/folders.

The app must be domain-agnostic and use relative paths wherever possible.

## Preferred implementation

Use:

- PHP 8.2+
- Plain PHP
- Bootstrap 5
- Minimal JavaScript
- Apache-compatible `.htaccess`
- Filesystem storage
- JSON user store
- No database
- No framework
- No Composer dependencies unless absolutely necessary

## Required directory structure

Create this structure:

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

## Config

Create `config/config.php`:

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

## Apache routing

Create `public/.htaccess` that routes:

```text
/.mgmt      -> mgmt.php
/.mgmt/...  -> mgmt.php
everything else not a real public file -> index.php?path=...
```

Suggested starting point:

```apache
RewriteEngine On

RewriteRule ^\.mgmt/?$ mgmt.php [L,QSA]
RewriteRule ^\.mgmt/(.*)$ mgmt.php [L,QSA]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?path=$1 [L,QSA]
```

## Public browser requirements

Implement the public browser at `/`.

It should:

- Browse `storage/files`
- Show folders first, then files
- Show file/folder icons
- Show file size
- Show modified date
- Show breadcrumb navigation
- Use relative links
- Allow folders to be opened
- Allow files to be opened or downloaded
- Render `.html` and `.htm` files in the browser
- Force download for configured archive/executable/installer/script extensions
- Prevent path traversal
- Never expose files outside `storage/files`

## Management portal requirements

Implement the management portal at `/.mgmt`.

It should:

- Require login
- Support logout
- Show current folder contents
- Upload normal files
- Upload entire folders using browser directory upload support
- Create folders
- Rename files/folders
- Delete files/folders
- Use POST for destructive actions
- Use CSRF tokens for all POST actions
- Write basic logs to `data/logs/actions.log`

## Admin user creation

Create:

```text
scripts/create-admin.php
```

The script should be run from CLI:

```bash
php scripts/create-admin.php paul
```

It should prompt for a password, hash it using `password_hash()`, and save it to:

```text
data/users.json
```

Use a simple JSON structure like:

```json
{
  "users": [
    {
      "username": "paul",
      "password_hash": "$2y$..."
    }
  ]
}
```

If the user already exists, ask before replacing the hash or clearly refuse.

## Security requirements

This project is for public files, but management still needs proper security.

Implement:

- Password hashing with `password_hash()`
- Login verification with `password_verify()`
- Secure session cookie settings
- `HttpOnly` session cookies
- `SameSite=Strict` where practical
- `Secure` cookies when HTTPS is detected
- CSRF tokens on management POST actions
- POST-only destructive actions
- Strict path validation
- Path traversal blocking
- Upload relative path validation
- Block uploaded server-side script extensions
- Never expose files outside `storage/files`
- Never directly expose `app/`, `config/`, `data/`, `storage/`, or `scripts/`

Blocked upload extensions:

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

## Path handling

Create a dedicated `PathGuard` class.

It should provide safe helpers for resolving user-requested paths inside the configured storage root.

Rules:

- Treat all input paths as untrusted
- Reject null bytes
- Reject `..`
- Normalize slashes
- Remove leading slashes
- Resolve against `storage/files`
- Confirm resolved paths remain inside `storage/files`
- Reject unsafe uploaded relative paths

Do not duplicate path safety logic in random files. Centralise it.

## Classes to create

### `app/Config.php`

Loads config from `config/config.php`.

### `app/PathGuard.php`

Validates and resolves paths safely inside the storage root.

### `app/Auth.php`

Handles sessions, login, logout, and current user.

### `app/Csrf.php`

Creates and validates CSRF tokens.

### `app/FileManager.php`

Handles listing, creating folders, deleting, renaming, file metadata, and safe file operations.

### `app/UploadManager.php`

Handles file upload and folder upload.

### `app/PublicBrowser.php`

Renders or coordinates public folder browsing.

### `app/View.php`

Small helper for escaping and rendering shared page layout.

## UI

Use Bootstrap 5.

The UI should be clean and simple.

Public browser:

- Header with BrowseBox name
- Breadcrumbs
- File/folder table or cards
- Mobile-friendly layout

Management portal:

- Header with BrowseBox management
- Login form when logged out
- Current folder browser when logged in
- Upload file control
- Upload folder control
- Create folder form
- Rename/delete controls
- Logout link

## Folder upload

Use browser folder upload support:

```html
<input type="file" name="files[]" webkitdirectory multiple>
```

The upload handler must preserve relative folder paths safely under the selected target folder.

Reject unsafe relative paths.

## File serving

Implement `public/file.php` if needed to serve files with controlled headers.

Behaviour:

- `.html` and `.htm` render if `allow_html_rendering` is true
- Images, PDFs, text, video, and audio may open in browser
- Force-download configured archive/executable/installer/script extensions
- Set safe `Content-Type`
- Set `Content-Disposition` correctly
- Do not serve files outside `storage/files`

## Logging

Write logs to:

```text
data/logs/actions.log
```

Log management actions:

- login success
- login failure
- logout
- upload
- mkdir
- rename
- delete

Log format can be simple text:

```text
2026-04-29T14:10:00+12:00 paul upload /software/tool.zip success
```

Do not log passwords.

## Done criteria

The first implementation is done when all of these are true:

- `/` renders the public BrowseBox browser
- Public folder navigation works
- Public breadcrumbs work
- Files display size and modified date
- Files can open or download correctly
- HTML files render publicly
- ZIPs and executables download
- `/.mgmt` renders the management portal
- `/.mgmt` requires login
- Login works
- Logout works
- `scripts/create-admin.php` can create an admin user
- Admin can upload files
- Admin can upload folders
- Admin can create folders
- Admin can rename files/folders
- Admin can delete files/folders
- CSRF protection is implemented
- Path traversal attempts are blocked
- Server-side script uploads are blocked
- Action logging works
- Links are relative and domain-agnostic

## Important constraints

Do not add features outside the first implementation slice.

Do not add:

- Multiple roles
- ACL levels
- Public uploads
- Private files
- Database
- Laravel
- Symfony
- Composer packages
- Docker
- Cloud storage
- Payment/licensing
- Search indexing
- Download counters

Focus on a simple, working, secure first slice.
