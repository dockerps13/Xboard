<?php
/**
 * Populate a disposable SQLite database for real HTTP integration.
 * This file is only executed in GitHub Actions with a temporary database.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || !str_contains((string) config('database.connections.sqlite.database'), 'v105-stage')) {
    throw new RuntimeException('Refusing to initialize a non-staging database');
}
// Global HTTP InitializePlugins middleware queries this table even when no
// plugins are installed. Keep the isolated staging fixture empty.
Schema::create('v2_plugins', static function (Blueprint $t): void {
    $t->increments('id');
    $t->boolean('is_enabled')->default(false);
});
Schema::create('v2_server_machine', static function (Blueprint $t): void {
    $t->increments('id');
    $t->string('name')->nullable();
    $t->string('token');
    $t->boolean('is_active')->default(true);
    $t->integer('last_seen_at')->nullable();
    $t->timestamps();
});
Schema::create('v2_server', static function (Blueprint $t): void {
    $t->increments('id');
    $t->integer('machine_id');
    $t->boolean('enabled')->default(true);
    $t->text('group_ids')->nullable();
    $t->timestamps();
});
Schema::create('v2_user', static function (Blueprint $t): void {
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
    ['id'=>101,'name'=>'stage-a','token'=>'secret-a','is_active'=>true],
    ['id'=>202,'name'=>'stage-b','token'=>'secret-b','is_active'=>true],
]);
DB::table('v2_server')->insert([
    ['id'=>11,'machine_id'=>101,'enabled'=>true,'group_ids'=>'[1]'],
    ['id'=>22,'machine_id'=>202,'enabled'=>true,'group_ids'=>'[1]'],
]);
DB::table('v2_user')->insert([
    ['id'=>7,'group_id'=>1,'transfer_enable'=>1000000,'u'=>0,'d'=>0,'expired_at'=>time()+3600,'banned'=>0,'device_limit'=>2],
]);
echo "PASS: isolated staging SQLite fixtures ready\n";
