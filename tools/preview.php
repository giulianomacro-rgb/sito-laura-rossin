<?php
// Local PHP preview with the same public routing as Apache.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/healthz') { echo 'ok'; return true; }
if ($path === '/' || preg_match('~\.(html|xml)$~', $path) || in_array($path, ['/content/site.json','/robots.txt'], true)) {
    require dirname(__DIR__) . '/deploy/render.php';
    return true;
}
return false;
