# Lab addons contract

Terraform owns **AWS resources only**. Helm and Kubernetes installation/removal belong to the lab scripts, not Terraform providers. Remove the Ingress and wait for its ALB/target groups to disappear while the controller, cluster and IRSA still exist, then stop the known lab workloads and only afterward remove AWS infrastructure, including private RDS. No database PVC exists in this architecture; unexpected PVCs/StatefulSets block automatic cleanup for review. No AWS operations were executed during implementation.

## AWS Load Balancer Controller

Pinned artifacts are in `versions.json`: official `eks/aws-load-balancer-controller` Helm chart **3.6.3**, controller **v3.6.3**, release `aws-load-balancer-controller`, namespace `kube-system`. The vendored `iam-policy-v3.6.3.json` is the unmodified official release policy. Its SHA-256 is recorded in `versions.json`; preserve LF line endings when comparing bytes. Terraform attaches an additional permissions boundary: EC2 security-group mutations are denied, ELB mutations are constrained to the lab's account/region and cluster tag, and listener/rule operations to the exact lab ALB name.

The installer must merge these two values sources, in order:

1. `aws-load-balancer-controller.values.yaml` (static defaults).
2. JSON from `terraform output -json load_balancer_controller_helm_values` (JSON is YAML-compatible).

Dynamic values provide `clusterName`, `region`, `vpcId` and the exact IRSA service-account annotation. Never omit region/VPC: IMDS hop limit is 1. The chart creates `kube-system/aws-load-balancer-controller`; do not create a competing service account. Chart RBAC remains enabled. The controller watches only namespace `manaleague-eks-lab`, uses one replica and IP targets, and disables Shield/WAF/WAFv2 and automatic backend security groups.

Every lab Ingress must include:

```yaml
spec:
  ingressClassName: alb
metadata:
  annotations:
    alb.ingress.kubernetes.io/load-balancer-name: manaleague-eks-lab
    alb.ingress.kubernetes.io/scheme: internet-facing
    alb.ingress.kubernetes.io/target-type: ip
    alb.ingress.kubernetes.io/security-groups: <terraform output -raw alb_security_group_id>
    alb.ingress.kubernetes.io/manage-backend-security-group-rules: "false"
    alb.ingress.kubernetes.io/listen-ports: '[{"HTTP":80}]'
    alb.ingress.kubernetes.io/tags: Project=manaleague-eks-lab,Environment=eks-lab
```

This is an annotation fragment, not an installable manifest. Web and Reverb pod targets must listen on **8080**. Terraform's dedicated frontend SG permits TCP/80 only from required `allowed_client_cidrs` (IPv4 /24 or narrower), egress only TCP/8080 to the node SG; the node SG permits ingress TCP/8080 only from that frontend SG. No Internet node ingress, SSH, public database or NodePort opening is defined. The fixed ALB name is important for the controller permissions boundary. There must be only one lab ALB per account/region.

**Authoritative client access:** with `alb.ingress.kubernetes.io/security-groups` set, the controller ignores `alb.ingress.kubernetes.io/inbound-cidrs`. The latter is informational only in this lab. Change `allowed_client_cidrs` through an authorized Terraform operation; scripts should render the annotation from that output but must never imply the annotation itself enforces access. Backend SG rules remain Terraform-owned: the controller boundary denies EC2 SG mutation and `manage-backend-security-group-rules` must remain `"false"`.

## Managed EKS addons

Terraform installs `vpc-cni`, `coredns`, `kube-proxy` and `aws-ebs-csi-driver` using versions selected by AWS for the **explicit Kubernetes version**, with `most_recent=false`. Optional `addon_versions` accepts explicit compatible pins. AWS compatibility cannot be proven offline; resolved versions appear in `managed_addon_versions`. Review them in the authorized future plan. The offline tests intentionally mock the compatibility query; their fake addon version is not a deployment recommendation.

VPC CNI explicitly enables NetworkPolicy enforcement with `configuration_values = jsonencode({ enableNetworkPolicy = "true" })`; the managed-addon API uses a string, not a JSON boolean. Supplied NetworkPolicies are not sufficient without this setting. The AWS network-policy agent uses node-local TCP/8162 for metrics and TCP/8163 for health; no public ingress or new ALB/backend rule is needed or opened for either port. Existing same-node-group internal traffic remains private. The policy agent adds per-node CPU, memory and eBPF overhead to the CNI baseline; budget this **in addition** to app, CoreDNS, CSI and controller requests on the t3.medium. No live memory measurement or one-node fit guarantee is claimed. Check allocatable capacity, actual addon requests and usage before enabling the workload; use the two-node option or reduce lab workloads if needed. Default CNI standard enforcement can briefly allow new-pod traffic before policy programming; this is not an instantaneous default-deny-at-startup guarantee. Strict enforcement is not enabled because it requires auditing policies for all dependencies, including CoreDNS.

CNI uses its own IRSA role and is installed before managed nodes; its IAM policy is not attached to the node role. EBS CSI uses `kube-system/ebs-csi-controller-sa` with `arn:aws:iam::aws:policy/AmazonEBSCSIDriverPolicyV2` (the V2 ARN has **no** `service-role/` segment). CoreDNS and EBS controller use one replica to fit the one-node lab. No separate Helm EBS CSI installation or Pod Identity agent is required.

The Kubernetes bootstrap preserves an encrypted gp3 StorageClass with provisioner `ebs.csi.aws.com` and `volumeBindingMode: WaitForFirstConsumer` for optional Kubernetes volumes. **RDS is the required database and does not use this driver or a PVC.** There is no in-cluster PostgreSQL workload/volume. The two-node option fixes one node in each AZ but does not turn Single-AZ RDS into Multi-AZ. Unexpected dynamic volumes must be reviewed and accounted for before teardown.

Application service account: `manaleague-eks-lab/lab-app`, annotated with `app_role_arn`. Only the dedicated bucket's `avatars/*` prefix is allowed; private URLs need app authorization or presigning. Do not inject static AWS keys. No production secrets are used.

## Local verification

See `validation.log` for actual execution output. Terraform mock tests are plans only and make no AWS API calls. `init -backend=false` downloads the signed provider but does not initialize/read a remote backend. Helm lint/render use the downloaded, pinned official chart locally and do not contact a cluster. Offline success does **not** prove AWS quotas, IAM authorization, regional addon compatibility, cluster access, workload scheduling or live ALB/EBS behavior.

Sources reviewed on 2026-11-16:

- https://docs.aws.amazon.com/eks/latest/userguide/kubernetes-versions.html
- https://docs.aws.amazon.com/eks/latest/userguide/cni-network-policy-configure.html
- https://kubernetes-sigs.github.io/aws-load-balancer-controller/latest/guide/ingress/annotations/
- https://docs.aws.amazon.com/eks/latest/userguide/ebs-csi.html
- https://docs.aws.amazon.com/aws-managed-policy/latest/reference/AmazonEBSCSIDriverPolicyV2.html
- https://github.com/aws/eks-charts/tree/master/stable/aws-load-balancer-controller
- https://github.com/kubernetes-sigs/aws-load-balancer-controller/blob/v3.6.3/docs/install/iam_policy.json
