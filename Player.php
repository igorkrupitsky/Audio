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

// Check if user is authenticated
$isAuthenticated = isset($_SESSION['user']) && is_array($_SESSION['user']) && (string)($_SESSION['user']['email'] ?? '') !== '';

function get_logged_in_user_id(): int {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return 0;
    }
    $uid = (int)($_SESSION['user']['userId'] ?? 0);
    return $uid > 0 ? $uid : 0;
}

function db_connect(): mysqli {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $mysqli = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function get_folder_id_for_path(mysqli $mysqli, string $folderPath): int {
    // Folder table is keyed by FolderName (basename) in this app.
    $folderName = basename($folderPath);
    if ($folderName === '') {
        return 0;
    }

    $stmt = $mysqli->prepare('SELECT FolderId FROM Folder WHERE FolderName = ? LIMIT 1');
    $stmt->bind_param('s', $folderName);
    $stmt->execute();
    $stmt->bind_result($folderId);
    $id = 0;
    if ($stmt->fetch()) {
        $id = (int)$folderId;
    }
    $stmt->close();
    return $id;
}

function get_folder_path_for_id(mysqli $mysqli, int $folderId): string {
    if ($folderId <= 0) {
        return '';
    }
    $stmt = $mysqli->prepare('SELECT FolderPath FROM Folder WHERE FolderId = ? LIMIT 1');
    $stmt->bind_param('i', $folderId);
    $stmt->execute();
    $stmt->bind_result($folderPath);
    $path = '';
    if ($stmt->fetch()) {
        $path = (string)$folderPath;
    }
    $stmt->close();
    return $path;
}

function setFolderInfo($folderPath, &$folderObject, $userId = 0) {
    // Use MySQL database instead of reading from files
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $host = $DB_HOST;
    $db = $DB_NAME;
    $user = $DB_USER;
    $pass = $DB_PASS;

    // Extract folder name from path
    $folderName = basename($folderPath);

    $mysqli = null;
    try {
        $mysqli = mysqli_connect($host, $user, $pass, $db);
        
        if ($userId > 0) {
            // Join with UserFolder to get user's rating
            $stmt = $mysqli->prepare("
                SELECT f.BookName, f.Url, f.RateCount, f.Rate, uf.Rating AS MyRate, f.Author, f.Category, 
                       YEAR(f.PublicationDate) AS PubYear, f.PublicationDate 
                FROM Folder f
                LEFT JOIN UserFolder uf ON uf.FolderId = f.FolderId AND uf.UserId = ?
                WHERE f.FolderName = ? 
                LIMIT 1
            ");
            $stmt->bind_param('is', $userId, $folderName);
        } else {
            // No user logged in, don't fetch user rating
            $stmt = $mysqli->prepare("
                SELECT BookName, Url, RateCount, Rate, NULL AS MyRate, Author, Category, 
                       YEAR(PublicationDate) AS PubYear, PublicationDate 
                FROM Folder 
                WHERE FolderName = ? 
                LIMIT 1
            ");
            $stmt->bind_param('s', $folderName);
        }
        
        $stmt->execute();
        $stmt->bind_result($title, $url, $rateCount, $rate, $myRate, $Author, $Category, $PubYear, $PubDate);
        if ($stmt->fetch()) {
            $folderObject['TitleUrl'] = encodeText($url);
            $folderObject['Title'] = encodeText($title);
            $folderObject['MyRating'] = $myRate !== null ? $myRate . "" : "";
            $folderObject['TitleRating'] = is_numeric($rate) ? round($rate, 1) . "" : $rate . "";
            $folderObject['RateCount'] = $rateCount . "";
            $folderObject['Author'] = encodeText($Author . "");
            $folderObject['Category'] = encodeText($Category . "");
            $folderObject['PubYear'] = $PubYear . "";
            $folderObject['PubDate'] = $PubDate . "";
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        // Optionally log error or handle as needed
    } finally {
        if ($mysqli) {
            mysqli_close($mysqli);
        }
    }
}

function encodeText($s) {
    if ($s == null || $s == "") { 
        return "";
    } else {
        // First try to detect the current encoding
        $encoding = mb_detect_encoding($s, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding === false) {
            // If detection fails, assume it's already UTF-8 or try to clean it
            return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        } else {
            // Convert from detected encoding to UTF-8
            return mb_convert_encoding($s, 'UTF-8', $encoding);
        }
    }
}

function ensure_userfolder_row(mysqli $mysqli, int $userId, int $folderId): void {
    // Ensure a row exists so we can store IsFave even if no rating was made.
    // Use neutral defaults for required columns.
    $stmt = $mysqli->prepare('
        INSERT INTO UserFolder (UserId, FolderId, Rating, IsFave, DateRated)
        VALUES (?, ?, 0, b\'0\', NOW())
        ON DUPLICATE KEY UPDATE UserId = UserId
    ');
    $stmt->bind_param('ii', $userId, $folderId);
    $stmt->execute();
    $stmt->close();
}

// NEW: upsert per-folder progress
function upsert_userfolder_progress(mysqli $mysqli, int $userId, int $folderId, int $timeSeconds, string $fileUrl): void {
    if ($userId <= 0 || $folderId <= 0) return;

    // Ensure row exists so UPDATE always works
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

$mode = $_REQUEST['mode'] ?? '';

// Require authentication for all API modes except authStatus
if ($mode !== '' && $mode !== 'authStatus' && !$isAuthenticated) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

if ($mode === 'json') {
    $folderPath = $_POST['folderPath'] ?? '';
    $baseFolder = dirname(__FILE__);
    $userId = get_logged_in_user_id();

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
        if (!is_dir($folderPath)) {
            $folderPath = $baseFolder;
            $result['CurrentPath'] = encodeText($folderPath);
        } else {
            if (strlen($folderPath) > strlen($baseFolder)) {
                $rel = substr($folderPath, strlen($baseFolder) + 1);
                $result['CurrentFolder'] = encodeText(PHP_OS_FAMILY === 'Windows' ? str_replace("\\", "/", $rel) : $rel);
            }
        }

        if (is_dir($folderPath)) {
            $subfolders = array_filter(glob($folderPath . '/*'), 'is_dir');

            foreach ($subfolders as $subfolder) {
                $hasSubfolders = count(array_filter(glob($subfolder . '/*'), 'is_dir')) > 0;
                $hasMp3 = count(glob($subfolder . '/*.mp3')) > 0;

                if ($hasSubfolders || $hasMp3) {
                    $normalizedPath = (PHP_OS_FAMILY === 'Windows') ? str_replace('/', '\\', $subfolder) : $subfolder;

                    $folderObject = [
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

                    setFolderInfo($subfolder, $folderObject, $userId);
                    $result['Subfolders'][] = $folderObject;
                }
            }

            if (count($result['Subfolders']) === 0) {
                $files = array_merge(
                    glob($folderPath . '/*.mp3'),
                    glob($folderPath . '/*.pdf'),
                    glob($folderPath . '/*.txt')
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
                setFolderInfo($folderPath, $result, $userId);
            }
        }

        header('Content-Type: application/json');

        $json = json_encode($result);
        if ($json === false) {
            echo json_encode(["error" => "JSON encoding failed: " . json_last_error_msg()]);
        } else {
            echo $json;
        }

        exit;
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(["error" => "Server error: " . str_replace('"', "'", $ex->getMessage())]);
        exit;
    }

} elseif ($mode === 'basepath') {
    echo json_encode(["basePath" => encodeText(dirname(__FILE__)), "OS" => PHP_OS_FAMILY]);
    exit;

} elseif ($mode === 'authStatus') {
    header('Content-Type: application/json');
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
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
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
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) {
            mysqli_close($mysqli);
        }
    }

} elseif ($mode === 'getFolderProgress') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $folderPath = (string)($_GET['folderPath'] ?? '');
    if ($folderPath === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing folderPath']);
        exit;
    }

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
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'setProgress') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $folderPath = (string)($_POST['folderPath'] ?? '');
    $timeSeconds = (int)($_POST['timeSeconds'] ?? 0);
    $fileUrl = (string)($_POST['fileUrl'] ?? '');

    if ($timeSeconds < 0) {
        $timeSeconds = 0;
    }

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

        // ALSO save per-folder progress (only when folder is known)
        if ($folderId > 0) {
            upsert_userfolder_progress($mysqli, $userId, $folderId, $timeSeconds, $fileUrl);
        }

        echo json_encode(['ok' => true, 'lastFolderId' => $folderId, 'lastTimeSeconds' => $timeSeconds, 'lastFileUrl' => encodeText($fileUrl)]);
        exit;
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) {
            mysqli_close($mysqli);
        }
    }

} elseif ($mode === 'updateFolder') {
    $folderPath = $_POST['folderPath'] ?? '';
    $title = $_POST['title'] ?? '';
    $titleUrl = $_POST['titleUrl'] ?? '';
    $myRating = $_POST['myRating'] ?? '';
    $rate = $_POST['rate'] ?? '';
    $rateCount = $_POST['rateCount'] ?? '';
    $author = $_POST['author'] ?? '';
    $category = $_POST['category'] ?? '';
    $publicationDate = $_POST['publicationDate'] ?? '';
    $folderName = basename($folderPath);

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $host = $DB_HOST;
    $db = $DB_NAME;
    $user = $DB_USER;
    $pass = $DB_PASS;

    $userId = get_logged_in_user_id();

    $mysqli = null;
    try {
        $mysqli = mysqli_connect($host, $user, $pass, $db);
        // Check if record exists
        $stmt = $mysqli->prepare("SELECT FolderId, COUNT(*) as cnt FROM Folder WHERE FolderName = ? GROUP BY FolderId");
        $stmt->bind_param('s', $folderName);
        $stmt->execute();
        $stmt->bind_result($folderId, $count);
        $found = $stmt->fetch();
        $stmt->close();

        $myRatingForDb = (is_numeric($myRating) && $myRating !== '') ? (float)$myRating : null;
        $rateForDb = (is_numeric($rate) && $rate !== '') ? $rate : null;
        $rateCountForDb = (is_numeric($rateCount) && $rateCount !== '') ? $rateCount : null;
        $publicationDateForDb = ($publicationDate !== '') ? $publicationDate : null; //2000-12-08
        
        if ($found && $count > 0) {
            $stmt = $mysqli->prepare("UPDATE Folder SET FolderPath=?, BookName=?, Url=?, Rate=?, RateCount=?, Author=?, Category=?, PublicationDate=?, UrlUpdated=NULL WHERE FolderName=?");
            $stmt->bind_param('ssssissss', $folderPath, $title, $titleUrl, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb, $folderName);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare("INSERT INTO Folder (FolderPath, FolderName, BookName, Url, Rate, RateCount, Author, Category, PublicationDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssdisss', $folderPath, $folderName, $title, $titleUrl, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb);
            $stmt->execute();
            $folderId = $mysqli->insert_id;
            $stmt->close();
        }

        // Save user's rating to UserFolder if user is logged in
        if ($userId > 0 && $folderId > 0) {
            ensure_userfolder_row($mysqli, $userId, $folderId);
            
            if ($myRatingForDb !== null) {
                $stmt = $mysqli->prepare("UPDATE UserFolder SET Rating = ?, DateRated = NOW() WHERE UserId = ? AND FolderId = ?");
                $stmt->bind_param('dii', $myRatingForDb, $userId, $folderId);
            } else {
                // Clear the rating if empty
                $stmt = $mysqli->prepare("UPDATE UserFolder SET Rating = NULL WHERE UserId = ? AND FolderId = ?");
                $stmt->bind_param('ii', $userId, $folderId);
            }
            $stmt->execute();
            $stmt->close();
        }
        
        mysqli_close($mysqli);
        echo json_encode(["success" => true]);
    } catch (Exception $ex) {
        if ($mysqli) mysqli_close($mysqli);
        http_response_code(500);
        echo json_encode(["success" => false, "error" => str_replace('"', "'", $ex->getMessage())]);

    }
    exit;
} elseif ($mode === 'getFave') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $folderPath = (string)($_GET['folderPath'] ?? '');
    if ($folderPath === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing folderPath']);
        exit;
    }

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
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'setFave') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $folderPath = (string)($_POST['folderPath'] ?? '');
    $isFave = (int)($_POST['isFave'] ?? 0) ? 1 : 0;

    if ($folderPath === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing folderPath']);
        exit;
    }

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
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'getBookmarks') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $mysqli = null;
    try {
        $mysqli = db_connect();

        $stmt = $mysqli->prepare("
            SELECT f.FolderId, f.FolderPath, uf.Rating as MyRating, 
                   IFNULL(f.BookName, f.FolderName) AS BookName, f.Author, f.Rate, f.RateCount,
                   SUBSTRING_INDEX(REPLACE(REPLACE(f.FolderPath,'/','\\\\'), CONCAT('\\\\', f.FolderName), ''), '\\\\', -1) AS ParentName, 
                   f.Url
            FROM UserFolder uf
                JOIN Folder f ON f.FolderId = uf.FolderId	
            WHERE uf.IsFave = 1
                AND uf.UserId = ?
            ORDER BY BookName
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $bookmarks = [];
        while ($row = $result->fetch_assoc()) {
            $bookmarks[] = [
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

        echo json_encode(['ok' => true, 'bookmarks' => $bookmarks]);
        exit;
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }

} elseif ($mode === 'getRatings') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = get_logged_in_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $mysqli = null;
    try {
        $mysqli = db_connect();

        $stmt = $mysqli->prepare("
            SELECT f.FolderId, f.FolderPath, uf.Rating as MyRating,
                   IFNULL(f.BookName, f.FolderName) AS BookName, f.Author, f.Rate, f.RateCount,
                   SUBSTRING_INDEX(REPLACE(REPLACE(f.FolderPath,'/','\\\\'), CONCAT('\\\\', f.FolderName), ''), '\\\\', -1) AS ParentName,
                   f.Url
            FROM UserFolder uf
                JOIN Folder f ON f.FolderId = uf.FolderId
            WHERE uf.UserId = ?
              AND uf.Rating IS NOT NULL
            ORDER BY BookName
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $ratings = [];
        while ($row = $result->fetch_assoc()) {
            $ratings[] = [
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

        echo json_encode(['ok' => true, 'ratings' => $ratings]);
        exit;
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    } finally {
        if ($mysqli) mysqli_close($mysqli);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MP3 Player</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <?php if ($isAuthenticated) { ?>
    <script src="Player.js?v=62"></script>
    <?php } ?>
    <link href="Player.css?v=62" rel="stylesheet" />
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1976d2">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="images/icon192.png">

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
    <!-- Login Required Screen -->
    <div id="loginRequired" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 80vh; text-align: center;">
        <h1>MP3 Player</h1>
        <p style="margin-bottom: 20px; color: #666;">Please sign in to continue</p>
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
            <p style="color: red;">Login is not configured. Please contact the administrator.</p>
        <?php } ?>
    </div>
    <?php } else { ?>
    <!-- Authenticated User Content -->
    <form id="form1">
        <div id="topMenu" style="display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-bottom:8px;">
            <a href="#" id="myBookmarksLink" onclick="openBookmarksDialog(); return false;" style="font-size: 12px;">My Bookmarks</a>
            <a href="#" id="myRatingsLink" onclick="openRatingsDialog(); return false;" style="font-size: 12px;">My Ratings</a>
            <span style="font-size: 12px; color:#666;"><strong><?php echo htmlspecialchars((string)$_SESSION['user']['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong></span>
            <a href="Auth/Logout.php" style="font-size: 12px;">Log out</a>
        </div>

        <div id="breadcrumb" class="breadcrumb"></div>        
        <div id="ratings"></div>

        <div id="playerControl" style="display:none">
            <select id="trackSelector"></select>
            <audio id="audioPlayer" controls></audio>

            <div class="controls">
                <button type="button" class="pl-btn" onclick="goBackSec(-30)">-30</button>
                <button type="button" class="pl-btn" onclick="goForwardSec(30)">+30</button>
                <button type="button" class="pl-btn" onclick="cacheFolder()" id="btnCacheFolder"> Cache</button>
                <button type="button" class="pl-btn" id="playPauseButton" title="Play/Pause">▶︎</button>
                <button type="button" class="pl-btn" id="volumeButton" title="Volume">🔈</button>
                <button type="button" class="pl-btn" onclick="OpenFolderDialog(false)" id="editButton" title="Volume">&#x270E;</button>
                <button type="button" class="pl-btn" onclick="shareLink()">&#128279;</button>

                <span id="faveStar" title='Star this audiobook' style="cursor: pointer; margin-left: 8px; user-select: none">☆</span>
                <span class="share-link-feedback" style="display: none">Link copied!</span>
  
            </div>
        </div>

        <div id="content"></div>
    </form>
        
    <div id="spinnerContainer" class="spinner-container"><div class="spinner"></div></div>

    <dialog id="editFolderModal" style="width: 800px; position: relative; max-height: 80vh; overflow-y: auto;">
        <div style="margin-right: 25px;">
            <span onclick="CloseFolderDialog()" style="position: absolute; top: 10px; right: 30px; font-size: 22px; font-weight: bold; color: #888; cursor: pointer; z-index: 10;" title="Close">&times;</span>
            <h3 id="editModalHeader">Edit Folder Info</h3>
            <form id="editFolderForm">
                <input type="hidden" id="editFolderPath" name="folderPath">
                <div style="margin-bottom: 12px;">
                    <label>Title:<br>
                    <input type="text" id="editTitle" name="title" style="width:100%; padding: 6px;"></label>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Title URL:<br>
                    <input type="text" id="editTitleUrl" name="titleUrl" style="width:100%; padding: 6px;"></label>
                </div>
                <table style="width: 100%; margin-top: 15px;">
                    <tr>
                        <td style="width: 50%; padding-right: 10px; vertical-align: top;">
                            <label>My Rating:<br>
                            <select id="editMyRating" name="myRating" style="width:100%; padding: 6px;">
                                <option value=""></option>    
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
                        </td>
                        <td style="width: 50%; padding-left: 10px; vertical-align: top;">
                            <label>Author:<br>
                            <input type="text" id="editAuthor" name="author" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-right: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Public Rating:<br>
                            <input type="number" id="editRate" name="rate" step="0.1" min="0" max="5" style="width:100%; padding: 6px;"></label>
                        </td>
                        <td style="padding-left: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Category:<br>
                            <input type="text" id="editCategory" name="category" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-right: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Rate Count:<br>
                            <input type="number" id="editRateCount" name="rateCount" min="0" style="width:100%; padding: 6px;"></label>
                        </td>
                        <td style="padding-left: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Publication Date:<br>
                            <input type="date" id="editPublicationDate" name="publicationDate" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                </table>
                <div style="text-align:right;">
                    <button type="button" onclick="SaveFolderDialog()">Save</button>
                    <button type="button" onclick="CloseFolderDialog()" style="background-color:gray">Cancel</button>
                </div>
                <div id="editFolderMsg"></div>
            </form>
        </div>
    </dialog>

    <dialog id="bookmarksModal">
        <span onclick="closeBookmarksDialog()" style="position: absolute; top: 10px; right: 15px; font-size: 22px; font-weight: bold; color: #888; cursor: pointer; z-index: 10;" title="Close">&times;</span>
        <h3>My Bookmarks</h3>
        <div id="bookmarksContent">
            <p>Loading...</p>
        </div>
        <div class="dialog-footer">
            <button type="button" onclick="closeBookmarksDialog()">Close</button>
        </div>
    </dialog>

    <dialog id="ratingsModal">
        <span onclick="closeRatingsDialog()" style="position: absolute; top: 10px; right: 15px; font-size: 22px; font-weight: bold; color: #888; cursor: pointer; z-index: 10;" title="Close">&times;</span>
        <h3>My Ratings</h3>
        <div id="ratingsContent">
            <p>Loading...</p>
        </div>
        <div class="dialog-footer">
            <button type="button" onclick="closeRatingsDialog()">Close</button>
        </div>
    </dialog>
    <?php } ?>

</body>
</html>