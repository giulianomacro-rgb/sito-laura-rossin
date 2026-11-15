<?php
declare(strict_types=1);

function storage_dir(): ?string
{
    return getenv('CMS_DATA_DIR') === '/data' ? '/data' : null;
}

function site_content_file(): string
{
    return storage_dir() ? '/data/site.json' : dirname(__DIR__) . '/content/site.json';
}

function calendar_config_file(): string
{
    return storage_dir() ? '/data/private/calendar.php' : dirname(__DIR__) . '/admin/google-calendar-config.php';
}

function atomic_write(string $file, string $body): bool
{
    $temp = tempnam(dirname($file), '.write-');
    if ($temp === false) {
        return false;
    }
    $mode = is_file($file) ? fileperms($file) : false;
    $ok = ($mode === false || chmod($temp, $mode & 0777))
        && file_put_contents($temp, $body) === strlen($body) && rename($temp, $file);
    if (!$ok) {
        unlink($temp);
    }
    return $ok;
}
