# EKS lab runner: offline renderer and explicitly gated session

**Prepared locally only: no runner applied, registered, or shown online.** The lab
remains destroyed and `LAB_DEPLOY_ENABLED=false`. `operator.py` stays render-only;
`session.py` adds a future authorized lifecycle, tested with fake transports only.

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

## Session CLI — offline by default, live only after separate approval

This is preparation, **not evidence of live operation**. Python 3.11+ stdlib only.
The original ten tests and renderer defaults remain intact. Optional renderer
`run_id='44421131546'` / CLI `--run-id 44421131546` adds custom label
`eks-lab-44421131546` alongside `eks-lab`; without it the original script is unchanged.
A run ID is canonical positive decimal, at most 20 digits, never normalized.
Labels route jobs; **labels are not an authorization or public-repository security boundary**.

Offline example from repository root (all values are fixtures):

```powershell
python -I -B infra/eks-lab/runner/session.py --account 123456789012 --region us-east-1 --cluster manaleague-eks-lab --vpc-cidr 10.42.0.0/16 --api-cidr 10.42.1.8/32 --run-id 44421131546 --sha aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
```

Omitted action means `plan`; explicit `plan` is equivalent. It prints a deterministic
plan and renderer List with no Secret values, credential reads, subprocesses, DNS or
network calls, and writes no local state. The plan contains no allocated live
session UUID/UIDs; start binds those after read-only preflight. SHA must be exact
lowercase SHA40. API /32s are mandatory for session planning, optional for renderer.

### Future live prerequisites — do not execute during offline preparation

- Run the control CLI in trusted **WSL `manaleague-docker`**, where AWS CLI/kubectl
  are installed; control code may remain in the Windows worktree. Keep state on a
  private **WSL ext4** filesystem via `--state-dir /home/<OPERATOR>/manaleague-runner/.local`.
  Default state directory is `runner/.local`; permissive DrvFS modes are refused.
  Live mode refuses Windows Python rather than pretending `chmod` establishes ACL
  privacy. Do not execute the control CLI from a public-repository job.
- GitHub credential source defaults to `--github-auth gh`, captured `gh auth token`
  from its stored login (if gh is installed/logged in inside WSL). Explicit
  `--github-auth windows-gcm` instead invokes a fixed inline
  `powershell.exe -NoProfile -NonInteractive -Command` script. It sets process-local
  `HOME=USERPROFILE`, `GCM_INTERACTIVE=Never`, `GIT_TERMINAL_PROMPT=0`, disables traces,
  discovers Windows `git.exe`, and feeds `protocol=https/host=github.com` credential
  protocol via stdin. Windows Git runs at USERPROFILE, avoiding the WSL worktree's
  Windows `.git` path. stdout is captured only in memory. No helper/global/repo Git
  configuration is changed, no login UI is allowed, and there is no scratch script.
  The bridge is **mock-tested only here**, not exercised against real credentials.
- Both auth modes use verified HTTPS to fixed `api.github.com`, **no redirects** and
  no ambient HTTP proxies. Authorization stays in memory; tokens never enter argv,
  environment, state or logs. HTTP errors suppress response bodies/headers. Neither
  the long-lived credential nor the short registration token is copied to disk.
  Operator permissions must cover repository administration/Actions and Environment
  variable reads. GitHub credentials are never copied into the Pod.
- Independently authorize provision/bootstrap and verify all networking below. The
  script never creates the cluster, opens a security group, changes Terraform, turns
  the gate on, or alters repo/global runner security settings. Set/approve only the
  **eks-lab Environment** gate separately when release checks are ready. Repo-global
  runner changes would affect production/main and are outside this scope.
- Resolve the exact described cluster endpoint **from inside its VPC**. Supply current
  private IPv4 `/32`s matching EKS-managed ENIs in that cluster VPC. The script checks
  VPC CIDR, ENI VPC/description/requester-managed status, but does NOT resolve DNS on
  the Windows workstation or treat an ENI list as proof of DNS/connectivity. Operator
  must verify the supplied set is complete/current, CNI policy enforcement (including
  strict startup), DNS, TLS API reachability, IMDS/Pod Identity/private production
  denial and HTTP/WebSocket ALB access from the actual node/Pod path.
