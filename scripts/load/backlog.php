<?php

/*
 * Notification backlog drill (DEVELOPMENT ONLY; docs/R46_PERF.md section 3):
 *   php scripts/load/backlog.php <messages>
 * Run against a perf world (same guards and env as world.php) with QUEUE_CONNECTION=database and MAIL_MAILER=smtp
 * pointing at scripts/load/stub-smtp.mjs. Sends <messages> payment notifications through the real comms router
 * (each: the in-app record + a push copy + an email copy), then prints the delivery rows by channel and status.
 * Time the cron worker afterwards: php artisan queue:work --stop-when-empty --max-time=50
 */

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if (! app()->environment('local', 'testing') || ! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'ehail_perf_')))) {
    fwrite(STDERR, "Refusing: development perf databases only.\n");
    exit(2);
}

$command = $argv[1] ?? 'fill';

if ($command === 'counts') {
    echo json_encode(DB::table('notification_deliveries')->selectRaw('channel, status, count(*) as n')->groupBy('channel', 'status')->get(), JSON_PRETTY_PRINT).PHP_EOL;
    echo 'jobs pending: '.DB::table('jobs')->count().PHP_EOL;
    exit(0);
}

$messages = (int) $command;
$users = User::where('email', 'like', 'load-passenger-%')->orderBy('id')->get();
$router = app(NotificationRouter::class);
$started = microtime(true);
$run = bin2hex(random_bytes(3));

for ($i = 0; $i < $messages; $i++) {
    $user = $users[$i % $users->count()];
    $router->send(new CommsMessage($user, 'ride_paid', 'backlog:'.$run.':'.$i.':'.$user->id, 'Payment received', 'Your card was charged R85.00 for your trip.', '/ride/trips'));
}

printf("%d messages routed in %.1f s\n", $messages, microtime(true) - $started);
echo json_encode(DB::table('notification_deliveries')->selectRaw('channel, status, count(*) as n')->groupBy('channel', 'status')->get()).PHP_EOL;
echo 'jobs pending: '.DB::table('jobs')->count().PHP_EOL;
