<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/runtime.php';

$dir = sys_get_temp_dir() . '/laura-runtime-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$file = $dir . '/calendar.php';
try {
    if (!atomic_write($file, "<?php return ['enabled' => true];\n")) {
        throw new RuntimeException('Initial config write failed');
    }
    if ((require $file)['enabled'] !== true) {
        throw new RuntimeException('Initial config read failed');
    }
    if (!atomic_write($file, "<?php return ['enabled' => false];\n")) {
        throw new RuntimeException('Config update failed');
    }
    if ((require $file)['enabled'] !== false) {
        throw new RuntimeException('Stale booking configuration after save');
    }
    echo "OK: configuration changes are visible immediately\n";
} finally {
    if (is_file($file)) unlink($file);
    rmdir($dir);
}
