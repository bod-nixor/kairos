<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/resources/_embed.php';

function lms_sanitize_iframe(DOMElement $node): bool
{
    $src = trim((string)$node->getAttribute('src'));
    $descriptor = lms_external_embed_descriptor($src);
    if ($descriptor === null) {
        return false;
    }

    $node->setAttribute('src', $descriptor['embed_url']);
    $node->setAttribute('title', $descriptor['title']);
    $node->setAttribute('loading', 'lazy');
    $node->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    $node->removeAttribute('sandbox');
    if ($descriptor['allow'] !== '') {
        $node->setAttribute('allow', $descriptor['allow']);
        $node->setAttribute('allowfullscreen', '');
    } else {
        $node->removeAttribute('allow');
        $node->removeAttribute('allowfullscreen');
    }
    if (!$node->hasAttribute('width')) {
        $node->setAttribute('width', '640');
    }
    if (!$node->hasAttribute('height')) {
        $node->setAttribute('height', '360');
    }

    return true;
}

function lms_sanitize_lesson_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $allowedTags = [
        'body', 'p', 'br', 'strong', 'b', 'em', 'i', 'u',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'a', 'blockquote', 'pre', 'code', 'hr', 'span', 'iframe'
    ];
    $allowedAttrs = [
        'a' => ['href', 'target', 'rel'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'title', 'loading', 'referrerpolicy'],
        'span' => ['class'],
    ];

    $nodes = $doc->getElementsByTagName('*');
    for ($i = $nodes->length - 1; $i >= 0; $i--) {
        $node = $nodes->item($i);
        if (!$node instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($node->nodeName);
        if (!in_array($tag, $allowedTags, true)) {
            $parent = $node->parentNode;
            if ($parent) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
            }
            continue;
        }

        if ($node->hasAttributes()) {
            for ($j = $node->attributes->length - 1; $j >= 0; $j--) {
                $attr = $node->attributes->item($j);
                if (!$attr) {
                    continue;
                }
                $name = strtolower($attr->nodeName);
                $value = trim($attr->nodeValue);
                $tagAllowed = $allowedAttrs[$tag] ?? [];
                if (!in_array($name, $tagAllowed, true)) {
                    $node->removeAttribute($name);
                    continue;
                }

                if (($name === 'href' || $name === 'src') && !preg_match('/^https?:\/\//i', $value)) {
                    $node->removeAttribute($name);
                }
            }
        }

        if ($tag === 'a') {
            if (!$node->hasAttribute('target')) {
                $node->setAttribute('target', '_blank');
            }
            $node->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'iframe' && !lms_sanitize_iframe($node)) {
            $parent = $node->parentNode;
            if ($parent) {
                $parent->removeChild($node);
            }
        }
    }

    $result = '';
    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body) {
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }
    }
    return trim($result);
}
