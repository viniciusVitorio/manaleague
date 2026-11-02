<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Historico de deck so aceita INSERT: o mesmo bloqueio que os triggers do SQLite fazem
// (2026_10_20_030001_create_deck_history), agora tambem no PostgreSQL.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION manaleague_immutable_deck_history() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Immutable deck history';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        foreach (['deck_versions', 'deck_uses'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation} ON {$table}");
                DB::unprepared("CREATE TRIGGER {$table}_no_{$operation} BEFORE {$operation} ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION manaleague_immutable_deck_history()");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['deck_versions', 'deck_uses'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation} ON {$table}");
            }
        }
        DB::unprepared('DROP FUNCTION IF EXISTS manaleague_immutable_deck_history()');
    }
};
