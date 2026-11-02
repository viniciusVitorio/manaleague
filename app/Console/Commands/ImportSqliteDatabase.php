<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Copia os dados do banco SQLite antigo para o PostgreSQL (conexao padrao).
 *
 * O esquema vem das migrations (mesmos nomes de tabela e coluna); aqui so os dados sao copiados,
 * tabela por tabela, em ordem de chave estrangeira e dentro de UMA transacao: ou entra tudo, ou nada.
 * No fim as sequencias dos ids sao ajustadas e as contagens conferidas tabela a tabela.
 */
class ImportSqliteDatabase extends Command
{
    protected $signature = 'db:import-sqlite
        {path : Caminho do arquivo .sqlite (backup ou o banco antigo)}
        {--if-empty : Nao faz nada se o Postgres ja tiver usuarios (seguro para rodar a cada deploy)}
        {--force : Apaga os dados que ja existirem no Postgres antes de importar}';

    protected $description = 'Importa os dados de um banco SQLite do ManaLeague para o PostgreSQL';

    /** Tabelas que nao sao copiadas: controle interno do SQLite e o historico das migrations do destino. */
    private const SKIP_TABLES = ['sqlite_sequence', 'migrations'];

    private const CHUNK = 500;

    public function handle(): int
    {
        $target = DB::connection();
        if ($target->getDriverName() !== 'pgsql') {
            $this->error('A conexao padrao precisa ser PostgreSQL (DB_CONNECTION=pgsql).');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            if ($this->option('if-empty')) {
                $this->info("Sem banco SQLite em {$path}: nada para importar.");

                return self::SUCCESS;
            }
            $this->error("Arquivo nao encontrado: {$path}");

            return self::FAILURE;
        }

        // O destino precisa ter o esquema atual antes de receber os dados.
        Artisan::call('migrate', ['--force' => true], $this->output);

        if ($target->table('users')->exists()) {
            if ($this->option('if-empty')) {
                $this->info('O PostgreSQL ja tem dados: importacao ignorada.');

                return self::SUCCESS;
            }
            if (! $this->option('force')) {
                $this->error('O PostgreSQL ja tem dados. Use --force para apagar tudo e importar de novo.');

                return self::FAILURE;
            }
        }

        $source = $this->openSqlite($path);

        $sourceTables = collect($source->select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP_TABLES, true))->values()->all();
        $targetColumns = $this->targetColumns($target);

        $missing = array_values(array_diff($sourceTables, array_keys($targetColumns)));
        foreach ($missing as $table) {
            $this->warn("Tabela {$table} nao existe no PostgreSQL (nenhuma migration a cria): ignorada.");
        }
        $tables = $this->orderByForeignKeys($target, array_values(array_intersect($sourceTables, array_keys($targetColumns))));