- Review **all** public-repo workflows that might target this runner, trusted branch
  and Environment protection, OIDC subject/permissions and exact deploy role
  `arn:aws:iam::<ACCOUNT>:role/manaleague-eks-lab-github-deploy`. Run-scoped labels and
  single-use registration do not prevent another authorized workflow selecting it.
  Public HTTP(S)/DNS egress remains a data-exfiltration and production-public-access
  limitation, not solved by the two ACKs. If this risk is unacceptable, do not start.
- Existing ALB operator allowlist does not cover the runner automatically. Verify its
  real effective source; any narrowly scoped node/runner-origin Terraform allowance
  requires separate review. No `0.0.0.0/0`, SG mutations or job SG-write privilege.

Only after those checks, use the same validated arguments with action `start` and
ALL four gates (the following is an authorization contract, not a command run here):

```text
start <same account/region/cluster/VPC/API/run/SHA arguments>
  --allow-cloud --confirm-lab manaleague-eks-lab
  --ack-public-self-hosted-risk --ack-network-verified-in-vpc
```

The network ACK means the operator performed the VPC-local checks above, including
private API DNS and actual ALB source/enforcement. The risk ACK accepts the explicit
public-repository limitations after review; it does not claim isolation is complete.
Both `start` and `cleanup` reject absent gates before any state/credential I/O or
remote process; an explicit `plan` remains offline even if live flags are present.

### Future start contract

1. Validate inputs and gates, then hash-check recorded image provenance. Check STS
   account and exact ACTIVE cluster ARN/name, HTTPS endpoint/CA and private API.
   AWS calls always pin the operator profile `eks-lab` and supplied region.
2. Check exact VPC and supplied API ENIs. Read the GitHub run: exact repository AND
   head repository `viniciusVitorio/manaleague`, branch `eks-lab`, event `push`,
   path `.github/workflows/pipeline.yml`, supplied SHA/run ID, first attempt only,
   queued/in-progress. Read **only** `eks-lab` Environment `LAB_DEPLOY_ENABLED=true`.
   Paginate all jobs; require exactly one queued job whose final display-name segment
   is `deploy` (including reusable-workflow `caller / deploy`), exact SHA/run, no
   assigned runner and labels exactly `self-hosted`, `linux`, `eks-lab-<RUN_ID>`.
3. Reserve `.local/session-<RUN_ID>.json` exclusively; no retry/replacement. Generate
   a fresh dedicated kubeconfig from the described ARN/endpoint/CA (no human-context
   merging), mode 0600, read back exactly. Its AWS exec profile stays on the control
   host. Refuse any existing fixed namespace, homonymous runner or same-run label.
4. Create (never adopt/overwrite) Namespace, SA and NetworkPolicy in that order;
   journal and re-read UIDs/ownership annotations before continuing. Recheck run,
   queued deploy job, gate and network metadata before requesting registration token.
5. Request a repository registration token through authenticated no-redirect HTTPS
   using only the captured stored credential in memory.
   Validate short expiry; keep token only in process memory and `kubectl create`
   **stdin**. No argv/env/log/file/last-applied annotation containing token. Create
   Secret, verify UID/ownership, then create a uniquely named Job with a known exact
   runner identity. Only the init container can read the Secret. No operator AWS
   profile or human kubeconfig is rendered, mounted or forwarded into the Pod.
6. Poll exact Job UID and its single owner-verified Pod, plus exact GitHub runner
   name/ID/run label. Journal the observed runner ID. Delete registration Secret
   with a UID-preconditioned DELETE only after `register` init terminates exit 0,
   and read absence back. Report actual `online`, `job_complete`, `job_failed`,
   `registration_failed` or `pending_timeout`; never infer online from a create.
   Polling is bounded; Job retains its renderer's 2h deadline. Online is a point-in-
   time observation, not proof the deploy succeeded or the requested run consumed it.

### Cleanup and interruption contract

Invoke `cleanup` with the **same metadata and all four gates**. It reads the private
journal, verifies cluster account/ARN again, then validates namespace ownership/UID,
Job/Secret recorded UIDs and exact GitHub runner name/ID/label **before any deletion**.
Busy or unrecorded runners are refused: cancel/wait for the trusted job separately.
Delete only recorded Job/Secret with atomic UID preconditions (foreground Job
propagation), and only the recorded repository runner numeric ID. Read deletion
back. Missing exact objects are accepted for cleanup retry; changed identities are
not. No glob, generic runner removal, namespace purge, production resource or gate
mutation is implemented.

