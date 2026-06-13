<?php
declare(strict_types=1);

function lms_embed_start_seconds(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    if (preg_match('/^[0-9]+$/D', $value) === 1) {
        return (int)$value;
    }
    if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/iD', $value, $matches) !== 1) {
        return 0;
    }
    return ((int)($matches[1] ?? 0) * 3600)
        + ((int)($matches[2] ?? 0) * 60)
        + (int)($matches[3] ?? 0);
}

/**
 * @return array{provider:string,embed_url:string,title:string,allow:string}|null
 */
function lms_external_embed_descriptor(string $rawUrl): ?array
{
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '' || filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($rawUrl);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host);
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);

    if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
        $videoId = '';
        if ($host === 'youtu.be') {
            $videoId = explode('/', ltrim($path, '/'))[0] ?? '';
        } elseif (preg_match('#^/(?:embed|shorts)/([A-Za-z0-9_-]{6,20})#', $path, $matches) === 1) {
            $videoId = $matches[1];
        } elseif ($path === '/watch') {
            $videoId = (string)($query['v'] ?? '');
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/D', $videoId) !== 1) {
            return null;
        }
        $start = lms_embed_start_seconds((string)($query['t'] ?? $query['start'] ?? ''));
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
        if ($start > 0) {
            $embedUrl .= '?start=' . $start;
        }
        return [
            'provider' => 'youtube',
            'embed_url' => $embedUrl,
            'title' => 'YouTube video',
            'allow' => 'encrypted-media; picture-in-picture; fullscreen',
        ];
    }

    if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
        if (preg_match('#/(?:video/)?([0-9]+)#', $path, $matches) !== 1) {
            return null;
        }
        return [
            'provider' => 'vimeo',
            'embed_url' => 'https://player.vimeo.com/video/' . $matches[1],
            'title' => 'Vimeo video',
            'allow' => 'picture-in-picture; fullscreen',
        ];
    }

    if ($host === 'docs.google.com') {
        $patterns = [
            'google_slides' => ['#^/presentation/d/([A-Za-z0-9_-]+)#', '/presentation/d/%s/embed?start=false&loop=false'],
            'google_docs' => ['#^/document/d/([A-Za-z0-9_-]+)#', '/document/d/%s/preview'],
            'google_sheets' => ['#^/spreadsheets/d/([A-Za-z0-9_-]+)#', '/spreadsheets/d/%s/preview'],
        ];
        foreach ($patterns as $provider => [$pattern, $format]) {
            if (preg_match($pattern, $path, $matches) === 1) {
                return [
                    'provider' => $provider,
                    'embed_url' => 'https://docs.google.com' . sprintf($format, rawurlencode($matches[1])),
                    'title' => 'Google document preview',
                    'allow' => '',
                ];
            }
        }
    }

    if ($host === 'drive.google.com') {
        $fileId = '';
        if (preg_match('#/(?:file/)?d/([A-Za-z0-9_-]+)#', $path, $matches) === 1) {
            $fileId = $matches[1];
        } elseif (isset($query['id']) && preg_match('/^[A-Za-z0-9_-]+$/D', (string)$query['id']) === 1) {
            $fileId = (string)$query['id'];
        }
        if ($fileId !== '') {
            return [
                'provider' => 'google_drive',
                'embed_url' => 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/preview',
                'title' => 'Google Drive preview',
                'allow' => '',
            ];
        }
    }

    if ($host === 'view.officeapps.live.com' && $path === '/op/embed.aspx') {
        return [
            'provider' => 'office',
            'embed_url' => $rawUrl,
            'title' => 'Microsoft Office preview',
            'allow' => '',
        ];
    }

    if (preg_match('/\.(?:docx?|xlsx?|pptx?)$/iD', $path) === 1) {
        return [
            'provider' => 'office',
            'embed_url' => 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($rawUrl),
            'title' => 'Microsoft Office preview',
            'allow' => '',
        ];
    }

    return null;
}
