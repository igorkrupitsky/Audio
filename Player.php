<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/config.php';
$DB_HOST = $config['DB_HOST'];
$DB_NAME = $config['DB_NAME'];
$DB_USER = $config['DB_USER'];
$DB_PASS = $config['DB_PASS'];

$GOOGLE_CLIENT_ID = getenv('GOOGLE_CLIENT_ID') ?: ($config['GOOGLE_CLIENT_ID'] ?? '');

// Optional: list of emails allowed to edit the shared book metadata (title, URL, author, ...).
// Leave empty / unset in config.php to keep the old behavior (every logged-in user can edit).
// Every user can always set their own rating.
$ADMIN_EMAILS = array_map('strtolower', array_map('trim', (array)($config['ADMIN_EMAILS'] ?? [])));

// Folder names that are never shown in listings.
const HIDDEN_FOLDER_NAMES = ['images', 'bin', 'obj', '.vs'];

// Check if user is authenticated
$isAuthenticated = isset($_SESSION['user']) && is_array($_SESSION['user']) && (string)($_SESSION['user']['email'] ?? '') !== '';

function get_logged_in_user_id(): int {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return 0;
    }
    $uid = (int)($_SESSION['user']['userId'] ?? 0);
    return $uid > 0 ? $uid : 0;
}

function can_edit_shared_metadata(): bool {
    global $ADMIN_EMAILS;
    if (count($ADMIN_EMAILS) === 0) {
        return true;
    }
    $email = strtolower((string)($_SESSION['user']['email'] ?? ''));
    return $email !== '' && in_array($email, $ADMIN_EMAILS, true);
}

function db_connect(): mysqli {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $mysqli = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * The Folder table's text (titles, authors, paths) was written through latin1
 * connections by the original code and by your other tools, so it must be read and
 * written the same way; over utf8mb4 Cyrillic comes out as "Ð¢Ð°Ðº". Progress and
 * bookmark data (UserFolder/AppUser) were always written over utf8mb4 and stay that way.
 * Set DB_FOLDER_CHARSET to 'utf8mb4' in config.php only after converting the Folder data.
 */
function folder_charset(): string {
    global $config;
    return (string)($config['DB_FOLDER_CHARSET'] ?? 'latin1');
}

/** Switch the connection to the Folder table's charset; returns the previous one. */
function use_folder_charset(mysqli $mysqli): string {
    $previous = $mysqli->character_set_name();
    if ($previous !== folder_charset()) {
        $mysqli->set_charset(folder_charset());
    }
    return $previous;
}

function restore_charset(mysqli $mysqli, string $previous): void {
    if ($mysqli->character_set_name() !== $previous) {
        $mysqli->set_charset($previous);
    }
}

/**
 * Log the real error server-side and send only a generic message to the client.
 * Both 'ok' and 'success' are set because different callers check different keys.
 */
function json_fail(int $status, string $publicMessage, ?Throwable $ex = null): void {
    if ($ex !== null) {
        error_log('[Player.php] ' . get_class($ex) . ': ' . $ex->getMessage());
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'success' => false, 'error' => $publicMessage]);
    exit;
}

/**
 * Basic cross-site request protection for API calls: browsers send Sec-Fetch-Site on
 * every request, and Origin on POSTs. A request coming from another site is rejected.
 */
function reject_cross_site_requests(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '') {
        if ($fetchSite === 'cross-site') {
            json_fail(403, 'Cross-site request rejected');
        }
        return;
    }

    // Older browsers: fall back to comparing Origin with Host.
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        return;
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $originPort = parse_url($origin, PHP_URL_PORT);
    if ($originPort !== null && $originPort !== false) {
        $originHost .= ':' . $originPort;
    }
    $allowed = [];
    foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', (string)$_SERVER[$key]) as $h) {
                $h = strtolower(trim($h));
                $allowed[] = $h;
                $allowed[] = preg_replace('/:(80|443)$/', '', $h);
            }
        }
    }
    if (!in_array($originHost, $allowed, true)) {
        json_fail(403, 'Cross-site request rejected');
    }
}

/**
 * Turn a client-supplied folder path into a server path that is guaranteed to be inside
 * the app folder. Returns null if the path escapes it.
 *
 * The check is lexical (no realpath), so symlinks placed inside the library keep working,
 * but '..' segments and paths outside the base folder are refused.
 */
function resolve_library_path(string $input): ?string {
    $base = __DIR__;
    $input = trim($input);
    if ($input === '') {
        return $base;
    }
    if (strpos($input, "\0") !== false) {
        return null;
    }

    $norm = str_replace('\\', '/', $input);
    foreach (explode('/', $norm) as $segment) {
        if ($segment === '..' || $segment === '.') {
            return null;
        }
    }
    $norm = rtrim(preg_replace('#(?<!^)/{2,}#', '/', $norm), '/');
    $baseNorm = rtrim(str_replace('\\', '/', $base), '/');

    $isWindows = PHP_OS_FAMILY === 'Windows';
    $same = $isWindows ? (strcasecmp($norm, $baseNorm) === 0) : ($norm === $baseNorm);
    if ($same) {
        return $base;
    }

    $prefix = $baseNorm . '/';
    $hasPrefix = $isWindows
        ? (strncasecmp($norm, $prefix, strlen($prefix)) === 0)
        : (strncmp($norm, $prefix, strlen($prefix)) === 0);
    if (!$hasPrefix) {
        return null;
    }

    // Rebuild from the canonical base so the returned path always starts with __DIR__ exactly.
    $rest = substr($norm, strlen($prefix));
    return $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rest);
}

