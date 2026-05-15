<?php
declare(strict_types=1);

/**
 * Cron usage:
 * php /home/USER/public_html/send_capi.php
 *
 * Runs safely every minute. It sends unsent business events to Meta CAPI
 * and stores the exact payload/response in meta_capi_logs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$dsn = 'mysql:host=localhost;dbname=YOUR_DATABASE_NAME;charset=utf8mb4';
$dbUser = 'YOUR_DATABASE_USER';
$dbPass = 'YOUR_DATABASE_PASSWORD';

$pixelId = 'YOUR_META_PIXEL_ID';
$accessToken = 'YOUR_META_ACCESS_TOKEN';
$graphVersion = 'v20.0';
$testEventCode = ''; // Optional. Use only while testing in Meta Events Manager.

$batchSize = 50;
$maxAttempts = 5;
$sendableEvents = [
    'PageView',
    'ViewContent',
    'Lead',
    'CompleteRegistration',
    'InitiateCheckout',
    'Purchase',
    'AddToCart',
    'Contact',
    'Search',
];

if ($pixelId === 'YOUR_META_PIXEL_ID' || $accessToken === 'YOUR_META_ACCESS_TOKEN') {
    fwrite(STDERR, "Please configure Meta Pixel ID and access token.\n");
    exit(1);
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if (!acquireLock($pdo)) {
        echo "Another send_capi.php process is running.\n";
        exit(0);
    }

    markUnsendableEventsSkipped($pdo, $sendableEvents);

    $events = fetchUnsentEvents($pdo, $sendableEvents, $batchSize, $maxAttempts);

    if (!$events) {
        releaseLock($pdo);
        echo "No events to send.\n";
        exit(0);
    }

    foreach (array_chunk($events, 20) as $chunk) {
        $chunk = filterEventsWithFacebookBrowserData($pdo, $chunk);

        if (!$chunk) {
            continue;
        }

        $payload = buildCapiPayload($chunk, $testEventCode);
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            rawurlencode($graphVersion),
            rawurlencode($pixelId),
            rawurlencode($accessToken)
        );

        $response = postJson($url, $payload);
        $success = $response['http_code'] >= 200
            && $response['http_code'] < 300
            && empty($response['decoded']['error']);

        foreach ($chunk as $event) {
            insertCapiLog($pdo, $event, $url, $payload, $response, $success);
            updateEventCapiStatus($pdo, $event, $response, $success);
        }

        echo sprintf(
            "Sent %d event(s). HTTP %d. Success: %s\n",
            count($chunk),
            $response['http_code'],
            $success ? 'yes' : 'no'
        );
    }

    releaseLock($pdo);
} catch (Throwable $exception) {
    if (isset($pdo)) {
        releaseLock($pdo);
    }

    fwrite(STDERR, 'send_capi.php failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

function acquireLock(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT GET_LOCK('meta_capi_sender', 5) AS locked");
    return (int)($stmt->fetch()['locked'] ?? 0) === 1;
}

function releaseLock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT RELEASE_LOCK('meta_capi_sender')");
    } catch (Throwable $ignored) {
        // Nothing useful to do during shutdown.
    }
}

function markUnsendableEventsSkipped(PDO $pdo, array $sendableEvents): void
{
    $placeholders = implode(',', array_fill(0, count($sendableEvents), '?'));

    $stmt = $pdo->prepare("
        UPDATE fb_events
        SET capi_status = 'skipped',
            capi_last_error = 'Event is not configured for Meta CAPI'
        WHERE capi_status = 'pending'
          AND event_name NOT IN ($placeholders)
    ");

    $stmt->execute($sendableEvents);
}

function fetchUnsentEvents(PDO $pdo, array $sendableEvents, int $limit, int $maxAttempts): array
{
    $placeholders = implode(',', array_fill(0, count($sendableEvents), '?'));

    $sql = "
        SELECT e.*
        FROM fb_events e
        WHERE e.event_name IN ($placeholders)
          AND e.capi_status IN ('pending', 'failed')
          AND e.capi_attempts < ?
          AND NOT EXISTS (
              SELECT 1
              FROM meta_capi_logs l
              WHERE l.fb_event_id = e.id
                AND l.success = 1
          )
        ORDER BY e.id ASC
        LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($sendableEvents, [$maxAttempts]));

    return $stmt->fetchAll();
}

function filterEventsWithFacebookBrowserData(PDO $pdo, array $events): array
{
    $sendable = [];

    foreach ($events as $event) {
        if (!empty($event['fbp']) || !empty($event['fbc'])) {
            $sendable[] = $event;
            continue;
        }

        markEventSkipped(
            $pdo,
            $event,
            'Missing fbp and fbc. Event was stored but not sent to Meta CAPI.'
        );
    }

    return $sendable;
}

function markEventSkipped(PDO $pdo, array $event, string $reason): void
{
    $stmt = $pdo->prepare(
        "UPDATE fb_events
         SET capi_status = 'skipped',
             capi_last_error = :reason
         WHERE id = :id"
    );

    $stmt->execute([
        ':id' => $event['id'],
        ':reason' => $reason,
    ]);

    insertSkippedCapiLog($pdo, $event, $reason);
}

function insertSkippedCapiLog(PDO $pdo, array $event, string $reason): void
{
    $payload = [
        'skipped' => true,
        'reason' => $reason,
        'event' => [
            'id' => $event['id'],
            'event_name' => $event['event_name'],
            'event_id' => eventId($event),
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO meta_capi_logs (
            fb_event_id,
            event_name,
            event_id,
            request_url,
            request_payload,
            response_http_code,
            response_body,
            response_text,
            success,
            error_message
        ) VALUES (
            :fb_event_id,
            :event_name,
            :event_id,
            :request_url,
            :request_payload,
            NULL,
            NULL,
            NULL,
            0,
            :error_message
        )'
    );

    $stmt->execute([
        ':fb_event_id' => $event['id'],
        ':event_name' => $event['event_name'],
        ':event_id' => eventId($event),
        ':request_url' => 'SKIPPED',
        ':request_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':error_message' => $reason,
    ]);
}

function buildCapiPayload(array $events, string $testEventCode): array
{
    $data = [];

    foreach ($events as $event) {
        $rawPayload = decodeJson($event['raw_payload'] ?? null);
        $customData = buildCustomData($event, $rawPayload);

        $capiEvent = [
            'event_name' => $event['event_name'],
            'event_time' => strtotime((string)$event['event_time']) ?: time(),
            'event_id' => eventId($event),
            'action_source' => 'website',
            'event_source_url' => $event['page_url'],
            'user_data' => array_filter([
                'client_ip_address' => $event['ip_address'],
                'client_user_agent' => $event['user_agent'],
                'fbp' => $event['fbp'],
                'fbc' => $event['fbc'],
            ], static function ($value): bool {
                return $value !== null && $value !== '';
            }),
        ];

        if ($customData) {
            $capiEvent['custom_data'] = $customData;
        }

        $data[] = $capiEvent;
    }

    $payload = ['data' => $data];

    if ($testEventCode !== '') {
        $payload['test_event_code'] = $testEventCode;
    }

    return $payload;
}

function buildCustomData(array $event, array $rawPayload): array
{
    $customData = [];

    if ($event['value'] !== null && $event['value'] !== '') {
        $customData['value'] = (float)$event['value'];
    }

    if ($event['currency'] !== null && $event['currency'] !== '') {
        $customData['currency'] = $event['currency'];
    }

    $contentIds = decodeJson($event['content_ids'] ?? null);
    if ($contentIds !== []) {
        $customData['content_ids'] = $contentIds;
    }

    foreach ([
        'content_name',
        'content_category',
        'content_type',
        'lead_type',
        'button_text',
        'page_type',
        'registration_type',
        'checkout_type',
        'checkout_step',
        'deposit_method',
        'purchase_type',
        'purchase_step',
        'payment_status',
    ] as $key) {
        if (isset($rawPayload[$key]) && $rawPayload[$key] !== null && $rawPayload[$key] !== '') {
            $customData[$key] = $rawPayload[$key];
        }
    }

    return $customData;
}

function eventId(array $event): string
{
    if (!empty($event['event_id'])) {
        return (string)$event['event_id'];
    }

    return sprintf('%s-%s', $event['event_name'], $event['id']);
}

function postJson(string $url, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'http_code' => $httpCode,
        'body' => is_string($responseBody) ? $responseBody : '',
        'decoded' => is_array($decoded) ? $decoded : null,
        'error' => $curlError !== '' ? $curlError : null,
    ];
}

function insertCapiLog(
    PDO $pdo,
    array $event,
    string $url,
    array $payload,
    array $response,
    bool $success
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO meta_capi_logs (
            fb_event_id,
            event_name,
            event_id,
            request_url,
            request_payload,
            response_http_code,
            response_body,
            response_text,
            success,
            error_message
        ) VALUES (
            :fb_event_id,
            :event_name,
            :event_id,
            :request_url,
            :request_payload,
            :response_http_code,
            :response_body,
            :response_text,
            :success,
            :error_message
        )'
    );

    $stmt->execute([
        ':fb_event_id' => $event['id'],
        ':event_name' => $event['event_name'],
        ':event_id' => eventId($event),
        ':request_url' => redactAccessToken($url),
        ':request_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':response_http_code' => $response['http_code'] ?: null,
        ':response_body' => is_array($response['decoded'])
            ? json_encode($response['decoded'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null,
        ':response_text' => $response['body'] !== '' ? $response['body'] : null,
        ':success' => $success ? 1 : 0,
        ':error_message' => $response['error'] ?? ($response['decoded']['error']['message'] ?? null),
    ]);
}

function updateEventCapiStatus(PDO $pdo, array $event, array $response, bool $success): void
{
    $errorMessage = $response['error'] ?? ($response['decoded']['error']['message'] ?? null);

    if ($success) {
        $stmt = $pdo->prepare(
            "UPDATE fb_events
             SET capi_status = 'sent',
                 capi_attempts = capi_attempts + 1,
                 capi_last_sent_at = NOW(),
                 capi_last_error = NULL
             WHERE id = :id"
        );

        $stmt->execute([':id' => $event['id']]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE fb_events
         SET capi_status = 'failed',
             capi_attempts = capi_attempts + 1,
             capi_last_error = :error_message
         WHERE id = :id"
    );

    $stmt->execute([
        ':id' => $event['id'],
        ':error_message' => $errorMessage,
    ]);
}

function redactAccessToken(string $url): string
{
    return preg_replace('/access_token=[^&]+/', 'access_token=REDACTED', $url) ?? $url;
}

function decodeJson(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
