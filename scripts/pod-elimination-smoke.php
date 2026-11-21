<?php

use App\Models\User;
use App\Services\MatchResultService;
use App\Services\PairingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Disposable browser fixture. Never reads .env or touches the configured application database.
 * php scripts/pod-elimination-smoke.php [--cleanup]
 * Start artisan serve with the exact environment printed below (PowerShell instructions in docs).
 */
$root = dirname(__DIR__);
$database = $root.'/storage/app/private/pod-elimination-smoke.sqlite';
$metadata = $root.'/storage/app/private/pod-elimination-smoke.json';
if (in_array('--cleanup', $argv, true)) {
    $saved = is_file($metadata) ? json_decode(file_get_contents($metadata), true) : [];
    if (isset($saved['session_id']) && preg_match('/^[a-zA-Z0-9]{40}$/', $saved['session_id'])) {
        $sessionFile = $root.'/storage/framework/sessions/'.$saved['session_id'];
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
    foreach ([$database, $database.'-wal', $database.'-shm', $metadata] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    echo "Disposable pod smoke fixture removed.\n";
    exit;
}
if (is_file($database)) {
    fwrite(STDERR, "Fixture exists. Run --cleanup first; no database was changed.\n");
    exit(1);
}
if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Cached configuration is not permitted for this disposable fixture.');
}
putenv('APP_ENV=testing');
putenv('APP_KEY=base64:Zwg8Vi58yyja0i2uMfYZRw0klTp/PKUmMTUpo/EppbY=');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$database);
putenv('DB_URL=');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=file');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');
putenv('BROADCAST_CONNECTION=null');
putenv('RANKED_AUTO_YEARLY_SEASON=false');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
// Disable environment loading explicitly: production configuration must never enter this script.
$app->loadEnvironmentFrom('.env.pod-smoke-does-not-exist');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    throw new RuntimeException('Clear cached configuration in this worktree before creating the fixture.');
}
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Unsafe fixture database configuration.');
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0777, true);
}
touch($database);
Artisan::call('migrate', ['--force' => true]);
$owner = User::factory()->create(['name' => 'Pod smoke organizer', 'email' => 'pod-smoke@example.test', 'email_verified_at' => now()]);
Auth::login($owner);
$urls = [];
foreach (['semifinals', 'finals', 'finished'] as $state) {
    $t = $owner->tournaments()->create(['name' => 'Pod smoke '.$state, 'format' => 'Commander', 'system' => 'pod_elimination',
        'tournament_date' => today(), 'max_players' => 8, 'pod_qualifiers' => 2, 'pod_consolation' => true,
        'public_slug' => 'pod-smoke-'.$state, 'public_token' => Str::random(40), 'invite_token' => Str::random(40)]);
    foreach (['Ajani', 'Chandra', 'Jace', 'Liliana', 'Nissa', 'Teferi', 'Karn', 'Elspeth'] as $name) {
        $t->players()->create(['name' => $name])->forceFill(['checked_in_at' => now()])->save();
    }
    app(PairingService::class)->generateLeague($t);
    if ($state !== 'semifinals') {
        foreach ($t->matches()->get() as $m) {
            app(MatchResultService::class)->record($t, $m, ['placements' => array_combine($m->pod_player_ids, [1, 2, 3, 4]), 'confirm' => 1]);
        }
    }
    if ($state === 'finished') {
        foreach ($t->matches()->where('round', 2)->get() as $m) {
            app(MatchResultService::class)->record($t, $m, ['placements' => array_combine($m->pod_player_ids, [1, 2, 3, 4]), 'confirm' => 1]);
        }
    }
    $urls[$state] = ['organizer' => '/tournaments/'.$t->id, 'public' => '/t/'.$t->public_slug, 'tv' => '/t/'.$t->public_slug.'/tv'];
}
$session = app('session')->driver();
$session->start();
$session->put(Auth::guard()->getName(), $owner->id);
$session->save();
$name = config('session.cookie');
$cookie = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
file_put_contents($metadata, json_encode(['database' => $database, 'session_id' => $session->getId(), 'cookie' => ['name' => $name, 'value' => $cookie], 'urls' => $urls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['database' => $database, 'browser_metadata' => $metadata, 'urls' => $urls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
