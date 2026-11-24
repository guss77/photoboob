# Photoboob

A single-file PHP photo viewer, mobile-first by desktop fiendly.

## Installation

Requires a web server with PHP 7.4 or newer with PDO and SQLite enabled.

1. Copy `index.php`, the icons, and optionally the `.htaccess` file to a web-served directory where you have images to display - the viewer will show all images in that directory and sub-directories (symbolic links are not followed).
2. Give the web server write access to the directory - to allow it to create the database file - or alternatively, create an empty file called `.photoboob.sqlite` in the same directory and give the web server write access to just that file.

Supported formats are JPEG, PNG, GIF, WebP, AVIF, BMP, ICO, SVG, TIFF, HEIC, and HEIF - though browser support varies.

## Local Testing

Run:

```sh
php -S localhost:8000 -t .
```

Then open <http://localhost:8000/>.

## Usage

The application periodically (when loaded) scans the directories and updates the database with new images found and removes images that no longer exist.

### Navigation

Use the icons to navigate to first/previous/next/last image, pick a random image or to flag an image.

Long press the flag icon to enter "flagged only" browsing mode that show only flagged files, from which you can also clear all flags or exit back to normal browsing.

Reloading after using random, chooses a new random image.

#### Mobile

Swipe left or right on the image viewer to go to the previous or next image (respectively).

### Desktop

- Use left/right arrow keys for previous next image
- Use Home and End jump to the first and last image
- Use R to pick a random image

### Creating A Report Of Flagged Images

You can use a non-browser client to request a report that lists all flagged images, by specifying the desired report format using the `Accept` header. For example:

To get a JSON report:

```sh
curl -H 'Accept: application/json' http://localhost:8000/
```

To get a plain text report:

```sh
curl -H 'Accept: text/plain' http://localhost:8000/
```

To get a TSV (tab separated values, good for importing into a spreadsheet) report:

```sh
curl -H 'Accept: text/tsv' http://localhost:8000/
```

To get an XML report:

```sh
curl -H 'Accept: application/xml' http://localhost:8000/
```

### Security

All functionality (including flagging) have no authentication or authorization or other security feature. If you require any of those - add them on top using your web server, such as using HTTP basic authentication or IP access limits. 

The included `.htaccess` is a sample for Apache 2.4: it implements some expected behavior such as disabling automatic file indexes and prevent the database from being downloaded - but otherwise include no security mechanisms, you may add any you wish when serving using Apache. Apache should be configured to allow the `Options`, `Indexes`, and `AuthConfig` overrides for this directory (or the equivalent directives must be placed in the server configuration). 

# Last Notes

Yes, this is for the internet - therefor its for porn (in case you were wondering about some branding choices).
