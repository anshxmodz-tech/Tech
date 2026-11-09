<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function fail_json(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_json('POST required', 405);

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) fail_json('Invalid JSON');

$pid = trim((string)($input['pid'] ?? ''));
$duration = trim((string)($input['duration'] ?? ''));
$androidId = trim((string)($input['android_id'] ?? ''));

if ($pid === '' || $duration === '') fail_json('PID and duration are required');

$apiKey = getenv('RESELLER_API_KEY') ?: 'ae7a9f5a78f449c96caf1a4abd2ee26b';
$masterKey = getenv('RESELLER_MASTER_KEY') ?: 'a7f3e8b2c9d1f4a6b8c2d5e9f1a3b6c8';
$endpoint = getenv('RESELLER_API_ENDPOINT') ?: 'https://bantibhaiya.to/api/reseller_v1.php';

if ($apiKey === '' || $masterKey === '') {
    fail_json('Server-side reseller credentials are not configured', 500);
}

$data = [
    'api_key' => $apiKey,
    'action' => 'buy',
    'product_id' => $pid,
    'duration' => $duration,
];
if ($androidId !== '') $data['android_id'] = $androidId;

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'x-master-key: '.$masterKey,
    ],
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno || $response === false) fail_json('Upstream request failed', 502);

$decoded = json_decode($response, true);
$key = null;

$walk = function($value) use (&$walk, &$key) {
    if ($key !== null) return;
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (is_string($v) && preg_match('/^(key|license_key|license|product_key|activation_key)$/i', (string)$k) && trim($v) !== '') {
                $key = trim($v); return;
            }
            $walk($v);
        }
    }
};

if (is_array($decoded)) $walk($decoded);

if ($key === null && is_string($response)) {
    // Conservative fallback: only accept an obvious key-looking field from a JSON string.
    if (preg_match('/"(?:key|license_key|license|product_key|activation_key)"\s*:\s*"([^"]+)"/i', $response, $m)) {
        $key = trim($m[1]);
    }
}

if ($status < 200 || $status >= 300 || $key === null) {
    fail_json('Auto key provider did not return a license key', 502);
}

echo json_encode([
    'ok' => true,
    'key' => $key,
], JSON_UNESCAPED_SLASHES);
