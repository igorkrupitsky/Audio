<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$config = require __DIR__ . '/config.php';
$DB_HOST = $config['DB_HOST'];
$DB_NAME = $config['DB_NAME'];
$DB_USER = $config['DB_USER'];
$DB_PASS = $config['DB_PASS'];

function setFolderInfo($folderPath, &$folderObject) {
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
        $stmt = $mysqli->prepare("SELECT BookName, Url, RateCount, Rate, MyRate, Author, Category, YEAR(PublicationDate) AS PubYear, PublicationDate FROM Folder WHERE FolderName = ? LIMIT 1");
        $stmt->bind_param('s', $folderName);
        $stmt->execute();
        $stmt->bind_result($title, $url, $rateCount, $rate, $myRate, $Author, $Category, $PubYear, $PubDate);
        if ($stmt->fetch()) {
            $folderObject['TitleUrl'] = encodeText($url);
            $folderObject['Title'] = encodeText($title);
            $folderObject['MyRating'] = $myRate . "";
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

$mode = $_REQUEST['mode'] ?? '';
if ($mode === 'json') {
    $folderPath = $_POST['folderPath'] ?? '';
    $baseFolder = dirname(__FILE__);

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

                    setFolderInfo($subfolder, $folderObject);
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
                setFolderInfo($folderPath, $result);
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

    $mysqli = null;
    try {
        $mysqli = mysqli_connect($host, $user, $pass, $db);
        // Check if record exists
        $stmt = $mysqli->prepare("SELECT COUNT(*) FROM Folder WHERE FolderName = ?");
        $stmt->bind_param('s', $folderName);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        $myRatingForDb = (is_numeric($myRating) && $myRating !== '') ? $myRating : null;
        $rateForDb = (is_numeric($rate) && $rate !== '') ? $rate : null;
        $rateCountForDb = (is_numeric($rateCount) && $rateCount !== '') ? $rateCount : null;
        $publicationDateForDb = ($publicationDate !== '') ? $publicationDate : null; //2000-12-08
        
        if ($count > 0) {
            // Update
            $stmt = $mysqli->prepare("UPDATE Folder SET FolderPath=?, BookName=?, Url=?, MyRate=?, Rate=?, RateCount=?, Author=?, Category=?, PublicationDate=?, UrlUpdated=NULL WHERE FolderName=?");
            $stmt->bind_param('sssdsissss', $folderPath, $title, $titleUrl, $myRatingForDb, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb, $folderName);
            $stmt->execute();
            $stmt->close();
        } else {
            // Insert
            $stmt = $mysqli->prepare("INSERT INTO Folder (FolderPath, FolderName, BookName, Url, MyRate, Rate, RateCount, Author, Category, PublicationDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssddisss', $folderPath, $folderName, $title, $titleUrl, $myRatingForDb, $rateForDb, $rateCountForDb, $author, $category, $publicationDateForDb);
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
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MP3 Player</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="Player.js?v=23"></script>
    <link href="Player.css?v=23" rel="stylesheet" />
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1976d2">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="images/icon192.png">
</head>
<body>    
    <form id="form1">
        <div id="breadcrumb" class="breadcrumb"></div>
        
        <div id="shareDiv" style="float: right;  display: none" title="Share Link">
            <a target="_blank" id="shareLink">&#128279;</a>
            <span class="share-link-feedback" style="display: none">Link copied!</span>
            <span id="editButton" title="Edit" onclick="OpenFolderDialog(false)">&#x270E;</span>
        </div>
        
        <div id="ratings"></div>

        <div id="playerControl" style="display:none">
            <select id="trackSelector"></select>
            <audio id="audioPlayer" controls></audio>
            <div class="controls">
                <a onclick="goBack30()" class="player-button"> Go Back 30 seconds</a>
                <a onclick="goUpOneLevel()" class="player-button"> Go Back</a>
                <a onclick="cacheFolder()" class="player-button"> Cache Folder</a>
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

</body>
</html>