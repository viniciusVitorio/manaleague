#!/usr/bin/env python3
"""Isolated lab DNS operator. Default plan is offline metadata, NOT ownership proof.

Prepare only the two frontend Services, Ingress and the fixed A/TXT pair before
opening CD's gate. Never creates Pods, reads secrets or mutates the shared zone.
Cloud execution requires an explicitly configured operator profile `eks-lab`.
"""
import argparse
import importlib.util
import json
import re
from pathlib import Path
import sys

NAME = 'manaleague-eks-lab'
HOSTNAME = 'eks.manaleague.com.br'
ORIGIN = 'https://' + HOSTNAME
OWNER_NAME = '_eks-lab.' + HOSTNAME + '.'
NAMESERVERS = frozenset(('ns-605.awsdns-11.net', 'ns-16.awsdns-02.com',
                         'ns-1514.awsdns-61.org', 'ns-1580.awsdns-05.co.uk'))
LAB = Path(__file__).resolve().parents[1]
_DEPS = None


def load_module(name):
    spec = importlib.util.spec_from_file_location('domain_' + name, LAB / 'scripts' / (name + '.py'))
    if spec is None or spec.loader is None:
        raise ValueError('Local operator dependency unavailable')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def dependencies():
    global _DEPS
    if _DEPS is None:
        _DEPS = (load_module('github_bindings'), load_module('https_contract'))
    return _DEPS


def literals(account, region):
    if not isinstance(account, str) or not re.fullmatch(r'[0-9]{12}', account) or region != 'us-east-1':
        raise ValueError('Exact twelve-digit account and us-east-1 required')


def validate_outputs(outputs, account, region):
    literals(account, region)
    bindings, contract = dependencies()
    payload = bindings.render(outputs, account, region)
    values = {item['name']: item['value'] for item in payload['variables']}
    source = {}
    for key in ('lab_https_enabled', 'lab_hostname', 'lab_url', 'lab_certificate_arn',
                'lab_dns_zone_id', 'lab_dns_name_servers'):
        wrapper = outputs.get(key)
        if not isinstance(wrapper, dict) or wrapper.get('sensitive') is not False or 'value' not in wrapper:
            raise ValueError('Missing or sensitive HTTPS metadata')
        source[key] = wrapper['value']
    ns = source['lab_dns_name_servers']
    if (source['lab_https_enabled'] is not True or source['lab_hostname'] != HOSTNAME
            or source['lab_url'] != ORIGIN or not isinstance(source['lab_dns_zone_id'], str)
            or not re.fullmatch(r'Z[A-Z0-9]{5,32}', source['lab_dns_zone_id'])
            or not isinstance(ns, list) or len(ns) != 4 or any(not isinstance(n, str) for n in ns)
            or frozenset(ns) != NAMESERVERS):
        raise ValueError('Exact enabled lab HTTPS/zone/authoritative NS metadata required')
    contract.validate_certificate_arn(source['lab_certificate_arn'], account=account, region=region)
    values.update(APP_URL=ORIGIN, ACM_CERTIFICATE_ARN=source['lab_certificate_arn'])
    return {'account': account, 'region': region, 'zone_id': source['lab_dns_zone_id'], 'values': values,
            'outputs': outputs}


def plan(config):
    return {'action': 'plan', 'hostname': HOSTNAME, 'origin': ORIGIN,
            'zone_id': config['zone_id'], 'authoritative_name_servers': sorted(NAMESERVERS),
            'dns_names': [HOSTNAME + '.', OWNER_NAME],
            'frontend': ['Service/manaleague', 'Service/reverb', 'Ingress/manaleague'],
            'frontend_constraints': {'namespace': NAME, 'host': HOSTNAME, 'alb_name': NAME,
                'scheme': 'internet-facing', 'ingress_class': 'alb', 'target_type': 'ip',
                'ssl_policy': dependencies()[1].SSL_POLICY,
                'security_group': config['values']['ALB_SECURITY_GROUP_ID'],
                'subnets': config['values']['SUBNET_IDS'].split(','), 'project_tag': NAME,
                'services': {'manaleague': {'selector': {'app': 'manaleague'}, 'port': 80, 'targetPort': 'http'},
                             'reverb': {'selector': {'app': 'reverb'}, 'port': 8080, 'targetPort': 'ws'}}},
            'expected_dns': [{'Name': HOSTNAME + '.', 'Type': 'A', 'target': 'pending-verified-ALB'},
                             {'Name': OWNER_NAME, 'Type': 'TXT', 'TTL': 60,
                              'marker_identity': f'{NAME}|{config["account"]}|{config["region"]}',
                              'target': 'pending-verified-ALB'}],
            'certificate_arn': config['values']['ACM_CERTIFICATE_ARN'],
            'ready_for_cd': False, 'application_verified': False,
            'metadata_is_ownership_proof': False,
            'next_step': 'Review, bootstrap, then explicitly authorize prepare before enabling CD'}


