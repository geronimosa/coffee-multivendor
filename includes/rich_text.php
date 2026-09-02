<?php
declare(strict_types=1);

function sanitize_vendor_description(?string $html): string
{
    $html = trim((string) $html);
    if ($html === '') return '';
    if (strlen($html) > 20000) {
        throw new InvalidArgumentException('Vendor description must be 20,000 characters or fewer.');
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="vendor-description-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return '';

    $root = $document->getElementById('vendor-description-root');
    if (!$root) return '';
    sanitize_rich_text_children($root);

    $clean = '';
    foreach ($root->childNodes as $child) {
        $clean .= $document->saveHTML($child);
    }
    return trim($clean);
}

function sanitize_rich_text_children(DOMNode $parent): void
{
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];
    $discardWithContent = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button'];

    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMComment) {
            $parent->removeChild($node);
            continue;
        }
        if (!$node instanceof DOMElement) continue;

        $tag = strtolower($node->tagName);
        if (in_array($tag, $discardWithContent, true)) {
            $parent->removeChild($node);
            continue;
        }
        if (!in_array($tag, $allowed, true)) {
            sanitize_rich_text_children($node);
            while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
            $parent->removeChild($node);
            continue;
        }

        $href = $tag === 'a' ? trim($node->getAttribute('href')) : '';
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $node->removeAttribute($attribute->name);
        }
        if ($tag === 'a' && rich_text_href_is_safe($href)) {
            $node->setAttribute('href', $href);
            $node->setAttribute('rel', 'noopener noreferrer nofollow');
        }
        sanitize_rich_text_children($node);
    }
}

function rich_text_href_is_safe(string $href): bool
{
    if ($href === '' || str_starts_with($href, '//')) return false;
    if ($href[0] === '/' && !str_starts_with($href, '//')) return true;
    $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https', 'mailto'], true);
}

function vendor_description_excerpt(?string $html, int $limit = 140): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($text) <= $limit) return $text;
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}
