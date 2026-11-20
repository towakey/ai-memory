<?php
declare(strict_types=1);
// ドメイン直下 /.well-known/ の OAuth メタデータ振り分け（MCP 発見用）
// acme-challenge 等の実在ファイルは .htaccess の !-f で素通しされる

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$origin = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$app = $origin . '/ai-memory';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (strpos($uri, 'oauth-protected-resource') !== false) {
    echo json_encode([
        'resource' => "$app/mcp",
        'authorization_servers' => [$origin],
        'bearer_methods_supported' => ['header'],
    ]);
} elseif (strpos($uri, 'oauth-authorization-server') !== false) {
    echo json_encode([
        'issuer' => $origin,
        'authorization_endpoint' => "$app/oauth/authorize",
        'token_endpoint' => "$app/oauth/token",
        'registration_endpoint' => "$app/oauth/register",
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
    ]);
} else {
    http_response_code(404);
    echo '{}';
}