def identity_and_zone(cli, config):
    if cli.aws('sts', 'get-caller-identity').get('Account') != config['account']:
        raise ValueError('Caller account mismatch')
    result = cli.aws('route53', 'get-hosted-zone', '--id', config['zone_id'])
    zone = result.get('HostedZone', {})
    ns = result.get('DelegationSet', {}).get('NameServers', [])
    if (zone.get('Id') != '/hostedzone/' + config['zone_id'] or zone.get('Name') != 'manaleague.com.br.'
            or zone.get('Config', {}).get('PrivateZone') is not False
            or not isinstance(ns, list) or len(ns) != 4 or frozenset(ns) != NAMESERVERS):
        raise ValueError('Public zone identity/delegation mismatch')


def alias_records(config, hostname, zone_id):
    if (not isinstance(hostname, str)
            or not re.fullmatch(re.escape(NAME) + r'-[a-z0-9]+\.' + re.escape(config['region']) + r'\.elb\.amazonaws\.com', hostname)
            or not isinstance(zone_id, str) or not re.fullmatch(r'Z[A-Z0-9]{5,32}', zone_id)):
        raise ValueError('Invalid exact lab ALB DNS target')
    marker = f'{NAME}|{config["account"]}|{config["region"]}|{hostname}|{zone_id}'
    return [{'Name': HOSTNAME + '.', 'Type': 'A',
             'AliasTarget': {'HostedZoneId': zone_id, 'DNSName': hostname + '.', 'EvaluateTargetHealth': False}},
            {'Name': OWNER_NAME, 'Type': 'TXT', 'TTL': 60, 'ResourceRecords': [{'Value': json.dumps(marker)}]}]


def exact_name_records(cli, config, name):
    if name not in (HOSTNAME + '.', OWNER_NAME):
        raise ValueError('DNS scope violation')
    payload = {'HostedZoneId': config['zone_id'], 'StartRecordName': name, 'StartRecordType': 'A', 'MaxItems': '100'}
    records, seen = [], set()
    for _ in range(16):
        result = cli.aws('route53', 'list-resource-record-sets', payload=payload)
        page = result.get('ResourceRecordSets')
        if not isinstance(page, list) or not isinstance(result.get('IsTruncated'), bool):
            raise ValueError('Malformed DNS page')
        records.extend(r for r in page if r.get('Name') == name)
        if not result['IsTruncated']:
            return records
        next_name, next_type = result.get('NextRecordName'), result.get('NextRecordType')
        if not isinstance(next_name, str) or not isinstance(next_type, str):
            raise ValueError('Missing DNS pagination cursor')
        # Route53 sorts reversed DNS labels, not ordinary lexical domain names.
        # If the API cursor has advanced to another name, all exact-name records
        # are exhausted; no guessed earlier record token is ever manufactured.
        if next_name != name:
            return records
        cursor = (next_name, next_type, result.get('NextRecordIdentifier'))
        if cursor in seen:
            raise ValueError('Repeated DNS pagination cursor')
        seen.add(cursor)
        payload.update(StartRecordName=next_name, StartRecordType=next_type)
        payload.pop('StartRecordIdentifier', None)
        if cursor[2] is not None:
            payload['StartRecordIdentifier'] = cursor[2]
    raise ValueError('DNS pagination limit exceeded')


