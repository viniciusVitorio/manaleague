#!/usr/bin/env python
"""Local-only IaC checks; never invokes AWS, a real plan/apply, or kubectl."""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--terraform", default="terraform")
    parser.add_argument("--skip-init", action="store_true", help="Reuse an initialized provider cache; avoid downloads")
    parser.add_argument("--helm", help="Optional Helm binary; requires --chart")
    parser.add_argument("--chart", type=Path, help="Already downloaded official 3.6.3 chart archive (no downloads here)")
    parser.add_argument("--write-log", action="store_true", help="Write actual output to addons/validation.log")
    args = parser.parse_args()
    if bool(args.helm) != bool(args.chart):
        parser.error("Supply both --helm and --chart, or neither")
    addons = Path(__file__).resolve().parent
    terraform = addons.parent / "terraform"
    manifest = json.loads((addons / "versions.json").read_text(encoding="utf-8"))
    controller = manifest["aws_load_balancer_controller"]
    log: list[str] = []

    def record(text: str) -> None:
        print(text, flush=True)
        log.append(text)

    def run(command: list[str]) -> str:
        record("$ " + subprocess.list2cmdline(command))
        result = subprocess.run(command, cwd=terraform, capture_output=True, text=True, check=False)
        record(result.stdout.rstrip())
        if result.stderr:
            record(result.stderr.rstrip())
        record(f"exit_code={result.returncode}")
        if result.returncode:
            raise RuntimeError(f"Command failed with exit code {result.returncode}")
        return result.stdout

    try:
        policy = (addons / controller["policy_file"]).read_bytes().replace(b"\r\n", b"\n")
        digest = hashlib.sha256(policy).hexdigest()
        if digest != controller["upstream_policy_sha256"]:
            raise RuntimeError("Vendored upstream controller policy checksum mismatch")
        record(f"Official controller policy SHA-256 (LF): {digest} MATCH")
        run([args.terraform, "version", "-no-color"])
        run([args.terraform, "fmt", "-check", "-recursive", "-no-color"])
        if not args.skip_init:
            run([args.terraform, "init", "-backend=false", "-input=false", "-no-color", "-lockfile=readonly"])
        run([args.terraform, "validate", "-no-color"])
        run([args.terraform, "test", "-no-color"])
        if args.helm:
            run([args.helm, "version", "--short"])
            chart_info = run([args.helm, "show", "chart", str(args.chart.resolve())])
            expected = controller["chart_version"]
            if f"version: {expected}" not in chart_info.splitlines():
                raise RuntimeError("Downloaded Helm chart version does not match versions.json")
            values = str(addons / "aws-load-balancer-controller.values.yaml")
            flags = ["--values", values, "--set", "clusterName=manaleague-eks-lab", "--set", "region=us-east-1", "--set", "vpcId=vpc-offline"]
            run([args.helm, "lint", str(args.chart.resolve()), "--strict", *flags])
            record("$ helm template (locally render and inspect; full generated manifests omitted)")
            result = subprocess.run([args.helm, "template", "aws-load-balancer-controller", str(args.chart.resolve()), "--namespace", "kube-system", *flags], capture_output=True, text=True, check=True)
            for flag in ["--aws-region=us-east-1", "--aws-vpc-id=vpc-offline", "--watch-namespace=manaleague-eks-lab", "--enable-backend-security-group=false"]:
                if flag not in result.stdout:
                    raise RuntimeError(f"Rendered controller flag missing: {flag}")
                record(f"Rendered flag verified: {flag}")
        record("PASS: local validation only; no AWS API, infrastructure plan/apply, or Kubernetes calls executed.")
        return 0
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        record(f"FAIL: {error}")
        return 1
    finally:
        if args.write_log:
            (addons / "validation.log").write_text("\n".join(log) + "\n", encoding="utf-8", newline="\n")


if __name__ == "__main__":
    sys.exit(main())
