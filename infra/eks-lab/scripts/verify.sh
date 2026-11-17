#!/usr/bin/env bash
# Local verification only: never invokes AWS CLI or a Kubernetes API.
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$root"
for tool in python3 terraform kubectl kubeconform actionlint helm; do
  command -v "$tool" >/dev/null || { echo "Required local tool missing: $tool" >&2; exit 127; }
done
python3 -m unittest discover -s infra/eks-lab/tests -v
python3 infra/eks-lab/local/test-rds-contract.py
python3 -I -B -m unittest discover -s infra/eks-lab/runner/tests -v
python3 -I -B infra/eks-lab/runner/operator.py render --account 123456789012 --region us-east-1 --vpc-cidr 10.42.0.0/16 \
  | kubeconform -strict -summary -kubernetes-version 1.37.0
for script in infra/eks-lab/scripts/*.sh infra/eks-lab/local/*.sh infra/eks-lab/runtime/*.sh; do bash -n "$script"; done
actionlint -config-file infra/eks-lab/tests/actionlint.yaml -shellcheck= .github/workflows/*.yml
python3 infra/eks-lab/scripts/validate_manifests.py
work="$(mktemp -d "${TMPDIR:-/tmp}/eks-lab-offline.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT
export TF_DATA_DIR="$work/providers" TF_WORKSPACE=default TF_IN_AUTOMATION=true
# Prevent shell-level injected Terraform args from selecting another workspace/backend.
unset TF_CLI_ARGS TF_CLI_ARGS_init TF_CLI_ARGS_validate TF_CLI_ARGS_test TF_CLI_CONFIG_FILE || true
helm pull aws-load-balancer-controller --repo https://aws.github.io/eks-charts --version 3.6.3 --destination "$work"
python3 infra/eks-lab/addons/validate_offline.py --terraform terraform --helm helm --chart "$work/aws-load-balancer-controller-3.6.3.tgz"
printf '%s\n' 'PASS: local scripts, workflows, schemas, mocked Terraform plans and controller chart. No AWS or Kubernetes API contacted.'