def owned_records(cli, config):
    aliases = exact_name_records(cli, config, HOSTNAME + '.')
    markers = exact_name_records(cli, config, OWNER_NAME)
    if not aliases and not markers:
        return []
    if len(aliases) != 1 or len(markers) != 1:
        raise ValueError('Foreign or partial lab DNS pair; refusing adoption')
    alias = aliases[0]
    target = alias.get('AliasTarget', {})
    host = target.get('DNSName', '')
    if not host.endswith('.'):
        raise ValueError('Noncanonical DNS target')
    expected = alias_records(config, host[:-1], target.get('HostedZoneId'))
    if [alias, markers[0]] != expected:
        raise ValueError('DNS ownership marker or exact alias mismatch')
    return [alias, markers[0]]


def dns_change(cli, config, old, new, *, sleep):
    changes = [{'Action': action, 'ResourceRecordSet': record}
               for action, records in (('DELETE', old), ('CREATE', new)) for record in records]
    if changes:
        response = cli.aws('route53', 'change-resource-record-sets', payload={
            'HostedZoneId': config['zone_id'], 'ChangeBatch': {'Changes': changes}})
        change_id = response.get('ChangeInfo', {}).get('Id', '')
        if not re.fullmatch(r'/change/[A-Z0-9]+', change_id):
            raise ValueError('Missing DNS change identity')
        for attempt in range(60):
            change = cli.aws('route53', 'get-change', '--id', change_id).get('ChangeInfo', {})
            if change.get('Id') != change_id:
                raise ValueError('DNS change identity mismatch')
            if change.get('Status') == 'INSYNC':
                break
            if change.get('Status') != 'PENDING':
                raise ValueError('Unexpected DNS change status')
            if attempt == 59:
                raise ValueError('DNS change wait timed out; resources left for inspection')
            sleep(5)
    if owned_records(cli, config) != new:
        raise ValueError('Exact DNS pair read-back mismatch')


def reconcile_dns(cli, config, new, *, sleep):
    old = owned_records(cli, config)
    # Even no-op is verified by a second exact read; never claim cached success.
    dns_change(cli, config, old if old != new else [], new if old != new else [], sleep=sleep) if old != new else None
    if old == new and owned_records(cli, config) != new:
        raise ValueError('DNS pair changed during read-back')
    return 'unchanged' if old == new else ('rebound' if old else 'created')


def cleanup(config, cli, *, sleep):
    config['phase'] = 'cleanup-identity-zone'
    identity_and_zone(cli, config)
    config['phase'] = 'cleanup-owned-dns-delete-readback'
    old = owned_records(cli, config)
    dns_change(cli, config, old, [], sleep=sleep)
    return {'action': 'cleanup', 'dns_absent': True, 'ready_for_cd': False,
            'application_verified': False, 'frontend_deleted': False}


def documents(path):
    bindings, _ = dependencies()
    return [json.loads(doc, object_pairs_hook=bindings.strict_object)
            for doc in re.split(r'^---\s*$', path.read_text(encoding='utf-8'), flags=re.MULTILINE) if doc.strip()]


def canonical_frontend(config):
    """Extract only canonical Services; shared helper configures the TLS Ingress."""
    import tempfile
    lab = load_module('lab')
    resources = []
    # Rendering remains local and occurs only on explicitly authorized prepare.
    with tempfile.TemporaryDirectory(prefix='eks-lab-domain-render-') as directory:
        base = Path(directory) / 'base'
        lab.render_tree(LAB / 'kubernetes/base', base, config['values'])
        lab.configure_ingress(base, config['values'])
        for filename in ('app.yaml', 'reverb.yaml'):
            selected = [r for r in documents(base / filename) if r.get('kind') == 'Service']
            if len(selected) != 1:
                raise ValueError('Canonical Service missing or ambiguous')
            resources.extend(selected)
        resources.extend(documents(base / 'ingress.yaml'))
    if [(r.get('kind'), r.get('metadata', {}).get('name')) for r in resources] != [
            ('Service', 'manaleague'), ('Service', 'reverb'), ('Ingress', 'manaleague')]:
        raise ValueError('Canonical frontend resource scope changed')
    if any(r['metadata'].get('namespace') != NAME for r in resources):
        raise ValueError('Canonical frontend namespace mismatch')
    return resources


def tags_owned(tags):
    if not isinstance(tags, list):
        raise ValueError('Missing ownership tags')
    result = {}
    for tag in tags:
        if not isinstance(tag, dict) or not isinstance(tag.get('Key'), str) or tag['Key'] in result:
            raise ValueError('Ambiguous ownership tags')
        result[tag['Key']] = tag.get('Value')
    if result.get('Project') != NAME or result.get('Environment') != 'eks-lab':
        raise ValueError('Foreign cloud resource tags')
    return result


