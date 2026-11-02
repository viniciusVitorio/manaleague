<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Migracao SQLite -> PostgreSQL (php artisan db:import-sqlite). Roda so com o Postgres (CI). */
class ImportSqliteDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A importacao grava no PostgreSQL: rode com DB_CONNECTION=pgsql.');
        }

        $this->file = tempnam(sys_get_temp_dir(), 'ml-import-').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->file);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT, batch INTEGER)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000000_create_users_table', 1)");
        // Coluna de uma feature revertida e tabela que nao existe mais: devem ser ignoradas sem quebrar.
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, password TEXT,
            is_admin INTEGER, show_deck_ownership INTEGER, theme TEXT, legacy_flag INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE collection_items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, scryfall_id TEXT,
            name TEXT, foil INTEGER, condition TEXT, quantity INTEGER, for_trade INTEGER, price_usd NUMERIC)');
        $pdo->exec('CREATE TABLE old_feature (id INTEGER PRIMARY KEY, note TEXT)');
        // Filho inserido antes do pai e ids com buraco: a ordem e as sequencias precisam se ajustar.
        $pdo->exec("INSERT INTO collection_items VALUES (3, 7, 'E3285E6B-3E79-4D7C-BF96-D920F973B80D', 'Lightning Bolt', 1, 'NM', 4, 1, 1.25)");
        $pdo->exec("INSERT INTO users VALUES (2, 'Ana', 'ana@example.com', 'x', 0, 1, 'dark', 1, '2026-10-01 10:00:00', '2026-10-01 10:00:00')");
        $pdo->exec("INSERT INTO users VALUES (7, 'Bia', 'bia@example.com', 'y', 1, 0, 'light', 0, '2026-10-02 10:00:00', '2026-10-02 10:00:00')");
        $pdo->exec("INSERT INTO old_feature VALUES (1, 'some')");
        $pdo = null;
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_import');
        if (isset($this->file) && is_file($this->file)) {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_copies_rows_converts_types_and_moves_sequences(): void
    {
        $this->artisan('db:import-sqlite', ['path' => $this->file])
            ->expectsOutputToContain('users: coluna(s) sem equivalente no esquema atual, ignorada(s): legacy_flag')
            ->expectsOutputToContain('Tabela old_feature nao existe no PostgreSQL')
            ->expectsOutputToContain('todas as contagens conferem')
            ->assertSuccessful();

        $this->assertSame(['ana@example.com', 'bia@example.com'], User::orderBy('id')->pluck('email')->all());
        $bia = User::find(7);
        $this->assertTrue($bia->is_admin);
        $this->assertFalse((bool) $bia->show_deck_ownership);
        $item = DB::table('collection_items')->first();
        $this->assertTrue($item->foil);
        $this->assertSame('e3285e6b-3e79-4d7c-bf96-d920f973b80d', $item->scryfall_id);
        $this->assertSame('1.25', $item->price_usd);

        // O proximo cadastro continua do maior id importado, sem colidir.
        $this->assertSame(8, User::factory()->create()->id);
    }

    public function test_never_overwrites_existing_data_without_force(): void
    {
        $existing = User::factory()->create();

        $this->artisan('db:import-sqlite', ['path' => $this->file, '--if-empty' => true])
            ->expectsOutputToContain('importacao ignorada')->assertSuccessful();
        $this->artisan('db:import-sqlite', ['path' => $this->file])
            ->expectsOutputToContain('--force')->assertFailed();
        $this->assertSame([$existing->id], User::pluck('id')->all());

        $this->artisan('db:import-sqlite', ['path' => $this->file, '--force' => true])->assertSuccessful();
        $this->assertSame([2, 7], User::orderBy('id')->pluck('id')->all());
    }

    public function test_missing_file_is_a_no_op_only_in_if_empty_mode(): void
    {
        $this->artisan('db:import-sqlite', ['path' => $this->file.'.nope', '--if-empty' => true])->assertSuccessful();
        $this->artisan('db:import-sqlite', ['path' => $this->file.'.nope'])->assertFailed();
    }
}
