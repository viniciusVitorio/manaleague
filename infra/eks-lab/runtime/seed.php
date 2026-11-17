<?php

require __DIR__.'/database-guard.php';
labDatabaseGuard();

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

if (!$app->environment('eks-lab') || config('database.default') !== 'pgsql'
    || !empty(config('database.connections.pgsql.url'))
    || config('database.connections.pgsql.host') !== getenv('DB_HOST')
    || config('database.connections.pgsql.sslmode') !== getenv('DB_SSLMODE')
    || config('database.connections.pgsql.database') !== 'manaleague_eks_lab') {
    fwrite(STDERR, "Seed refused: exact isolated lab database required.\n");
    exit(64);
}
$password = getenv('DEMO_PASSWORD') ?: '';
if (strlen($password) < 20) {
    fwrite(STDERR, "Seed requires DEMO_PASSWORD of at least 20 characters.\n");
    exit(64);
}
config(['broadcasting.default' => 'null', 'mail.default' => 'log']);
try {
    DB::transaction(function () use ($password): void {
        DB::select('SELECT pg_advisory_xact_lock(719621834)');
        if (!Schema::hasTable('eks_lab_seed_markers')) {
            Schema::create('eks_lab_seed_markers', function ($table): void {
                $table->string('name')->primary();
                $table->timestamp('created_at');
            });
        }
        if (DB::table('eks_lab_seed_markers')->where('name', 'synthetic-demo-v1')->exists()) {
            echo "Synthetic demo already seeded; no changes.\n";
            return;
        }
        if (App\Models\User::query()->exists()) {
            throw new RuntimeException('Refusing to seed a nonempty unmarked database.');
        }
        app()->call([app(Database\Seeders\DemoSeeder::class), 'run']);
        foreach (App\Models\User::query()->get() as $user) {
            if (!str_ends_with($user->email, '@manaleague.test')) {
                throw new RuntimeException('Non-synthetic account detected; rolling back.');
            }
            $user->forceFill([
                'password' => Hash::make($user->email === 'demo@manaleague.test' ? $password : bin2hex(random_bytes(32))),
                'email_verified_at' => now(), 'email_verification_required' => false,
            ])->save();
        }
        DB::table('eks_lab_seed_markers')->insert(['name' => 'synthetic-demo-v1', 'created_at' => now()]);
        echo "Synthetic demo seeded: demo@manaleague.test (password supplied by secret).\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic seed failed: '.$error->getMessage()."\n");
    exit(1);
}
