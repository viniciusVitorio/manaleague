# Offline EKS lab runner

**Generator only: no runner applied, registered, or shown online.** The lab remains
destroyed and `LAB_DEPLOY_ENABLED=false`.

## Offline usage

From the repository root, Python 3.11+ and Bash for shell-syntax tests:

```sh
python -I -B -m unittest discover -s infra/eks-lab/runner/tests -v
python -I -B infra/eks-lab/runner/operator.py render \
  --account 123456789012 --region us-east-1 --vpc-cidr 10.42.0.0/16
```

Use `python3` on Linux. `-I` also prevents the local `operator.py` filename from
shadowing Python's standard-library module. Stdout is Kubernetes `v1/List` JSON.
No apply/register/cloud subcommands, SDKs, credential reads, processes or network
calls occur during import/render. Examples are test values, not discovered lab data.

`render(account, region, vpc_cidr)` validates exact account/commercial-region syntax
and canonical RFC1918 IPv4 /16–/28 (Terraform's VPC contract is narrower: /16). It
does not prove existence/ownership or region availability. The VPC CIDR alone cannot
identify EKS API ENIs; default private egress fails closed except cluster DNS.
Supply **operator-verified current API addresses**, never a broad VPC 443 allowance:

```sh
python -I -B infra/eks-lab/runner/operator.py render \
  --account 123456789012 --region us-east-1 --vpc-cidr 10.42.0.0/16 \
  --api-cidr 10.42.1.8/32 --api-cidr 10.42.2.9/32
```

Those addresses are examples too. Optional Python keyword:
`api_cidrs=('10.42.1.8/32',)`. Only canonical /32s within the lab VPC are accepted.
Review/re-render when endpoint DNS addresses change.

## Isolation and lifecycle

- Only Namespace, ServiceAccount, NetworkPolicy and single-use Job, owned by
  `manaleague-eks-lab-runner`. No RBAC grants, Services, Ingress, host/PVC mounts,
  host namespaces, Docker socket, cloud resources or scale-up automation.
- Restricted Pod Security v1.37, UID/GID 1001, fsGroup, RuntimeDefault seccomp,
  dropped capabilities, no escalation, read-only roots, no mounted Kubernetes token.
  Explicit CPU/memory/ephemeral-storage requests/limits and bounded emptyDirs.
  Existing Linux/amd64 node only; insufficient capacity means Pending, not a new node.
- AWS CLI init copies its pinned official dist bundle (not absolute installer links)
  to a tools emptyDir mounted read-only by main. Upstream runner supplies Bash/Git/
  curl/certificates and Python through Ubuntu Noble's `software-properties-common`
  dependency. Startup checks Python and AWS CLI; copying/linkage were exercised locally
  under UID1001/read-only roots/no capabilities/network-none; Kubernetes mounts and
  admission still need live validation.
  Existing workflow installs pinned kubectl. No Helm/Terraform/Docker engine needed.
  Upstream includes sudo/Docker binaries; no usable sudo privilege, daemon, socket
  or host access is granted here.
- Registration init **alone** mounts external Secret `runner-registration-token`,
  key `token`, mode 0440. No Secret/value is rendered. Supply only a short-lived
  repository registration token, never PAT/App key, human AWS profile or admin
  kubeconfig. Init uses `ACTIONS_RUNNER_INPUT_TOKEN`, not argv/Pod env, suppresses
  config output, unsets it and removes initial diagnostics. Main has no registration
  Secret mount/reference/env. Its own `.credentials*` identity remains sensitive in
  emptyDir: absence of registration token does not mean credentialless runner.
- `--ephemeral --disableupdate`, custom `eks-lab` plus default self-hosted/Linux
  labels; one trusted job, no runner replacement. Parallelism/completions 1,
  backoff 0, restart Never, two-hour total deadline. Loss/completion requires manual
  old Job/offline-runner cleanup and fresh registration; no ARC/reconciler/recovery.

## Egress

Ingress denied; permit only:

1. TCP/UDP 53 to combined namespace `kube-system` + Pod `k8s-app=kube-dns` selectors.
2. Public IPv4 TCP 443 (GitHub/actions/AWS), plus 80 (HTTP ALB/WebSocket smoke).
   Exclude RFC1918, link-local/IMDS/Pod Identity, loopback, CGNAT, multicast,
   reserved and documentation ranges.
3. TCP 443 to supplied private lab API /32s.

**L3/L4 policy, not an FQDN allowlist or exfiltration barrier.** Arbitrary public
HTTP(S), including public production endpoints, remains reachable; DNS carries data.
Private production is excluded except an operator-misidentified API/DNS exception.
Policies are additive with node-traffic/NAT caveats. Require IPv4, no secondary
interfaces/bypasses, and actual enforcement/node IMDS checks before registration.
Stricter public-domain restrictions need a separately reviewed proxy/FQDN-aware layer.

## Provenance and tests

`images.json` records official registry URLs, versions and index/amd64 hashes:
actions-runner **2.337.0** (index pin), AWS CLI **2.40.4** (amd64 manifest pin, not
multiarch index). `provenance/*-index.json` preserves exact public response bytes;
tests recompute SHA-256 and verify descriptor/pin membership. Anonymous registry
tokens were not stored; no authenticated account API was used. Recorded upstream
`provenance/runner.Dockerfile` is review material, not a custom image build.

Pins do not establish signatures, vulnerability status or continued GitHub version
acceptance. Review/refresh before registration, especially with updates disabled.
Tests cover original contract, validation, Pod/Secret/policy safety, provenance,
no-I/O deterministic rendering, CLI rejection and shell syntax. Four objects also
passed official Kubernetes v1.37.0 OpenAPI validation, interpreting IntOrString's
true union. Pinned images/toolchain were executed locally without network or registration:
Python3.12.3, AWS CLI2.40.4 and Runner.Listener2.337.0 started successfully after
the actual copy steps under UID1001, read-only rootfs and dropped capabilities.
Only temporary owned Docker volumes were used and removed. No Kubernetes admission,
live networking or registration tested; private evidence is `.local/runner-local-tools-proof.json`.

Primary sources (registry URLs in `images.json`):

- https://raw.githubusercontent.com/actions/runner/v2.337.0/images/Dockerfile
- https://raw.githubusercontent.com/actions/runner/v2.337.0/src/Runner.Listener/CommandSettings.cs
- https://packages.ubuntu.com/noble/software-properties-common
- https://docs.github.com/en/actions/how-tos/manage-runners/self-hosted-runners/add-runners
- https://docs.github.com/en/actions/reference/security/secure-use
- https://docs.aws.amazon.com/eks/latest/userguide/cni-network-policy.html
- https://kubernetes.io/docs/concepts/services-networking/network-policies/
- https://raw.githubusercontent.com/kubernetes/kubernetes/v1.37.0/api/openapi-spec/swagger.json

## Remaining live operator checks — NOT executed

1. Separately recreate the authorized lab; verify account/VPC/API IPs, Kubernetes
   version and existing node capacity. Verify EKS CNI policies (strict startup), DNS,
   node-local/IMDS protections and no additive-policy/interface bypass. Test denied
   IMDS/private production/other lab services and allowed DNS/API/public routes.
2. Review **every workflow** selecting this public-repository runner, branch/
   Environment protection, OIDC trust and least-privilege deploy role/RBAC. Labels
   and one-shot Pods do not authorize jobs or make arbitrary fork PRs safe. Never
   register on the credentialed Windows/WSL workstation.
3. Operator separately reviews/applies namespace/SA/policy and verifies enforcement;
   supplies fresh Secret through an approved non-logging channel, then submits Job.
   Never store token in Git/history/logs or pass upstream admin credentials into
   Kubernetes. Delete registration Secret after successful init.
4. Verify image pulls, non-root copies, AWS CLI libraries, Python, writable tool cache
   and kubectl setup under restrictions. Confirm intended runner online in GitHub
   and healthy in Kubernetes. Use lab OIDC only; `lab.py` builds its own validated
   kubeconfig. Check stale registrations after interruptions.
5. **ALB source allowlist:** operator `/32` does not prove Pod access. Determine
   runner's effective public source; allow only that lab origin via reviewed
   Terraform. Never open `0.0.0.0/0` or grant job SG-write rights. Verify actual
   Pod HTTP/WebSocket smoke access.
6. Only then may the owner enable the gate for a trusted release. Remove Job/Secret/
   identity promptly afterwards; emptyDir loss always needs manual re-registration.
