<?php
/**
 * Exercise the real HTTP kernel, ServerV2 middleware, and lease controller.
 * Ephemeral in-memory SQLite + disposable Redis only.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
config()->set('global_device_admission.enabled', true);
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', ':memory:');
DB::purge('sqlite');
Schema::connection('sqlite')->create('v2_server_machine', static function (Blueprint $t): void {
    $t->increments('id');
    $t->string('name')->nullable();
    $t->string('token');
    $t->boolean('is_active')->default(true);
    $t->integer('last_seen_at')->nullable();
    $t->timestamps();
});
Schema::connection('sqlite')->create('v2_server', static function (Blueprint $t): void {
    $t->increments('id');
    $t->integer('machine_id');
    $t->boolean('enabled')->default(true);
    $t->text('group_ids')->nullable();
    $t->timestamps();
});
Schema::connection('sqlite')->create('v2_user', static function (Blueprint $t): void {
    $t->increments('id');
    $t->integer('group_id');
    $t->integer('transfer_enable');
    $t->integer('u')->default(0);
    $t->integer('d')->default(0);
    $t->integer('expired_at')->nullable();
    $t->integer('banned')->default(0);
    $t->integer('device_limit')->default(0);
    $t->string('uuid')->nullable();
    $t->integer('speed_limit')->default(0);
});
DB::table('v2_server_machine')->insert([
    ['id'=>101,'name'=>'node-a','token'=>'secret-a','is_active'=>true],
    ['id'=>202,'name'=>'node-b','token'=>'secret-b','is_active'=>true],
    ['id'=>303,'name'=>'inactive','token'=>'secret-c','is_active'=>false],
]);
DB::table('v2_server')->insert([
    ['id'=>11,'machine_id'=>101,'enabled'=>true,'group_ids'=>'[1]'],
    ['id'=>22,'machine_id'=>202,'enabled'=>true,'group_ids'=>'[1]'],
    ['id'=>33,'machine_id'=>101,'enabled'=>false,'group_ids'=>'[1]'],
]);
DB::table('v2_user')->insert([
    ['id'=>7,'group_id'=>1,'transfer_enable'=>100000,'expired_at'=>time()+3600,'device_limit'=>2],
    ['id'=>8,'group_id'=>2,'transfer_enable'=>100000,'expired_at'=>time()+3600,'device_limit'=>1],
    ['id'=>9,'group_id'=>1,'transfer_enable'=>100000,'expired_at'=>time()-3600,'device_limit'=>1],
]);
$keys = array_map(static fn(int $id): string => 'global_device_admission:v1:user:' . $id,[7,8,9]);
$assert = static function(bool $ok,string $why): void {
    if (!$ok) {throw new RuntimeException($why);}
};
$kernel = $app->make(HttpKernel::class);
$send = static function(array $data) use ($kernel): array {
    $request = Request::create('/api/v2/server/device-lease', 'POST', [], [], [], [
        'HTTP_ACCEPT'=>'application/json',
        'CONTENT_TYPE'=>'application/json',
    ], json_encode($data, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $json = json_decode($response->getContent(), true) ?: [];
    $kernel->terminate($request,$response);
    return [$status,$json];
};
$base = ['machine_id'=>101,'node_id'=>11,'token'=>'secret-a','action'=>'acquire',
    'user_id'=>7,'lease_id'=>'test-owner-000001','ip'=>'192.0.2.1'];
try {
    [$status,$reply] = $send($base);
    $assert($status===200 && ($reply['allowed']??false)===true, 'first machine HTTP grant: '.json_encode([$status,$reply]));
    [$status,$reply] = $send(array_merge($base,['machine_id'=>202,'node_id'=>22,'token'=>'secret-b',
        'lease_id'=>'test-owner-000002','ip'=>'192.0.2.2']));
    $assert($status===200 && ($reply['allowed']??false)===true, 'second machine HTTP grant: '.json_encode([$status,$reply]));
    [$status,$reply] = $send(array_merge($base,['machine_id'=>202,'node_id'=>22,'token'=>'secret-b',
        'lease_id'=>'test-owner-000003','ip'=>'192.0.2.3']));
    $assert($status===200 && ($reply['allowed']??true)===false, 'third IP must be denied: '.json_encode([$status,$reply]));
    [$status,] = $send(array_merge($base,['token'=>'wrong']));
    $assert($status>=400,'invalid token unexpectedly accepted');
    [$status,] = $send(array_merge($base,['machine_id'=>202,'token'=>'secret-b','node_id'=>11]));
    $assert($status>=400,'cross-machine node unexpectedly accepted');
    [$status,] = $send(array_merge($base,['machine_id'=>303,'token'=>'secret-c','node_id'=>11]));
    $assert($status>=400,'disabled machine unexpectedly accepted');
    [$status,] = $send(array_merge($base,['node_id'=>33]));
    $assert($status>=400,'disabled node unexpectedly accepted');
    [$status,] = $send(array_merge($base,['user_id'=>8,'lease_id'=>'test-owner-000004']));
    $assert($status===403,'user in ineligible group unexpectedly accepted: '.$status);
    [$status,] = $send(array_merge($base,['user_id'=>9,'lease_id'=>'test-owner-000005']));
    $assert($status===403,'expired user unexpectedly accepted: '.$status);
    [$status,] = $send(array_merge($base,['lease_id'=>'bad']));
    $assert($status===422,'invalid lease ID unexpectedly accepted: '.$status);
    [$status,$reply] = $send(array_merge($base,['action'=>'release','ip'=>null]));
    $assert($status===200, 'release endpoint failed: '.json_encode([$status,$reply]));
    // After reducing the live account limit from 2 to 1, a renewal must
    // immediately revoke a deterministic excess-IP lease.
    [$status, $reply] = $send(array_merge($base, ['lease_id'=>'quota-owner-000001']));
    $assert($status===200 && ($reply['allowed']??false), 'reacquire first IP before quota shrink');
    DB::table('v2_user')->where('id', 7)->update(['device_limit'=>1]);
    [$status, $reply] = $send(array_merge($base, [
        'machine_id'=>202, 'node_id'=>22, 'token'=>'secret-b',
        'action'=>'renew', 'lease_id'=>'test-owner-000002', 'ip'=>null,
    ]));
    $assert($status===200 && ($reply['allowed']??true)===false, 'excess lease renewed after quota shrink: '.json_encode([$status,$reply]));
    [$status, $reply] = $send(array_merge($base, [
        'action'=>'renew', 'lease_id'=>'quota-owner-000001', 'ip'=>null,
    ]));
    $assert($status===200 && ($reply['allowed']??false), 'surviving lease did not renew');
    // A plugin must not make an otherwise ineligible injected user pass
    // the single-user authorization check.
    $node = \App\Models\Server::findOrFail(11);
    $filter = static function ($users, $ignoredNode) {
        return collect([(object) ['id'=>8, 'device_limit'=>1]]);
    };
    \App\Services\Plugin\HookManager::registerFilter('server.users.get', $filter);
    try {
        $candidate = \App\Services\ServerService::getAvailableUserForAdmission($node, 7);
        $assert($candidate === null, 'plugin injected different account into admission');
    } finally {
        \App\Services\Plugin\HookManager::remove('server.users.get', $filter);
    }
    echo "PASS: real Laravel HTTP kernel, machine auth, Redis leases, authorization denial\n";
} finally {
    foreach ($keys as $key) {
        Redis::del($key);
    }
}