def one(result, key):
    items = result.get(key)
    if not isinstance(items, list) or len(items) != 1 or not isinstance(items[0], dict):
        raise ValueError('Cloud identity missing or ambiguous')
    return items[0]


def cloud_preflight(cli, config):
    identity_and_zone(cli, config)
    v, account, region = config['values'], config['account'], config['region']
    cluster = cli.aws('eks', 'describe-cluster', '--name', NAME).get('cluster', {})
    if (cluster.get('arn') != f'arn:aws:eks:{region}:{account}:cluster/{NAME}'
            or cluster.get('name') != NAME or cluster.get('status') != 'ACTIVE'
            or cluster.get('resourcesVpcConfig', {}).get('vpcId') != v['VPC_ID']
            or not isinstance(cluster.get('endpoint'), str) or not cluster['endpoint'].startswith('https://')
            or not cluster.get('certificateAuthority', {}).get('data')):
        raise ValueError('Exact ACTIVE lab cluster/VPC identity required')
    vpc = one(cli.aws('ec2', 'describe-vpcs', '--vpc-ids', v['VPC_ID']), 'Vpcs')
    if vpc.get('VpcId') != v['VPC_ID'] or vpc.get('OwnerId') != account or vpc.get('CidrBlock') != v['VPC_CIDR']:
        raise ValueError('VPC owner/CIDR mismatch')
    tags_owned(vpc.get('Tags'))
    sg = one(cli.aws('ec2', 'describe-security-groups', '--group-ids', v['ALB_SECURITY_GROUP_ID']), 'SecurityGroups')
    if any(sg.get(k) != val for k, val in {'GroupId': v['ALB_SECURITY_GROUP_ID'], 'VpcId': v['VPC_ID'],
                                          'OwnerId': account, 'GroupName': NAME + '-alb'}.items()):
        raise ValueError('Exact lab frontend SG required')
    tags_owned(sg.get('Tags'))
    ids = v['SUBNET_IDS'].split(',')
    subnets = cli.aws('ec2', 'describe-subnets', '--subnet-ids', *ids).get('Subnets', [])
    if len(subnets) != 2 or {s.get('SubnetId') for s in subnets} != set(ids) or any(
            s.get('VpcId') != v['VPC_ID'] or s.get('OwnerId') != account or s.get('MapPublicIpOnLaunch') is not True for s in subnets):
        raise ValueError('Public subnet identity mismatch')
    arn = v['ACM_CERTIFICATE_ARN']
    cert = cli.aws('acm', 'describe-certificate', '--certificate-arn', arn).get('Certificate')
    tags = cli.aws('acm', 'list-tags-for-certificate', '--certificate-arn', arn).get('Tags')
    dependencies()[1].validate_certificate(cert, tags, account=account, region=region, arn=arn)
    return cluster


def bootstrap_identity(cli, config):
    ns = cli.kube('get', 'namespace', NAME)
    sa = cli.kube('get', 'serviceaccount', 'lab-app')
    n, s = ns.get('metadata', {}), sa.get('metadata', {})
    expected_labels = documents(LAB / 'kubernetes/bootstrap/resources.yaml')[0]['metadata']['labels']
    ns_labels = dict(n.get('labels', {}))
    if 'kubernetes.io/metadata.name' in ns_labels and ns_labels.pop('kubernetes.io/metadata.name') != NAME:
        raise ValueError('Namespace system identity label mismatch')
    if (ns.get('kind') != 'Namespace' or n.get('name') != NAME or not n.get('uid') or n.get('deletionTimestamp')
            or n.get('ownerReferences') or ns_labels != expected_labels
            or sa.get('kind') != 'ServiceAccount' or s.get('name') != 'lab-app' or s.get('namespace') != NAME
            or s.get('labels', {}) != {}
            or not s.get('uid') or s.get('deletionTimestamp') or s.get('ownerReferences')
            or s.get('annotations', {}).get('eks.amazonaws.com/role-arn') != config['values']['APP_ROLE_ARN']):
        raise ValueError('Bootstrap namespace/IRSA ServiceAccount ownership mismatch')
    return n['uid'], s['uid']


