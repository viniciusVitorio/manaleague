# Independent AWS-only Terraform root

This root is isolated from all existing/production state and resources. It uses only `hashicorp/aws` (pinned **6.73.0** in configuration and `.terraform.lock.hcl`), no Kubernetes/Helm providers/modules, and a local backend at **`.state/terraform.tfstate`** relative to this root. Never copy production state here, point backend overrides at production, or commit state/tfvars/plan files. Preserve the lockfile.

## Deliberate defaults

- Fixed name and namespace `manaleague-eks-lab`, region `us-east-1`, tags `Project=manaleague-eks-lab`, `Environment=eks-lab`.
- Dedicated VPC, two public EKS subnets and two separate private database subnets across two AZs. Only public subnets route to the Internet gateway; the private route table has only VPC-local routing. **No NAT**, SSH/key pair, production DNS, load balancer provisioned by Terraform, or existing network reuse.
- **Required Amazon RDS PostgreSQL 17**, Single-AZ `db.t4g.micro`, encrypted 20GiB gp3, storage autoscaling disabled (`max_allocated_storage=0`), no public IP/endpoint accessibility. Only TCP/5432 from the existing dedicated EKS node SG; no database CIDR ingress. PostgreSQL is not an in-cluster StatefulSet.
- **Kubernetes 1.37** with `upgrade_policy.support_type=STANDARD`. AWS's calendar reviewed on **November 16, 2026** lists it as standard support through **December 1, 2027**. 1.35/1.36 are optional reviewed alternatives; recheck the calendar before deployment. STANDARD prevents opting into paid extended support but does not freeze the control plane indefinitely: plan for AWS automatic upgrades and align node/addon versions.
- One ON_DEMAND **t3.medium** AL2023 node, encrypted 20GiB gp3 root, standard CPU credits, IMDSv2 hop limit 1, no instance SSH access. `node_count=2` creates two separate fixed-size managed groups with one subnet/node per AZ. This is a disposable cost-focused lab, not HA production.
- ECR `manaleague-eks-lab-app` / `-web` with immutable tags (publish commit SHA tags), basic push scanning, ten tagged builds retained and untagged builds expired after a day. Immutable tags do not themselves enforce SHA syntax; publishing scripts enforce it. Lifecycle cleanup can remove old rollback images: verify running/rollback digests before cleanup. Repositories require explicit emptying before removal.
- Dedicated private SSE-S3 bucket, no versioning/Object Lock, no historical retention surprise; TLS required; IAM scoped to `avatars/*`. Bucket requires explicit emptying before removal.
- ALB HTTP access requires `allowed_client_cidrs`: a nonempty IPv4 /24-or-narrower allowlist, preferably /32. The frontend SG has one TCP/80 ingress rule per unique CIDR. With a custom SG, the controller ignores Ingress `inbound-cidrs`; Terraform is the authoritative boundary, and changing an annotation cannot authorize a new client.
- API private access enabled and public access explicitly restricted to required trusted IPv4 CIDRs (/24 or narrower). No `0.0.0.0/0`, empty list or implicit hosted-runner ranges. Public node IPs incur IPv4 cost and enable outbound AWS API access without NAT; they do not grant node Internet ingress.

## Required private RDS contract

`engine_version="17"` intentionally pins the major, not a stale minor: AWS selects an available 17.x on creation and automatic minor maintenance remains enabled. Extended Support is explicitly disabled via `engine_lifecycle_support="open-source-rds-extended-support-disabled"`; this is not a perpetual version freeze. AWS documents `db.t4g.micro` support for PostgreSQL 17 and accepting major-only engine versions. Regional/AZ capacity and the resolved minor still require a later authorized live check; no AWS API was called here. A custom `postgres17` parameter group explicitly requires `rds.force_ssl=1` (also the AWS default for major 15+). Clients must use TLS, at least `sslmode=require`.

RDS manages the master password (`manage_master_user_password=true`) using the default AWS-managed Secrets Manager key; storage also uses the default RDS AWS-managed key. This root never generates a password, accepts a password variable, or reads a secret version. Terraform state contains secret ARN/metadata, not the credential value. The app IRSA role stays S3-only. A separate policy on the GitHub deploy role permits **only** `rds:DescribeDBInstances` on this exact DB ARN and `secretsmanager:GetSecretValue` on the actual computed RDS-managed secret ARN, not a prefix/wildcard. The authorized pipeline must verify DB identifier/VPC/nonpublic status/DBName/endpoint, retrieve the current secret without logging it, and write a namespace-scoped Kubernetes Secret. The runner needs AWS API access, not direct database connectivity; migrations run inside EKS.

Outputs: `rds_identifier=manaleague-eks-lab`, `rds_endpoint` (**hostname only**, no `:5432`), `rds_port=5432`, `rds_database=manaleague_eks_lab`, `rds_username=manaleague_lab`, `rds_master_secret_arn`, `rds_security_group_id`, `private_subnet_ids`. Keep `subnet_ids` as the original **public** subnet IDs. Supply `RDS_IDENTIFIER`, `RDS_MASTER_SECRET_ARN` and the exact expected `DB_HOST` from those outputs to deployment; none is a password.

**Disposable, not HA:** one-day managed backup retention is enabled, but deletion protection is off, final snapshots are skipped and automated backups are deleted on an explicitly authorized teardown. **Database data and managed credentials are lost on teardown.** `apply_immediately=true` can cause disruptive changes outside a maintenance window. No enhanced monitoring, Advanced Database Insights, Performance Insights or CloudWatch log exports are enabled. RDS compute/storage, Secrets Manager, possible burst CPU surplus and backup/traffic charges still exist; no free credit or zero-cost assumption. Provisioning and teardown require separate approval.

