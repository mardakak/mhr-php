<?php
$myOwnHost = 'example.com';

if (isset($_SERVER['HTTP_X_RELAY_HOP']) && $_SERVER['HTTP_X_RELAY_HOP'] === '1') {
    http_response_code(508);
    echo json_encode(['e' => 'loop detected']);
    exit;
}

$input = file_get_contents('php://input');
$req = json_decode($input, true);
if (!$req) {
    http_response_code(400);
    echo json_encode(['e' => 'invalid json']);
    exit;
}

if (empty($req['u'])) {
    http_response_code(400);
    echo json_encode(['e' => 'missing url']);
    exit;
}

$targetUrl = $req['u'];

$parsedTarget = parse_url($targetUrl);
$BLOCKED_HOSTS = [$myOwnHost];
if (isset($parsedTarget['host']) && in_array($parsedTarget['host'], $BLOCKED_HOSTS)) {
    http_response_code(400);
    echo json_encode(['e' => 'self-fetch blocked']);
    exit;
}

$headers = [];
if (!empty($req['h']) && is_array($req['h'])) {
    foreach ($req['h'] as $key => $value) {
        $headers[] = "$key: $value";
    }
}
$headers[] = 'x-relay-hop: 1';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$method = strtoupper($req['m'] ?? 'GET');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

if (!empty($req['b'])) {
    $body = base64_decode($req['b']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

if (isset($req['r']) && $req['r'] === false) {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
} else {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
}

$response = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['e' => 'curl error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawHeaders = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

$base64Body = base64_encode($body);

$responseHeaders = [];
foreach (explode("\r\n", trim($rawHeaders)) as $headerLine) {
    if (strpos($headerLine, 'HTTP/') === 0) continue;
    $parts = explode(': ', $headerLine, 2);
    if (count($parts) === 2) {
        $responseHeaders[$parts[0]] = $parts[1];
    }
}

$result = [
    's' => $httpCode,
    'h' => (object)$responseHeaders,
    'b' => $base64Body
];

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($result);
