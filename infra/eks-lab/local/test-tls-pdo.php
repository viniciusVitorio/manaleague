<?php
// Exercises the installed Laravel PDO/libpq connector, not the cloud guard.
// Fixture certificates must NEVER be used for RDS mode or shipped in the image.
require '/var/www/html/vendor/autoload.php';
$mode = $argv[1];
$config = [
    'host' => $mode === 'wrong-host' ? 'wrong-postgres' : 'postgres',
    'port' => '5432', 'database' => 'manaleague_eks_lab',
    'username' => 'manaleague_lab', 'password' => getenv('TEST_DB_PASSWORD'),
    'charset' => 'utf8', 'sslmode' => 'verify-full',
];
// Deliberately no sslrootcert config/DSN key: verify libpq uses PGSSLROOTCERT.
try {
    $pdo = (new Illuminate\Database\Connectors\PostgresConnector())->connect($config);
    if ($mode !== 'trusted') {
        throw new RuntimeException('TLS verification unexpectedly accepted '.$mode);
    }
    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('TLS fixture SELECT 1 failed');
    }
    echo "PASS: Laravel PDO verifies fixture CA and hostname using PGSSLROOTCERT\n";
} catch (PDOException $error) {
    $pattern = match ($mode) {
        'wrong-host' => '/does not match host name/i',
        'untrusted' => '/certificate verify failed/i',
        'missing-ca' => '/root certificate file.*does not exist/is',
        default => null,
    };
    if ($pattern === null || !preg_match($pattern, $error->getMessage())) {
        fwrite(STDERR, 'Unexpected TLS fixture failure: '.$error->getMessage()."\n");
        exit(1);
    }
    echo 'PASS: Laravel PDO rejects '.$mode." under verify-full\n";
}
