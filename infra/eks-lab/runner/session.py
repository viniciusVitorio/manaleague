#!/usr/bin/env python3
"""Offline by default. Live session operations require explicit authorization."""
import argparse
import importlib.util
import json
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
CLUSTER = 'manaleague-eks-lab'
REPO = 'viniciusVitorio/manaleague'
OWNER = 'eks-lab.manaleague.test/session'


def renderer():
    spec = importlib.util.spec_from_file_location('eks_runner_renderer', HERE / 'operator.py')
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


RENDERER = renderer()


def parse(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, allow_abbrev=False)
    parser.add_argument('action', nargs='?', choices=('plan', 'start', 'cleanup'), default='plan')
    for name in ('account', 'region', 'vpc-cidr', 'run-id', 'sha'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--cluster', default=CLUSTER)
    parser.add_argument('--api-cidr', action='append', default=[])
    parser.add_argument('--github-auth', choices=('gh', 'windows-gcm'), default='gh',
                        help='stored gh login, or explicit WSL-to-Windows captured credential bridge')
    parser.add_argument('--state-dir', help='absolute private POSIX directory named .local (use WSL ext4)')
    parser.add_argument('--allow-cloud', action='store_true')
    parser.add_argument('--confirm-lab')
    parser.add_argument('--ack-public-self-hosted-risk', action='store_true')
    parser.add_argument('--ack-network-verified-in-vpc', action='store_true')
    args = parser.parse_args(argv)
    try:
        if args.state_dir and (not Path(args.state_dir).is_absolute() or Path(args.state_dir).name != '.local'):
            raise ValueError('--state-dir must be an explicit absolute directory named .local')
        if args.cluster != CLUSTER or not re.fullmatch('[a-f0-9]{40}', args.sha):
            raise ValueError('exact lab cluster and lowercase SHA40 required')
        if not args.api_cidr:
            raise ValueError('explicit API /32s required; resolve and verify INSIDE the VPC, not on Windows')
        RENDERER.render(args.account, args.region, args.vpc_cidr,
                        api_cidrs=args.api_cidr, run_id=args.run_id)
        if args.action != 'plan' and not (args.allow_cloud and args.confirm_lab == CLUSTER
                and args.ack_public_self_hosted_risk and args.ack_network_verified_in_vpc):
            raise ValueError('live actions require --allow-cloud --confirm-lab manaleague-eks-lab '
                             '--ack-public-self-hosted-risk --ack-network-verified-in-vpc')
    except ValueError as error:
        parser.error(str(error))
    return args


def plan(args):
    return {'action': 'plan', 'cloud_actions': False, 'repository': REPO,
            'run_id': args.run_id, 'sha': args.sha, 'run_label': 'eks-lab-' + args.run_id,
            'manifest': {'apiVersion': 'v1', 'kind': 'List', 'items': RENDERER.render(
                args.account, args.region, args.vpc_cidr, api_cidrs=args.api_cidr, run_id=args.run_id)},
            'required_before_start': ['VPC-local API DNS/ENI verification and live network enforcement',
                'public self-hosted risk review; labels are NOT an authorization boundary',
                'trusted push pipeline.yml run and queued deploy job; gate must already be true'],
            'gate_mutation': False}


def authorize(args):
    if args.action not in ('start', 'cleanup') or not (args.allow_cloud and args.confirm_lab == CLUSTER
            and args.ack_public_self_hosted_risk and args.ack_network_verified_in_vpc):
        raise ValueError('live authorization missing')


def verify_provenance():
    import hashlib
    try:
        rows = json.loads((HERE / 'images.json').read_text(encoding='utf-8'))
        if {r['image'] for r in rows} != {RENDERER.RUNNER_IMAGE, RENDERER.AWS_IMAGE}:
            raise ValueError('pin mismatch')
        for row in rows:
            path = (HERE / row['manifest_file']).resolve()
            if path.parent != HERE / 'provenance':
                raise ValueError('manifest path outside provenance')
            raw = path.read_bytes()
            if 'sha256:' + hashlib.sha256(raw).hexdigest() != row['index_digest']:
                raise ValueError('manifest digest mismatch')
            amd64 = [m['digest'] for m in json.loads(raw)['manifests'] if
                     m['platform'] == {'architecture': 'amd64', 'os': 'linux'}]
            if amd64 != [row['amd64_digest']] or row['image'].split('@')[1] not in (
                    row['index_digest'], row['amd64_digest']):
                raise ValueError('platform pin mismatch')
    except (KeyError, TypeError, OSError, ValueError):
        raise ValueError('recorded image provenance invalid') from None


def target(args, remote):
    authorize(args)
    if remote.aws('sts', 'get-caller-identity').get('Account') != args.account:
        raise ValueError('STS account mismatch')
    cluster = remote.aws('eks', 'describe-cluster', '--name', CLUSTER)['cluster']
    arn = f'arn:aws:eks:{args.region}:{args.account}:cluster/{CLUSTER}'
    if (cluster.get('arn') != arn or cluster.get('name') != CLUSTER or cluster.get('status') != 'ACTIVE'
            or not re.fullmatch(r'https://[A-Za-z0-9.-]+\.eks\.amazonaws\.com', cluster.get('endpoint', ''))
            or not cluster.get('certificateAuthority', {}).get('data')
            or cluster.get('resourcesVpcConfig', {}).get('endpointPrivateAccess') is not True):
        raise ValueError('exact ACTIVE cluster, TLS endpoint, CA and private API required')
    return cluster


def topology_subnets(cluster, args, remote):
    import ipaddress
    vpc_id = cluster['resourcesVpcConfig']['vpcId']
    subnets = remote.aws('ec2', 'describe-subnets', '--filters', f'Name=vpc-id,Values={vpc_id}')['Subnets']
    expected = {CLUSTER + '-' + role + '-' + str(i): public
                for role, public in (('public', True), ('private', False)) for i in (1, 2)}
    found = {}
    for subnet in subnets:
        names = [t['Value'] for t in subnet.get('Tags', []) if t.get('Key') == 'Name']
        if (len(names) != 1 or names[0] not in expected or names[0] in found
                or subnet.get('VpcId') != vpc_id
                or subnet.get('MapPublicIpOnLaunch') is not expected[names[0]]
                or not subnet.get('SubnetId')
                or not ipaddress.IPv4Network(subnet['CidrBlock']).subnet_of(ipaddress.IPv4Network(args.vpc_cidr))):
            raise ValueError('exact public/private lab subnet identity and mapping required')
        found[names[0]] = subnet
    if set(found) != set(expected) or len({s['SubnetId'] for s in found.values()}) != 4:
        raise ValueError('two public and two shared EKS/RDS private subnets required')
    private_ids = {s['SubnetId'] for name, s in found.items() if not expected[name]}
    configured = cluster['resourcesVpcConfig'].get('subnetIds', [])
    if len(configured) != 2 or set(configured) != private_ids:
        raise ValueError('cluster ENI subnets must be exactly both private subnets')
    return found, private_ids


def topology_nat(cluster, subnets, private_ids, remote):
    import ipaddress
    vpc_id = cluster['resourcesVpcConfig']['vpcId']
    tables = remote.aws('ec2', 'describe-route-tables', '--filters', f'Name=vpc-id,Values={vpc_id}')['RouteTables']
    nats = remote.aws('ec2', 'describe-nat-gateways', '--filter', f'Name=vpc-id,Values={vpc_id}')['NatGateways']
    if len(nats) != 1:
        raise ValueError('exactly one lab NAT gateway required')
    nat = nats[0]
    if (nat.get('VpcId') != vpc_id or nat.get('State') != 'available'
            or nat.get('ConnectivityType') != 'public'
            or nat.get('SubnetId') != subnets[CLUSTER + '-public-1']['SubnetId']
            or not re.fullmatch(r'nat-[a-f0-9]{17}', nat.get('NatGatewayId', ''))):
        raise ValueError('exact available public NAT in first public subnet required')
    addresses = nat.get('NatGatewayAddresses', [])
    if len(addresses) != 1 or not addresses[0].get('AllocationId'):
        raise ValueError('one NAT Elastic IP allocation required')
    address = addresses[0]
    ip = address.get('PublicIp', '')
    if str(ipaddress.IPv4Address(ip)) != ip or not ipaddress.IPv4Address(ip).is_global or ipaddress.IPv4Address(ip).is_multicast:
        raise ValueError('global IPv4 NAT Elastic IP required')
    eips = remote.aws('ec2', 'describe-addresses', '--allocation-ids', address['AllocationId'])['Addresses']
    if (len(eips) != 1 or eips[0].get('AllocationId') != address['AllocationId']
            or eips[0].get('PublicIp') != ip or eips[0].get('Domain') != 'vpc'):
        raise ValueError('exact NAT Elastic IP allocation mismatch')
    igws = remote.aws('ec2', 'describe-internet-gateways', '--filters',
                      f'Name=attachment.vpc-id,Values={vpc_id}')['InternetGateways']
    if (len(igws) != 1 or not igws[0].get('InternetGatewayId', '').startswith('igw-')
            or igws[0].get('Attachments') != [{'VpcId': vpc_id, 'State': 'available'}]):
        raise ValueError('exact VPC attached IGW required')
    for subnet in subnets.values():
        sid = subnet['SubnetId']
        matches = [table for table in tables if any(a.get('SubnetId') == sid
                   and a.get('AssociationState', {}).get('State') == 'associated'
                   for a in table.get('Associations', []))]
        if len(matches) != 1 or matches[0].get('VpcId') != vpc_id:
            raise ValueError('exact associated subnet route table required')
        defaults = [r for r in matches[0].get('Routes', []) if r.get('DestinationCidrBlock') == '0.0.0.0/0']
        key, value = ('NatGatewayId', nat['NatGatewayId']) if sid in private_ids else ('GatewayId', igws[0]['InternetGatewayId'])
        if (len(defaults) != 1 or defaults[0].get('State') != 'active' or defaults[0].get(key) != value
                or (sid in private_ids and defaults[0].get('GatewayId'))):
            raise ValueError('private default must use exact NAT; public default must use attached IGW')
    return ip + '/32'


def topology_nodes(cluster, subnets, private_ids, remote):
    vpc_id = cluster['resourcesVpcConfig']['vpcId']
    names = remote.aws('eks', 'list-nodegroups', '--cluster-name', CLUSTER)['nodegroups']
    expected = [CLUSTER + '-az-1', CLUSTER + '-az-2']
    if names not in ([expected[0]], expected, list(reversed(expected))):
        raise ValueError('one first-AZ or two exact lab managed node groups required')
    node_subnets = set()
    for name in names:
        group = remote.aws('eks', 'describe-nodegroup', '--cluster-name', CLUSTER,
                           '--nodegroup-name', name)['nodegroup']
        sid = subnets[CLUSTER + '-private-' + name[-1]]['SubnetId']
        if (group.get('clusterName') != CLUSTER or group.get('nodegroupName') != name
                or group.get('subnets') != [sid]):
            raise ValueError('exact managed node group must use its private AZ subnet')
        node_subnets.add(sid)
    reservations = remote.aws('ec2', 'describe-instances', '--filters',
        f'Name=vpc-id,Values={vpc_id}', 'Name=instance-state-name,Values=pending,running')['Reservations']
    nodes = [node for reservation in reservations for node in reservation['Instances']]
    if not nodes:
        raise ValueError('at least one actual pending/running lab node required')
    for node in nodes:
        if (node.get('VpcId') != vpc_id or node.get('SubnetId') not in private_ids & node_subnets
                or node.get('State', {}).get('Name') not in ('pending', 'running')
                or not node.get('InstanceId')
                or not any(t.get('Key') == 'eks:eks-cluster-name' and t.get('Value') == CLUSTER
                           for t in node.get('Tags', []))
                or node.get('PublicIpAddress')
                or any(eni.get('Association', {}).get('PublicIp') for eni in node.get('NetworkInterfaces', []))):
            raise ValueError('every live EC2 instance in lab VPC must be an eligible private lab node without public IP')


def topology_alb(cluster, nat_cidr, remote, *, tls=False):
    import ipaddress
    vpc_id = cluster['resourcesVpcConfig']['vpcId']
    groups = remote.aws('ec2', 'describe-security-groups', '--filters',
        f'Name=vpc-id,Values={vpc_id}', f'Name=group-name,Values={CLUSTER}-alb')['SecurityGroups']
    if (len(groups) != 1 or groups[0].get('VpcId') != vpc_id
            or groups[0].get('GroupName') != CLUSTER + '-alb' or not groups[0].get('GroupId')):
        raise ValueError('exact named lab VPC ALB security group required')
    allowed = {port: set() for port in ((80, 443) if tls else (80,))}
    for rule in groups[0].get('IpPermissions', []):
        port = rule.get('FromPort')
        if (rule.get('IpProtocol') not in ('tcp', '6') or port not in allowed or rule.get('ToPort') != port
                or rule.get('Ipv6Ranges') or rule.get('UserIdGroupPairs') or rule.get('PrefixListIds')):
            raise ValueError('ALB ingress must be host-only TCP80 or TLS TCP80+443 without other sources')
        for source in rule.get('IpRanges', []):
            value = source.get('CidrIp', '')
            cidr = ipaddress.IPv4Network(value, strict=True)
            if str(cidr) != value or cidr.prefixlen != 32 or not cidr.network_address.is_global or cidr.network_address.is_multicast:
                raise ValueError('ALB sources must be canonical global IPv4 /32s')
            allowed[port].add(value)
    if any(nat_cidr not in clients for clients in allowed.values()) or (tls and allowed[80] != allowed[443]):
        raise ValueError('exact NAT EIP /32 and identical ALB client allowlists required on every port')
    return groups[0]['GroupId']


def github_variable(remote, name):
    value = remote.gh(f'repos/{REPO}/environments/eks-lab/variables/{name}')
    if not isinstance(value, dict) or value.get('name') != name or not isinstance(value.get('value'), str):
        raise ValueError('exact named string GitHub environment variable required')
    return value['value']


def https_mode(remote):
    from urllib.parse import urlsplit
    url = github_variable(remote, 'APP_URL')
    if url == 'https://eks.manaleague.com.br':
        return True
    if url == '':
        return False
    try:
        parsed = urlsplit(url)
        host = parsed.hostname or ''
        if (re.search(r'[\s\x00-\x1f\x7f\\?#%]', url) or not url.startswith('http://')
                or parsed.scheme != 'http' or not host or host.endswith('.')
                or host == 'manaleague.com.br' or host.endswith('.manaleague.com.br')
                or parsed.username is not None or parsed.password is not None
                or parsed.path not in ('', '/') or parsed.query or parsed.fragment
                or (parsed.port is not None and not 1 <= parsed.port <= 65535)):
            raise ValueError('invalid origin')
    except ValueError:
        raise ValueError('APP_URL must be isolated HTTP or exact https://eks.manaleague.com.br') from None
    return False


def https_preflight(args, remote, cluster, subnets, group_id):
    # Load the pure contract only after consent and exact HTTPS mode selection.
    spec = importlib.util.spec_from_file_location('eks_runner_https_contract',
                                                HERE.parent / 'scripts' / 'https_contract.py')
    assert spec is not None and spec.loader is not None
    contract = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(contract)
    arn = github_variable(remote, 'ACM_CERTIFICATE_ARN')
    contract.validate_certificate_arn(arn, account=args.account, region=args.region)
    certificate = remote.aws('acm', 'describe-certificate', '--certificate-arn', arn)['Certificate']
    tags = remote.aws('acm', 'list-tags-for-certificate', '--certificate-arn', arn)['Tags']
    contract.validate_certificate(certificate, tags, account=args.account, region=args.region, arn=arn)
    balancers = remote.aws('elbv2', 'describe-load-balancers', '--names', CLUSTER)['LoadBalancers']
    if len(balancers) != 1:
        raise ValueError('one exact lab HTTPS ALB required')
    balancer = balancers[0]
    balancer_arn = balancer.get('LoadBalancerArn', '')
    prefix = f'arn:aws:elasticloadbalancing:{args.region}:{args.account}:'
    zones = balancer.get('AvailabilityZones', [])
    public_ids = {subnets[CLUSTER + '-public-' + str(i)]['SubnetId'] for i in (1, 2)}
    if (not isinstance(balancer_arn, str)
            or not re.fullmatch(re.escape(prefix + 'loadbalancer/app/' + CLUSTER + '/') + r'[a-f0-9]{16}', balancer_arn)
            or balancer.get('LoadBalancerName') != CLUSTER
            or balancer.get('VpcId') != cluster['resourcesVpcConfig']['vpcId']
            or balancer.get('Type') != 'application' or balancer.get('Scheme') != 'internet-facing'
            or balancer.get('SecurityGroups') != [group_id]
            or len(zones) != 2 or {zone.get('SubnetId') for zone in zones} != public_ids):
        raise ValueError('exact account/region lab internet-facing ALB, public subnets and SG required')
    listeners = remote.aws('elbv2', 'describe-listeners', '--load-balancer-arn', balancer_arn)['Listeners']
    if len(listeners) != 2:
        raise ValueError('exactly HTTP80 and HTTPS443 listeners required')
    by_port, listener_ids = {}, set()
    listener_prefix = balancer_arn.replace(':loadbalancer/', ':listener/') + '/'
    for listener in listeners:
        port, identity = listener.get('Port'), listener.get('ListenerArn', '')
        if (port not in (80, 443) or port in by_port
                or listener.get('Protocol') != {80: 'HTTP', 443: 'HTTPS'}[port]
                or listener.get('LoadBalancerArn') != balancer_arn or not isinstance(identity, str)
                or not re.fullmatch(re.escape(listener_prefix) + r'[a-f0-9]{16}', identity)
                or identity in listener_ids):
            raise ValueError('listener identity must scope exactly to lab ALB and HTTP80/HTTPS443')
        listener_ids.add(identity)
        by_port[port] = listener
    tls = by_port[443]
    certs = tls.get('Certificates', [])
    if (tls.get('SslPolicy') != contract.SSL_POLICY or len(certs) != 1
            or certs[0].get('CertificateArn') != arn or certs[0].get('IsDefault') is False):
        raise ValueError('HTTPS listener must use exact issued lab certificate and TLS policy')
    actions = by_port[80].get('DefaultActions', [])
    redirect = actions[0].get('RedirectConfig', {}) if len(actions) == 1 else {}
    if (len(actions) != 1 or actions[0].get('Type') != 'redirect'
            or redirect.get('Protocol') != 'HTTPS' or redirect.get('Port') != '443'
            or redirect.get('StatusCode') not in ('HTTP_301', 'HTTP_302')
            or redirect.get('Host', '#{host}') not in ('#{host}', contract.HOSTNAME)
            or redirect.get('Path', '/#{path}') != '/#{path}'
            or redirect.get('Query', '#{query}') != '#{query}'):
        raise ValueError('HTTP80 must redirect to HTTPS443 without foreign origin/path rewriting')


def preflight(args, remote):
    authorize(args)
    verify_provenance()
    tls = https_mode(remote)
    cluster = target(args, remote)
    vpc_id = cluster['resourcesVpcConfig']['vpcId']
    vpcs = remote.aws('ec2', 'describe-vpcs', '--vpc-ids', vpc_id)['Vpcs']
    if (len(vpcs) != 1 or vpcs[0].get('VpcId') != vpc_id or vpcs[0].get('OwnerId') != args.account
            or vpcs[0].get('CidrBlock') != args.vpc_cidr):
        raise ValueError('exact cluster VPC CIDR required')
    subnets, private_ids = topology_subnets(cluster, args, remote)
    nat_cidr = topology_nat(cluster, subnets, private_ids, remote)
    topology_nodes(cluster, subnets, private_ids, remote)
    group_id = topology_alb(cluster, nat_cidr, remote, tls=tls)
    if tls:
        https_preflight(args, remote, cluster, subnets, group_id)
    ips = {cidr.split('/')[0] for cidr in args.api_cidr}
    enis = remote.aws('ec2', 'describe-network-interfaces', '--filters',
                      f'Name=vpc-id,Values={vpc_id}',
                      'Name=addresses.private-ip-address,Values=' + ','.join(sorted(ips)))['NetworkInterfaces']
    verified = set()
    for eni in enis:
        if (eni.get('VpcId') == vpc_id and eni.get('SubnetId') in private_ids
                and eni.get('RequesterManaged') is True
                and eni.get('Description') == 'Amazon EKS ' + CLUSTER):
            verified.update(p['PrivateIpAddress'] for p in eni.get('PrivateIpAddresses', []))
    if not ips.issubset(verified):
        raise ValueError('API /32s must match current exact-cluster managed ENIs; VPC-local DNS ACK still required')
    prefix = f'repos/{REPO}'
    run = remote.gh(f'{prefix}/actions/runs/{args.run_id}')
    if (run.get('id') != int(args.run_id) or run.get('repository', {}).get('full_name') != REPO
            or run.get('head_repository', {}).get('full_name') != REPO
            or run.get('head_branch') != 'eks-lab' or run.get('event') != 'push'
            or run.get('path') != '.github/workflows/pipeline.yml' or run.get('head_sha') != args.sha
            or run.get('status') not in ('queued', 'in_progress') or run.get('run_attempt') != 1):
        raise ValueError('trusted first-attempt exact push pipeline run required')
    gate = remote.gh(f'{prefix}/environments/eks-lab/variables/LAB_DEPLOY_ENABLED')
    if gate.get('name') != 'LAB_DEPLOY_ENABLED' or gate.get('value') != 'true':
        raise ValueError('eks-lab gate must already be true; this tool never changes it')
    jobs = remote.gh(f'{prefix}/actions/runs/{args.run_id}/jobs?filter=latest&per_page=100', collection='jobs')
    label = 'eks-lab-' + args.run_id
    selected = [j for j in jobs if label in j.get('labels', [])]
    if len(selected) != 1:
        raise ValueError('exactly one unique-label deploy job required')
    job = selected[0]
    if (job.get('name', '').split(' / ')[-1] != 'deploy' or job.get('status') != 'queued'
            or job.get('head_sha') != args.sha or job.get('run_id') != int(args.run_id)
            or job.get('runner_id') not in (None, 0)
            or set(job.get('labels', [])) != {'self-hosted', 'linux', label}
            or not isinstance(job.get('id'), int)):
        raise ValueError('deploy job must be queued for exact unique Linux label and SHA')
    return {'cluster': cluster, 'job_id': job['id'], 'run_attempt': 1}


POLL_ATTEMPTS = 60


def pause():
    import time
    time.sleep(5)


def owned(obj, state, *, uid=None):
    meta = (obj or {}).get('metadata', {})
    if (not meta.get('uid') or meta.get('annotations', {}).get(OWNER) != state['session_id']
            or (uid is not None and meta['uid'] != uid)
            or (obj['kind'] != 'Namespace' and meta.get('namespace') != RENDERER.NAMESPACE)):
        raise ValueError('ownership/UID mismatch; no deletion authorized')
    return meta['uid']


def namespace_check(remote, state):
    if not state.get('namespace_uid'):
        raise ValueError('namespace UID not recorded; manual recovery required')
    obj = remote.get('Namespace', state['namespace'])
    owned(obj, state, uid=state['namespace_uid'])


def create_owned(remote, journal, state, obj, uid_key):
    namespace = obj['kind'] == 'Namespace'
    if not namespace:
        namespace_check(remote, state)
    obj['metadata'].setdefault('annotations', {})[OWNER] = state['session_id']
    state['pending_create'] = {'kind': obj['kind'], 'name': obj['metadata']['name']}
    journal.save(state)
    created = remote.create(obj)
    state[uid_key] = owned(created, state)
    # Journal response UID before a second read, so read-back failure is recoverable.
    journal.save(state)
    actual = remote.get(obj['kind'], obj['metadata']['name'])
    owned(actual, state, uid=state[uid_key])
    state['pending_create'] = None
    journal.save(state)


def remove_owned(remote, state, kind, name, uid):
    namespace_check(remote, state)
    obj = remote.get(kind, name)
    if obj is None:
        return
    owned(obj, state, uid=uid)
    if not uid:
        raise ValueError('missing recorded UID')
    remote.delete(kind, name, uid)
    for attempt in range(POLL_ATTEMPTS):
        obj = remote.get(kind, name)
        if obj is None:
            return
        owned(obj, state, uid=uid)
        pause()
    raise ValueError('deletion not yet observed; retry cleanup after review')


def runner_list(remote):
    return remote.gh(f'repos/{REPO}/actions/runners', collection='runners')


def matching_runner(remote, state):
    matches = [r for r in runner_list(remote) if r.get('name') == state['runner_name']
               or (state.get('runner_id') is not None and r.get('id') == state['runner_id'])]
    if len(matches) > 1:
        raise ValueError('ambiguous runner name')
    if not matches:
        return None
    runner = matches[0]
    labels = {item['name'] for item in runner.get('labels', [])}
    if (runner.get('name') != state['runner_name'] or 'eks-lab-' + state['run_id'] not in labels
            or not isinstance(runner.get('id'), int)
            or (state.get('runner_id') is not None and state['runner_id'] != runner['id'])):
        raise ValueError('foreign runner ID/label')
    return runner


def start(args, remote, journal, directory):
    authorize(args)
    import uuid
    proof = preflight(args, remote)
    sid = uuid.uuid4().hex
    name = f'runner-{args.run_id}-{sid[:8]}'
    state = {'version': 1, 'session_id': sid, 'account': args.account, 'region': args.region,
             'cluster': CLUSTER, 'cluster_arn': proof['cluster']['arn'], 'repository': REPO,
             'namespace': RENDERER.NAMESPACE, 'run_id': args.run_id, 'sha': args.sha,
             'job_id': proof['job_id'], 'job_name': name, 'runner_name': name,
             'namespace_uid': None, 'job_uid': None, 'secret_uid': None, 'runner_id': None,
             'registration_removed': False, 'status': 'reserved', 'pending_create': None}
    journal.reserve(state)  # Exclusive creation rejects retries, including after interruption.
    remote.configure(proof['cluster'], directory)
    if remote.get('Namespace', RENDERER.NAMESPACE) is not None:
        raise ValueError('namespace exists; do not adopt or overwrite it')
    runners = runner_list(remote)
    if any(r.get('name') == name or any(l.get('name') == 'eks-lab-' + args.run_id
                                       for l in r.get('labels', [])) for r in runners):
        raise ValueError('runner with same name or run label already exists')
    objects = plan(args)['manifest']['items']
    for obj, key in zip(objects[:3], ('namespace_uid', 'sa_uid', 'policy_uid')):
        create_owned(remote, journal, state, obj, key)
    # Recheck run/job/gate just before issuing any registration credential.
    if preflight(args, remote)['job_id'] != state['job_id']:
        raise ValueError('queued job changed')
    registration = remote.gh(f'repos/{REPO}/actions/runners/registration-token', method='POST')
    if not isinstance(registration, dict):
        raise ValueError('invalid registration response; body suppressed')
    from datetime import datetime, timezone
    try:
        expiry = datetime.fromisoformat(registration.get('expires_at', '').replace('Z', '+00:00'))
        seconds = (expiry - datetime.now(timezone.utc)).total_seconds()
        if not 30 < seconds <= 3900:
            raise ValueError('expiry outside short-lived window')
    except (AttributeError, TypeError, ValueError):
        registration.clear()
        raise ValueError('registration token expiry invalid; no credential persisted') from None
    token = registration.get('token')
    if not isinstance(token, str) or not token or any(c in token for c in '\r\n\x00'):
        raise ValueError('registration API returned invalid short-lived token')
    secret = {'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {
        'name': 'runner-registration-token', 'namespace': RENDERER.NAMESPACE},
        'type': 'Opaque', 'stringData': {'token': token}}
    try:
        create_owned(remote, journal, state, secret, 'secret_uid')
    finally:
        secret['stringData'].clear()
        registration.clear()
        token = None
    job = objects[-1]
    job['metadata']['name'] = name
    # Stable, known identity permits a homonym check BEFORE registration.
    job['spec']['template']['spec']['initContainers'][-1]['env'][-1] = {'name': 'POD_NAME', 'value': name}
    create_owned(remote, journal, state, job, 'job_uid')
    return observe(args, remote, journal, state)