def validate_runtime(actual, expected, uid=None):
    """Name alone is insufficient; allow only known API defaults, never spec extras."""
    import copy
    m, e = actual.get('metadata', {}), expected['metadata']
    if (actual.get('apiVersion') != expected['apiVersion'] or actual.get('kind') != expected['kind']
            or m.get('name') != e['name'] or m.get('namespace') != NAME or not m.get('uid')
            or (uid is not None and m['uid'] != uid) or m.get('deletionTimestamp') or m.get('ownerReferences')
            or m.get('labels', {}) != e.get('labels', {})):
        raise ValueError('Frontend runtime identity/labels/UID mismatch')
    annotations = dict(m.get('annotations', {}))
    annotations.pop('kubectl.kubernetes.io/last-applied-configuration', None)
    if annotations != e.get('annotations', {}):
        raise ValueError('Frontend annotations differ from canonical source')
    if set(m.get('finalizers', [])) - ({'ingress.k8s.aws/resources'} if actual['kind'] == 'Ingress' else set()):
        raise ValueError('Unknown frontend finalizer')
    spec = copy.deepcopy(actual.get('spec', {}))
    expected_spec = copy.deepcopy(expected['spec'])
    if actual['kind'] == 'Service':
        import ipaddress
        for key, default in (('ipFamilies', ['IPv4']), ('ipFamilyPolicy', 'SingleStack')):
            if key in spec and spec.pop(key) != default:
                raise ValueError('Unknown Service IP family/default')
        allocated = spec.pop('clusterIP', None)
        addresses = spec.pop('clusterIPs', None)
        if allocated is not None:
            ipaddress.IPv4Address(allocated)
        if addresses is not None:
            if not isinstance(addresses, list) or len(addresses) != 1:
                raise ValueError('Ambiguous Service cluster addresses')
            ipaddress.IPv4Address(addresses[0])
            if allocated is not None and addresses != [allocated]:
                raise ValueError('Service cluster address mismatch')
        for key, default in (('type', 'ClusterIP'), ('sessionAffinity', 'None'), ('internalTrafficPolicy', 'Cluster')):
            if key in spec and spec.pop(key) != default:
                raise ValueError('Unsafe Service default')
        for port in spec.get('ports', []):
            if 'protocol' in port and port.pop('protocol') != 'TCP':
                raise ValueError('Unsafe Service port protocol')
    if spec != expected_spec:
        raise ValueError('Unknown or changed frontend specification')
    return m['uid']


def frontend_snapshot(cli, resources):
    expected = {(r['kind'], r['metadata']['name']): r for r in resources}
    snapshot = {}
    for collection in ('services', 'ingresses'):
        inventory = cli.kube('get', collection)
        items = inventory.get('items')
        if not isinstance(items, list) or inventory.get('metadata', {}).get('continue'):
            raise ValueError('Missing or truncated frontend inventory')
        for resource in items:
            key = (resource.get('kind'), resource.get('metadata', {}).get('name'))
            if key not in expected or key in snapshot:
                raise ValueError('Unexpected frontend object; refusing mutation')
            snapshot[key] = validate_runtime(resource, expected[key])
    return snapshot


def verified_alb(cli, config, hostname):
    v = config['values']
    lb = one(cli.aws('elbv2', 'describe-load-balancers', '--names', NAME), 'LoadBalancers')
    prefix = f'arn:aws:elasticloadbalancing:{config["region"]}:{config["account"]}:loadbalancer/app/{NAME}/'
    if (not re.fullmatch(re.escape(prefix) + r'[a-f0-9]{16}', lb.get('LoadBalancerArn', ''))
            or lb.get('LoadBalancerName') != NAME or lb.get('DNSName') != hostname
            or lb.get('VpcId') != v['VPC_ID'] or lb.get('Scheme') != 'internet-facing'
            or lb.get('Type') != 'application'
            or lb.get('SecurityGroups') != [v['ALB_SECURITY_GROUP_ID']]
            or len(lb.get('AvailabilityZones', [])) != 2
            or {a.get('SubnetId') for a in lb['AvailabilityZones']} != set(v['SUBNET_IDS'].split(','))):
        raise ValueError('Exact public lab ALB account/VPC/subnet/SG identity required')
    description = one(cli.aws('elbv2', 'describe-tags', '--resource-arns', lb['LoadBalancerArn']), 'TagDescriptions')
    tags = {t['Key']: t['Value'] for t in description.get('Tags', [])}
    if (description.get('ResourceArn') != lb['LoadBalancerArn'] or tags.get('elbv2.k8s.aws/cluster') != NAME
            or tags.get('ingress.k8s.aws/stack') != NAME + '/manaleague' or tags.get('Project') != NAME):
        raise ValueError('ALB controller ownership mismatch')
    records = alias_records(config, hostname, lb.get('CanonicalHostedZoneId'))
    state = lb.get('State', {}).get('Code')
    if state == 'provisioning':
        return None
    if state != 'active':
        raise ValueError('Lab ALB is not active or provisioning')
    return records


