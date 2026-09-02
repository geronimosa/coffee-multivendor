<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/rich_text.php';

$input = '<h2 onclick="alert(1)">Welcome</h2><p>Fresh <strong>coffee</strong> daily.</p>'
    . '<script>alert(2)</script><a href="javascript:alert(3)" style="color:red">Bad link</a>'
    . '<a href="https://example.com" target="_blank">Good link</a>';
$clean = sanitize_vendor_description($input);

$checks = [
    str_contains($clean, '<h2>Welcome</h2>'),
    str_contains($clean, '<strong>coffee</strong>'),
    !str_contains($clean, 'onclick'),
    !str_contains($clean, '<script'),
    !str_contains($clean, 'alert(2)'),
    !str_contains($clean, 'javascript:'),
    !str_contains($clean, 'style='),
    str_contains($clean, 'href="https://example.com"'),
    str_contains($clean, 'rel="noopener noreferrer nofollow"'),
    sanitize_vendor_description('<p>Café &amp; crème</p>') === '<p>Café &amp; crème</p>',
    vendor_description_excerpt('<p>Hello <strong>vendor</strong></p>') === 'Hello vendor',
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Rich-text sanitization test failed.\n$clean\n");
    exit(1);
}

echo "Rich-text sanitization test passed.\n";
