<?php
declare(strict_types=1);

/*
 * Secure GetPay/Custom payment proxy.
 * Keep API credentials in server environment variables, never in HTML/Firebase.
 *
 * GetPay (getpay.io) configuration:
 *   GETPAY_API_KEY
 *   GETPAY_PROJECT_ID
 *   GETPAY_API_URL (optional, defaults to https://getpay.io/api/v1/payment/create)
 *   PAYMENT_RETURN_URL
 *   PAYMENT_WEBHOOK_URL
 *
 * This endpoint creates a hosted checkout order. Wallet credit MUST be performed
 * by a verified server-side webhook/status check, not by a browser redirect alone.
 */

header('Content-Type: application/json; charset=utf-8');

function out(array $x, int $status=200): never {
    http_response_code($status);
    echo json_encode($x, JSON_UNESCAPED_SLASHES);
    exit;
}
function envv(string $k, string $d=''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $d : $v;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok'=>false,'error'=>'POST required'],405);

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) out(['ok'=>false,'error'=>'Invalid JSON'],400);

$provider = strtolower(trim((string)($data['provider'] ?? 'getpay')));
$amount = (float)($data['amount'] ?? 0);
$orderId = trim((string)($data['order_id'] ?? ''));
if ($amount <= 0 || $orderId === '') out(['ok'=>false,'error'=>'amount and order_id are required'],400);

if ($provider !== 'getpay') {
    out(['ok'=>false,'error'=>'Only GetPay is configured in this proxy. Add a provider-specific server adapter for another gateway.'],400);
}

$apiKey = envv('GETPAY_API_KEY');
$projectId = envv('GETPAY_PROJECT_ID');
$apiUrl = envv('GETPAY_API_URL','https://getpay.io/api/v1/payment/create');
$returnUrl = envv('PAYMENT_RETURN_URL');
$webhookUrl = envv('PAYMENT_WEBHOOK_URL');

if ($apiKey === '' || $projectId === '' || $returnUrl === '' || $webhookUrl === '') {
    out(['ok'=>false,'error'=>'GetPay server configuration is incomplete'],500);
}

$payload = [
    'project_id' => is_numeric($projectId) ? (int)$projectId : $projectId,
    'order_id' => $orderId,
    'amount' => $amount,
];
if ($returnUrl !== '') $payload['back_url'] = $returnUrl;
if ($webhookUrl !== '') $payload['result_url'] = $webhookUrl;
if (!empty($data['client_id'])) $payload['client_id'] = (string)$data['client_id'];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Api-Key: '.$apiKey,
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false) out(['ok'=>false,'error'=>'Gateway connection failed: '.$err],502);

$json = json_decode($body, true);
if (!is_array($json)) out(['ok'=>false,'error'=>'Gateway returned invalid JSON'],502);

$status = strtolower((string)($json['status'] ?? ''));
if ($status !== 'success') {
    out(['ok'=>false,'error'=>(string)($json['message'] ?? 'Gateway order creation failed'),'gateway'=>$json], max(400,min(599,$code ?: 502)));
}

$redirect = (string)($json['redirectUrl'] ?? $json['redirect_url'] ?? '');
if ($redirect === '') out(['ok'=>false,'error'=>'Gateway did not return a checkout URL'],502);

out([
    'ok'=>true,
    'order_id'=>$orderId,
    'payment_id'=>$json['paymentId'] ?? null,
    'redirect_url'=>$redirect,
    'gateway'=>$json
]);
