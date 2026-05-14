<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://YOUR-WEBSITE-DOMAIN.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$eventName = (string)($payload['event_name'] ?? '');

if ($eventName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Event name is required']);
    exit;
}

$eventTime = isset($payload['event_time']) && is_numeric($payload['event_time'])
    ? (int)$payload['event_time']
    : time();

$eventId = isset($payload['event_id']) ? trim((string)$payload['event_id']) : null;
$eventId = $eventId !== '' ? $eventId : null;

$dsn = 'mysql:host=localhost;dbname=YOUR_DATABASE_NAME;charset=utf8mb4';
$dbUser = 'YOUR_DATABASE_USER';
$dbPass = 'YOUR_DATABASE_PASSWORD';

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO fb_events (
            event_name,
            event_id,
            event_time,
            page_url,
            referrer,
            user_agent,
            fbp,
            fbc,
            value,
            currency,
            content_ids,
            raw_payload,
            ip_address
        ) VALUES (
            :event_name,
            :event_id,
            FROM_UNIXTIME(:event_time),
            :page_url,
            :referrer,
            :user_agent,
            :fbp,
            :fbc,
            :value,
            :currency,
            :content_ids,
            :raw_payload,
            INET6_ATON(:ip_address)
        )'
    );

    $stmt->execute([
        ':event_name' => $eventName,
        ':event_id' => $eventId,
        ':event_time' => $eventTime,
        ':page_url' => nullableString($payload['page_url'] ?? null, 2048),
        ':referrer' => nullableString($payload['referrer'] ?? null, 2048),
        ':user_agent' => nullableString($_SERVER['HTTP_USER_AGENT'] ?? ($payload['user_agent'] ?? null), 1024),
        ':fbp' => nullableString($payload['fbp'] ?? null, 255),
        ':fbc' => nullableString($payload['fbc'] ?? null, 255),
        ':value' => isset($payload['value']) && is_numeric($payload['value']) ? (float)$payload['value'] : null,
        ':currency' => nullableString($payload['currency'] ?? null, 8),
        ':content_ids' => jsonEncodeOrNull($payload['content_ids'] ?? null),
        ':raw_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':ip_address' => clientIp(),
    ]);

    echo json_encode(['ok' => true]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000' && $eventId !== null) {
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }

    error_log('FB event insert failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

function nullableString(mixed $value, int $maxLength): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $maxLength);
}

function jsonEncodeOrNull(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function clientIp(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';

    return trim(explode(',', $ip)[0]);
}