def observe(args, remote, journal, state):
    status = 'pending_timeout'
    for attempt in range(POLL_ATTEMPTS):
        namespace_check(remote, state)
        job = remote.get('Job', state['job_name'])
        owned(job, state, uid=state['job_uid'])
        pods = remote.pods(state['job_uid'])
        registration_ready = False
        registration_failed = False
        if len(pods) > 1:
            raise ValueError('multiple runner Pods; refuse ambiguity')
        for pod in pods:
            meta = pod.get('metadata', {})
            refs = meta.get('ownerReferences', [])
            if (meta.get('namespace') != state['namespace'] or not meta.get('uid') or len(refs) != 1
                    or refs[0].get('kind') != 'Job' or refs[0].get('uid') != state['job_uid']
                    or refs[0].get('name') != state['job_name'] or refs[0].get('controller') is not True):
                raise ValueError('Pod owner not exact Job UID')
            state['pod_uid'] = meta['uid']
            for init in pod.get('status', {}).get('initContainerStatuses', []):
                if init.get('name') == 'register':
                    terminated = init.get('state', {}).get('terminated', {})
                    registration_ready = terminated.get('exitCode') == 0
                    registration_failed = bool(terminated) and not registration_ready
        if registration_ready and not state['registration_removed']:
            remove_owned(remote, state, 'Secret', 'runner-registration-token', state['secret_uid'])
            state['registration_removed'] = True
            journal.save(state)
        runner = matching_runner(remote, state)
        if runner:
            state['runner_id'] = runner['id']
            journal.save(state)
        job_status = job.get('status', {})
        if registration_failed:
            status = 'registration_failed'
        elif job_status.get('failed'):
            status = 'job_failed'
        elif job_status.get('succeeded'):
            status = 'job_complete'
        elif registration_ready and runner and runner.get('status') == 'online':
            status = 'online'
        else:
            if attempt + 1 < POLL_ATTEMPTS:
                pause()
            continue
        break
    state['status'] = status
    journal.save(state)
    return {'status': status, 'job_name': state['job_name'], 'runner_id': state['runner_id'],
            'registration_removed': state['registration_removed']}


