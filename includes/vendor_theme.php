<?php
declare(strict_types=1);

function valid_hex_color(?string $value, string $fallback): string
{
    $value = strtoupper(trim((string) $value));
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function vendor_theme(array $vendor): array
{
    return [
        'primary' => valid_hex_color($vendor['theme_primary'] ?? null, '#1F4D3A'),
        'accent' => valid_hex_color($vendor['theme_accent'] ?? null, '#F2B84B'),
        'background' => valid_hex_color($vendor['theme_background'] ?? null, '#F7F5F0'),
        'surface' => valid_hex_color($vendor['theme_surface'] ?? null, '#FFFFFF'),
        'text' => valid_hex_color($vendor['theme_text'] ?? null, '#17211C'),
    ];
}

function vendor_theme_style(array $vendor): string
{
    $theme = vendor_theme($vendor);
    return sprintf(
        '--brand:%s;--accent:%s;--page:%s;--surface:%s;--text:%s',
        $theme['primary'], $theme['accent'], $theme['background'], $theme['surface'], $theme['text']
    );
}

function store_vendor_image(array $file, int $vendorId, string $kind): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Brand images must be smaller than 4 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Use a JPG, PNG, or WebP brand image.');

    $directory = dirname(__DIR__) . '/assets/vendor/' . $vendorId;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the vendor asset directory.');
    }
    $filename = $kind . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Unable to store the brand image.');
    }
    return '/assets/vendor/' . $vendorId . '/' . $filename;
}
