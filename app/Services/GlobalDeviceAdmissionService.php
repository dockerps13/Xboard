<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Redis-backed device lease authority. This is independent of the existing
 * online-device snapshot, which is observational and not admission control.
 *
 * A single Lua EVAL is the linearization point for all authorized machines.
 * Redis outages MUST fail closed at the caller.
 */
class GlobalDeviceAdmissionService
{
    private const SCRIPT_PATH = 'lua/global_device_lease.lua';
    private const KEY_PREFIX = 'global_device_admission:v1:user:';

    /**
     * @return array{allowed: bool, distinct_ips: int, lease_ttl_ms: int}
     */
    public function execute(
        string $action,
        int $userId,
        string $owner,
        string $ip,
        int $limit,
        int $ttlMs
    ): array {
        if (!in_array($action, ['acquire', 'renew', 'release'], true)) {
            throw new RuntimeException('Invalid lease action');
        }
        if ($userId <= 0 || $owner === '' || $limit <= 0 || $ttlMs < 1) {
            throw new RuntimeException('Invalid lease parameters');
        }

        // This key is panel-local by design; a separately deployed panel uses
        // an independent Redis installation / configured key prefix.
        $key = self::KEY_PREFIX . $userId;
        $lua = file_get_contents(resource_path(self::SCRIPT_PATH));
        if ($lua === false) {
            throw new RuntimeException('Global device lease Lua script is missing');
        }

        $args = [$action, $owner, $ip, (string) $limit, (string) $ttlMs];
        $client = config('database.redis.client', 'phpredis');

        if ($client === 'phpredis') {
            // PhpRedis eval(string $script, array $args, int $numKeys)
            $raw = Redis::eval($lua, array_merge([$key], $args), 1);
        } elseif ($client === 'predis') {
            // Predis EVAL script numkeys key [arg ...]
            $raw = Redis::eval($lua, 1, $key, ...$args);
        } else {
            throw new RuntimeException('Unsupported Redis driver');
        }

        if (!is_array($raw) || count($raw) !== 3) {
            throw new RuntimeException('Unexpected Redis lease reply');
        }
        return [
            'allowed' => (int) $raw[0] === 1,
            'distinct_ips' => (int) $raw[1],
            'lease_ttl_ms' => (int) $raw[2],
        ];
    }
}
