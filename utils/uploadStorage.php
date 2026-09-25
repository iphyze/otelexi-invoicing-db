<?php
// utils/uploadStorage.php
// Shared storage helpers for files kept inside the backend codebase.

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

function uploadStorageDirectory(string $relativePath = ''): string
{
    $root = dirname(__DIR__) . '/uploads';
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    if ($relativePath === '') {
        return $root;
    }

    if (str_contains($relativePath, '..') || !preg_match('#^[A-Za-z0-9/_-]+$#', $relativePath)) {
        throw new InvalidArgumentException('Invalid upload storage path.');
    }

    return $root . '/' . $relativePath;
}

function uploadPublicBaseUrl(): string
{
    $configured = rtrim((string) (config('UPLOAD', '') ?? ''), '/');
    if ($configured === '') {
        throw new RuntimeException('Upload public URL configuration is unavailable.');
    }

    $parts = parse_url($configured);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('UPLOAD must be configured as an absolute public URL.');
    }

    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin . apiBasePath() . '/uploads';
}

function uploadPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        throw new InvalidArgumentException('Invalid upload public path.');
    }

    return uploadPublicBaseUrl() . '/' . $relativePath;
}

function normalizeStoredUploadUrl(?string $url): string
{
    $url = trim((string) ($url ?? ''));
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('#/uploads/(.+)$#', $path, $matches)) {
        return $url;
    }

    try {
        return uploadPublicUrl($matches[1]);
    } catch (Throwable $e) {
        return $url;
    }
}