def cleanup(args, remote, journal, directory):
    authorize(args)
    state = journal.read()
    expected = {'version': 1, 'account': args.account, 'region': args.region, 'cluster': CLUSTER,
                'namespace': RENDERER.NAMESPACE, 'repository': REPO, 'run_id': args.run_id, 'sha': args.sha}
    if not state or any(state.get(k) != v for k, v in expected.items()):
        raise ValueError('session metadata mismatch')
    if not re.fullmatch(r'[a-f0-9]{32}', state.get('session_id', '')):
        raise ValueError('invalid recorded session ID')
    expected_name = f'runner-{args.run_id}-{state["session_id"][:8]}'
    if state.get('job_name') != expected_name or state.get('runner_name') != expected_name:
        raise ValueError('recorded names invalid')
    cluster = target(args, remote)  # Cleanup does not depend on gate remaining enabled or queued run.
    if cluster['arn'] != state.get('cluster_arn'):
        raise ValueError('cluster identity changed')
    remote.configure(cluster, directory)
    namespace_check(remote, state)
    # Validate ALL identities before first deletion, not just the next resource.
    for kind, name, key in (('Job', state['job_name'], 'job_uid'),
                            ('Secret', 'runner-registration-token', 'secret_uid')):
        obj = remote.get(kind, name)
        if obj is not None:
            if not state.get(key):
                raise ValueError('unrecorded object; manual recovery required')
            owned(obj, state, uid=state[key])
    runner = matching_runner(remote, state)
    if runner and (state.get('runner_id') != runner['id'] or runner.get('busy') is not False):
        raise ValueError('unrecorded or busy runner; review/cancel job separately')
    remove_owned(remote, state, 'Job', state['job_name'], state.get('job_uid'))
    remove_owned(remote, state, 'Secret', 'runner-registration-token', state.get('secret_uid'))
    if runner:
        # Re-read the exact recorded ID/name/label immediately before removal.
        runner = matching_runner(remote, state)
        if runner and runner.get('busy') is not False:
            raise ValueError('runner became busy; refuse removal')
        if runner:
            remote.gh(f'repos/{REPO}/actions/runners/{state["runner_id"]}', method='DELETE')
        if any(r.get('id') == state['runner_id'] or r.get('name') == state['runner_name']
               for r in runner_list(remote)):
            raise ValueError('runner deletion not verified')
    state['status'] = 'cleaned'
    state['registration_removed'] = True
    journal.save(state)
    return {'status': 'cleaned', 'namespace_retained': True, 'runner_id': state.get('runner_id')}


