#!/usr/bin/env python3
"""Render nonsecret GitHub Environment variables from fresh Terraform outputs.

Offline only: does not invoke Terraform, AWS, GitHub, git or Kubernetes.
The result is a proposed payload for operator review, never proof of ownership.
"""
import argparse
import json
import ipaddress
import re
from pathlib import Path
import sys

NAME = 'manaleague-eks-lab'
MAPPING = {
    'AWS_ACCOUNT_ID': 'account_id', 'AWS_REGION': 'aws_region',
    'AWS_ROLE_ARN': 'github_deploy_role_arn', 'EKS_CLUSTER_NAME': 'cluster_name',
    'ECR_APP_REPOSITORY': 'ecr_app_repository_url', 'ECR_WEB_REPOSITORY': 'ecr_web_repository_url',
    'AVATAR_BUCKET': 'avatar_bucket_name', 'APP_ROLE_ARN': 'app_role_arn',
    'VPC_CIDR': 'vpc_cidr', 'VPC_ID': 'vpc_id', 'DB_HOST': 'rds_endpoint',
    'RDS_MASTER_SECRET_ARN': 'rds_master_secret_arn', 'SUBNET_IDS': 'subnet_ids',
    'ALLOWED_CIDRS': 'allowed_client_cidrs', 'ALB_SECURITY_GROUP_ID': 'alb_security_group_id',
}


def render(outputs, account, region):
    if not isinstance(account, str) or not re.fullmatch(r'[0-9]{12}', account):
        raise ValueError('Expected account must be exactly twelve digits')
    if not isinstance(region, str) or not re.fullmatch(r'[a-z]{2}-[a-z]+-[1-9]', region):
        raise ValueError('Invalid selected region')
    required = set(MAPPING.values()) | {'lab_identity', 'rds_identifier', 'rds_database'}
    if not isinstance(outputs, dict):
        raise ValueError('Terraform outputs object required')
    source = {}
    for key in required:
        wrapper = outputs.get(key)
        if not isinstance(wrapper, dict) or wrapper.get('sensitive') is not False or 'value' not in wrapper:
            raise ValueError(f'Missing, sensitive or invalid output: {key}')
        source[key] = wrapper['value']
    identity = {'name': NAME, 'namespace': NAME, 'region': region, 'account_id': account}
    exact = {
        'lab_identity': identity, 'account_id': account, 'aws_region': region,
        'cluster_name': NAME, 'rds_identifier': NAME, 'rds_database': 'manaleague_eks_lab',
        'github_deploy_role_arn': f'arn:aws:iam::{account}:role/{NAME}-github-deploy',
        'app_role_arn': f'arn:aws:iam::{account}:role/{NAME}-app',
        'avatar_bucket_name': f'{NAME}-{account}-{region}-avatars',
        'ecr_app_repository_url': f'{account}.dkr.ecr.{region}.amazonaws.com/{NAME}-app',
        'ecr_web_repository_url': f'{account}.dkr.ecr.{region}.amazonaws.com/{NAME}-web',
    }
    for key, expected in exact.items():
        if source[key] != expected:
            raise ValueError(f'Foreign or invalid lab output: {key}')
    patterns = {
        'vpc_id': r'vpc-(?:[a-f0-9]{8}|[a-f0-9]{17})',
        'alb_security_group_id': r'sg-(?:[a-f0-9]{8}|[a-f0-9]{17})',
        'rds_endpoint': re.escape(NAME) + r'\.[a-z0-9]+\.' + re.escape(region) + r'\.rds\.amazonaws\.com',
        'rds_master_secret_arn': re.escape(f'arn:aws:secretsmanager:{region}:{account}:secret:rds!db-') + r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}-[A-Za-z0-9]{6}',
    }
    for key, pattern in patterns.items():
        if not isinstance(source[key], str) or not re.fullmatch(pattern, source[key]):
            raise ValueError(f'Invalid lab output format: {key}')
    try:
        if not isinstance(source['vpc_cidr'], str):
            raise ValueError('VPC CIDR must be a string')
        network = ipaddress.IPv4Network(source['vpc_cidr'], strict=True)
        private = [ipaddress.IPv4Network(n) for n in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')]
        if str(network) != source['vpc_cidr'] or network.prefixlen != 16 or not any(network.subnet_of(n) for n in private):
            raise ValueError('VPC must be an RFC1918 IPv4 /16')
        subnets = source['subnet_ids']
        if not isinstance(subnets, list) or len(subnets) != 2 or len(set(subnets)) != 2:
            raise ValueError('Two distinct public subnet IDs required')
        if any(not isinstance(s, str) or not re.fullmatch(r'subnet-(?:[a-f0-9]{8}|[a-f0-9]{17})', s) for s in subnets):
            raise ValueError('Invalid subnet IDs')
        cidrs = source['allowed_client_cidrs']
        if not isinstance(cidrs, list) or not cidrs or len(set(cidrs)) != len(cidrs):
            raise ValueError('Unique client host CIDRs required')
        for value in cidrs:
            if not isinstance(value, str):
                raise ValueError('String host CIDRs required')
            cidr = ipaddress.ip_network(value, strict=True)
            if cidr.version != 4 or cidr.prefixlen != 32 or str(cidr) != value or not cidr.network_address.is_global:
                raise ValueError('Only canonical public IPv4 /32 clients accepted')
    except (TypeError, AttributeError) as error:
        raise ValueError('Invalid network output types') from error
    values = {name: source[key] for name, key in MAPPING.items()}
    for key in ('SUBNET_IDS', 'ALLOWED_CIDRS'):
        values[key] = ','.join(values[key])
    values.update(LAB_DEPLOY_ENABLED='false', APP_URL='')
    return {'repository': 'viniciusVitorio/manaleague', 'environment': 'eks-lab',
            'variables': [{'name': k, 'value': v} for k, v in values.items()]}


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON object key')
        result[key] = value
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, required=True, help='Fresh terraform output -json file; never state backup')
    parser.add_argument('--expected-account', required=True)
    parser.add_argument('--region', required=True)
    args = parser.parse_args(argv)
    try:
        outputs = json.loads(args.input.read_text(encoding='utf-8-sig'), object_pairs_hook=strict_object)
        result = render(outputs, args.expected_account, args.region)
    except (ValueError, KeyError, TypeError, OSError):
        parser.exit(2, 'Refused invalid/missing Terraform outputs or lab bindings. No API called.\n')
    print(json.dumps(result, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