        $counts = [];
        try {
            $target->transaction(function () use ($source, $target, $tables, $targetColumns, &$counts): void {
                // Limpa tudo o que as migrations possam ter semeado (temporadas, formatos...) e zera os ids.
                $list = implode(', ', array_map(fn ($t) => $this->quote($t), array_keys($targetColumns)));
                $target->statement("TRUNCATE {$list} RESTART IDENTITY CASCADE");

                foreach ($tables as $table) {
                    $counts[$table] = $this->copyTable($source, $target, $table, $targetColumns[$table]);
                }
                $this->resetSequences($target, $tables, $targetColumns);
            });
        } catch (Throwable $e) {
            $this->error('Importacao desfeita (nada foi gravado): '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->verify($source, $target, $tables, $counts) ? self::SUCCESS : self::FAILURE;
    }

    private function openSqlite(string $path): Connection
    {
        config(['database.connections.sqlite_import' => [
            'driver' => 'sqlite',
            'database' => realpath($path),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        return DB::connection('sqlite_import');
    }

    /** @return array<string, array<string, string>> tabela => [coluna => tipo do Postgres] */
    private function targetColumns(Connection $target): array
    {
        $rows = $target->select("SELECT table_name, column_name, data_type FROM information_schema.columns
            WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position");
        $columns = [];
        foreach ($rows as $row) {
            if ($row->table_name !== 'migrations') {
                $columns[$row->table_name][$row->column_name] = $row->data_type;
            }
        }

        return $columns;
    }

    /**
     * Pais antes dos filhos, para cada INSERT encontrar a linha referenciada.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderByForeignKeys(Connection $target, array $tables): array
    {
        $parents = array_fill_keys($tables, []);
        $edges = $target->select("SELECT DISTINCT tc.table_name AS child, ccu.table_name AS parent
            FROM information_schema.table_constraints tc
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
                AND ccu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()");
        foreach ($edges as $edge) {
            if ($edge->child !== $edge->parent && isset($parents[$edge->child], $parents[$edge->parent])) {
                $parents[$edge->child][] = $edge->parent;
            }
        }

        $ordered = [];
        while ($parents !== []) {
            $ready = array_keys(array_filter($parents, fn ($deps) => array_diff($deps, $ordered) === []));
            if ($ready === []) {
                throw new RuntimeException('Ciclo de chaves estrangeiras entre: '.implode(', ', array_keys($parents)));
            }
            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($parents[$table]);
            }
        }

        return $ordered;
    }

    /** @param  array<string, string>  $types */
    private function copyTable(Connection $source, Connection $target, string $table, array $types): int
    {
        $sourceColumns = collect($source->select('PRAGMA table_info('.$this->quote($table).')'))->pluck('name')->all();
        $dropped = array_diff($sourceColumns, array_keys($types));
        if ($dropped !== []) {
            $this->warn("{$table}: coluna(s) sem equivalente no esquema atual, ignorada(s): ".implode(', ', $dropped));
        }
        $columns = array_values(array_intersect($sourceColumns, array_keys($types)));
        $orderBy = in_array('id', $columns, true) ? 'id' : 'rowid';

        $copied = 0;
        $select = 'SELECT '.implode(', ', array_map(fn ($c) => $this->quote($c), $columns))
            .' FROM '.$this->quote($table).' ORDER BY '.$orderBy;
        $batch = [];
        foreach ($source->cursor($select) as $row) {
            $record = [];
            foreach ($columns as $column) {
                $record[$column] = $this->convert($row->{$column}, $types[$column]);
            }
            $batch[] = $record;
            if (count($batch) === self::CHUNK) {
                $target->table($table)->insert($batch);
                $copied += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $target->table($table)->insert($batch);
            $copied += count($batch);
        }
        $this->line(sprintf('  %-34s %6d linha(s)', $table, $copied));

        return $copied;
    }

    /** SQLite guarda booleanos como 0/1 e nao tem tipos rigidos; o Postgres exige o tipo certo. */
    private function convert(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => (bool) (is_string($value) ? in_array(strtolower($value), ['1', 'true', 't', 'yes'], true) : $value),
            'smallint', 'integer', 'bigint' => is_numeric($value) ? (int) $value : $value,
            'bytea' => is_resource($value) ? stream_get_contents($value) : $value,
            default => $value,
        };
    }

    /**
     * Os ids vieram prontos do SQLite; sem isso o proximo INSERT tentaria o id 1 de novo.
     *
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $columns
     */
    private function resetSequences(Connection $target, array $tables, array $columns): void
    {
        foreach ($tables as $table) {
            if (! isset($columns[$table]['id'])) {
                continue;
            }
            $sequence = $target->selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', [$this->quote($table), 'id'])->seq ?? null;
            if ($sequence) {
                $target->statement("SELECT setval(?, COALESCE((SELECT MAX(id) FROM {$this->quote($table)}), 1),
                    (SELECT MAX(id) FROM {$this->quote($table)}) IS NOT NULL)", [$sequence]);
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, int>  $copied
     */
    private function verify(Connection $source, Connection $target, array $tables, array $copied): bool
    {
        $rows = [];
        $ok = true;
        foreach ($tables as $table) {
            $expected = (int) $source->table($table)->count();
            $actual = (int) $target->table($table)->count();
            $match = $expected === $actual && $actual === $copied[$table];
            $ok = $ok && $match;
            $rows[] = [$table, $expected, $actual, $match ? 'ok' : 'DIFERENTE'];
        }
        $this->table(['Tabela', 'SQLite', 'PostgreSQL', ''], $rows);
        $ok ? $this->info('Importacao concluida: todas as contagens conferem.')
            : $this->error('Ha tabelas com contagem diferente: confira antes de colocar no ar.');

        return $ok;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