def valid_lb_status(entry):
    """Accept the documented hostname plus the published 80/443 listener ports only.

    The API server and the controller publish `ports` alongside `hostname`; any
    other key, an IP-based status or an unexpected port is still ambiguous and
    must refuse before the hostname is trusted for DNS.
    """
    if not isinstance(entry, dict) or set(entry) - {'hostname', 'ports'}:
        return False
    if not isinstance(entry.get('hostname'), str) or not entry['hostname']:
        return False
    if 'ports' not in entry:
        return True
    ports = entry['ports']
    if not isinstance(ports, list) or not ports:
        return False
    for port in ports:
        if (not isinstance(port, dict) or set(port) - {'port', 'protocol'}
                or port.get('port') not in (80, 443)
                or not isinstance(port.get('protocol', ''), str)
                or port.get('protocol', '') not in ('', 'TCP')):
            return False
    return sorted(port['port'] for port in ports) == sorted({port['port'] for port in ports})


def prepare(config, cli, *, sleep):
    config['phase'] = 'cloud-identity-preflight'
    cluster = cloud_preflight(cli, config)
    # Check existing DNS BEFORE even creating a Service; no adoption of orphan pairs.
    config['phase'] = 'existing-dns-preflight'
    owned_records(cli, config)
    config['phase'] = 'canonical-frontend-render'
    resources = canonical_frontend(config)
    config['phase'] = 'bootstrap-frontend-preflight'
    cli.bind_cluster(cluster)
    bootstrap_uids = bootstrap_identity(cli, config)
    snapshot = frontend_snapshot(cli, resources)
    # Re-read all existing objects and bootstrap UIDs before the first mutation.
    if bootstrap_identity(cli, config) != bootstrap_uids or frontend_snapshot(cli, resources) != snapshot:
        raise ValueError('Kubernetes identity changed during preflight')
    config['phase'] = 'frontend-create-readback'
    uids = dict(snapshot)
    for resource in resources:
        key = (resource['kind'], resource['metadata']['name'])
        if key not in uids:
            created = cli.kube('create', payload=resource)
            uids[key] = validate_runtime(created, resource)
        actual = cli.kube('get', *key)
        validate_runtime(actual, resource, uids[key])
    ingress_template = resources[-1]
    config['phase'] = 'ingress-hostname-wait'
    hostname = None
    for attempt in range(60):
        ingress = cli.kube('get', 'Ingress', 'manaleague')
        validate_runtime(ingress, ingress_template, uids[('Ingress', 'manaleague')])
        hosts = ingress.get('status', {}).get('loadBalancer', {}).get('ingress', [])
        if hosts:
            if len(hosts) != 1 or not valid_lb_status(hosts[0]):
                raise ValueError('Ambiguous Ingress load balancer status')
            hostname = hosts[0]['hostname']
            break
        if attempt == 59:
            raise ValueError('Ingress hostname wait timed out; owned frontend left for inspection')
        sleep(5)
    config['phase'] = 'alb-ownership-activation-wait'
    records = None
    for attempt in range(60):
        records = verified_alb(cli, config, hostname)
        if records is not None:
            break
        if attempt == 59:
            raise ValueError('ALB activation wait timed out; owned frontend left for inspection')
        sleep(5)
    # Recheck UID immediately before DNS; no stale Ingress observation is trusted.
    ingress = cli.kube('get', 'Ingress', 'manaleague')
    validate_runtime(ingress, ingress_template, uids[('Ingress', 'manaleague')])
    final = ingress.get('status', {}).get('loadBalancer', {}).get('ingress', [])
    if len(final) != 1 or not valid_lb_status(final[0]) or final[0]['hostname'] != hostname:
        raise ValueError('Ingress hostname changed after ALB verification')
    config['phase'] = 'dns-cas-write-insync-readback'
    dns_result = reconcile_dns(cli, config, records, sleep=sleep)
    return {'action': 'prepare', 'origin': ORIGIN, 'ready_for_cd': True,
            'application_verified': False, 'dns_result': dns_result,
            'readiness_scope': 'frontend and DNS metadata only; CD must verify real TLS/application'}


