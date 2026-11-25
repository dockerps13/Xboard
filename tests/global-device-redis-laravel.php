<?php
/**
 * Isolated smoke test for the REAL Laravel GlobalDeviceAdmissionService.
 *
 * Run with an ephemeral Redis server only, never the production Redis.
 * No users, nodes, production database records or network accounts needed.
 */
declare(strict_types=1);

use App\Services\GlobalDeviceAdmissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Redis;

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$uid = random_int(100000000, 999999999);
$key = 'global_device_admission:v1:user:' . $uid;
$authority = $app->make(GlobalDeviceAdmissionService::class);
$failure = null;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $a = $authority->execute('acquire', $uid, 'm101:n11:a', '192.0.2.1', 1, 30000);
    $assert($a['allowed'] && $a['distinct_ips'] === 1 && $a['lease_ttl_ms'] > 0, 'first IP should acquire');

    $shared = $authority->execute('acquire', $uid, 'm202:n22:b', '192.0.2.1', 1, 30000);
    $assert($shared['allowed'] && $shared['distinct_ips'] === 1, 'second machine should share same IP');

    $denied = $authority->execute('acquire', $uid, 'm202:n22:c', '192.0.2.2', 1, 30000);
    $assert(!$denied['allowed'], 'second distinct IP should be denied');

    $renew = $authority->execute('renew', $uid, 'm202:n22:b', '', 1, 30000);
    $assert($renew['allowed'] && $renew['lease_ttl_ms'] > 0, 'renew should succeed');

    $authority->execute('release', $uid, 'm101:n11:a', '', 1, 30000);
    $stillDenied = $authority->execute('acquire', $uid, 'm202:n22:c', '192.0.2.2', 1, 30000);
    $assert(!$stillDenied['allowed'], 'release of one machine should preserve other owner');

    $authority->execute('release', $uid, 'm202:n22:b', '', 1, 30000);
    $granted = $authority->execute('acquire', $uid, 'm202:n22:c', '192.0.2.2', 1, 30000);
    $assert($granted['allowed'] && $granted['distinct_ips'] === 1, 'released slot should be reusable');

    echo "PASS: Real Laravel GlobalDeviceAdmissionService + isolated Redis\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        Redis::del($key);
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "Redis test cleanup failed: " . $cleanupError->getMessage() . "\n");
        exit(2);
    }
}
if ($failure !== null) {
    fwrite(STDERR, "FAIL: " . $failure->getMessage() . "\n");
    exit(1);
}