- Journal stores only session/run/SHA/account/region/IDs/UIDs/status and pending-create
  metadata, never tokens or CLI output. Use the same `--state-dir` for start/cleanup.
  Default `.local/` is ignored; an explicit WSL ext4 `.local` is outside the worktree.
  Keep it private, backed up securely until teardown, and never `git add -f`. Directory 0700, files 0600,
  symlinks refused at the checked leaf/direct parent, journal updates atomic, operator lock serializes live operations.
  All ancestors must also remain operator-controlled: use a private, non-shared ext4
  path with no symlink ancestors or directories writable by other users. The helper
  does not walk and validate the entire ancestor chain; leaf checks alone do not
  establish that trust.
- **Namespace, SA and policy are intentionally retained**, with recorded UIDs. Before
  another session an operator must separately review/reset this dedicated namespace;
  start refuses adoption. This implementation does not automate repeat-session
  namespace reclamation or unowned-resource deletion.
- Interrupted create/response-loss may leave an object with no captured UID. Missing
  journal, missing namespace UID or a runner registered before its ID was observed
  is a **manual recovery blocker**, not permission to discover-and-delete by name.
  Do not delete the journal and rerun start. Recover with exact live evidence under
  separate operator approval. A cluster already destroyed also blocks this combined
  cleanup; separately review any stale GitHub identity instead of bypassing guards.
- On init failure/timeout the registration Secret can remain until explicit cleanup;
  no token is printed for retry. On late failures check journal IDs and perform
  authorized cleanup; never reuse a registration token or replace a runner.
- Transport error bodies are deliberately suppressed because they can contain secrets.
  This code has **not** proved live IAM/GitHub permissions, EKS ENI description shape,
  policy admission/enforcement, mounts, registration, ALB reachability, or deploy.
  These are the exact remaining operator checks, not completed work.

Tests: `python -I -B -m unittest discover -s infra/eks-lab/runner/tests -v`.
New session API/process interactions are fakes/mocks; existing offline renderer CLI
and shell-syntax subprocess tests are preserved. RED/GREEN slices cover optional
label, offline default/gates, read-only provenance, lifecycle, captured transport,
expiry, and foreign/missing UID/renamed-runner cleanup refusal.

API references:

- https://cli.github.com/manual/gh_api
- https://docs.github.com/en/rest/actions/workflow-runs
- https://docs.github.com/en/rest/actions/workflow-jobs
- https://docs.github.com/en/rest/actions/self-hosted-runners
- https://docs.github.com/en/rest/actions/variables
- https://raw.githubusercontent.com/kubernetes/kubectl/master/pkg/cmd/delete/delete.go
- https://kubernetes.io/docs/reference/kubernetes-api/common-definitions/delete-options/

## Remaining live operator checks — NOT executed

1. Separately recreate the authorized lab; verify account/VPC/API IPs, Kubernetes
   version and existing node capacity. Verify EKS CNI policies (strict startup), DNS,
   node-local/IMDS protections and no additive-policy/interface bypass. Test denied
   IMDS/private production/other lab services and allowed DNS/API/public routes.
2. Review **every workflow** selecting this public-repository runner, branch/
   Environment protection, OIDC trust and least-privilege deploy role/RBAC. Labels
   and one-shot Pods do not authorize jobs or make arbitrary fork PRs safe. Never
   register on the credentialed Windows/WSL workstation.
3. Operator separately reviews the session contract above, or manually applies the
   namespace/SA/policy and verifies enforcement before supplying a fresh Secret
   through an approved non-logging channel and submitting the Job. Do not mix a
   manually created namespace with session start (it refuses adoption).
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
6. After infrastructure/network/risk review, the owner may enable the gate for a trusted
   SSDLC push. Wait for its exact deploy job to queue, then open the authorized session
   (start itself requires that queued job and gate true). Do not pre-create the fixed
   namespace when using session start. Remove Job/Secret/identity promptly afterwards;
   review/reset retained Namespace/SA/policy before another run.