function path_key(?string $s): string {
    $s = str_replace('\\', '/', (string)$s);
    $s = rtrim($s, '/');
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Look up Folder rows (plus this user's rating) for many paths in ONE query.
 *
 * Match rules:
 *  1. Exact FolderPath match.
 *  2. Otherwise, fall back to FolderName (the folder's basename) but only when exactly one
 *     row has that name AND that row is not owned by another folder that still exists on
 *     disk. This keeps old rows (saved before paths were used as the key) working, without
 *     two different "Book 1" folders sharing the same row.
 *
 * Returns [inputPath => row]; paths with no match are absent.
 */
function find_folder_rows(mysqli $mysqli, array $paths, int $userId = 0): array {
    $paths = array_values(array_unique(array_filter($paths, function ($p) { return (string)$p !== ''; })));
    if (count($paths) === 0) {
        return [];
    }

    $names = array_values(array_unique(array_map('basename', $paths)));
    $pathMarks = implode(',', array_fill(0, count($paths), '?'));
    $nameMarks = implode(',', array_fill(0, count($names), '?'));

    $sql = "
        SELECT f.FolderId, f.FolderPath, f.FolderName, f.BookName, f.Url, f.RateCount, f.Rate,
               NULLIF(uf.Rating, 0) AS MyRate, f.Author, f.Category,
               YEAR(f.PublicationDate) AS PubYear, f.PublicationDate
        FROM Folder f
        LEFT JOIN UserFolder uf ON uf.FolderId = f.FolderId AND uf.UserId = ?
        WHERE f.FolderPath IN ($pathMarks) OR f.FolderName IN ($nameMarks)
        ORDER BY f.FolderId
    ";
    $previousCharset = use_folder_charset($mysqli);
    try {
        $stmt = $mysqli->prepare($sql);
        $params = array_merge([$userId], $paths, $names);
        $types = 'i' . str_repeat('s', count($paths) + count($names));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $byPath = [];
        $byName = [];
        while ($row = $res->fetch_assoc()) {
            $pk = path_key($row['FolderPath']);
            if ($pk !== '' && !isset($byPath[$pk])) {
                $byPath[$pk] = $row;
            }
            $byName[mb_strtolower((string)$row['FolderName'], 'UTF-8')][] = $row;
        }
        $stmt->close();
    } finally {
        restore_charset($mysqli, $previousCharset);
    }

    $out = [];
    foreach ($paths as $p) {
        $pk = path_key($p);
        if (isset($byPath[$pk])) {
            $out[$p] = $byPath[$pk];
            continue;
        }
        $candidates = $byName[mb_strtolower(basename($p), 'UTF-8')] ?? [];
        if (count($candidates) === 1) {
            $storedPath = (string)($candidates[0]['FolderPath'] ?? '');
            if ($storedPath === '' || !is_dir($storedPath)) {
                $out[$p] = $candidates[0];
            }
        }
    }
    return $out;
}

function get_folder_id_for_path(mysqli $mysqli, string $folderPath): int {
    $rows = find_folder_rows($mysqli, [$folderPath]);
    return isset($rows[$folderPath]) ? (int)$rows[$folderPath]['FolderId'] : 0;
}

function get_folder_path_for_id(mysqli $mysqli, int $folderId): string {
    if ($folderId <= 0) {
        return '';
    }
    $previousCharset = use_folder_charset($mysqli);
    try {
        $stmt = $mysqli->prepare('SELECT FolderPath FROM Folder WHERE FolderId = ? LIMIT 1');
        $stmt->bind_param('i', $folderId);
        $stmt->execute();
        $stmt->bind_result($folderPath);
        $path = '';
        if ($stmt->fetch()) {
            $path = (string)$folderPath;
        }
        $stmt->close();
    } finally {
        restore_charset($mysqli, $previousCharset);
    }
    return $path;
}

function apply_folder_info(array $row, array &$folderObject): void {
    $url = (string)($row['Url'] ?? '');
    $rate = $row['Rate'];
    $folderObject['TitleUrl'] = encodeText($url);
    $folderObject['Title'] = encodeText((string)($row['BookName'] ?? ''));
    $folderObject['MyRating'] = $row['MyRate'] !== null ? $row['MyRate'] . "" : "";
    $folderObject['TitleRating'] = is_numeric($rate) ? round((float)$rate, 1) . "" : (string)$rate;
    $folderObject['RateCount'] = (string)($row['RateCount'] ?? '');
    $folderObject['Author'] = encodeText((string)($row['Author'] ?? ''));
    $folderObject['Category'] = encodeText((string)($row['Category'] ?? ''));
    $folderObject['PubYear'] = (string)($row['PubYear'] ?? '');
    $folderObject['PubDate'] = (string)($row['PublicationDate'] ?? '');
}

function encodeText($s) {
    if ($s == null || $s == "") {
        return "";
    } else {
        // Database strings are already UTF-8 (connection uses utf8mb4); this mainly matters
        // for file and folder names coming from the filesystem.
        $encoding = mb_detect_encoding($s, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding === false) {
            return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        } else {
            return mb_convert_encoding($s, 'UTF-8', $encoding);
        }
    }
}

function ensure_userfolder_row(mysqli $mysqli, int $userId, int $folderId): void {
    // Ensure a row exists so we can store IsFave / progress even if no rating was made.
    // Rating stays NULL until the user actually rates (requires Migration.sql).
    $stmt = $mysqli->prepare('
        INSERT INTO UserFolder (UserId, FolderId, Rating, IsFave, DateRated)
        VALUES (?, ?, NULL, b\'0\', NULL)
        ON DUPLICATE KEY UPDATE UserId = UserId
    ');
    $stmt->bind_param('ii', $userId, $folderId);
    $stmt->execute();
    $stmt->close();
}

function upsert_userfolder_progress(mysqli $mysqli, int $userId, int $folderId, int $timeSeconds, string $fileUrl): void {
    if ($userId <= 0 || $folderId <= 0) return;

    ensure_userfolder_row($mysqli, $userId, $folderId);

    $stmt = $mysqli->prepare('
        UPDATE UserFolder
        SET LastTimeSeconds = ?, LastFileUrl = ?
        WHERE UserId = ? AND FolderId = ?
    ');
    $stmt->bind_param('isii', $timeSeconds, $fileUrl, $userId, $folderId);
    $stmt->execute();
    $stmt->close();
}

/** Resolve the folderPath parameter for endpoints that only look it up in the DB. */
function require_folder_param(string $raw): string {
    if ($raw === '') {
        json_fail(400, 'Missing folderPath');
    }
    $path = resolve_library_path($raw);
    if ($path === null) {
        json_fail(400, 'Invalid folderPath');
    }
    return $path;
}

$mode = $_REQUEST['mode'] ?? '';

// Require authentication for all API modes except authStatus
if ($mode !== '' && $mode !== 'authStatus' && !$isAuthenticated) {
    json_fail(401, 'Authentication required');
}

if ($mode !== '') {
    reject_cross_site_requests();
}

if ($mode === 'json') {
    $baseFolder = __DIR__;
    $userId = get_logged_in_user_id();

    // Anything outside the app folder (or not a directory) falls back to the root.
    $folderPath = resolve_library_path((string)($_POST['folderPath'] ?? ''));
    if ($folderPath === null || !is_dir($folderPath)) {
        $folderPath = $baseFolder;
    }

    $result = [
        "CurrentPath" => encodeText($folderPath),
        "CurrentFolder" => "",
        "Title" => "",
        "TitleUrl" => "",
        "TitleRating" => "",
        "MyRating" => "",
        "RateCount" => "",
        "Author" => "",
        "Category" => "",
        "PubYear" => "",
        "PubDate" => "",
        "Subfolders" => [],
        "Mp3Files" => []
    ];

    try {
        if (strlen($folderPath) > strlen($baseFolder)) {
            $rel = substr($folderPath, strlen($baseFolder) + 1);
            $result['CurrentFolder'] = encodeText(PHP_OS_FAMILY === 'Windows' ? str_replace("\\", "/", $rel) : $rel);
        }

        $subfolderObjects = [];
        $subfolders = array_filter(glob($folderPath . '/*') ?: [], 'is_dir');

        foreach ($subfolders as $subfolder) {
            if (in_array(basename($subfolder), HIDDEN_FOLDER_NAMES, true)) {
                continue;
            }

            $hasSubfolders = count(array_filter(glob($subfolder . '/*') ?: [], 'is_dir')) > 0;
            $hasMp3 = count(glob($subfolder . '/*.mp3') ?: []) > 0;

            if ($hasSubfolders || $hasMp3) {
                $normalizedPath = (PHP_OS_FAMILY === 'Windows') ? str_replace('/', '\\', $subfolder) : $subfolder;
                $subfolderObjects[$normalizedPath] = [
                    "Folder" => encodeText($normalizedPath),
                    "Title" => "",
                    "TitleUrl" => "",
                    "TitleRating" => "",
                    "MyRating" => "",
                    "RateCount" => "",
                    "Author" => "",
                    "Category" => "",
                    "PubYear" => "",
                    "PubDate" => ""
                ];
            }
        }

        if (count($subfolderObjects) === 0) {
            $files = array_merge(
                glob($folderPath . '/*.mp3') ?: [],
                glob($folderPath . '/*.pdf') ?: [],
                glob($folderPath . '/*.txt') ?: []
            );

            $webPaths = [];
            foreach ($files as $file) {
                $sFileName = strtolower(basename($file));
                if ($sFileName !== 'index.txt' && $sFileName !== 'rating.txt') {
                    $rel = substr($file, strlen($folderPath));
                    $rel = ltrim($rel, DIRECTORY_SEPARATOR . '/');
                    $rel = str_replace(['\\', '/'], '/', $rel); // Normalize for web
                    $webPaths[] = encodeText($rel);
                }
            }
            $result['Mp3Files'] = $webPaths;
        }

        // One connection, one query for the current folder and all its subfolders.
        // A database problem only means no metadata; the listing itself still works.
        $mysqli = null;
        try {
            $mysqli = db_connect();
            $rows = find_folder_rows($mysqli, array_merge([$folderPath], array_keys($subfolderObjects)), $userId);

            if (isset($rows[$folderPath])) {
                apply_folder_info($rows[$folderPath], $result);
            }
            foreach ($subfolderObjects as $path => &$obj) {
                if (isset($rows[$path])) {
                    apply_folder_info($rows[$path], $obj);
                }
            }
            unset($obj);
        } catch (Throwable $dbEx) {
            error_log('[Player.php] folder info lookup failed: ' . $dbEx->getMessage());
        } finally {
            if ($mysqli) mysqli_close($mysqli);
        }

        $result['Subfolders'] = array_values($subfolderObjects);

        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($result);
        if ($json === false) {
            error_log('[Player.php] JSON encoding failed: ' . json_last_error_msg());
            echo json_encode(["error" => "Could not encode the folder listing."]);
        } else {
            echo $json;
        }
        exit;
    } catch (Throwable $ex) {
        error_log('[Player.php] ' . $ex->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["error" => "Server error while reading the folder."]);
        exit;
    }

} elseif ($mode === 'basepath') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["basePath" => encodeText(__DIR__), "OS" => PHP_OS_FAMILY]);
    exit;

} elseif ($mode === 'authStatus') {
    header('Content-Type: application/json; charset=utf-8');
    $userEmail = '';
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $userEmail = (string)($_SESSION['user']['email'] ?? '');
    }
    echo json_encode([
        'authenticated' => ($userEmail !== ''),
        'email' => encodeText($userEmail)
    ]);
    exit;

} elseif ($mode === 'getProgress') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $mysqli = null;
    try {
        $mysqli = db_connect();

        $stmt = $mysqli->prepare('SELECT LastFolderId, LastTimeSeconds, LastFileUrl FROM AppUser WHERE UserId = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($lastFolderId, $lastTimeSeconds, $lastFileUrl);

        $folderId = 0;
        $timeSeconds = 0;
        $fileUrl = '';
        if ($stmt->fetch()) {
            $folderId = (int)($lastFolderId ?? 0);
            $timeSeconds = (int)($lastTimeSeconds ?? 0);
            $fileUrl = (string)($lastFileUrl ?? '');
        }
        $stmt->close();

        $folderPath = '';
        if ($folderId > 0) {
            $folderPath = get_folder_path_for_id($mysqli, $folderId);
        }

        echo json_encode([
            'ok' => true,
            'lastFolderId' => $folderId,
            'lastFolderPath' => encodeText($folderPath),
            'lastTimeSeconds' => $timeSeconds,
            'lastFileUrl' => encodeText($fileUrl)
        ]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not load progress.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'getFolderProgress') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $folderPath = require_folder_param((string)($_GET['folderPath'] ?? ''));

    $mysqli = null;
    try {
        $mysqli = db_connect();
        $folderId = get_folder_id_for_path($mysqli, $folderPath);
        if ($folderId <= 0) {
            echo json_encode(['ok' => true, 'lastTimeSeconds' => 0, 'lastFileUrl' => '']);
            exit;
        }

        $stmt = $mysqli->prepare('
            SELECT IFNULL(LastTimeSeconds,0) AS LastTimeSeconds, IFNULL(LastFileUrl, \'\') AS LastFileUrl
            FROM UserFolder
            WHERE UserId = ? AND FolderId = ?
            LIMIT 1
        ');
        $stmt->bind_param('ii', $userId, $folderId);
        $stmt->execute();
        $stmt->bind_result($lastTimeSeconds, $lastFileUrl);

        $timeSeconds = 0;
        $fileUrl = '';
        if ($stmt->fetch()) {
            $timeSeconds = (int)$lastTimeSeconds;
            $fileUrl = (string)$lastFileUrl;
        }
        $stmt->close();

        echo json_encode([
            'ok' => true,
            'folderId' => $folderId,
            'lastTimeSeconds' => $timeSeconds,
            'lastFileUrl' => encodeText($fileUrl)
        ]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not load progress.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'setProgress') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $rawFolderPath = (string)($_POST['folderPath'] ?? '');
    $folderPath = $rawFolderPath === '' ? '' : resolve_library_path($rawFolderPath);
    if ($folderPath === null) {
        json_fail(400, 'Invalid folderPath');
    }
    $timeSeconds = max(0, (int)($_POST['timeSeconds'] ?? 0));
    $fileUrl = mb_substr((string)($_POST['fileUrl'] ?? ''), 0, 2000);

    $mysqli = null;
    try {
        $mysqli = db_connect();
        $folderId = 0;
        if ($folderPath !== '') {
            $folderId = get_folder_id_for_path($mysqli, $folderPath);
        }

        // If we can't resolve folderId, don't overwrite LastFolderId.
        if ($folderId > 0) {
            $stmt = $mysqli->prepare('UPDATE AppUser SET LastFolderId = ?, LastTimeSeconds = ?, LastFileUrl = ? WHERE UserId = ?');
            $stmt->bind_param('iisi', $folderId, $timeSeconds, $fileUrl, $userId);
        } else {
            $stmt = $mysqli->prepare('UPDATE AppUser SET LastTimeSeconds = ?, LastFileUrl = ? WHERE UserId = ?');
            $stmt->bind_param('isi', $timeSeconds, $fileUrl, $userId);
        }
        $stmt->execute();
        $stmt->close();

        // Also save per-folder progress (only when folder is known)
        if ($folderId > 0) {
            upsert_userfolder_progress($mysqli, $userId, $folderId, $timeSeconds, $fileUrl);
        }

        echo json_encode(['ok' => true, 'lastFolderId' => $folderId, 'lastTimeSeconds' => $timeSeconds, 'lastFileUrl' => encodeText($fileUrl)]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not save progress.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'updateFolder') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();

    $folderPath = resolve_library_path((string)($_POST['folderPath'] ?? ''));
    if ($folderPath === null || $folderPath === __DIR__ || !is_dir($folderPath)) {
        json_fail(400, 'Invalid folder.');
    }
    $folderName = basename($folderPath);

    $title = trim((string)($_POST['title'] ?? ''));
    $titleUrl = trim((string)($_POST['titleUrl'] ?? ''));
    $myRating = (string)($_POST['myRating'] ?? '');
    $rate = (string)($_POST['rate'] ?? '');
    $rateCount = (string)($_POST['rateCount'] ?? '');
    $author = trim((string)($_POST['author'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $publicationDate = trim((string)($_POST['publicationDate'] ?? ''));

    // Validation (the URL check also blocks javascript: links from being stored).
    if ($titleUrl !== '' && !preg_match('#^https?://#i', $titleUrl)) {
        json_fail(400, 'Title URL must start with http:// or https://');
    }
    if ($publicationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $publicationDate)) {
        json_fail(400, 'Publication date must be in YYYY-MM-DD format.');
    }

    $myRatingForDb = (is_numeric($myRating) && (float)$myRating > 0 && (float)$myRating <= 5) ? (float)$myRating : null;
    $rateForDb = is_numeric($rate) ? (float)$rate : null;
    $rateCountForDb = is_numeric($rateCount) ? (int)$rateCount : null;
    $publicationDateForDb = ($publicationDate !== '') ? $publicationDate : null;
    $canEditShared = can_edit_shared_metadata();

    $mysqli = null;
    try {
        $mysqli = db_connect();
        use_folder_charset($mysqli);
        $mysqli->begin_transaction();

        $rows = find_folder_rows($mysqli, [$folderPath]);
        $folderId = isset($rows[$folderPath]) ? (int)$rows[$folderPath]['FolderId'] : 0;

        if ($folderId > 0) {
            if ($canEditShared) {
                // Update by FolderId (not FolderName) so same-named folders elsewhere are untouched.
                // FolderPath/FolderName are refreshed so a row matched by name gets its path.
                $stmt = $mysqli->prepare("UPDATE Folder SET FolderPath=?, FolderName=?, BookName=?, Url=?, Rate=?, RateCount=?, Author=?, Category=?, PublicationDate=?, UrlUpdated=NULL WHERE FolderId=?");
                $stmt->bind_param('ssssdisssi', $folderPath, $folderName, $title, $titleUrl, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb, $folderId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            if ($canEditShared) {
                $stmt = $mysqli->prepare("INSERT INTO Folder (FolderPath, FolderName, BookName, Url, Rate, RateCount, Author, Category, PublicationDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssdisss', $folderPath, $folderName, $title, $titleUrl, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb);
            } else {
                // Non-editors still need a row to attach their own rating to.
                $stmt = $mysqli->prepare("INSERT INTO Folder (FolderPath, FolderName) VALUES (?, ?)");
                $stmt->bind_param('ss', $folderPath, $folderName);
            }
            $stmt->execute();
            $folderId = (int)$mysqli->insert_id;
            $stmt->close();
        }

        // Save the user's own rating
        if ($userId > 0 && $folderId > 0) {
            ensure_userfolder_row($mysqli, $userId, $folderId);

            if ($myRatingForDb !== null) {
                $stmt = $mysqli->prepare("UPDATE UserFolder SET Rating = ?, DateRated = NOW() WHERE UserId = ? AND FolderId = ?");
                $stmt->bind_param('dii', $myRatingForDb, $userId, $folderId);
            } else {
                $stmt = $mysqli->prepare("UPDATE UserFolder SET Rating = NULL, DateRated = NULL WHERE UserId = ? AND FolderId = ?");
                $stmt->bind_param('ii', $userId, $folderId);
            }
            $stmt->execute();
            $stmt->close();
        }

        $mysqli->commit();
        echo json_encode(["success" => true, "ok" => true, "sharedSaved" => $canEditShared]);
        exit;
    } catch (Throwable $ex) {
        if ($mysqli) {
            try { $mysqli->rollback(); } catch (Throwable $ignored) {}
        }
        json_fail(500, 'Could not save folder info.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'getFave') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $folderPath = require_folder_param((string)($_GET['folderPath'] ?? ''));

    $mysqli = null;
    try {
        $mysqli = db_connect();
        $folderId = get_folder_id_for_path($mysqli, $folderPath);
        if ($folderId <= 0) {
            echo json_encode(['ok' => true, 'isFave' => false]);
            exit;
        }

        $stmt = $mysqli->prepare('
            SELECT IsFave
            FROM UserFolder
            WHERE UserId = ? AND FolderId = ?
            LIMIT 1
        ');
        $stmt->bind_param('ii', $userId, $folderId);
        $stmt->execute();
        $stmt->bind_result($isFaveBit);

        $isFave = false;
        if ($stmt->fetch()) {
            $isFave = ((int)$isFaveBit) === 1;
        }
        $stmt->close();

        echo json_encode(['ok' => true, 'isFave' => $isFave]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not load bookmark state.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'setFave') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $folderPath = require_folder_param((string)($_POST['folderPath'] ?? ''));
    $isFave = (int)($_POST['isFave'] ?? 0) ? 1 : 0;

    $mysqli = null;
    try {
        $mysqli = db_connect();
        $folderId = get_folder_id_for_path($mysqli, $folderPath);
        if ($folderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Unknown folder']);
            exit;
        }

        ensure_userfolder_row($mysqli, $userId, $folderId);

        $stmt = $mysqli->prepare('
            UPDATE UserFolder
            SET IsFave = ?
            WHERE UserId = ? AND FolderId = ?
        ');
        $stmt->bind_param('iii', $isFave, $userId, $folderId);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['ok' => true, 'isFave' => ($isFave === 1)]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not save bookmark.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'getBookmarks' || $mode === 'getRatings') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        json_fail(401, 'Not authenticated');
    }

    $isBookmarks = ($mode === 'getBookmarks');
    $where = $isBookmarks
        ? 'uf.IsFave = 1'
        : 'uf.Rating IS NOT NULL AND uf.Rating > 0'; // > 0 hides placeholder rows from before Migration.sql

    $mysqli = null;
    try {
        $mysqli = db_connect();
        use_folder_charset($mysqli); // titles/paths come from Folder

        $stmt = $mysqli->prepare("
            SELECT f.FolderId, f.FolderPath, NULLIF(uf.Rating, 0) AS MyRating,
                   IFNULL(f.BookName, f.FolderName) AS BookName, f.Author, f.Rate, f.RateCount,
                   SUBSTRING_INDEX(REPLACE(REPLACE(f.FolderPath,'/','\\\\'), CONCAT('\\\\', f.FolderName), ''), '\\\\', -1) AS ParentName,
                   f.Url
            FROM UserFolder uf
                JOIN Folder f ON f.FolderId = uf.FolderId
            WHERE uf.UserId = ? AND $where
            ORDER BY BookName
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'folderId' => (int)$row['FolderId'],
                'folderPath' => encodeText($row['FolderPath']),
                'myRating' => $row['MyRating'] !== null ? (float)$row['MyRating'] : null,
                'bookName' => encodeText($row['BookName']),
                'author' => encodeText($row['Author']),
                'rate' => $row['Rate'] !== null ? (float)$row['Rate'] : null,
                'rateCount' => $row['RateCount'] !== null ? (int)$row['RateCount'] : null,
                'parentName' => encodeText($row['ParentName']),
                'url' => encodeText($row['Url'])
            ];
        }
        $stmt->close();

        echo json_encode(['ok' => true, ($isBookmarks ? 'bookmarks' : 'ratings') => $items]);
        exit;
    } catch (Throwable $ex) {
        json_fail(500, 'Could not load the list.', $ex);
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode !== '') {
    json_fail(400, 'Unknown mode');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Audiobooks</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F4F6F4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="images/icon192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Literata:opsz,wght@7..72,400;7..72,600&display=swap">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link href="Player.css?v=68" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="Player.js?v=68"></script>

    <?php if ($GOOGLE_CLIENT_ID !== '' && !$isAuthenticated) { ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
            async function onGoogleCredential(resp) {
                const r = await fetch('Auth/GoogleSignIn.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'credential=' + encodeURIComponent(resp.credential)
                });

                const txt = await r.text();
                let payload = null;
                try { payload = JSON.parse(txt); } catch (e) { /* ignore */ }

                if (!r.ok || (payload && payload.ok === false)) {
                    const err = (payload && payload.error) ? payload.error : txt;
                    console.error('Login failed:', r.status, err);
                    alert('Login failed: ' + (err || 'Unknown error'));
                    return;
                }

                window.location.reload();
            }
        </script>
    <?php } ?>
</head>
<body>

    <?php if (!$isAuthenticated) { ?>
    <main id="loginRequired">
        <h1>Audiobooks</h1>
        <p>Sign in to pick up where you left off.</p>
        <?php if ($GOOGLE_CLIENT_ID !== '') { ?>
            <div id="g_id_onload"
                 data-client_id="<?php echo htmlspecialchars($GOOGLE_CLIENT_ID, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                 data-callback="onGoogleCredential"></div>
            <div class="g_id_signin"
                 data-type="standard"
                 data-size="large"
                 data-theme="outline"
                 data-text="sign_in_with"
                 data-shape="rectangular"
                 data-logo_alignment="left"></div>
        <?php } else { ?>
            <p class="login-error">Sign-in isn't configured yet. Add GOOGLE_CLIENT_ID to config.php.</p>
        <?php } ?>
    </main>
    <?php } else { ?>
    <script>window.CAN_EDIT_SHARED = <?php echo can_edit_shared_metadata() ? 'true' : 'false'; ?>;</script>

    <!-- Icon set (referenced with <use href="#i-...">) -->
    <svg xmlns="http://www.w3.org/2000/svg" style="display:none">
        <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.2v13.6a.8.8 0 0 0 1.2.7l10.6-6.8a.8.8 0 0 0 0-1.4L9.2 4.5A.8.8 0 0 0 8 5.2z" fill="currentColor"/></symbol>
        <symbol id="i-pause" viewBox="0 0 24 24"><rect x="6" y="5" width="4.2" height="14" rx="1.2" fill="currentColor"/><rect x="13.8" y="5" width="4.2" height="14" rx="1.2" fill="currentColor"/></symbol>
        <symbol id="i-prev" viewBox="0 0 24 24"><path d="M6.5 5.5v13" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M18.5 6.3v11.4a.7.7 0 0 1-1.1.6L9.6 12.6a.7.7 0 0 1 0-1.2l7.8-5.7a.7.7 0 0 1 1.1.6z" fill="currentColor"/></symbol>
        <symbol id="i-next" viewBox="0 0 24 24"><path d="M17.5 5.5v13" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M5.5 6.3v11.4a.7.7 0 0 0 1.1.6l7.8-5.7a.7.7 0 0 0 0-1.2L6.6 5.7a.7.7 0 0 0-1.1.6z" fill="currentColor"/></symbol>
        <symbol id="i-back30" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.6 13.2A7.6 7.6 0 1 0 7 6.6"/><path d="M7.6 3.2 7 6.6l3.4.8"/></g><text x="12.3" y="16" text-anchor="middle" font-size="7.4" font-weight="700" font-family="system-ui, -apple-system, Segoe UI, sans-serif" fill="currentColor">30</text></symbol>
        <symbol id="i-fwd30" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19.4 13.2A7.6 7.6 0 1 1 17 6.6"/><path d="m16.4 3.2.6 3.4-3.4.8"/></g><text x="11.7" y="16" text-anchor="middle" font-size="7.4" font-weight="700" font-family="system-ui, -apple-system, Segoe UI, sans-serif" fill="currentColor">30</text></symbol>
        <symbol id="i-volume" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9.5h3.4L12 5.6v12.8l-4.6-3.9H4z"/><path class="wave1" d="M15.4 9.3a3.8 3.8 0 0 1 0 5.4"/><path class="wave2" d="M17.9 6.8a7.3 7.3 0 0 1 0 10.4"/></g></symbol>
        <symbol id="i-star" viewBox="0 0 24 24"><path d="m12 3.6 2.6 5.2 5.8.9-4.2 4.1 1 5.7L12 16.8l-5.2 2.7 1-5.7-4.2-4.1 5.8-.9z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
        <symbol id="i-link" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></g></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v10.5"/><path d="m7.5 10 4.5 4.5 4.5-4.5"/><path d="M5 19h14"/></g></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="i-pencil" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 19.5l1-4.4L15.6 5a2.1 2.1 0 0 1 3 3L8.5 18.4z"/><path d="m13.6 7 3 3"/></g></symbol>
        <symbol id="i-saved" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" fill="currentColor"/><path d="M12 7.8v7.4M8.9 12.3 12 15.4l3.1-3.1" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="i-external" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 5h5v5"/><path d="m19 5-8 8"/><path d="M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4"/></g></symbol>
    </svg>

    <header class="app-bar">
        <a href="#" class="brand" onclick="loadFolder(basePath); return false;">Audiobooks</a>
        <nav class="app-nav" aria-label="Account">
            <a href="#" id="myBookmarksLink" onclick="openBookmarksDialog(); return false;">Bookmarks</a>
            <a href="#" id="myRatingsLink" onclick="openRatingsDialog(); return false;">Ratings</a>
            <span class="account"><?php echo htmlspecialchars((string)$_SESSION['user']['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
            <a href="Auth/Logout.php">Log out</a>
        </nav>
    </header>

    <main class="page">
        <nav id="breadcrumb" class="breadcrumb" aria-label="Folder path"></nav>
        <div id="ratings" class="book-header"></div>

        <section id="playerControl" class="player" style="display:none" aria-label="Player">
            <!-- The select keeps the track order/state for the script; the chapter list is the visible picker. -->
            <select id="trackSelector" hidden aria-hidden="true" tabindex="-1"></select>
            <audio id="audioPlayer" preload="metadata"></audio>

            <p class="np-chapter" id="npIndex"></p>
            <h2 class="np-title" id="npTitle"></h2>

            <div class="scrubber">
                <input type="range" id="seekBar" min="0" max="1000" step="1" value="0" aria-label="Position in chapter">
                <div class="times"><span id="timeElapsed">0:00</span><span id="timeRemaining">-0:00</span></div>
            </div>

            <div class="transport">
                <button type="button" class="tbtn" id="prevTrackButton" aria-label="Previous chapter" title="Previous chapter"><svg class="icon"><use href="#i-prev"/></svg></button>
                <button type="button" class="tbtn" onclick="goBackSec(30)" aria-label="Back 30 seconds" title="Back 30 seconds"><svg class="icon icon-lg"><use href="#i-back30"/></svg></button>
                <button type="button" class="tbtn play" id="playPauseButton" data-state="paused" aria-label="Play" title="Play">
                    <svg class="icon i-play"><use href="#i-play"/></svg><svg class="icon i-pause"><use href="#i-pause"/></svg>
                </button>
                <button type="button" class="tbtn" onclick="goForwardSec(30)" aria-label="Forward 30 seconds" title="Forward 30 seconds"><svg class="icon icon-lg"><use href="#i-fwd30"/></svg></button>
                <button type="button" class="tbtn" id="nextTrackButton" aria-label="Next chapter" title="Next chapter"><svg class="icon"><use href="#i-next"/></svg></button>
            </div>

            <div class="actions">
                <button type="button" class="act" id="speedButton" title="Playback speed"><span class="act-icon speed-value">1×</span><span class="act-label">Speed</span></button>
                <button type="button" class="act" id="volumeButton" data-level="0" title="Volume boost"><svg class="icon act-icon"><use href="#i-volume"/></svg><span class="act-label">Normal</span></button>
                <button type="button" class="act" id="faveStar" aria-pressed="false" title="Bookmark this book"><svg class="icon act-icon"><use href="#i-star"/></svg><span class="act-label">Bookmark</span></button>
                <button type="button" class="act" onclick="shareLink()" title="Copy a link to this book"><svg class="icon act-icon"><use href="#i-link"/></svg><span class="act-label">Share</span></button>
                <button type="button" class="act" id="btnCacheFolder" onclick="cacheFolder()" title="Save all chapters for offline listening"><svg class="icon act-icon i-dl"><use href="#i-download"/></svg><svg class="icon act-icon i-done"><use href="#i-check"/></svg><span class="act-label">Download</span></button>
                <button type="button" class="act" id="editButton" onclick="OpenFolderDialog(false)" title="Edit book info"><svg class="icon act-icon"><use href="#i-pencil"/></svg><span class="act-label">Edit</span></button>
            </div>
        </section>

        <div id="content"></div>
    </main>

    <!-- Compact player shown when the main controls are scrolled out of view -->
    <div id="miniPlayer" class="mini" hidden>
        <div class="mini-progress"><span id="miniProgress"></span></div>
        <button type="button" class="mini-info" id="miniInfo" title="Show player">
            <span class="mini-title" id="miniTitle"></span>
            <span class="mini-sub" id="miniSub"></span>
        </button>
        <button type="button" class="tbtn play small" id="miniPlayButton" data-state="paused" aria-label="Play">
            <svg class="icon i-play"><use href="#i-play"/></svg><svg class="icon i-pause"><use href="#i-pause"/></svg>
        </button>
    </div>

    <div class="toast share-link-feedback" role="status" style="display: none">Link copied</div>

    <div id="spinnerContainer" class="spinner-container"><div class="spinner"></div></div>

    <dialog id="editFolderModal">
        <button type="button" class="dialog-close" onclick="CloseFolderDialog()" aria-label="Close">&times;</button>
        <h3>Edit book info</h3>
        <p class="dialog-sub" id="editModalHeader"></p>
        <form id="editFolderForm" onsubmit="SaveFolderDialog(); return false;">
            <input type="hidden" id="editFolderPath" name="folderPath">
            <label class="field wide">Title
                <input type="text" id="editTitle" name="title"></label>
            <label class="field wide">Book page URL
                <input type="text" id="editTitleUrl" name="titleUrl" inputmode="url" placeholder="https://"></label>
            <label class="field">Author
                <input type="text" id="editAuthor" name="author"></label>
            <label class="field">Category
                <input type="text" id="editCategory" name="category"></label>
            <label class="field">Public rating
                <input type="number" id="editRate" name="rate" step="0.1" min="0" max="5"></label>
            <label class="field">Number of ratings
                <input type="number" id="editRateCount" name="rateCount" min="0"></label>
            <label class="field">Published
                <input type="date" id="editPublicationDate" name="publicationDate"></label>
            <label class="field">My rating
                <select id="editMyRating" name="myRating">
                    <option value="">Not rated</option>
                    <option value="5.0">5.0</option>
                    <option value="4.5">4.5</option>
                    <option value="4.0">4.0</option>
                    <option value="3.5">3.5</option>
                    <option value="3.0">3.0</option>
                    <option value="2.5">2.5</option>
                    <option value="2.0">2.0</option>
                    <option value="1.5">1.5</option>
                    <option value="1.0">1.0</option>
                </select></label>
            <div id="editFolderMsg" class="wide" role="alert"></div>
            <div class="dialog-buttons wide">
                <button type="button" class="btn-secondary" onclick="CloseFolderDialog()">Cancel</button>
                <button type="submit" class="btn-primary">Save changes</button>
            </div>
        </form>
    </dialog>

    <dialog id="bookmarksModal">
        <button type="button" class="dialog-close" onclick="closeBookmarksDialog()" aria-label="Close">&times;</button>
        <h3>Bookmarks</h3>
        <div id="bookmarksContent">
            <p>Loading...</p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn-secondary" onclick="closeBookmarksDialog()">Close</button>
        </div>
    </dialog>
    <?php } ?>

</body>
</html>
