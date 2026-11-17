<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/up', '/readyz'], true)) {
    try {
        require __DIR__.'/../vendor/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        require __DIR__.'/../infra/eks-lab/runtime/health.php';
        $status = labHealth($path);
    } catch (Throwable) {
        $status = 503;
    }
    http_response_code($status);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo $status === 200 ? "OK\n" : "Unavailable\n";
    exit;
}
require __DIR__.'/lab-original.php';
