#!/usr/bin/env python3
"""Validate lab manifests locally: no cluster, AWS API, or credentials.

Requires kubectl and kubeconform. Kubernetes schemas can be downloaded by
kubeconform from its default GitHub schema registry; no cloud API is contacted.
All values below are explicitly validation-only placeholders, never deployment
configuration or simulated integration results.
"""
import json
from pathlib import Path
import re
import tempfile
from lab import LAB, render_tree, manifest_guard, replace_tokens, run


def main():
    values = {
        'APP_URL': 'http://lab.example.test',
        'AVATAR_BUCKET': 'manaleague-eks-lab-validation-only',
        'AWS_REGION': 'us-east-1',
        'DB_HOST': 'manaleague-eks-lab.abcdefghijkl.us-east-1.rds.amazonaws.com',
        'VPC_CIDR': '10.73.0.0/16',
        'APP_ROLE_ARN': 'arn:aws:iam::123456789012:role/manaleague-eks-lab-app',
        'ALLOWED_CIDRS': '192.0.2.1/32',
        'SUBNET_IDS': 'subnet-00000000000000001,subnet-00000000000000002',
        'ALB_SECURITY_GROUP_ID': 'sg-00000000000000001',
    }
    with tempfile.TemporaryDirectory(prefix='eks-lab-schema-') as directory:
        target = Path(directory)
        base = target / 'base'
        render_tree(LAB / 'kubernetes/base', base, values)
        for path in base.rglob('*.yaml'):
            text = path.read_text()
            for target_name in ('app', 'web'):
                image = f'123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-{target_name}@sha256:' + '0' * 64
                text = text.replace(f'lab-{target_name}:dev', image)
            path.write_text(text)
        manifest_guard(base, values['DB_HOST'], values['AWS_REGION'])
        rendered = run(['kubectl', 'kustomize', base])
        if 'lab-app:dev' in rendered or 'lab-web:dev' in rendered or '__' in rendered:
            raise ValueError('Kustomize retained development image names or unresolved tokens')
        documents = [rendered]
        for source in ('bootstrap/resources.yaml', 'bootstrap/rbac.yaml', 'jobs/migrate.yaml', 'jobs/seed.yaml'):
            text = (LAB / 'kubernetes' / source).read_text()
            text = replace_tokens(text.replace('__RELEASE__', '0' * 40), values)
            # Source manifests deliberately use JSON-compatible YAML, avoiding a third-party parser.
            for document in re.split(r'^---\s*$', text, flags=re.MULTILINE):
                if document.strip():
                    json.loads(document)
            documents.append(text)
        print(run(['kubeconform', '-strict', '-summary', '-kubernetes-version', '1.37.0'], input='\n---\n'.join(documents)))


if __name__ == '__main__':
    main()