Primary references: AWS RDS PostgreSQL release notes (`postgresql-versions.html`), supported DB instance classes (`Concepts.DBInstanceClass.Support.html`), PostgreSQL TLS (`PostgreSQL.Concepts.General.SSL.html`), and the pinned HashiCorp AWS provider `aws_db_instance` reference. These public documentation checks are not proof of account/region availability.

## Prerequisites before any authorized live operation

Copy `terraform.tfvars.example` to ignored `terraform.tfvars` and replace its documentation-only values. Authenticate the AWS provider separately using trusted operator credentials; there is intentionally **no** provisioning role for GitHub in this root. Provider `allowed_account_ids` gates the required twelve-digit `expected_account_id`.

Supply the permanent IAM role/user ARN of the trusted provisioner in that account. This principal receives the one explicit admin EKS access entry; implicit cluster-creator admin is disabled. An STS session ARN is not accepted. Do not supply a production operator without deliberate authorization.

Supply an **existing**, account-wide GitHub OIDC provider ARN ending `oidc-provider/token.actions.githubusercontent.com`; this state never creates/owns/deletes the shared provider. Verify its configuration and `sts.amazonaws.com` audience outside this root. Trust for the separate deploy role pins exact `aud=sts.amazonaws.com` and `sub=repo:viniciusVitorio/manaleague:environment:eks-lab` by default, without wildcards. Confirm repository ownership and exact token claims from the intended repository, not untrusted workflow input, and restrict GitHub environment `eks-lab` to branch `eks-lab`. Name-only subjects remain accepted for existing repositories. ID-bearing subjects are also accepted in the exact form `repo:OWNER@OWNER_ID/REPO@REPO_ID:environment:eks-lab`, with numeric IDs required on both names. Read the real token claim from the authorized repository and set it verbatim; never invent IDs or widen trust with wildcards.

The deploy role receives ECR authentication (the token API requires resource `*`), repository-scoped `DescribeRepositories` preflight and push/pull only for the two lab repositories and DescribeCluster only for this cluster. It has an EKS access entry in group **`manaleague-eks-lab-deploy`**, with **no EKS admin policy association**. A trusted operator must bootstrap the namespace-scoped Kubernetes Role/RoleBinding separately before deployment; a deployer cannot create its own permissions. It cannot update API CIDRs or provision infrastructure.

The operator and runner must reach the restricted API. Provide an explicitly authorized stable runner egress CIDR (self-hosted runner or separately supplied stable egress), not an ephemeral hosted runner IP discovered then automatically authorized. Workflows must not mutate endpoint settings.

## Controller network-update boundary

The boundary permits `SetSecurityGroups` and `SetSubnets` only on the exact lab ALB name/account/region with its lab cluster tag. Separate grants constrain each operation to the Terraform-owned frontend SG ID or public subnet IDs, respectively. `ForAllValues:StringEquals` excludes foreign/mixed values; `ForAnyValue:StringEquals` plus `Null=false` rejects empty/missing context instead of accepting vacuous All. The unrelated network key is not required by either update. No tag-only grant includes these actions.

AWS's ELBv2 service-authorization reference explicitly supports `elasticloadbalancing:SecurityGroup` for `SetSecurityGroups` and `elasticloadbalancing:Subnet` for `SetSubnets`, both `ArrayOfString` containing **IDs, not ARNs**: https://docs.aws.amazon.com/service-authorization/latest/reference/list_elbv2.html . The ALB resource itself remains ARN-scoped. ID comparisons intentionally use Terraform's canonical lowercase IDs (other capitalization fails closed). Approved nonempty subnet subsets are permitted by IAM; AWS still enforces ALB/AZ topology validity. This is an approved-ID allowlist, not an IAM assertion that every public subnet must be present. Target-group creation still relies on its cluster tag, and Describe remains global. Fresh mocked Terraform rendering plus the local policy evaluator are regression evidence, **not live AWS authorization or controller-runtime proof**.

## Offline checks (no AWS credentials needed)

Run from this directory with Terraform >=1.11:

```sh
terraform fmt -check -recursive
terraform init -backend=false -input=false
terraform validate
terraform test
```

The test files use `mock_provider "aws"` and `command=plan` exclusively. They also cover required private RDS, route/subnet isolation under one/two-node options, TLS, bounded storage, managed credentials, exact read-only database IAM and hostname/output contracts. They test default/two-node topology, support policy, IMDS/root disk/CPU settings, immutable/private storage, narrow GitHub trust, deploy RBAC group, explicit operator and negative inputs. `terraform test` makes no AWS requests. Do not replace it with a real plan/apply to reproduce offline validation. Backend initialization is intentionally skipped; a later authorized operator must initialize the local backend before live use.

`../addons/README.md` specifies exact controller settings, Helm merge inputs and cleanup ordering. Outputs preserve the script integration contract, including `aws_region`, `account_id`, `cluster_name`, `namespace`, `vpc_id`, `vpc_cidr`, `subnet_ids`, ECR URLs, bucket/IRSA/deploy role names, `api_public_access_cidrs` and `allowed_client_cidrs`. Additional outputs expose dedicated frontend/node SG IDs, the Helm values object and resolved managed addon versions.

No AWS provisioning, live IAM validation, deployment, commit or push was performed for this implementation. See `../addons/validation.log` for actual local checks and their tool versions.
