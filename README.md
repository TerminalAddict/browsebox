# BrowseBox

BrowseBox is a small self-hosted PHP file browser with two areas:

- `/` for public browsing and downloading
- `/.mgmt` for authenticated file management

It uses plain PHP, filesystem storage, JSON-backed users, Bootstrap 5, and no database.

## Setup

Point your web server document root at [public/index.php](/home/paul/git-repos/BrowseBox/public/index.php). Do not expose `app/`, `config/`, `data/`, `storage/`, or `scripts/` directly.

Apache rewrite rules live in [public/.htaccess](/home/paul/git-repos/BrowseBox/public/.htaccess).

## Deployment helper

The included [Makefile](/home/paul/git-repos/BrowseBox/Makefile) does not hardcode a runtime deployment target anymore. Provide the destination explicitly:

```bash
make deploy DEPLOY_HOST=user@example-host DEPLOY_PATH=/path/to/browsebox
```

Dry run:

```bash
make deploy-dry-run DEPLOY_HOST=user@example-host DEPLOY_PATH=/path/to/browsebox
```

If you deploy to the same host repeatedly, you can create a local untracked file named `.make.local` with values such as:

```makefile
DEPLOY_HOST=user@example-host
DEPLOY_PATH=/path/to/browsebox
```

Then plain `make deploy` will work without embedding environment-specific values in the project defaults.

## Admin user

Create an admin from the project root:

```bash
php scripts/create-admin.php paul
```

The script prompts for a password and stores a `password_hash()` value in [data/users.json](/home/paul/git-repos/BrowseBox/data/users.json).

## Storage

Public files live under [storage/files](/home/paul/git-repos/BrowseBox/storage/files). The public browser lists folders first, shows size and modified time, and serves files through [public/file.php](/home/paul/git-repos/BrowseBox/public/file.php) so downloads and inline rendering can be controlled safely.

## Management

The management portal at `/.mgmt` supports:

- login and logout
- file upload
- folder upload with `webkitdirectory`
- folder creation
- rename
- delete
- editing selected BrowseBox config values from the management UI
- PHP upload size and file-count visibility in the management UI
- CSRF protection on all POST actions
- action logging to [data/logs/actions.log](/home/paul/git-repos/BrowseBox/data/logs/actions.log)

## Large files

Very large files such as multi-gigabyte ISOs or archives should not be uploaded through the browser. For files in the multi-GB range, use direct server transfer instead and let BrowseBox expose the files after they are in [storage/files](/home/paul/git-repos/BrowseBox/storage/files).

Recommended approach for one large file:

```bash
rsync -avP "/local/path/bigfile.iso" user@example-host:/path/to/browsebox/storage/files/
```

Recommended approach for a folder tree:

```bash
rsync -avP "/local/path/downloads/" user@example-host:/path/to/browsebox/storage/files/downloads/
```

Why use `rsync`:

- it resumes interrupted transfers
- it is more reliable than browser uploads for large files
- it preserves folder structure
- it works well for updating existing folders

Safe workflow for large public files:

- upload to a temporary name such as `movie.iso.partial`
- wait for the transfer to complete
- rename the file to its final public name

If the file already exists somewhere on the server, move it directly over SSH instead of uploading it again:

```bash
ssh user@example-host
mv /some/other/path/bigfile.iso /path/to/browsebox/storage/files/
```

---

## Creating an admin user

The first version should include:

```text
scripts/create-admin.php
```

Expected usage:

```bash
php scripts/create-admin.php paul
```

The script should prompt for a password and save a password hash to:

```text
data/users.json
```

Passwords must be stored using PHP `password_hash()`.

---

## Security model

BrowseBox assumes the files being hosted are public.

However, the management portal must still be protected.

Security requirements:

- Management portal requires login
- Passwords are hashed
- Sessions use secure cookie settings
- CSRF tokens protect management POST actions
- Destructive actions use POST, not GET
- Path traversal is blocked
- Uploaded file paths are validated
- Uploaded server-side scripts are blocked
- Uploaded public files are never executed as server-side scripts
- Hidden dotfile-style names and paths are rejected
- Only files inside `storage/files` are browseable

Blocked upload extensions:

```text
.php
.phtml
.phar
.cgi
.pl
.py
.rb
.asp
.aspx
.jsp
```

HTML files are allowed to render publicly because BrowseBox is intended to host public content uploaded by the owner.

Public HTML is served with a restrictive `Content-Security-Policy` sandbox so rendered projects do not share a normal browser origin with the management portal. This reduces the risk that uploaded HTML can interact with an authenticated `/.mgmt` session.

If a trusted HTML project needs normal browser features such as `sessionStorage`, `localStorage`, or same-origin `fetch`/XHR to sibling files, disable `sandbox_public_html` in the management configuration panel or in [config/config.php](/home/paul/git-repos/BrowseBox/config/config.php). This restores normal browser behavior for public HTML at the cost of reduced isolation.

## Configuration in management

The logged-in management page includes a configuration form for these keys from [config/config.php](/home/paul/git-repos/BrowseBox/config/config.php):

- `default_timezone`
- `allow_html_rendering`
- `sandbox_public_html`
- `max_upload_size`
- `blocked_upload_extensions`
- `force_download_extensions`

The same panel also shows:

- PHP `upload_max_filesize`
- PHP `post_max_size`
- effective PHP upload limit
- PHP `max_file_uploads`

This is intended to make browser upload constraints visible without needing shell access.

---

## Domain-agnostic design

BrowseBox should not depend on a particular domain.

Avoid hardcoded absolute URLs.

Use relative URLs wherever possible.

Good:

```text
./
../
./.mgmt
```

Avoid:

```text
https://example.com/some/path
```

---

## First implementation status

The first version is complete when:

- Public browser works at `/`
- Management portal works at `/.mgmt`
- Admin login/logout works
- Admin user creation works
- File upload works
- Folder upload works
- Folder creation works
- Rename works
- Delete works
- Path traversal is blocked
- CSRF protection is implemented
- HTML files render publicly
- ZIPs/executables download
- Server-side script uploads are blocked
- All links are relative/domain-agnostic

---

## Future ideas

Possible later improvements:

- Search
- Download counters
- File descriptions
- Markdown rendering
- ZIP extraction
- ZIP folder download
- Bulk delete
- Bulk move
- Drag-and-drop upload
- Upload progress bars
- Dark mode
- Tags
- Recent uploads
- QR codes for files
- Expiring links
- Optional 2FA for management