WINDOWS_CREDENTIAL_SCRIPT = r'''
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$env:HOME = $env:USERPROFILE
$env:GCM_INTERACTIVE = 'Never'
$env:GIT_TERMINAL_PROMPT = '0'
$env:GCM_TRACE = '0'
$env:GIT_TRACE = '0'
$env:GIT_TRACE_CURL = '0'
$env:GIT_CURL_VERBOSE = '0'
$payload = [Console]::In.ReadToEnd()
$git = $null
$candidates = @((Join-Path $env:ProgramFiles 'Git\cmd\git.exe'),
                (Join-Path $env:LOCALAPPDATA 'Programs\Git\cmd\git.exe'))
foreach ($candidate in $candidates) {
    if (Test-Path -LiteralPath $candidate) { $git = $candidate; break }
}
if (-not $git) {
    $found = Get-Command git.exe -ErrorAction SilentlyContinue
    if ($found) { $git = $found.Source }
}
if (-not $git) { exit 1 }
# Avoid the WSL worktree .git containing Windows-only paths. No config mutation.
$payload | & $git -C $env:USERPROFILE credential fill
if ($LASTEXITCODE -ne 0) { exit 1 }
'''


class Remote:
    """Captured CLI transport. No external output/credentials are printed."""
    def __init__(self, args):
        authorize(args)
        self.args = args
        self.kube = None

    def command(self, argv, *, input=None):
        import os
        import subprocess
        authorize(self.args)
        # gh must use its stored login; never take a PAT from session argv/env.
        env = {k: v for k, v in os.environ.items() if k not in (
            'GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN',
            'GH_DEBUG', 'DEBUG', 'KUBECONFIG', 'AWS_ROLE_ARN', 'AWS_WEB_IDENTITY_TOKEN_FILE')}
        env.update(AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off', AWS_EC2_METADATA_DISABLED='true',
                   GH_PROMPT_DISABLED='1', GH_HOST='github.com')
        try:
            result = subprocess.run([str(x) for x in argv], input=input, text=True,
                                    capture_output=True, check=True, timeout=120, env=env)
            return result.stdout.strip()
        except (OSError, subprocess.SubprocessError):
            # Exceptions may contain stdin, tokens, response bodies or credential-helper output.
            raise ValueError('external command failed; remote output suppressed') from None

    def aws(self, *args):
        return json.loads(self.command(['aws', '--profile', 'eks-lab', '--region', self.args.region,
                                       '--output', 'json', *args]))

    def windows_credential(self):
        raw = self.command(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                            WINDOWS_CREDENTIAL_SCRIPT], input='protocol=https\nhost=github.com\n\n')
        try:
            fields = dict(line.split('=', 1) for line in raw.splitlines() if line)
            if (fields.get('protocol') != 'https' or fields.get('host') != 'github.com'
                    or not fields.get('username') or not fields.get('password')
                    or any(c in fields['password'] for c in '\r\n\x00')):
                raise ValueError('credential mismatch')
            return fields['password']
        except (KeyError, TypeError, ValueError):
            raise ValueError('Windows credential bridge unavailable; no response logged') from None

    def credential(self):
        if not hasattr(self, '_credential'):
            token = (self.windows_credential() if self.args.github_auth == 'windows-gcm' else
                     self.command(['gh', 'auth', 'token', '--hostname', 'github.com']))
            if not token or any(c in token for c in '\r\n\x00'):
                raise ValueError('stored GitHub credential unavailable')
            self._credential = token
        return self._credential

    def github_target(self, path, method):
        from urllib.parse import urlsplit
        prefix = f'repos/{REPO}/'
        parts = urlsplit(path)
        if (not path.startswith(prefix) or '..' in path or parts.scheme or parts.netloc or parts.fragment
                or any(c in path for c in '\r\n\x00') or method not in ('GET', 'POST', 'DELETE')):
            raise ValueError('GitHub target not allowed')
        if method == 'POST' and path != prefix + 'actions/runners/registration-token':
            raise ValueError('only registration token POST allowed')
        if method == 'DELETE' and not re.fullmatch(re.escape(prefix) + r'actions/runners/[1-9][0-9]*', path):
            raise ValueError('only exact numeric runner deletion allowed')

    def http(self, path, method='GET'):
        import urllib.request
        import urllib.error
        authorize(self.args)
        self.github_target(path, method)
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                raise ValueError('authenticated redirects refused')
        opener = urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}))
        request = urllib.request.Request('https://api.github.com/' + path, method=method,
            data=b'{}' if method == 'POST' else None,
            headers={'Authorization': 'Bearer ' + self.credential(), 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'manaleague-eks-lab-session',
                     'Content-Type': 'application/json'})
        try:
            with opener.open(request, timeout=30) as response:
                raw = response.read(2_000_001)
                if len(raw) > 2_000_000:
                    raise ValueError('oversized response')
                return json.loads(raw) if raw else None
        except (OSError, urllib.error.URLError, ValueError):
            raise ValueError('GitHub HTTPS request failed; redirects and response logging disabled') from None

    def gh(self, path, method='GET', collection=None):
        # Both credential modes use the same no-redirect HTTPS transport. Never `gh api`.
        authorize(self.args)
        self.github_target(path, method)
        if not collection:
            return self.http(path, method)
        if method != 'GET' or collection not in ('runners', 'jobs'):
            raise ValueError('pagination only for runner/job reads')
        from urllib.parse import parse_qsl, urlencode, urlsplit
        parts = urlsplit(path)
        query = dict(parse_qsl(parts.query))
        result = []
        for page in range(1, 101):
            query.update(per_page='100', page=str(page))
            data = self.http(parts.path + '?' + urlencode(query))
            try:
                items, total = data[collection], data['total_count']
                if not isinstance(items, list) or not isinstance(total, int) or total < 0:
                    raise ValueError('invalid pagination')
                result.extend(items)
                if len(result) >= total:
                    if len(result) != total or len({item['id'] for item in result}) != total:
                        raise ValueError('collection changed during pagination')
                    return result
                if not items:
                    raise ValueError('incomplete pagination')
            except (KeyError, TypeError, ValueError):
                raise ValueError('invalid or changing GitHub collection; body suppressed') from None
        raise ValueError('pagination safety limit reached; no mutations authorized')

    def configure(self, cluster, directory):
        import base64
        arn = f'arn:aws:eks:{self.args.region}:{self.args.account}:cluster/{CLUSTER}'
        if cluster.get('arn') != arn or cluster.get('name') != CLUSTER or cluster.get('status') != 'ACTIVE':
            raise ValueError('unvalidated cluster')
        ca = cluster['certificateAuthority']['data']
        try:
            if not base64.b64decode(ca, validate=True):
                raise ValueError('empty CA')
        except (TypeError, ValueError):
            raise ValueError('invalid cluster CA') from None
        config = {'apiVersion': 'v1', 'kind': 'Config', 'current-context': arn,
            'clusters': [{'name': arn, 'cluster': {'server': cluster['endpoint'], 'certificate-authority-data': ca}}],
            'contexts': [{'name': arn, 'context': {'cluster': arn, 'user': arn}}],
            'users': [{'name': arn, 'user': {'exec': {'apiVersion': 'client.authentication.k8s.io/v1beta1',
                'command': 'aws', 'args': ['--profile', 'eks-lab', '--region', self.args.region,
                    'eks', 'get-token', '--cluster-name', CLUSTER, '--output', 'json'],
                'interactiveMode': 'Never'}}}]}
        path = Path(directory) / 'kubeconfig.json'
        private_write(path, config, exclusive=True)
        if json.loads(path.read_text(encoding='utf-8')) != config:
            raise ValueError('exclusive kubeconfig read-back mismatch')
        # Built from the described endpoint/CA, never merges or executes a human config.
        self.kube = ['kubectl', '--kubeconfig', str(path), '--context', arn, '--request-timeout=30s']

    def get(self, kind, name):
        cmd = [*self.kube, 'get', kind.lower(), name, '--ignore-not-found', '-o', 'json']
        if kind != 'Namespace':
            cmd += ['-n', RENDERER.NAMESPACE]
        raw = self.command(cmd)
        return json.loads(raw) if raw else None

    def create(self, obj):
        # create, NOT apply: no last-applied annotation storing registration credentials.
        return json.loads(self.command([*self.kube, 'create', '-f', '-', '-o', 'json'], input=json.dumps(obj)))

    def pods(self, job_uid):
        return json.loads(self.command([*self.kube, '-n', RENDERER.NAMESPACE, 'get', 'pods',
            '-l', 'batch.kubernetes.io/controller-uid=' + job_uid, '-o', 'json']))['items']

    def delete(self, kind, name, uid):
        # DELETE body supplies an atomic UID precondition; ordinary `delete -f` does not.
        if kind not in ('Job', 'Secret') or not re.fullmatch('[a-z0-9-]+', name) or not uid:
            raise ValueError('invalid exact deletion identity')
        base = '/apis/batch/v1' if kind == 'Job' else '/api/v1'
        plural = 'jobs' if kind == 'Job' else 'secrets'
        path = f'{base}/namespaces/{RENDERER.NAMESPACE}/{plural}/{name}'
        options = {'apiVersion': 'v1', 'kind': 'DeleteOptions', 'preconditions': {'uid': uid},
                   'propagationPolicy': 'Foreground'}
        self.command([*self.kube, 'delete', '--raw', path, '-f', '-'], input=json.dumps(options))


