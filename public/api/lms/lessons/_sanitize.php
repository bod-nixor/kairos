<?php
declare(strict_types=1);

function lms_sanitize_lesson_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $allowedTags = [
        'body', 'p', 'br', 'strong', 'b', 'em', 'i', 'u',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'a', 'blockquote', 'pre', 'code', 'hr', 'span', 'iframe'
    ];
    $allowedAttrs = [
        'a' => ['href', 'target', 'rel'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder'],
        'span' => ['class'],
    ];

    $nodes = $doc->getElementsByTagName('*');
    for ($i = $nodes->length - 1; $i >= 0; $i--) {
        $node = $nodes->item($i);
        if (!$node) {
            continue;
        }
        $tag = strtolower($node->nodeName);
        if (!in_array($tag, $allowedTags, true)) {
            $node->parentNode?->removeChild($node);
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
