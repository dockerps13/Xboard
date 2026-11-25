<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\GlobalDeviceAdmissionService;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opt-in endpoint for machine-authenticated nodes only.
 * Never use online-device snapshots to make admission decisions.
 */
class DeviceAdmissionController extends Controller
{
    public function lease(Request $request, GlobalDeviceAdmissionService $authority): JsonResponse
    {
        // A strict false-by-default feature flag prevents accidental use before
        // both node and panel have completed distributed acceptance testing.
        if (!config('global_device_admission.enabled', false)) {
            return response()->json(['allowed' => false, 'error' => 'not_enabled'], 503);
        }

        $machine = $request->attributes->get('machine_info');
        $node = $request->attributes->get('node_info');
        if (!$machine || !$node || (int) $node->machine_id !== (int) $machine->id) {
            return response()->json(['allowed' => false, 'error' => 'machine_auth_required'], 403);
        }

        $data = $request->validate([
            'action' => 'required|in:acquire,renew,release',
            'user_id' => 'required|integer|min:1',
            'lease_id' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'ip' => 'required_if:action,acquire|nullable|ip',
        ]);

        $userId = (int) $data['user_id'];
        // Resolve limits from the panel, never trust a device limit sent
        // by a remote node. This includes the server's group and traffic rules.
        $user = ServerService::getAvailableUserForAdmission($node, $userId);
        if (!$user || (int) $user->device_limit <= 0) {
            return response()->json(['allowed' => false, 'error' => 'user_not_eligible'], 403);
        }

        $action = $data['action'];
        $ip = (string) ($data['ip'] ?? '');
        if ($action === 'acquire') {
            $ip = $this->canonicalIP($ip);
            if ($ip === '') {
                return response()->json(['allowed' => false, 'error' => 'invalid_ip'], 422);
            }
        }

        // The machine and node ID are server-derived. Lease IDs must be random
        // per connection; one machine must not release another machine's owner.
        $owner = 'm' . (int) $machine->id . ':n' . (int) $node->id . ':' . $data['lease_id'];
        $ttlMs = 30000;

        try {
            $result = $authority->execute(
                $action, $userId, $owner, $ip,
                (int) $user->device_limit, $ttlMs
            );
        } catch (Throwable $e) {
            // Avoid leaking Redis/internal errors to clients.
            Log::error('Global device lease failure', [
                'action' => $action,
                'node_id' => $node->id,
                'exception' => $e,
            ]);
            return response()->json(['allowed' => false, 'error' => 'authority_unavailable'], 503);
        }

        return response()->json($result);
    }

    private function canonicalIP(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return '';
        }
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }
        return inet_ntop($packed) ?: '';
    }
}
