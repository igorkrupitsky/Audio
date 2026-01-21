<?php
// GoogleSignIn.php
// Receives a Google Identity Services "credential" (JWT), validates it (RS256), upserts AppUser,
// and starts a PHP session for use by Player.php.
//
// Expected POST: credential=<jwt>
// Response: 200 OK (text/plain) on success; errors are plain text with relevant HTTP codes.

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$config = require __DIR__ . '/../config.php';

$DB_HOST = $config['DB_HOST'] ?? '';
$DB_NAME = $config['DB_NAME'] ?? '';
$DB_USER = $config['DB_USER'] ?? '';
$DB_PASS = $config['DB_PASS'] ?? '';

// Prefer env var when set, but fall back to config.php (common in shared hosting).
// Example: setx GOOGLE_CLIENT_ID "xxxx.apps.googleusercontent.com"
$GOOGLE_CLIENT_ID = getenv('GOOGLE_CLIENT_ID') ?: ($config['GOOGLE_CLIENT_ID'] ?? '');

// Google certs endpoint (kid -> pem cert)
const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$jwt = $_POST['credential'] ?? '';
if ($jwt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing credential']);
    exit;
}

try {
    if ($GOOGLE_CLIENT_ID === '') {
        throw new Exception('Missing GOOGLE_CLIENT_ID');
    }

    $payload = validate_google_jwt($jwt, $GOOGLE_CLIENT_ID);

    $email = isset($payload['email']) ? (string)$payload['email'] : '';
    $email = normalize_email($email);
    if ($email === '') {
        throw new Exception('Invalid: No email');
    }

    $ip = get_client_ip();
    $userId = save_user_and_login($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $email, $ip);

    // Session-based auth (simple and works for PHP side)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // If you serve over HTTPS only, consider session.cookie_secure=1
        session_start();
    }

    $_SESSION['user'] = [
        'userId' => $userId,
        'email' => $email,
        'loginUtc' => gmdate('c')
    ];

    http_response_code(200);
    echo json_encode(['ok' => true, 'email' => $email]);
    exit;

} catch (Exception $ex) {
    $msg = $ex->getMessage();
    if (str_starts_with($msg, 'Invalid:') || $msg === 'Bad JWT' || $msg === 'sig') {
        http_response_code(401);
    } else {
        http_response_code(500);
    }
    // Log server-side details, but keep response minimal.
    error_log('GoogleSignIn.php error: ' . $msg);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function normalize_email(string $email): string {
    $email = trim($email);
    if (strlen($email) > 500) {
        $email = substr($email, 0, 500);
    }
    return strtolower($email);
}

function get_client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = '';

    if ($xff !== '') {
        $parts = explode(',', $xff);
        if (count($parts) > 0) {
            $ip = trim((string)$parts[0]);
        }
    }

    if ($ip === '') {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    if (strlen($ip) > 200) {
        $ip = substr($ip, 0, 200);
    }

    return $ip;
}

function save_user_and_login(string $host, string $user, string $pass, string $db, string $email, string $ip): int {
    $mysqli = mysqli_connect($host, $user, $pass, $db);
    $mysqli->set_charset('utf8mb4');

    try {
        $mysqli->begin_transaction();

        $userId = 0;

        // Lock row if exists (closest MySQL equivalent to updlock/holdlock)
        $stmt = $mysqli->prepare('SELECT UserId FROM AppUser WHERE Email = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($existingUserId);
        if ($stmt->fetch()) {
            $userId = (int)$existingUserId;
        }
        $stmt->close();

        if ($userId <= 0) {
            $stmt = $mysqli->prepare('INSERT INTO AppUser (Email, DateCreated, LastLoginDate) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->close();
            $userId = (int)$mysqli->insert_id;
        } else {
            $stmt = $mysqli->prepare('UPDATE AppUser SET LastLoginDate = UTC_TIMESTAMP() WHERE UserId = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }

        // Optional: if you want parity with the ASP.NET code, create a UserLogin table.
        // If it doesn't exist, we just skip inserting the login record.
        try {
            $stmt = $mysqli->prepare('INSERT INTO UserLogin (UserId, LoginDate, IpAddress) VALUES (?, UTC_TIMESTAMP(), ?)');
            $stmt->bind_param('is', $userId, $ip);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            // Ignore if table doesn't exist.
        }

        $mysqli->commit();
        return $userId;

    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;

    } finally {
        mysqli_close($mysqli);
    }
}

function validate_google_jwt(string $jwt, string $expectedAud): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new Exception('Bad JWT');
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;

    $headerJson = base64url_decode_to_string($headerB64);
    $payloadJson = base64url_decode_to_string($payloadB64);

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        throw new Exception('Bad JWT');
    }

    if (($header['alg'] ?? '') !== 'RS256') {
        throw new Exception('alg');
    }

    $kid = (string)($header['kid'] ?? '');
    if ($kid === '') {
        throw new Exception('kid');
    }

    $iss = (string)($payload['iss'] ?? '');
    if ($iss !== 'https://accounts.google.com' && $iss !== 'accounts.google.com') {
        throw new Exception('iss');
    }

    $aud = (string)($payload['aud'] ?? '');
    if ($aud !== $expectedAud) {
        throw new Exception('aud');
    }

    $exp = (int)($payload['exp'] ?? 0);
    if ($exp <= time()) {
        throw new Exception('exp');
    }

    $pem = get_google_pem_cert_for_kid($kid);
    if ($pem === null) {
        throw new Exception('no cert');
    }

    $signedData = $headerB64 . '.' . $payloadB64;
    $signature = base64url_decode($sigB64);

    $ok = verify_rs256($signedData, $signature, $pem);
    if (!$ok) {
        throw new Exception('sig');
    }

    return $payload;
}

function base64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad !== 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($s, true);
    return $bin === false ? '' : $bin;
}

function base64url_decode_to_string(string $s): string {
    return (string)base64url_decode($s);
}

function verify_rs256(string $data, string $sig, string $pemCert): bool {
    $pubKey = openssl_pkey_get_public($pemCert);
    if ($pubKey === false) {
        return false;
    }

    try {
        $result = openssl_verify($data, $sig, $pubKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    } finally {
        if (PHP_VERSION_ID < 80000) {
            // PHP 7: free key resource
            openssl_free_key($pubKey);
        }
    }
}

function get_google_pem_cert_for_kid(string $kid): ?string {
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audio_google_certs';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0700, true);
    }

    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'certs.json';
    $maxAgeSeconds = 6 * 3600;

    $json = null;
    if (is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age >= 0 && $age < $maxAgeSeconds) {
            $json = @file_get_contents($cacheFile);
        }
    }

    if ($json === null || $json === false || $json === '') {
        $json = http_get(GOOGLE_CERTS_URL);
        @file_put_contents($cacheFile, $json);
    }

    $map = json_decode($json, true);
    if (!is_array($map)) {
        return null;
    }

    $pem = $map[$kid] ?? null;
    if (!is_string($pem) || $pem === '') {
        return null;
    }

    return $pem;
}

function http_get(string $url): string {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "User-Agent: AudioPlayer\r\n"
        ]
    ]);

    $s = @file_get_contents($url, false, $ctx);
    if ($s === false) {
        throw new Exception('cert download failed');
    }
    return $s;
}
