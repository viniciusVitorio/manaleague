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


DNS_NAME_SERVERS = frozenset(('ns-605.awsdns-11.net', 'ns-16.awsdns-02.com',
                             'ns-1514.awsdns-61.org', 'ns-1580.awsdns-05.co.uk'))
HTTPS_HOSTNAME = 'eks.manaleague.com.br'
HTTPS_ORIGIN = 'https://' + HTTPS_HOSTNAME


def https_bindings(source, account, region):
    """Validate output consistency only; never infer live ACM/DNS ownership."""
    enabled = source['lab_https_enabled']
    if type(enabled) is not bool or source['lab_hostname'] != HTTPS_HOSTNAME:
        raise ValueError('Invalid explicit HTTPS mode or lab hostname')
    if not enabled:
        if any(source[key] != '' for key in ('lab_url', 'lab_certificate_arn', 'lab_dns_zone_id')) or source['lab_dns_name_servers'] != []:
            raise ValueError('Disabled HTTPS must not carry active domain bindings')
        return {'ACM_CERTIFICATE_ARN': '', 'APP_URL': ''}
    cert = source['lab_certificate_arn']
    pattern = (re.escape(f'arn:aws:acm:{region}:{account}:certificate/')
               + r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}')
    if source['lab_url'] != HTTPS_ORIGIN or not isinstance(cert, str) or not re.fullmatch(pattern, cert):
        raise ValueError('Only exact lab HTTPS origin and account/region certificate allowed')
    zone = source['lab_dns_zone_id']
    servers = source['lab_dns_name_servers']
    if (not isinstance(zone, str) or not re.fullmatch(r'Z[A-Z0-9]{7,31}', zone)
            or not isinstance(servers, list) or len(servers) != 4
            or any(not isinstance(server, str) for server in servers)
            or set(servers) != DNS_NAME_SERVERS):
        raise ValueError('Explicit public-zone identity and authoritative name servers required')
    return {'ACM_CERTIFICATE_ARN': cert, 'APP_URL': HTTPS_ORIGIN}


def render(outputs, account, region):
    if not isinstance(account, str) or not re.fullmatch(r'[0-9]{12}', account):
        raise ValueError('Expected account must be exactly twelve digits')
    if not isinstance(region, str) or not re.fullmatch(r'[a-z]{2}-[a-z]+-[1-9]', region):
        raise ValueError('Invalid selected region')
    required = set(MAPPING.values()) | {'lab_identity', 'rds_identifier', 'rds_database',
        'private_subnet_ids', 'node_subnet_ids', 'nat_gateway_id', 'nat_public_ip', 'nat_egress_cidr',
        'lab_https_enabled', 'lab_hostname', 'lab_url', 'lab_certificate_arn',
        'lab_dns_zone_id', 'lab_dns_name_servers'}
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
        private_ids, node_ids = source['private_subnet_ids'], source['node_subnet_ids']
        for ids, sizes in ((private_ids, (2,)), (node_ids, (1, 2))):
            if (not isinstance(ids, list) or len(ids) not in sizes
                    or any(not isinstance(s, str) or not re.fullmatch(r'subnet-(?:[a-f0-9]{8}|[a-f0-9]{17})', s) for s in ids)
                    or len(set(ids)) != len(ids)):
                raise ValueError('Distinct private/node subnet IDs required')
        if set(private_ids) & set(subnets) or not set(node_ids).issubset(private_ids):
            raise ValueError('Nodes must use private subnets disjoint from public ALB subnets')
        if not isinstance(source['nat_gateway_id'], str) or not re.fullmatch(r'nat-[a-f0-9]{17}', source['nat_gateway_id']):
            raise ValueError('Invalid NAT gateway ID')
        ip = source['nat_public_ip']
        if not isinstance(ip, str) or str(ipaddress.IPv4Address(ip)) != ip or not ipaddress.IPv4Address(ip).is_global or ipaddress.IPv4Address(ip).is_multicast:
            raise ValueError('Canonical global IPv4 NAT EIP required')
        if source['nat_egress_cidr'] != ip + '/32' or source['nat_egress_cidr'] not in source['allowed_client_cidrs']:
            raise ValueError('Exact NAT EIP /32 must be in ALB allowlist')
        cidrs = source['allowed_client_cidrs']
        if not isinstance(cidrs, list) or not cidrs or len(set(cidrs)) != len(cidrs):
            raise ValueError('Unique client host CIDRs required')
        for value in cidrs:
            if not isinstance(value, str):
                raise ValueError('String host CIDRs required')
            cidr = ipaddress.ip_network(value, strict=True)
            if cidr.version != 4 or cidr.prefixlen != 32 or str(cidr) != value or not cidr.network_address.is_global or cidr.network_address.is_multicast:
                raise ValueError('Only canonical public IPv4 /32 clients accepted')
    except (TypeError, AttributeError) as error:
        raise ValueError('Invalid network output types') from error
    values = {name: source[key] for name, key in MAPPING.items()}
    for key in ('SUBNET_IDS', 'ALLOWED_CIDRS'):
        values[key] = ','.join(values[key])
    values.update(https_bindings(source, account, region))
    values['LAB_DEPLOY_ENABLED'] = 'false'
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
