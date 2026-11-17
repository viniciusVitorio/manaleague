# Operator/workflow integration contract

Only this worktree is edited; no cloud actions have been executed.

* Entrypoints: `bash infra/eks-lab/scripts/lab.sh VERB ...` (Linux/WSL), `./infra/eks-lab/scripts/lab.ps1 VERB ...` (WSL distro `manaleague-docker`).
* `validate` is backend-disabled Terraform validation in isolated TF_DATA_DIR; no AWS/Kubernetes credentials used. Requires terraform and cached/downloadable pinned provider.
* Cloud verbs require ALL of `--allow-cloud --confirm-lab manaleague-eks-lab --expected-account 123456789012` plus AWS_ACCOUNT_ID, AWS_REGION, EKS_CLUSTER_NAME=manaleague-eks-lab. Never executed during implementation.
* `plan` writes `terraform/.state/provision.tfplan` and a hash/account/region/action sidecar; review separately, then `apply` consumes that pinned plan with `-input=false`. State remains isolated at `terraform/.state/terraform.tfstate`.
* Pipeline image artifact includes exactly smoke-tested `lab-app:dev` and `lab-web:dev`. Publish does not build. ECR vars are **full repository URLs**, must be `${account}.dkr.ecr.${region}.amazonaws.com/manaleague-eks-lab-{app,web}`. Immutable retry pulls existing digest and compares Docker config image ID with artifact; a non-identical rebuild at the same SHA fails rather than replacing/reusing untested content.
* Workflow Environment `eks-lab`: restrict allowed branch to exact `eks-lab`, reviewers recommended; never reuse production secrets. The credential action uses only vars.AWS_ROLE_ARN (Terraform github_deploy_role_arn), vars.AWS_ACCOUNT_ID, vars.AWS_REGION, OIDC. No static fallback.
* Environment gate reads LAB_DEPLOY_ENABLED from inside the Environment (not job-if where env-scoped vars are unavailable). It is opt-in; cloud jobs default skipped. Exact branch/event guards apply again before cloud commands.
* Deploy runner: `[self-hosted, linux, eks-lab]`, dedicated non-production Linux runner with stable egress in `api_public_access_cidrs`; never widen cluster endpoint per run.
* App deploy RBAC is namespace-only group `manaleague-eks-lab-deploy`. Bootstrap cluster RBAC/controller installation is a separate operator verb; the GitHub deploy role must never become cluster admin.
* Required deployment vars: AVATAR_BUCKET, APP_ROLE_ARN, VPC_CIDR, SUBNET_IDS (comma separated public ALB subnet IDs), ALLOWED_CIDRS (comma separated IPv4 CIDRs), ALB_SECURITY_GROUP_ID, **DB_HOST** (`rds_endpoint` Terraform output: hostname only), **VPC_ID** (`vpc_id` output), **RDS_MASTER_SECRET_ARN** (`rds_master_secret_arn` output). APP_URL is optional: an empty value discovers the ALB origin and rolls consumers before the real HTTP/WebSocket probe. Required GitHub Environment secrets: **APP_KEY and DEMO_PASSWORD only**, both lab-only. There is **no GitHub DB_PASSWORD secret or shared/production fallback**.
* Deploy performs read-only `rds:DescribeDBInstances` for the fixed identifier `manaleague-eks-lab`, proving exact ARN/account/region, available encrypted nonpublic PostgreSQL 17, database `manaleague_eks_lab`, master user `manaleague_lab`, endpoint exactly DB_HOST, port 5432, exclusive VPC_ID and active managed secret ARN. Invalid or still-creating instances fail before namespace writes; retry deployment after RDS is available. Existing namespace StatefulSets/PVCs also block deployment; deploy RBAC needs only read/list access to those resource types for the fail-closed check, not permission to create or mutate them. Endpoint syntax is `manaleague-eks-lab.<hash>.<region>.rds.amazonaws.com`, never a URL or host:port.
* Only after that binding is proven does deploy call Secrets Manager `GetSecretValue` with the **exact managed ARN and explicit AWSCURRENT version stage**. JSON username must match and the password must be a string of at least 24 characters. It stays in process memory and Kubernetes Secret stdin (not shell arguments, GitHub outputs, a generated file, or logged exceptions). The ephemeral in-memory DB_PASSWORD populates only the reserved namespaced Opaque Secret. GitHub IAM needs GetSecretValue on that exact managed ARN and DescribeDBInstances on the exact DB ARN; no RDS mutation permission. Pods use their namespaced Secret and do not need AWS Secrets Manager access.
* **Credential rotation is not automatically synchronized to Kubernetes.** Each deploy snapshots the current managed credential. RDS-managed secrets rotate every seven days by default; redeploy after rotation to refresh the namespaced Secret and roll consuming Pods. A long-running lab may lose DB connections until redeployed. Deleting the database also deletes its managed secret; do not treat this as retained credentials/backups.
* Manifests expected: `kubernetes/base`, `kubernetes/bootstrap/{resources,rbac}.yaml`, `kubernetes/jobs/{migrate,seed}.yaml`; JSON-compatible YAML is intentionally used for stdlib validation. Names migrate-__RELEASE__/seed-__RELEASE__ become full 40-character commit SHA names. Placeholders can be quoted or unquoted whole YAML scalars: __APP_URL__, __DB_HOST__, __AVATAR_BUCKET__, __AWS_REGION__, __VPC_CIDR__, __APP_ROLE_ARN__, __ALLOWED_CIDRS__, __SUBNET_IDS__, __ALB_SECURITY_GROUP_ID__ (required for the Terraform-owned custom SG). The renderer consumes existing quotes and emits JSON-escaped strings (no double quoting). ALLOWED_CIDRS/SUBNET_IDS are comma-separated strings for annotations.
* Bootstrap reserves empty `manaleague-secrets` (APP_KEY/DB_PASSWORD) and `manaleague-demo` (DEMO_PASSWORD), and installs `lab-secret-updater` Role/RoleBinding: the deployment group can get/update/patch only those existing names, never create/list arbitrary Secrets or manage RBAC. First deploy fills APP_KEY/DEMO_PASSWORD from the lab Environment and DB_PASSWORD only from the proven RDS-managed secret. Namespace application Role remains in bootstrap/rbac.yaml, not managed by CI.
* Production workflow copies: main push remains supported; release-triggered production side effects are disabled in this branch copy, daily manual tasks require refs/heads/main. Original production worktree remains untouched.
* Destruction drains only proven ALBs, CronJobs, release/owned Jobs and app Deployments while controllers still exist. **Any StatefulSet or PVC is unexpected in RDS mode and blocks cleanup before the first runtime write**; orphan namespace EBS checks remain fail-closed and never auto-delete legacy volumes. No generic tag cleanup, S3 emptying, ECR tag deletion or forced removal is implemented. Non-empty owned bucket/repositories intentionally block Terraform destruction; operator must explicitly review retention before deleting data. Storage and exact live RDS ownership/privacy checks run before runtime cleanup and again before Terraform apply. The reviewed destroy-plan sidecar pins `rds_identifier=manaleague-eks-lab` alongside plan hash/account/region/action. `destroy --delete-lab-data` explicitly consents to permanently deleting this isolated RDS database and managed secret through the operator-only Terraform step **after runtime cleanup**. The disposable Terraform DB disables deletion protection and skips a final snapshot: export/back up required data separately first; scripts create no remote backup/snapshot. Disable LAB_DEPLOY_ENABLED and wait for existing pipelines before teardown.

