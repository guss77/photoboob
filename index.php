<?php
declare(strict_types=1);

// Photoboob: put images beside this file or in its subdirectories.
// PHP needs PDO SQLite and permission to write to this directory.
const SCAN_INTERVAL = 3600;
const IMAGE_TYPES = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
    'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
    'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'heic' => 'image/heic', 'heif' => 'image/heif',
];

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// A subdirectory that cannot be read (permissions, deleted mid-scan, ...)
// must not abort the whole scan: hasChildren() returns false so the
// iterator never descends into it, and the directory is simply skipped.
// RecursiveDirectoryIterator::getChildren() would otherwise throw an
// UnexpectedValueException for such directories.
class SkippableDirectoryIterator extends RecursiveDirectoryIterator
{
    public function hasChildren(?bool $allowLinks = null): bool
    {
        if (!is_readable($this->getPathname())) {
            error_log('Photoboob: skipping unreadable directory: ' . $this->getPathname());
            return false;
        }
        // Omit the argument when unset: the parent's accepted parameter
        // types differ between PHP versions (bool vs ?bool).
        return $allowLinks === null
            ? parent::hasChildren()
            : parent::hasChildren($allowLinks);
    }
}

function accepts(string $header, string $type): bool
{
    // More specific media ranges override less specific ones, even at q=0.
    $best = -1;
    $quality = 0.0;
    [$major] = explode('/', $type, 2);
    foreach (explode(',', $header) as $entry) {
        $parts = array_map('trim', explode(';', $entry));
        $range = strtolower(array_shift($parts));
        $specificity = $range === $type ? 2 : ($range === $major . '/*' ? 1 : ($range === '*/*' ? 0 : -1));
        if ($specificity < 0) {
            continue;
        }
        $q = 1.0;
        foreach ($parts as $part) {
            if (preg_match('/^q\s*=\s*(.*)$/i', $part, $match)) {
                $q = is_numeric($match[1]) ? (float) $match[1] : 0.0;
            }
        }
        if ($specificity > $best || ($specificity === $best && $q > $quality)) {
            $best = $specificity;
            $quality = $q;
        }
    }
    return $best >= 0 && $quality > 0 && $quality <= 1;
}

function responseType(string $header): ?string
{
    if ($header === '' || accepts($header, 'text/html')) {
        return 'text/html';
    }
    $supported = ['text/plain', 'text/tsv', 'application/json', 'application/xml'];
    // Preserve the client's order rather than sorting the media ranges by q.
    foreach (explode(',', $header) as $entry) {
        $range = strtolower(trim(explode(';', $entry, 2)[0]));
        foreach ($supported as $type) {
            [$major] = explode('/', $type, 2);
            if (($range === $type || $range === $major . '/*' || $range === '*/*')
                && accepts($entry, $type) && accepts($header, $type)) {
                return $type;
            }
        }
    }
    return null;
}

