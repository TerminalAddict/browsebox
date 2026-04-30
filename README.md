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

The deploy helper also normalizes permissions for [data](/home/paul/git-repos/BrowseBox/data), [storage/files](/home/paul/git-repos/BrowseBox/storage/files), and [storage/thumbnails](/home/paul/git-repos/BrowseBox/storage/thumbnails) so the web server can rename, move, upload, delete managed content, write cached thumbnails, persist remember-login tokens consistently, and maintain the search index/cache. It sets the group to `www-data`, applies setgid to directories, and grants group write access recursively.

Deploys also preserve the live server copy of [config/config.php](/home/paul/git-repos/BrowseBox/config/config.php), [data/users.json](/home/paul/git-repos/BrowseBox/data/users.json), [data/remember_tokens.json](/home/paul/git-repos/BrowseBox/data/remember_tokens.json), [data/search-index.json](/home/paul/git-repos/BrowseBox/data/search-index.json), [data/search-cache](/home/paul/git-repos/BrowseBox/data/search-cache), and [data/logs/actions.log](/home/paul/git-repos/BrowseBox/data/logs/actions.log), so settings changed through `/.mgmt`, saved users, remember-login tokens, search data, and logs are not overwritten by `make deploy`. On a brand-new server, missing runtime files are created automatically.

## Admin user

Create an admin from the project root:

```bash
php scripts/create-admin.php paul
```

The script prompts for a password and stores a `password_hash()` value in [data/users.json](/home/paul/git-repos/BrowseBox/data/users.json).

## Storage

Public files live under [storage/files](/home/paul/git-repos/BrowseBox/storage/files). Generated image thumbnails are cached under [storage/thumbnails](/home/paul/git-repos/BrowseBox/storage/thumbnails). The public browser supports both `List View` and `Icon View`, remembers the selected view in a persistent browser cookie, lists folders first, shows size and modified time, supports global search, and serves files through [public/file.php](/home/paul/git-repos/BrowseBox/public/file.php) so downloads, inline rendering, thumbnail responses, and on-demand ZIP archive downloads can be controlled safely. Normal folders expose their ZIP download action after you enter the folder, while folders detected as web apps expose a parent-level ZIP shortcut because opening them may hand control to the uploaded app.

`Icon View` uses local SVG file-type icons from [public/assets/file-icons](/home/paul/git-repos/BrowseBox/public/assets/file-icons). Raster image thumbnails are generated on demand and cached by file path plus file timestamp, so updated images automatically get a new thumbnail cache key. Thumbnail generation uses PHP GD when available; if GD is missing, image files fall back to their normal icon or inline image rendering.

## Search

Public search is global across [storage/files](/home/paul/git-repos/BrowseBox/storage/files), regardless of which folder you are currently browsing.

Search behavior:

- filename search supports partial matches and close matches
- content search covers indexed readable files such as text, Markdown, HTML, CSV, JSON, XML, logs, and PDFs
- results include matching files and folders
- readable-file content is extracted into a persistent on-disk search cache under [data/search-cache](/home/paul/git-repos/BrowseBox/data/search-cache)

Search is backed by a persistent index in [data/search-index.json](/home/paul/git-repos/BrowseBox/data/search-index.json), so normal searches do not have to recursively scan the full storage tree on every request.

The management portal keeps the index in sync for normal actions such as:

- upload
- create folder
- rename
- move
- delete

If you add files directly on the server with `rsync`, `scp`, `sftp`, or SSH, BrowseBox cannot see that change immediately at the moment it happens. In that case, open `/.mgmt` and use the `Rebuild Search Index` button.

PDF search uses `pdftotext` when it is available on the server. If it is not available, BrowseBox falls back to a much more limited built-in text extraction path.

## Management

The management portal at `/.mgmt` supports:

- login and logout
- optional `Remember me on this device` persistent login
- file upload
- folder upload with `webkitdirectory`
- desktop drag-and-drop upload for files and folders in supported browsers
- folder creation
- moving files and folders with direct drag-and-drop onto visible folders, breadcrumbs, and the persistent folder tree
- rename
- delete
- search index status and manual rebuild
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
- Persistent login uses a separate random remember-token cookie with server-side hashed token storage
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

Public HTML can optionally be served with a restrictive `Content-Security-Policy` sandbox so rendered projects do not share a normal browser origin with the management portal. This reduces the risk that uploaded HTML can interact with an authenticated `/.mgmt` session.

If a trusted HTML project needs normal browser features such as `sessionStorage`, `localStorage`, or same-origin `fetch`/XHR to sibling files, disable `sandbox_public_html` in the management configuration panel or in [config/config.php](/home/paul/git-repos/BrowseBox/config/config.php). This restores normal browser behavior for public HTML at the cost of reduced isolation.

## Remember me

The management login form includes a `Remember me on this device` checkbox.

When enabled:

- the normal PHP session is still used first
- a separate long-lived cookie is also issued
- only a hash of the remember token is stored server-side in [data/remember_tokens.json](/home/paul/git-repos/BrowseBox/data/remember_tokens.json)
- the token is rotated after successful automatic re-authentication
- logout clears both the session cookie and the remember cookie

This is intended for trusted personal devices. Avoid enabling it on shared machines.

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