# AWS CLI has no portable Windows stdin JSON loader. This fixed stdlib bridge
# receives JSON on stdin and passes ONLY nonsecret request metadata to AWS CLI's
# documented --cli-input-json option. No shell, credential values or temp payload.
AWS_BRIDGE = '''import json, subprocess, sys
try:
    payload = json.load(sys.stdin)
    argv = sys.argv[1:]
    if payload is not None:
        argv += ['--cli-input-json', json.dumps(payload, separators=(',', ':'))]
    result = subprocess.run(argv, text=True, capture_output=True, check=True, timeout=75)
    sys.stdout.write(result.stdout)
except Exception:
    sys.stderr.write('AWS operator request failed; raw diagnostic suppressed.\\n')
    sys.exit(1)
'''


class Cli:
    """Bounded, nonsecret, shell-free operator subprocess transport."""
    AWS_ALLOWED = frozenset({('sts', 'get-caller-identity'), ('route53', 'get-hosted-zone'),
        ('route53', 'list-resource-record-sets'), ('route53', 'change-resource-record-sets'),
        ('route53', 'get-change'), ('eks', 'describe-cluster'), ('ec2', 'describe-vpcs'),
        ('ec2', 'describe-security-groups'), ('ec2', 'describe-subnets'),
        ('acm', 'describe-certificate'), ('acm', 'list-tags-for-certificate'),
        ('elbv2', 'describe-load-balancers'), ('elbv2', 'describe-tags')})

    def __init__(self, config):
        self.config = config
        self.directory = None
        self.kube_prefix = None

    def execute(self, argv, payload):
        import os
        import subprocess
        # Do not propagate application secrets or ambient AWS access-key overrides.
        allowed = {'PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'HOME', 'USERPROFILE',
                   'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL',
                   'AWS_CONFIG_FILE', 'AWS_SHARED_CREDENTIALS_FILE', 'AWS_CA_BUNDLE',
                   'SSL_CERT_FILE', 'REQUESTS_CA_BUNDLE', 'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY'}
        env = {k: v for k, v in os.environ.items() if k.upper() in allowed}
        env.update(AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off', AWS_EC2_METADATA_DISABLED='true',
                   AWS_DEFAULT_OUTPUT='json', AWS_PROFILE='eks-lab')
        try:
            output = subprocess.run([str(a) for a in argv], input=json.dumps(payload), text=True,
                                    capture_output=True, check=True, timeout=90, env=env).stdout
            result = json.loads(output, object_pairs_hook=dependencies()[0].strict_object)
            if not isinstance(result, dict):
                raise ValueError('Object response required')
            return result
        except (OSError, ValueError, subprocess.SubprocessError):
            raise ValueError('Operator subprocess failed; raw stdout/stderr suppressed; no readiness claimed') from None

    def aws(self, service, operation, *args, payload=None):
        if (service, operation) not in self.AWS_ALLOWED:
            raise ValueError('AWS operation outside lab domain scope')
        if service == 'route53' and operation == 'change-resource-record-sets':
            if not isinstance(payload, dict) or payload.get('HostedZoneId') != self.config['zone_id']:
                raise ValueError('DNS mutation zone scope mismatch')
            for change in payload.get('ChangeBatch', {}).get('Changes', []):
                r = change.get('ResourceRecordSet', {})
                if change.get('Action') not in ('CREATE', 'DELETE') or (r.get('Name'), r.get('Type')) not in (
                        (HOSTNAME + '.', 'A'), (OWNER_NAME, 'TXT')):
                    raise ValueError('DNS mutation name/type/action scope mismatch')
        return self.execute([sys.executable, '-I', '-B', '-c', AWS_BRIDGE, 'aws',
                             '--profile', 'eks-lab', '--region', self.config['region'], '--output', 'json',
                             '--no-cli-pager', '--no-paginate', service, operation, *args], payload)

    def bind_cluster(self, cluster):
        import tempfile
        if self.directory is not None:
            raise ValueError('Kubernetes context already bound')
        self.directory = tempfile.TemporaryDirectory(prefix='eks-lab-domain-kube-')
        path = Path(self.directory.name) / 'kubeconfig.json'
        arn = cluster['arn']
        config = {'apiVersion': 'v1', 'kind': 'Config', 'current-context': arn,
                  'contexts': [{'name': arn, 'context': {'cluster': arn, 'user': arn, 'namespace': NAME}}],
                  'clusters': [{'name': arn, 'cluster': {'server': cluster['endpoint'],
                      'certificate-authority-data': cluster['certificateAuthority']['data']}}],
                  'users': [{'name': arn, 'user': {'exec': {'apiVersion': 'client.authentication.k8s.io/v1beta1',
                      'command': 'aws', 'interactiveMode': 'Never', 'args': ['--profile', 'eks-lab',
                          '--region', self.config['region'], 'eks', 'get-token', '--cluster-name', NAME]}}}]}
        path.write_text(json.dumps(config), encoding='utf-8')
        self.kube_prefix = ['kubectl', '--kubeconfig', str(path), '--context', arn,
                            '--namespace', NAME, '--request-timeout=20s']

    def kube(self, *args, payload=None):
        if self.kube_prefix is None or not args:
            raise ValueError('Unbound Kubernetes transport')
        if args == ('create',):
            if not isinstance(payload, dict) or (payload.get('kind'), payload.get('metadata', {}).get('name')) not in (
                    ('Service', 'manaleague'), ('Service', 'reverb'), ('Ingress', 'manaleague')) or payload['metadata'].get('namespace') != NAME:
                raise ValueError('Kubernetes create outside exact frontend scope')
            return self.execute(self.kube_prefix + ['create', '-f', '-', '-o', 'json'], payload)
        allowed = {('get', 'namespace', NAME), ('get', 'serviceaccount', 'lab-app'),
                   ('get', 'services'), ('get', 'ingresses'), ('get', 'Service', 'manaleague'),
                   ('get', 'Service', 'reverb'), ('get', 'Ingress', 'manaleague')}
        if args not in allowed or payload is not None:
            raise ValueError('Kubernetes operation outside frontend read/create scope')
        return self.execute(self.kube_prefix + [*args, '-o', 'json'], None)

    def close(self):
        if self.directory is not None:
            self.directory.cleanup()
            self.directory = None
            self.kube_prefix = None



