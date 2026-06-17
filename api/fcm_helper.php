<?php
/**
 * Simple FCM HTTP v1 Helper
 * Uses the firebase-adminsdk.json service account key to generate an OAuth2 token and send a push.
 */

function sendFcmPush(string $fcmToken, string $title, string $body, array $data = []): bool {
    $keyFile = __DIR__ . '/firebase-adminsdk.json';
    if (!file_exists($keyFile)) return false;

    $keyData = json_decode(file_get_contents($keyFile), true);
    if (!$keyData || !isset($keyData['project_id'])) return false;

    $projectId = $keyData['project_id'];
    $clientEmail = $keyData['client_email'];
    $privateKey = $keyData['private_key'];

    // 1. Create JWT
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $claim = json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlClaim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));
    
    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlClaim, $signature, $privateKey, "sha256WithRSAEncryption");
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    $jwt = $base64UrlHeader . "." . $base64UrlClaim . "." . $base64UrlSignature;

    // 2. Exchange JWT for OAuth2 Token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    $res = curl_exec($ch);
    curl_close($ch);
    
    $tokenData = json_decode($res, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) return false;

    // 3. Send FCM Message
    $message = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
            'data' => $data
        ]
    ];

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($message));
    $fcmRes = curl_exec($ch2);
    $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    return $httpCode === 200;
}
