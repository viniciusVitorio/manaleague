#!/bin/sh
set -eu
cd "$(dirname "$0")/../../.."
f=infra/eks-lab/runtime/health.php
[ -f "$f" ] || { echo 'FAIL: PHP health controller missing'; exit 1; }
# Built image test supplies the real autoloader and PostgreSQL. /up must not query DB.
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "infra/eks-lab/runtime/health.php";
if (labHealth("/up", static function () { throw new RuntimeException("DB queried by liveness"); }) !== 200) exit(1);
if (labHealth("/readyz", static function () { throw new RuntimeException("DB unavailable"); }) !== 503) exit(2);
if (labHealth("/readyz", static function () {}) !== 200) exit(3);
'
echo 'PASS: liveness independent of DB; readiness fails closed'
