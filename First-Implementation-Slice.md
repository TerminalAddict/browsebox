# First Implementation Slice — BrowseBox

## Goal

Build the smallest complete version of BrowseBox that proves the full public/private workflow:

- Public users can browse and open/download files from `/`.
- The owner can log in at `/.mgmt`.
- The owner can upload, upload folders, create folders, rename, and delete.
- All filesystem access is constrained to `storage/files`.

Do not add advanced features in this slice.

---

## Slice 1: Project skeleton

Create the required directory structure:

```text
browsebox/
├── app/
├── config/
├── data/logs/
├── public/assets/
├── scripts/
└── storage/files/
```

Create placeholder files:

```text
README.md
AGENTS.md
codex-kickoff-prompt.md
First-Implementation-Slice.md
public/index.php
public/file.php
public/mgmt.php
public/.htaccess
config/config.php
data/users.json
data/logs/actions.log
storage/files/.gitkeep
```

Acceptance checks:

- Project has the expected directories.
- Web root can be pointed at `public/`.
- Non-public directories are outside document root.

---

## Slice 2: Configuration loader

Create `app/Config.php`.

It should:

- Load `config/config.php`
- Return config values
- Provide defaults where sensible
- Fail clearly if required paths are missing

Acceptance checks:

- `Config::get('app_name')` returns `BrowseBox`.
- `Config::get('storage_root')` points to `storage/files`.
- Missing required config produces a clear error.

---

## Slice 3: PathGuard

Create `app/PathGuard.php`.

It must:

- Treat input paths as untrusted
- Reject null bytes
- Reject `..`
- Normalize slashes
- Remove leading slashes
- Resolve paths inside `storage/files`
- Confirm resolved paths remain inside `storage/files`
- Validate uploaded relative paths

Acceptance checks:

These should be rejected:

```text
../config/config.php
../../etc/passwd
/storage/files/../../config
folder/../../../bad
folder/%2e%2e/bad
```

These should be allowed:

```text
""
software
software/windows
docs/readme.html
```

---

## Slice 4: Public browser

Create public browsing from `/`.

Implement:

- Folder listing
- Folders first
- Files second
- Breadcrumb navigation
- File size
- Modified date
- Relative links
- HTML escaping

Acceptance checks:

- `/` lists `storage/files`.
- `/docs/` lists `storage/files/docs`.
- Breadcrumbs work.
- Path traversal does not work.
- Output is HTML-escaped.

---

## Slice 5: File serving

Create controlled file serving via `public/file.php` or equivalent routing.

Implement:

- Safe path resolution
- `Content-Type`
- `Content-Length`
- `Content-Disposition`
- Inline rendering for `.html` and `.htm` when enabled
- Forced download for configured executable/archive/script extensions

Acceptance checks:

- `.html` opens in browser.
- `.zip` downloads.
- `.exe` downloads.
- Missing file returns 404.
- Path traversal returns 400 or 404.
- Files outside `storage/files` cannot be read.

---

## Slice 6: Authentication

Create `app/Auth.php`.

Implement:

- Secure session start
- Login
- Logout
- Current user lookup
- Password verification from `data/users.json`
- Password hashes only

Create `scripts/create-admin.php`.

It should:

- Accept a username argument
- Prompt for password
- Store password hash using `password_hash()`
- Save to `data/users.json`
- Refuse or confirm before replacing an existing user

Acceptance checks:

- Admin user can be created.
- Plain password is not stored.
- Login works with correct password.
- Login fails with wrong password.
- Logout clears the session.

---

## Slice 7: CSRF protection

Create `app/Csrf.php`.

Implement:

- Token generation
- Token storage in session
- Token validation
- Hidden input helper if useful

Acceptance checks:

- Management POST without CSRF fails.
- Management POST with CSRF succeeds.
- GET requests do not perform destructive actions.

---

## Slice 8: Management portal

Create management UI at `/.mgmt`.

Implement:

- Login screen
- Logged-in file browser
- Current folder navigation
- Link back to public view
- Upload file form
- Upload folder form
- Create folder form
- Rename form
- Delete form
- Logout link

Acceptance checks:

- `/.mgmt` shows login when logged out.
- `/.mgmt` shows management UI when logged in.
- Management links are relative/domain-agnostic.
- All destructive controls use POST and CSRF.

---

## Slice 9: File operations

Create `app/FileManager.php`.

Implement:

- List directory
- Create folder
- Rename file/folder
- Delete file/folder
- Basic recursive folder delete
- Refuse operations outside storage root

Acceptance checks:

- Create folder works.
- Rename file works.
- Rename folder works.
- Delete file works.
- Delete folder works.
- Path traversal is blocked for every action.

---

## Slice 10: Uploads

Create `app/UploadManager.php`.

Implement:

- Normal file uploads
- Folder uploads using `webkitdirectory`
- Preserve relative paths
- Validate uploaded relative paths
- Block server-side script extensions
- Create missing directories as needed
- Confirm or refuse overwrites according to first-slice behaviour

Acceptance checks:

- Single file upload works.
- Multiple file upload works.
- Folder upload works.
- Relative folder structure is preserved.
- `.php`, `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.rb`, `.asp`, `.aspx`, `.jsp` uploads are blocked.
- Unsafe uploaded relative paths are blocked.

---

## Slice 11: Logging

Add basic logging to `data/logs/actions.log`.

Log:

- login success
- login failure
- logout
- upload
- mkdir
- rename
- delete

Format:

```text
2026-04-29T14:10:00+12:00 paul upload /software/tool.zip success
```

Acceptance checks:

- Successful management actions are logged.
- Failed login is logged.
- Passwords are never logged.
- Session IDs are never logged.

---

## Slice 12: Styling

Use Bootstrap 5 and `public/assets/app.css`.

Public UI:

- Clean header
- Breadcrumbs
- Responsive file list
- Simple icons
- Mobile-friendly layout

Management UI:

- Clear management header
- Login form
- Upload controls
- Folder tools
- Action buttons
- Mobile-friendly layout

Acceptance checks:

- Public browser is usable on desktop and mobile.
- Management portal is usable on desktop and mobile.
- No absolute domain-specific links.

---

## Final acceptance test

The first implementation is complete when:

- `/` renders the public BrowseBox browser
- `/folder/` navigates folders
- Public files open/download correctly
- HTML files render publicly
- ZIPs and executables download
- `/.mgmt` shows login when logged out
- Admin can log in
- Admin can log out
- Admin can upload files
- Admin can upload folders
- Admin can create folders
- Admin can rename files/folders
- Admin can delete files/folders
- CSRF protection works
- Path traversal is blocked everywhere
- Server-side script uploads are blocked
- Action logging works
- Links are relative/domain-agnostic
- Web server document root can safely be `public/`