def private_write(path, value, *, exclusive=False):
    import os
    flags = os.O_WRONLY | os.O_CREAT | (os.O_EXCL if exclusive else os.O_TRUNC)
    if path.is_symlink() or path.parent.is_symlink():
        raise ValueError('symlink state/config refused')
    try:
        fd = os.open(path, flags, 0o600)
    except FileExistsError:
        raise ValueError('private file already exists; unsafe replay refused') from None
    with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
        json.dump(value, stream, indent=2)
        stream.flush()
        os.fsync(stream.fileno())


class Journal:
    def __init__(self, path):
        self.path = Path(path)

    def safe(self):
        if self.path.is_symlink() or self.path.parent.is_symlink():
            raise ValueError('symlink journal refused')
        import os
        if os.name == 'posix':
            for path in (self.path.parent, self.path):
                if path.exists() and (path.stat().st_uid != os.getuid() or path.stat().st_mode & 0o077):
                    raise ValueError('private journal ownership/mode required')

    def reserve(self, value):
        self.path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.safe()
        private_write(self.path, value, exclusive=True)

    def save(self, value):
        import os
        import uuid
        self.safe()
        temp = self.path.with_name(self.path.name + '.' + uuid.uuid4().hex + '.tmp')
        try:
            private_write(temp, value, exclusive=True)
            os.replace(temp, self.path)
        finally:
            if temp.exists():
                temp.unlink()

    def read(self):
        self.safe()
        return json.loads(self.path.read_text(encoding='utf-8'))


