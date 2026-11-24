# Photoboob — Agent instructions

Single-file PHP photo viewer. Keep it that way: changes go into `index.php`
(or the small static icons); avoid splitting into multiple files or
frameworks.

## Project requirements

- A PHP 7.4+ runtime with PDO SQLite and write access to the project
  directory (or at least to the SQLite database file).
- No other server requirements: use the PHP development server for local
  testing (`php -S localhost:8000 -t .`), or any web server configured to
  serve `index.php` (see `.htaccess` for Apache sample rules).
- The gallery is the directory the server serves; images are the extensions
  listed in `IMAGE_TYPES` (jpg, jpeg, png, gif, webp, avif, bmp, ico, svg,
  tif, tiff, heic, heif); symlinks are not followed.

## Goals for changes

- **Simple to deploy.** A change that adds files, config, or deployment steps
  is probably wrong.
- **No extra features unless specifically required.** Skip bookmarklets,
  security BKMs, analytics, account systems, alternate storage backends, etc. — nothing
  beyond what the viewer needs today.
- **Keep it readable.** Small diffs in the existing style; new code should
  fit the current structure (procedural, single file).

## Rules for agents

- **Do not commit anything automatically.** Every code change must be
  reviewed and tested by a human first, then committed by the human.
- Test changes before presenting them: run `php -l`, and verify behaviour
  with the dev server (e.g. `php -S localhost:8000 -t .` and `curl`).
- If a change affects anything a human uses (mode flows, forms, URLs), show
  the new URLs/behaviour in the summary for verification.
- Documentation: do not insert large comments to explain agent's reasoning:
  use minimal comments only when otherwise readability suffers. Use PHPDoc
  style documentation for functions and classes.