## Exact commands (examples, NOT executed against AWS)

```bash
# No cloud API; pinned provider registry may be contacted by init.
bash infra/eks-lab/scripts/lab.sh validate
# No cloud API; requires kubectl + kubeconform; schemas may be fetched from GitHub.
python3 infra/eks-lab/scripts/validate_manifests.py
# Offline unit tests: every external command is explicitly mocked;
# an unmocked subprocess.run raises instead of reaching the OS.
python3 -m unittest discover -s infra/eks-lab/tests -v
# actionlint custom self-hosted label configuration:
actionlint -config-file infra/eks-lab/tests/actionlint.yaml -shellcheck= .github/workflows/*.yml
```

Future operator-only provisioning, after authorized configuration/credentials (never run here):

```bash
export AWS_ACCOUNT_ID=123456789012 AWS_REGION=us-east-1 EKS_CLUSTER_NAME=manaleague-eks-lab
# Supply terraform/terraform.tfvars (ignored) from terraform.tfvars.example.
# This tuple is required on EVERY cloud verb; no implicit provision/deploy/destroy.
flags=(--allow-cloud --confirm-lab manaleague-eks-lab --expected-account "$AWS_ACCOUNT_ID")
bash infra/eks-lab/scripts/lab.sh plan "${flags[@]}"
# Review .state/provision.tfplan with terraform show before explicitly consuming it:
bash infra/eks-lab/scripts/lab.sh apply "${flags[@]}"
bash infra/eks-lab/scripts/lab.sh bootstrap "${flags[@]}"
# Normally workflow owns publish/deploy; manual usage requires all vars documented above.
bash infra/eks-lab/scripts/lab.sh publish "${flags[@]}"
bash infra/eks-lab/scripts/lab.sh deploy "${flags[@]}"
# Review/empty ONLY Terraform-owned ECR/S3 lab data separately; no purge fallback.
bash infra/eks-lab/scripts/lab.sh destroy-plan "${flags[@]}"
# Review .state/destroy.tfplan; permanently deletes isolated RDS data + managed secret (no final snapshot):
bash infra/eks-lab/scripts/lab.sh destroy "${flags[@]}" --delete-lab-data
```

Windows entrypoint has identical arguments, e.g. `./infra/eks-lab/scripts/lab.ps1 validate`.
It invokes WSL distro `manaleague-docker` without shell interpolation. Configure/export
lab variables and lab-only AWS profile **inside that distro**; Windows environment
variables are not implicitly copied into WSL. Deploy runner needs Python 3.10+, AWS
CLI v2 and kubectl; bootstrap operator additionally needs Terraform and Helm.

`bash infra/eks-lab/scripts/smoke.sh` is LOCAL DOCKER ONLY: copies compose/smoke
files to a private temp directory, generates disposable credentials, uses unique
compose project manaleague-eks-lab-smoke-*, never builds, and removes only that
project's containers/volumes afterwards. It reuses runtime/local smoke tests.
It is configured in CI and was also exercised end-to-end locally by the integrating agent. Its copied nonsecret edge configuration is readable by nginx UID101; credentials remain private.

## Integrated constraints (verified locally)

1. Terraform GitHub deploy role includes ecr:DescribeRepositories, scoped to the two lab repositories, matching the publisher immutability check.
2. Terraform provisions a dedicated ALB SG and static node ingress from that SG.
   The Ingress references that SG (required token __ALB_SECURITY_GROUP_ID__,
   workflow Environment variable ALB_SECURITY_GROUP_ID = alb_security_group_id output)
   and must not request controller mutation of node security groups. Renderer and
   workflow already accept this token/variable. Confirm ingress CIDR restrictions
   are enforced by the custom SG; ALB inbound-cidrs annotation does not replace its rules.