def private_root(args):
    import os
    if os.name != 'posix':
        raise ValueError('live operations require private POSIX filesystem (Linux/WSL ext4), not Windows ACL assumptions')
    root = Path(args.state_dir) if args.state_dir else HERE / '.local'
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if root.is_symlink() or root.stat().st_uid != os.getuid() or root.stat().st_mode & 0o077:
        raise ValueError('runner/.local must be owned by current operator with mode 0700; use Linux ext4')
    return root


from contextlib import contextmanager


@contextmanager
def session_lock(root):
    import fcntl
    import os
    path = root / 'operator.lock'
    if path.is_symlink():
        raise ValueError('symlink lock refused')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ValueError('another session operation is active') from None
        yield
    finally:
        os.close(fd)  # flock released even on errors; leave inode, avoiding unlink races.


def main(argv=None):
    args = parse(argv)
    if args.action == 'plan':
        print(json.dumps(plan(args), indent=2))
        return 0
    import tempfile
    remote = Remote(args)
    if args.action == 'start':
        preflight(args, remote)  # Fail closed before private root, lock or temporary kubeconfig state.
    root = private_root(args)
    journal = Journal(root / f'session-{args.run_id}.json')
    with session_lock(root), tempfile.TemporaryDirectory(prefix='session-', dir=root) as directory:
        operation = start if args.action == 'start' else cleanup
        result = operation(args, remote, journal, Path(directory))
    print(json.dumps(result))
    return 0 if result['status'] in ('online', 'job_complete', 'cleaned') else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        # CLI boundary: malformed API responses and command errors may carry secrets.
        import sys
        print('Session refused or failed; no remote output is logged. Review owned local journal.', file=sys.stderr)
        raise SystemExit(1)
