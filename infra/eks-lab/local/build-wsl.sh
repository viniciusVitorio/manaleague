#!/bin/sh
set -eu
# Execute inside WSL; no host .env, vendor, storage, .git or unrelated infra enters context.
source=/mnt/d/Projetos/manaleague-eks-lab
context=/root/manaleague-eks-lab-context
mkdir -p "$context"
[ ! -L "$context" ] || { echo 'Refusing symlink build context' >&2; exit 64; }
# Remove only generated source trees in our dedicated context, never another project.
# Keep local/.env.local so restarting a disposable existing DB does not rotate its credentials.
for path in app bootstrap config database resources routes public deploy/modsecurity infra/eks-lab/docker infra/eks-lab/runtime; do
    rm -rf "$context/$path"
done
cd "$source"
tar -cf - app bootstrap/app.php bootstrap/providers.php config database/migrations database/seeders resources routes public artisan composer.json composer.lock deploy/modsecurity infra/eks-lab/docker infra/eks-lab/runtime infra/eks-lab/local | tar -xf - -C "$context"
# Normalize source text without changing user checkout.
find "$context/infra/eks-lab" -type f -exec sed -i 's/\r$//' {} +
cd "$context"
docker build -f infra/eks-lab/docker/Dockerfile --target app -t lab-app:dev .
docker build -f infra/eks-lab/docker/Dockerfile --target web -t lab-web:dev .
