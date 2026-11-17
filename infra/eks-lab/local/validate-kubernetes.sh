#!/bin/sh
set -eu
cd "$(dirname "$0")"
tools=/root/manaleague-eks-lab-tools
out=/root/manaleague-eks-lab-manifests-fixture
mkdir -p "$tools"
if [ ! -x "$tools/kubectl" ]; then
    version=$(curl -fsSL https://dl.k8s.io/release/stable.txt)
    curl -fsSL "https://dl.k8s.io/release/$version/bin/linux/amd64/kubectl" -o "$tools/kubectl"
    curl -fsSL "https://dl.k8s.io/release/$version/bin/linux/amd64/kubectl.sha256" -o "$tools/kubectl.sha256"
    (cd "$tools"; printf '%s  kubectl\n' "$(cat kubectl.sha256)" | sha256sum -c -)
    chmod 755 "$tools/kubectl"
fi
if [ ! -x "$tools/kubeconform" ]; then
    curl -fsSL https://github.com/yannh/kubeconform/releases/download/v0.7.0/kubeconform-linux-amd64.tar.gz -o "$tools/kubeconform.tar.gz"
    tar -xzf "$tools/kubeconform.tar.gz" -C "$tools" kubeconform
fi
python3 validate-kubernetes.py "$out"
"$tools/kubectl" kustomize "$out/base" > "$out/rendered.yaml"
"$tools/kubeconform" -strict -summary -kubernetes-version 1.34.0 "$out/rendered.yaml" "$out/jobs" "$out/bootstrap"
# Neither schema validation nor kustomize contacts a cluster.
