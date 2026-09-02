<?php
$url = $_GET['url'] ?? '';

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Invalid QR URL";
    exit;
}

$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: Mozilla/5.0\r\nReferer: https://yourdomain.com"
    ]
]);

$content = file_get_contents($url, false, $context);

if ($content === false) {
    http_response_code(502);
    echo "Failed to load QR";
    exit;
}

// Determine content type from extension
if (str_ends_with($url, '.svg')) {
    header("Content-Type: image/svg+xml");
} else {
    header("Content-Type: image/png");
}

header("Cache-Control: no-store");
echo $content;