def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', nargs='?', choices=('plan', 'prepare', 'cleanup'), default='plan')
    parser.add_argument('--outputs', required=True, type=Path)
    parser.add_argument('--expected-account', required=True)
    parser.add_argument('--region', required=True)
    parser.add_argument('--allow-cloud', action='store_true')
    parser.add_argument('--allow-lab-dns', action='store_true')
    parser.add_argument('--confirm-lab')
    args = parser.parse_args(argv)
    try:
        literals(args.expected_account, args.region)
        if args.action != 'plan' and (not args.allow_cloud or not args.allow_lab_dns or args.confirm_lab != NAME):
            raise ValueError('Explicit cloud, lab DNS and exact lab confirmation required')
    except ValueError as error:
        parser.error(str(error))
    config = None
    try:
        bindings, _ = dependencies()
        data = json.loads(args.outputs.read_text(encoding='utf-8-sig'), object_pairs_hook=bindings.strict_object)
        config = validate_outputs(data, args.expected_account, args.region)
        if args.action == 'plan':
            result = plan(config)
        else:
            import time
            cli = Cli(config)
            try:
                handler = prepare if args.action == 'prepare' else cleanup
                result = handler(config, cli, sleep=time.sleep)
            finally:
                cli.close()
    except (ValueError, KeyError, TypeError, AttributeError, OSError):
        phase = config.get('phase', 'input-validation') if config else 'input-validation'
        parser.exit(2, f'Refused at {phase}. No readiness claimed; no automatic rollback. '
                       'Inspect only lab Services/manaleague,reverb, Ingress/manaleague and the fixed lab A/TXT pair before retry.\n')
    print(json.dumps(result, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