function scanIfDue(PDO $db, bool $force = false): void
{
    // The write lock serializes scans and flag changes across PHP processes.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lastScan = (int) $db->query("SELECT value FROM settings WHERE name = 'last_scan'")->fetchColumn();
        if (!$force && $lastScan > 0 && time() - $lastScan < SCAN_INTERVAL) {
            $db->exec('COMMIT');
            return;
        }

        $db->exec('UPDATE images SET seen = 0');
        $upsert = $db->prepare('INSERT INTO images (path, modified, size, seen) VALUES (?, ?, ?, 1)
            ON CONFLICT(path) DO UPDATE SET modified = excluded.modified, size = excluded.size, seen = 1');
        $iterator = new RecursiveIteratorIterator(
            new SkippableDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            // Do not follow symlinks, which could expose files outside the gallery.
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!isset(IMAGE_TYPES[$extension])) {
                continue;
            }
            $path = substr($file->getPathname(), strlen(__DIR__) + 1);
            $upsert->execute([$path, $file->getMTime(), $file->getSize()]);
        }
        $db->exec('DELETE FROM images WHERE seen = 0');
        $statement = $db->prepare("INSERT INTO settings (name, value) VALUES ('last_scan', ?)
            ON CONFLICT(name) DO UPDATE SET value = excluded.value");
        $statement->execute([time()]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function startSession(): void
{
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n";
    exit;
}

function rescanNow(PDO $db): void
{
    try {
        scanIfDue($db, true);
    } catch (Throwable $error) {
        error_log("Photoboob: {$error->getMessage()}");
        fail(500, 'Unable to scan the gallery. Check directory permissions.');
    }
}

function imageFile(string $path): ?string
{
    $candidate = __DIR__ . '/' . $path;
    clearstatcache(true, $candidate);
    $absolute = realpath($candidate);
    if ($absolute === false || strpos($absolute, __DIR__ . DIRECTORY_SEPARATOR) !== 0 || !is_file($absolute)) {
        return null;
    }
    return $absolute;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/.photoboob.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 10000');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS images (
        path TEXT PRIMARY KEY, modified INTEGER NOT NULL, size INTEGER NOT NULL,
        flagged INTEGER NOT NULL DEFAULT 0, seen INTEGER NOT NULL DEFAULT 1
    )');
    scanIfDue($db);
} catch (Throwable $error) {
    error_log("Photoboob: {$error->getMessage()}");
    fail(500, 'Unable to open or scan the gallery. Check PHP, SQLite, and directory permissions.');
}

// Path-info style image URLs (index.php/<image path>) end with the real
// filename, so a browser's "save image" stores it under its own name.
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (is_string($pathInfo) && $pathInfo !== '' && $pathInfo !== '/') {
    $segments = explode('/', ltrim($pathInfo, '/'));
    $_GET['image'] = implode('/', array_map('rawurldecode', $segments));
}

// The image endpoint uses a database path, not a filesystem path supplied directly by the client.
if (isset($_GET['image']) && is_string($_GET['image'])) {
    $statement = $db->prepare('SELECT path FROM images WHERE path = ?');
    $statement->execute([$_GET['image']]);
    $path = $statement->fetchColumn();
    $statement->closeCursor();
    $absolute = $path === false ? null : imageFile($path);
    if ($absolute === null) {
        if ($path !== false) {
            rescanNow($db);
        }
        fail(404, 'Image not found.');
    }
    $type = IMAGE_TYPES[strtolower(pathinfo($absolute, PATHINFO_EXTENSION))] ?? null;
    if ($type === null) {
        fail(404, 'Image not found.');
    }
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    if ($type === 'image/svg+xml') {
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    }
    header('Cache-Control: private, no-cache');
    readfile($absolute);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    startSession();
    if (!isset($_POST['csrf'], $_POST['path']) || !is_string($_POST['csrf']) || !is_string($_POST['path'])
        || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        fail(403, 'Invalid request.');
    }
    $statement = $db->prepare('UPDATE images SET flagged = 1 - flagged WHERE path = ?');
    $statement->execute([$_POST['path']]);
    if ($statement->rowCount() === 0) {
        fail(404, 'Image not found.');
    }
    $statement = $db->prepare('SELECT COUNT(*) FROM images WHERE path <= ? COLLATE BINARY');
    $statement->execute([$_POST['path']]);
    header('Location: ?id=' . (int) $statement->fetchColumn(), true, 303);
    exit;
}

header('Vary: Accept');
$responseType = responseType(trim($_SERVER['HTTP_ACCEPT'] ?? ''));
if ($responseType === null) {
    fail(406, 'No acceptable representation is available.');
}

if ($responseType !== 'text/html') {
    // Count every image, not just flagged ones, to obtain the viewer's ordinal IDs.
    $rows = $db->query('SELECT path, flagged FROM images ORDER BY path COLLATE BINARY');
    $flagged = [];
    $id = 0;
    foreach ($rows as $row) {
        ++$id;
        if ((int) $row['flagged'] === 1) {
            $flagged[] = ['id' => $id, 'path' => $row['path']];
        }
    }
    header('Content-Type: ' . $responseType . '; charset=utf-8');
    if ($responseType === 'application/json') {
        echo json_encode($flagged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($responseType === 'application/xml') {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<flagged>\n";
        foreach ($flagged as $image) {
            $path = htmlspecialchars($image['path'], ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
            $path = str_replace(["\t", "\n", "\r"], ['&#x9;', '&#xA;', '&#xD;'], $path);
            echo '  <image id="' . $image['id'] . '" path="' . $path . '"/>' . "\n";
        }
        echo "</flagged>\n";
    } else {
        foreach ($flagged as $image) {
            echo $image['id'] . "\t" . $image['path'] . "\n";
        }
    }
    exit;
}

$image = null;
$id = 0;
$rescanned = false;
while (true) {
    $count = (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn();
    if ($count === 0) {
        if (isset($_GET['id'])) {
            fail(404, 'Image ID not found.');
        }
        break;
    }
    if (isset($_GET['id'])) {
        if (!is_string($_GET['id']) || !preg_match('/^[1-9][0-9]*$/D', $_GET['id'])
            || filter_var($_GET['id'], FILTER_VALIDATE_INT) === false || (int) $_GET['id'] > $count) {
            fail(404, 'Image ID not found.');
        }
        $id = (int) $_GET['id'];
    } else {
        $id = random_int(1, $count);
    }
    $statement = $db->prepare('SELECT path, flagged FROM images ORDER BY path COLLATE BINARY LIMIT 1 OFFSET ?');
    $statement->bindValue(1, $id - 1, PDO::PARAM_INT);
    $statement->execute();
    $image = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    if (!$image) {
        fail(404, 'Image ID not found.');
    }
    if (imageFile($image['path']) !== null) {
        break;
    }
    if ($rescanned) {
        fail(404, 'Image not found.');
    }
    // The ordinal may point to a different file after a deleted or renamed file is removed.
    rescanNow($db);
    $rescanned = true;
    $image = null;
}
if ($image) {
    startSession();
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', preg_replace('/\.[^.\/]+$/', '', $image['path']));
    $title = trim($title ?? $image['path']);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" type="image/png" href="icon.png">
    <title><?php /*= $image ? escape($title) : 'Photoboob' */?>Photoboob</title>
    <style>
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; background: #111; color: #ddd; font-family: system-ui, sans-serif; }
        .viewer { position: fixed; inset: 0; overflow: hidden; touch-action: pan-y; }
        .photo { width: 100%; height: 100%; object-fit: contain; display: block; -webkit-user-drag: none; user-select: none; }
        .title { position: absolute; top: 0; left: 0; right: 0; padding: max(12px, env(safe-area-inset-top)) 16px 12px; text-align: center; overflow-wrap: anywhere; background: linear-gradient(#000b, transparent); text-shadow: 0 1px 3px #000; }
        .controls { position: absolute; left: 0; right: 0; bottom: 0; padding: 0 max(8px, env(safe-area-inset-right)) max(8px, env(safe-area-inset-bottom)) max(8px, env(safe-area-inset-left)); display: flex; justify-content: space-between; align-items: flex-end; gap: 4px; }
        .column { display: flex; flex-direction: column; }
        .middle { display: flex; }
        .action { width: 56px; height: 56px; display: inline-flex; align-items: center; justify-content: center; padding: 9px; border: 0; border-radius: 8px; background: transparent; color: #ddd; opacity: .5; cursor: pointer; filter: drop-shadow(0 1px 2px #000); }
        .action svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
        .action.flagged { color: #ff9500; }
        .action:focus-visible { outline: 3px solid currentColor; opacity: 1; }
        .empty { height: 100%; display: grid; place-content: center; padding: 24px; text-align: center; }
        @media (max-width: 260px) { .action { width: 44px; height: 48px; padding: 7px; } }
    </style>
</head>
<body>
<?php if (!$image): ?>
    <main class="empty"><p>No images found. Add images to this directory or its subdirectories.</p><p>The gallery is rescanned hourly when accessed.</p></main>
<?php else: ?>
    <main class="viewer">
        <?php $imageSource = 'index.php/' . implode('/', array_map('rawurlencode', explode('/', $image['path']))); ?>
        <img class="photo" src="<?= escape($imageSource) ?>" alt="<?= escape($title) ?>" draggable="false">
        <div class="title"><?= escape($title) ?></div>
        <nav class="controls" aria-label="Image actions">
            <div class="column">
                <a class="action" href="?id=1" aria-label="First image" title="First image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4v16M19 5 9 12l10 7z"/></svg></a>
                <a class="action" href="?id=<?= max(1, $id - 1) ?>" aria-label="Previous image" title="Previous image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></a>
            </div>
            <div class="middle">
                <a class="action" href="?" aria-label="Random image" title="Random image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h3c5 0 6 12 12 12h3m-3-3 3 3-3 3M3 18h3c2 0 3-2 4-4m3-5c1-2 3-3 5-3h3m-3-3 3 3-3 3"/></svg></a>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                    <input type="hidden" name="path" value="<?= escape($image['path']) ?>">
                    <button class="action<?= $image['flagged'] ? ' flagged' : '' ?>" type="submit" aria-label="<?= $image['flagged'] ? 'Remove flag' : 'Flag image' ?>" aria-pressed="<?= $image['flagged'] ? 'true' : 'false' ?>" title="<?= $image['flagged'] ? 'Remove flag' : 'Flag image' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4m0 1c6-3 8 3 14 0v10c-6 3-8-3-14 0"/></svg></button>
                </form>
            </div>
            <div class="column">
                <a class="action" href="?id=<?= $count ?>" aria-label="Last image" title="Last image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4v16M5 5l10 7-10 7z"/></svg></a>
                <a class="action" href="?id=<?= min($count, $id + 1) ?>" aria-label="Next image" title="Next image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
            </div>
        </nav>
    </main>
    <script>
        // Links and the flag form also work without JavaScript.
        document.addEventListener('keydown', function (event) {
            if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ||
                /^(INPUT|TEXTAREA|SELECT|BUTTON)$/.test(event.target.tagName) || event.target.isContentEditable) return;
            const destinations = {
                ArrowLeft: '?id=<?= max(1, $id - 1) ?>',
                ArrowRight: '?id=<?= min($count, $id + 1) ?>',
                Home: '?id=1',
                End: '?id=<?= $count ?>',
                r: '?'
            };
            if (Object.prototype.hasOwnProperty.call(destinations, event.key)) {
                event.preventDefault();
                window.location.href = destinations[event.key];
            }
        });
    </script>
    <script>
        // Touch swipes reuse the navigation buttons' URLs. A mostly horizontal
        // swipe to the left goes to the next image, to the right the previous one.
        (function () {
            const viewer = document.querySelector('.viewer');
            if (!viewer) return;
            const go = function (label) {
                const link = document.querySelector('a[aria-label="' + label + '"]');
                if (link) window.location.href = link.getAttribute('href');
            };
            let swipe = null;
            viewer.addEventListener('pointerdown', function (event) {
                // A second finger cancels the swipe, e.g. during a pinch zoom.
                if (swipe !== null || !event.isPrimary || event.target.closest('.controls')) {
                    swipe = null;
                    return;
                }
                swipe = { id: event.pointerId, x: event.clientX, y: event.clientY, time: Date.now() };
            });
            viewer.addEventListener('pointerup', function (event) {
                if (!swipe || event.pointerId !== swipe.id) return;
                const dx = event.clientX - swipe.x;
                const dy = event.clientY - swipe.y;
                const elapsed = Date.now() - swipe.time;
                swipe = null;
                if (elapsed > 1500 || Math.abs(dx) < 60 || Math.abs(dx) < 2 * Math.abs(dy)) return;
                event.preventDefault();
                go(dx < 0 ? 'Next image' : 'Previous image');
            });
            viewer.addEventListener('pointercancel', function () { swipe = null; });
        })();
    </script>
<?php endif; ?>
</body>
</html>
