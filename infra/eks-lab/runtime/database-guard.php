<?php

/** Fail closed before any Laravel bootstrap, DB access, migration or synthetic seed. */
function labDatabaseGuard(): void
{
    $get = static fn (string $name): string => getenv($name) === false ? '' : (string) getenv($name);
    $refuse = static function (string $reason): never {
        fwrite(STDERR, 'Lab database guard refused: '.$reason."\n");
        exit(64);
    };
    if ($get('APP_ENV') !== 'eks-lab' || $get('DB_CONNECTION') !== 'pgsql'
        || $get('DB_URL') !== '' || $get('DB_DATABASE') !== 'manaleague_eks_lab') {
        $refuse('exact eks-lab environment and isolated PostgreSQL database without DB_URL required');
    }
    if ($get('APP_KEY') === '') {
        $refuse('APP_KEY required');
    }
    $mode = $get('LAB_DATABASE_MODE');
    if ($mode === 'local') {
        if ($get('DB_HOST') !== 'postgres' || $get('DB_SSLMODE') !== 'disable') {
            $refuse('local mode requires disposable postgres host and explicit SSL disable');
        }
        return;
    }
    if ($mode !== 'rds') {
        $refuse('explicit LAB_DATABASE_MODE=rds or local required');
    }
    $region = $get('AWS_DEFAULT_REGION');
    if (!preg_match('/\A[a-z]{2}(?:-[a-z]+)+-[0-9]+\z/D', $region)) {
        $refuse('literal AWS_DEFAULT_REGION required');
    }
    $hostPattern = '/\Amanaleague-eks-lab\.[a-z0-9]+\.'.preg_quote($region, '/').'\.rds\.amazonaws\.com\z/D';
    if (!preg_match($hostPattern, $get('DB_HOST'))) {
        $refuse('only the exact lab RDS instance endpoint in the configured region is allowed');
    }
    $ca = __DIR__.'/certs/global-bundle.pem';
    if ($get('DB_SSLMODE') !== 'verify-full' || $get('PGSSLROOTCERT') !== $ca
        || !is_readable($ca) || filesize($ca) === 0) {
        $refuse('RDS requires verify-full and the readable vendored RDS global CA bundle');
    }
    if ($get('DB_USERNAME') !== 'manaleague_lab' || $get('DB_PORT') !== '5432') {
        $refuse('exact lab RDS database user and PostgreSQL port required');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    labDatabaseGuard();
}
