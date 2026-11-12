# Photoboob

A single-file PHP photo viewer. Put images next to `index.php` or in its subdirectories. Supported extensions are JPEG, PNG, GIF, WebP, AVIF, BMP, ICO, SVG, TIFF, HEIC, and HEIF; browser support varies. Symlinks are not followed.

Requires PHP 7.4 or newer with PDO SQLite, and write access to the project directory. For local use:

```sh
php -S localhost:8000 -t .
```

Open <http://localhost:8000/>. The app creates `.photoboob.sqlite` and scans on the first request and hourly thereafter when accessed. If a selected image is missing, it immediately rescans and resolves the ID against the refreshed list. Images removed from disk are removed from the database at the next scan, including their flags. Navigation IDs are 1-based positions in path order and can change when the gallery changes. An out-of-range ID returns 404. Subdirectories that cannot be read (e.g. due to permissions) are skipped during a scan instead of failing it; each skip is noted in the PHP error log.

The arrow keys navigate, Home and End jump to the first and last image, and R picks a random image. The on-screen controls also work without JavaScript.

Request flagged images without accepting HTML, for example:

```sh
curl -H 'Accept: application/json' http://localhost:8000/
curl -H 'Accept: text/tsv' http://localhost:8000/
curl -H 'Accept: application/xml' http://localhost:8000/
```

The gallery and flag exports have no authentication; add access controls if needed. The included `.htaccess` is a sample for Apache 2.4: it disables directory listings, selects `index.php`, and denies direct access to dotfiles, including `.photoboob.sqlite` and its SQLite journal files. Apache must allow the `Options`, `Indexes`, and `AuthConfig` overrides for this directory (or the equivalent directives must be placed in the server configuration). Verify that requests to the database are denied after deployment.

Other web servers, including PHP's development server, do not use `.htaccess`; configure them separately to protect the database and its journal files. The PHP development server is intended only for local development.
