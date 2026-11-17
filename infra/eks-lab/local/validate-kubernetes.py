"""Render fixture tokens and structurally verify local manifests; no cluster calls."""
import json
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parents[1] / "kubernetes"
values = {
    "__APP_URL__": "http://lab-alb.example.test",
    "__AVATAR_BUCKET__": "manaleague-eks-lab-fixture",
    "__DB_HOST__": "manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com",
    "__ALB_SECURITY_GROUP_ID__": "sg-0123456789abcdef0",
    "__AWS_REGION__": "us-east-1",
    "__VPC_CIDR__": "10.77.0.0/16",
    "__ALLOWED_CIDRS__": "203.0.113.1/32",
    "__SUBNET_IDS__": "subnet-0123456789abcdef0,subnet-0123456789abcdef1",
    "__APP_ROLE_ARN__": "arn:aws:iam::123456789012:role/manaleague-eks-lab-app",
    "__RELEASE__": "0123456789abcdef",
}
output = Path(sys.argv[1])
output.mkdir(parents=True, exist_ok=True)
resources = []
for source in root.rglob("*.yaml"):
    text = source.read_text()
    for key, value in values.items():
        text = text.replace(key, value)
    assert not re.search(r"__[A-Z_]+__", text), source
    docs = [json.loads(doc) for doc in text.split("\n---\n")]
    target = output / source.relative_to(root)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text)
    resources.extend(docs)

base = json.loads((root / "base/kustomization.yaml").read_text())
assert not any("jobs/" in name or "bootstrap/" in name or "secret" in name for name in base["resources"])
assert {image["name"] for image in base["images"]} == {"lab-app", "lab-web"}
assert not any(item.get("kind") == "Secret" for item in resources)
for item in resources:
    kind = item.get("kind")
    if kind in {"Deployment", "StatefulSet", "Job", "CronJob"}:
        spec = item["spec"]
        pod = spec["jobTemplate"]["spec"]["template"]["spec"] if kind == "CronJob" else spec["template"]["spec"]
        if kind == "Deployment" and item["metadata"]["name"] == "manaleague":
            assert pod["terminationGracePeriodSeconds"] >= 90, "ALB drain plus request completion needs a bounded grace period"
            for container in pod["containers"]:
                assert container.get("lifecycle", {}).get("preStop", {}).get("exec", {}).get("command") == ["sleep", "30"], "ALB deregistration drain missing"
        for container in pod["containers"]:
            assert container["resources"]["requests"] and container["resources"]["limits"]
            assert container["securityContext"]["allowPrivilegeEscalation"] is False
            if container["image"].startswith("lab-app:"):
                assert "command" not in container, "Kubernetes command overrides the lab safety ENTRYPOINT"
    if kind == "Job":
        assert item["metadata"]["name"].endswith(values["__RELEASE__"])
        assert item["spec"]["backoffLimit"] == 0
cron_names = {item.get("metadata", {}).get("name") for item in resources if item.get("kind") == "CronJob"}
assert {"scheduler", "cards-sync-bans", "cards-sync-legality", "challenges-auto-confirm"} <= cron_names, "Daily maintenance CronJobs missing"
print("PASS: fixture rendering, isolated base, no shipped Secrets, bounded non-escalating workloads")
