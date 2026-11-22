"""Session tests exercise real policy/orchestration; every remote call is fake."""
import contextlib
import importlib.util
import io
import json
import pathlib
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location('lab_' + name, ROOT / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


BASE = ['--account', '123456789012', '--region', 'us-east-1', '--cluster',
        'manaleague-eks-lab', '--vpc-cidr', '10.42.0.0/16', '--run-id', '44421131546',
        '--sha', 'a' * 40, '--api-cidr', '10.42.10.8/32']
GATES = ['--allow-cloud', '--confirm-lab', 'manaleague-eks-lab',
         '--ack-public-self-hosted-risk', '--ack-network-verified-in-vpc']


class OfflineSessionTests(unittest.TestCase):
    def test_default_is_deterministic_plan_without_external_io_or_secret(self):
        session = load('session')
        with mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('socket.socket', side_effect=AssertionError('network')), \
             mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('read')), \
             mock.patch.object(pathlib.Path, 'mkdir', side_effect=AssertionError('write')):
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                self.assertEqual(session.main(BASE), 0)
            plan = json.loads(out.getvalue())
            self.assertEqual(plan['action'], 'plan')
            self.assertEqual(plan['run_label'], 'eks-lab-44421131546')
            self.assertEqual(len(plan['manifest']['items']), 4)
            self.assertNotIn('stringData', out.getvalue())

    def test_state_dir_is_explicit_absolute_local_and_plan_does_not_touch_it(self):
        session = load('session')
        for directory in ('relative/.local', 'relative', str(ROOT / 'not-private-state')):
            with self.subTest(directory=directory), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                session.parse(BASE + ['--state-dir', directory])
        with mock.patch.object(pathlib.Path, 'mkdir', side_effect=AssertionError('write')):
            args = session.parse(BASE + ['--state-dir', str(ROOT / '.local')])
            self.assertEqual(args.state_dir, str(ROOT / '.local'))

    def test_all_live_gates_precede_any_external_or_state_io(self):
        session = load('session')
        for action in ('start', 'cleanup'):
            for flags in ([], GATES[1:], GATES[:3], GATES[:4]):
                with self.subTest(action=action, flags=flags), \
                     mock.patch.object(session, 'Remote', side_effect=AssertionError('remote constructed')), \
                     mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('read')), \
                     contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as caught:
                    session.main([action] + BASE + flags)
                self.assertEqual(caught.exception.code, 2)

    def test_literal_validation_happens_before_remote(self):
        session = load('session')
        for key, value in (('--cluster', 'main'), ('--run-id', '01'), ('--sha', 'A' * 40),
                           ('--account', '123'), ('--region', 'us-east-1;id'),
                           ('--api-cidr', '10.99.1.8/32')):
            args = BASE.copy()
            args[args.index(key) + 1] = value
            with self.subTest(key=key), contextlib.redirect_stderr(io.StringIO()), \
                 mock.patch.object(session, 'Remote', side_effect=AssertionError('remote')), \
                 self.assertRaises(SystemExit):
                session.main(['start'] + args + GATES)
        args = BASE[:-2]
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            session.main(['start'] + args + GATES)


def live_args():
    return load('session').parse(['start'] + BASE + GATES)


class PreflightRemote:
    def __init__(self):
        self.calls = []
        self.account = '123456789012'
        self.cluster = {'name': 'manaleague-eks-lab',
            'arn': 'arn:aws:eks:us-east-1:123456789012:cluster/manaleague-eks-lab',
            'status': 'ACTIVE', 'endpoint': 'https://ABC.gr7.us-east-1.eks.amazonaws.com',
            'certificateAuthority': {'data': 'Y2VydA=='},
            'resourcesVpcConfig': {'vpcId': 'vpc-1234', 'endpointPrivateAccess': True}}
        self.run = {'id': 44421131546, 'repository': {'full_name': 'viniciusVitorio/manaleague'},
            'head_repository': {'full_name': 'viniciusVitorio/manaleague'}, 'head_branch': 'eks-lab',
            'head_sha': 'a' * 40, 'event': 'push', 'path': '.github/workflows/pipeline.yml',
            'status': 'in_progress', 'run_attempt': 1}
        self.jobs = [{'id': 42, 'run_id': 44421131546, 'head_sha': 'a' * 40,
            'name': 'eks-lab / deploy', 'status': 'queued', 'runner_id': None,
            'labels': ['self-hosted', 'linux', 'eks-lab-44421131546']}]
        self.runners = []
        self.gate = 'true'
        self.app_url = ''
        self.certificate_arn = ''
        self.vpc_owner = self.account
        self.vpc = '10.42.0.0/16'
        self.eni_vpc = 'vpc-1234'
        self.cluster['resourcesVpcConfig']['subnetIds'] = ['subnet-priv1', 'subnet-priv2']
        self.subnets = [
            {'SubnetId': 'subnet-' + suffix, 'VpcId': 'vpc-1234', 'MapPublicIpOnLaunch': public,
             'CidrBlock': cidr, 'Tags': [{'Key': 'Name', 'Value': 'manaleague-eks-lab-' + name}]}
            for suffix, public, cidr, name in (
                ('pub1', True, '10.42.0.0/24', 'public-1'),
                ('pub2', True, '10.42.1.0/24', 'public-2'),
                ('priv1', False, '10.42.10.0/24', 'private-1'),
                ('priv2', False, '10.42.11.0/24', 'private-2'))]
        self.security_groups = [{'GroupId': 'sg-lab', 'GroupName': 'manaleague-eks-lab-alb',
            'VpcId': 'vpc-1234', 'IpPermissions': [{'IpProtocol': 'tcp', 'FromPort': 80, 'ToPort': 80,
                'IpRanges': [{'CidrIp': '1.1.1.1/32'}, {'CidrIp': '8.8.8.8/32'}],
                'Ipv6Ranges': [], 'UserIdGroupPairs': [], 'PrefixListIds': []}]}]
        self.nodegroups = ['manaleague-eks-lab-az-1']
        self.nodegroup = {'clusterName': 'manaleague-eks-lab', 'nodegroupName': self.nodegroups[0],
            'nodegroupArn': 'arn:aws:eks:us-east-1:123456789012:nodegroup/manaleague-eks-lab/manaleague-eks-lab-az-1/fixture',
            'status': 'ACTIVE', 'subnets': ['subnet-priv1']}
        self.instances = [{'InstanceId': 'i-node', 'VpcId': 'vpc-1234', 'SubnetId': 'subnet-priv1', 'State': {'Name': 'running'},
            'Tags': [{'Key': 'eks:eks-cluster-name', 'Value': 'manaleague-eks-lab'}]}]
        self.routes = [
            {'VpcId': 'vpc-1234', 'RouteTableId': 'rtb-' + suffix,
             'Associations': [{'SubnetId': 'subnet-' + suffix,
                               'AssociationState': {'State': 'associated'}}],
             'Routes': [{'DestinationCidrBlock': '0.0.0.0/0', 'State': 'active', **target}]}
            for suffix, target in (
                ('pub1', {'GatewayId': 'igw-1234'}), ('pub2', {'GatewayId': 'igw-1234'}),
                ('priv1', {'NatGatewayId': 'nat-0123456789abcdef0'}),
                ('priv2', {'NatGatewayId': 'nat-0123456789abcdef0'}))]
        self.nats = [{'NatGatewayId': 'nat-0123456789abcdef0', 'VpcId': 'vpc-1234',
            'SubnetId': 'subnet-pub1', 'ConnectivityType': 'public', 'State': 'available',
            'NatGatewayAddresses': [{'AllocationId': 'eipalloc-1234', 'PublicIp': '8.8.8.8'}]}]
        self.addresses = [{'AllocationId': 'eipalloc-1234', 'PublicIp': '8.8.8.8', 'Domain': 'vpc'}]
        self.igws = [{'InternetGatewayId': 'igw-1234',
                      'Attachments': [{'VpcId': 'vpc-1234', 'State': 'available'}]}]
        self.eni = {'VpcId': self.eni_vpc, 'SubnetId': 'subnet-priv1',
            'Description': 'Amazon EKS manaleague-eks-lab', 'RequesterManaged': True,
            'PrivateIpAddresses': [{'PrivateIpAddress': '10.42.10.8'}]}

    def aws(self, *args):
        self.calls.append(('aws', args))
        if args[:2] == ('sts', 'get-caller-identity'):
            return {'Account': self.account}
        if args[:2] == ('eks', 'describe-cluster'):
            return {'cluster': self.cluster}
        if args[:2] == ('ec2', 'describe-vpcs'):
            return {'Vpcs': [{'VpcId': 'vpc-1234', 'CidrBlock': self.vpc, 'OwnerId': self.vpc_owner}]}
        if args[:2] == ('eks', 'list-nodegroups'):
            return {'nodegroups': self.nodegroups}
        if args[:2] == ('eks', 'describe-nodegroup'):
            return {'nodegroup': self.nodegroup}
        if args[:2] == ('ec2', 'describe-security-groups'):
            return {'SecurityGroups': self.security_groups}
        if args[:2] == ('ec2', 'describe-instances'):
            return {'Reservations': [{'Instances': self.instances}]}
        if args[:2] == ('ec2', 'describe-route-tables'):
            return {'RouteTables': self.routes}
        if args[:2] == ('ec2', 'describe-nat-gateways'):
            return {'NatGateways': self.nats}
        if args[:2] == ('ec2', 'describe-addresses'):
            return {'Addresses': self.addresses}
        if args[:2] == ('ec2', 'describe-internet-gateways'):
            return {'InternetGateways': self.igws}
        if args[:2] == ('ec2', 'describe-subnets'):
            return {'Subnets': self.subnets}
        if args[:2] == ('ec2', 'describe-network-interfaces'):
            self.eni['VpcId'] = self.eni_vpc
            return {'NetworkInterfaces': [self.eni]}
        raise AssertionError(args)

    def gh(self, path, method='GET', collection=None):
        self.calls.append(('gh', path, method))
        if '/variables/' in path:
            name = path.rsplit('/', 1)[1]
            values = {'LAB_DEPLOY_ENABLED': self.gate, 'APP_URL': self.app_url,
                      'ACM_CERTIFICATE_ARN': self.certificate_arn}
            return {'name': name, 'value': values[name]}
        if path.endswith('/runners'):
            return self.runners
        if '/jobs?' in path:
            return self.jobs
        if '/runs/' in path:
            return self.run
        raise AssertionError(path)


class PreflightTests(unittest.TestCase):
    def test_tls_security_group_requires_matching_host_sets_and_nat_on_both_ports(self):
        import copy
        session = load('session')
        for case in ('valid', 'missing443', 'missing80', 'missingnat', 'unequal', 'wide',
                     'ipv6', 'group', 'prefix', 'udp', 'otherport', 'noncanonical', 'private'):
            remote = PreflightRemote()
            rules = remote.security_groups[0]['IpPermissions']
            rules.append(copy.deepcopy(rules[0]))
            rules[1]['FromPort'] = rules[1]['ToPort'] = 443
            if case == 'missing443': rules.pop()
            elif case == 'missing80': rules.pop(0)
            elif case == 'missingnat': rules[1]['IpRanges'].pop()
            elif case == 'unequal': rules[1]['IpRanges'].append({'CidrIp': '9.9.9.9/32'})
            elif case == 'wide': rules[1]['IpRanges'].append({'CidrIp': '0.0.0.0/0'})
            elif case == 'ipv6': rules[1]['Ipv6Ranges'] = [{'CidrIpv6': '::/0'}]
            elif case == 'group': rules[1]['UserIdGroupPairs'] = [{'GroupId': 'sg-other'}]
            elif case == 'prefix': rules[1]['PrefixListIds'] = [{'PrefixListId': 'pl-other'}]
            elif case == 'udp': rules[1]['IpProtocol'] = 'udp'
            elif case == 'otherport': rules[1]['ToPort'] = 444
            elif case == 'noncanonical': rules[1]['IpRanges'][0]['CidrIp'] = '1.1.1.1'
            elif case == 'private': rules[1]['IpRanges'][0]['CidrIp'] = '10.0.0.1/32'
            with self.subTest(case=case):
                if case == 'valid':
                    self.assertEqual(session.topology_alb(remote.cluster, '8.8.8.8/32', remote, tls=True), 'sg-lab')
                else:
                    with self.assertRaises(ValueError):
                        session.topology_alb(remote.cluster, '8.8.8.8/32', remote, tls=True)

    def test_app_url_is_exact_named_read_and_rejects_nonlab_origins_before_state(self):
        for value in ('https://manaleague.com.br', 'https://other.test',
                      'https://eks.manaleague.com.br/', 'http://www.manaleague.com.br',
                      'http://user@lab.test', 'http://lab.test/path', 'http://lab.test?x=1',
                      'http://lab.test\\evil', None):
            remote = LifecycleRemote()
            remote.app_url = value
            journal = MemoryJournal()
            with self.subTest(value=value), self.assertRaises(ValueError):
                load('session').start(live_args(), remote, journal, ROOT)
            self.assertIsNone(journal.value)
            self.assertEqual(remote.applied, [])
            self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))
        for value in ('', 'http://isolated.test', 'http://isolated.test:8080/'):
            remote = PreflightRemote()
            remote.app_url = value
            self.assertEqual(load('session').preflight(live_args(), remote)['job_id'], 42)
            self.assertIn(('gh', 'repos/viniciusVitorio/manaleague/environments/eks-lab/variables/APP_URL', 'GET'), remote.calls)

    def test_wrong_app_url_variable_identity_fails_closed(self):
        remote = PreflightRemote()
        original = remote.gh
        def gh(path, **kwargs):
            if path.endswith('/APP_URL'):
                return {'name': 'APP_URL_OTHER', 'value': ''}
            return original(path, **kwargs)
        remote.gh = gh
        with self.assertRaises(ValueError):
            load('session').preflight(live_args(), remote)

    def test_preflight_accepts_private_names_from_actual_terraform_subnet_tags(self):
        import re
        source = (ROOT.parent / 'terraform' / 'rds.tf').read_text(encoding='utf-8')
        blocks = re.findall(r'^resource "aws_subnet" "database" \{\n(.*?)^\}',
                            source, re.MULTILINE | re.DOTALL)
        self.assertEqual(len(blocks), 1)
        block = blocks[0]
        self.assertEqual(re.findall(r'^\s*count\s*=\s*(\d+)\s*$', block, re.MULTILINE), ['2'])
        # Intentionally accept only the current literal Name form, not arbitrary HCL.
        templates = re.findall(r'^\s*tags\s*=\s*\{\s*Name\s*=\s*"([^"\n]+)"\s*\}\s*$',
                               block, re.MULTILINE)
        self.assertEqual(templates, ['${local.name}-private-${count.index + 1}'])
        session = load('session')
        names = [templates[0].replace('${local.name}', session.CLUSTER)
                 .replace('${count.index + 1}', str(index + 1)) for index in range(2)]
        remote = PreflightRemote()
        for subnet, name in zip(remote.subnets[2:], names):
            subnet['Tags'] = [{'Key': 'Name', 'Value': name}]
        with mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('socket.socket', side_effect=AssertionError('network')):
            try:
                proof = session.preflight(live_args(), remote)
            except (ValueError, KeyError) as error:
                self.fail(f'preflight rejected actual Terraform subnet Names: {error}')
            self.assertEqual(proof['job_id'], 42)
        self.assertEqual([s['Tags'][0]['Value'] for s in PreflightRemote().subnets[2:]], names)

    def test_legacy_database_subnet_names_are_rejected_before_any_write(self):
        for indices in ((2,), (3,), (2, 3)):
            remote = LifecycleRemote()
            for index in indices:
                remote.subnets[index]['Tags'] = [{'Key': 'Name',
                    'Value': 'manaleague-eks-lab-database-' + str(index - 1)}]
            with self.subTest(indices=indices), self.assertRaisesRegex(ValueError, 'subnet identity'):
                load('session').start(live_args(), remote, MemoryJournal(), ROOT)
            self.assertEqual(remote.applied, [])
            self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_readonly_preflight_binds_run_job_cluster_vpc_and_account(self):
        session = load('session')
        remote = PreflightRemote()
        proof = session.preflight(live_args(), remote)
        self.assertEqual(proof['job_id'], 42)
        self.assertEqual(proof['cluster']['arn'], remote.cluster['arn'])
        self.assertTrue(all(c[-1] == 'GET' for c in remote.calls if c[0] == 'gh'))

    def test_foreign_or_inactive_provenance_refuses_before_writes(self):
        session = load('session')
        mutations = [('account', None, '999999999999'), ('cluster', 'status', 'CREATING'),
            ('cluster', 'arn', 'arn:aws:eks:us-east-1:123456789012:cluster/main'),
            ('cluster', 'endpoint', 'http://not-tls'), ('run', 'head_sha', 'b' * 40),
            ('run', 'head_branch', 'main'), ('run', 'event', 'pull_request'),
            ('run', 'path', '.github/workflows/other.yml'), ('run', 'id', 1),
            ('run', 'head_repository', {'full_name': 'attacker/fork'}),
            ('run', 'repository', {'full_name': 'someone/other'}),
            ('run', 'status', 'completed'), ('gate', None, 'false'),
            ('vpc', None, '10.99.0.0/16'), ('eni_vpc', None, 'vpc-foreign')]
        for attr, key, value in mutations:
            remote = PreflightRemote()
            if key:
                getattr(remote, attr)[key] = value
            else:
                setattr(remote, attr, value)
            with self.subTest(attr=attr, key=key), self.assertRaises(ValueError):
                session.preflight(live_args(), remote)

    def test_only_single_queued_deploy_job_with_exact_unique_label_can_register(self):
        session = load('session')
        for patch in ({'labels': ['self-hosted', 'linux', 'eks-lab']}, {'status': 'in_progress'},
                      {'name': 'build'}, {'head_sha': 'b' * 40}, {'run_id': 1}, {'runner_id': 12}):
            remote = PreflightRemote()
            remote.jobs[0].update(patch)
            with self.subTest(patch=patch), self.assertRaises(ValueError):
                session.preflight(live_args(), remote)
        remote = PreflightRemote()
        remote.jobs *= 2
        with self.assertRaises(ValueError):
            session.preflight(live_args(), remote)

    def test_private_cluster_subnets_and_exact_api_eni_are_required_before_any_write(self):
        for case in ('wrong_vpc', 'public_mapping', 'public_cluster', 'foreign_cluster',
                     'missing_private', 'foreign_eni', 'public_eni', 'unmanaged_eni'):
            remote = LifecycleRemote()
            if case == 'wrong_vpc':
                remote.subnets[2]['VpcId'] = 'vpc-foreign'
            elif case == 'public_mapping':
                remote.subnets[2]['MapPublicIpOnLaunch'] = True
            elif case == 'public_cluster':
                remote.cluster['resourcesVpcConfig']['subnetIds'] = ['subnet-pub1', 'subnet-pub2']
            elif case == 'foreign_cluster':
                remote.cluster['resourcesVpcConfig']['subnetIds'] = ['subnet-priv1', 'subnet-foreign']
            elif case == 'missing_private':
                remote.subnets.pop()
            elif case == 'foreign_eni':
                remote.eni['Description'] = 'Amazon EKS another-cluster'
            elif case == 'public_eni':
                remote.eni['SubnetId'] = 'subnet-pub1'
            else:
                remote.eni['RequesterManaged'] = False
            with self.subTest(case=case), self.assertRaises(ValueError):
                load('session').start(live_args(), remote, MemoryJournal(), ROOT)
            self.assertEqual(remote.applied, [])
            self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_single_available_public_nat_and_both_private_default_routes_are_required(self):
        cases = ('missing_nat', 'foreign_nat_vpc', 'private_nat', 'pending_nat', 'wrong_nat_subnet',
                 'foreign_nat_id', 'private_route_igw', 'wrong_route_nat', 'blackhole',
                 'foreign_table', 'missing_route', 'duplicate_association', 'public_no_igw',
                 'foreign_igw', 'private_eip', 'wrong_eip', 'missing_allocation')
        for case in cases:
            remote = LifecycleRemote()
            if case == 'missing_nat': remote.nats = []
            elif case == 'foreign_nat_vpc': remote.nats[0]['VpcId'] = 'vpc-foreign'
            elif case == 'private_nat': remote.nats[0]['ConnectivityType'] = 'private'
            elif case == 'pending_nat': remote.nats[0]['State'] = 'pending'
            elif case == 'wrong_nat_subnet': remote.nats[0]['SubnetId'] = 'subnet-pub2'
            elif case == 'foreign_nat_id': remote.nats[0]['NatGatewayId'] = 'nat-foreign'
            elif case == 'private_route_igw': remote.routes[2]['Routes'][0] = {
                'DestinationCidrBlock': '0.0.0.0/0', 'State': 'active', 'GatewayId': 'igw-1234'}
            elif case == 'wrong_route_nat': remote.routes[3]['Routes'][0]['NatGatewayId'] = 'nat-1123456789abcdef0'
            elif case == 'blackhole': remote.routes[2]['Routes'][0]['State'] = 'blackhole'
            elif case == 'foreign_table': remote.routes[2]['VpcId'] = 'vpc-foreign'
            elif case == 'missing_route': remote.routes[3]['Routes'] = []
            elif case == 'duplicate_association': remote.routes.append(remote.routes[2])
            elif case == 'public_no_igw': remote.routes[0]['Routes'][0]['GatewayId'] = 'local'
            elif case == 'foreign_igw': remote.igws[0]['Attachments'][0]['VpcId'] = 'vpc-foreign'
            elif case == 'private_eip': remote.nats[0]['NatGatewayAddresses'][0]['PublicIp'] = '10.42.0.5'
            elif case == 'wrong_eip': remote.addresses[0]['PublicIp'] = '1.1.1.1'
            else: remote.nats[0]['NatGatewayAddresses'][0].pop('AllocationId')
            with self.subTest(case=case):
                with self.assertRaises(ValueError):
                    load('session').start(live_args(), remote, MemoryJournal(), ROOT)
                self.assertEqual(remote.applied, [])
                self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_managed_nodes_are_private_and_no_tagged_node_has_a_public_ip(self):
        for case in ('public_node', 'public_ip', 'foreign_node_vpc', 'foreign_node_subnet',
                     'foreign_node_tag', 'public_mng', 'foreign_mng_cluster', 'foreign_mng_name',
                     'missing_mng', 'single_node_wrong_private', 'secondary_eni_public_ip'):
            remote = LifecycleRemote()
            if case == 'public_node': remote.instances[0]['SubnetId'] = 'subnet-pub1'
            elif case == 'public_ip': remote.instances[0]['PublicIpAddress'] = '8.8.8.8'
            elif case == 'foreign_node_vpc': remote.instances[0]['VpcId'] = 'vpc-foreign'
            elif case == 'foreign_node_subnet': remote.instances[0]['SubnetId'] = 'subnet-foreign'
            elif case == 'foreign_node_tag': remote.instances[0]['Tags'] = []
            elif case == 'public_mng': remote.nodegroup['subnets'] = ['subnet-pub1']
            elif case == 'foreign_mng_cluster': remote.nodegroup['clusterName'] = 'production'
            elif case == 'foreign_mng_name': remote.nodegroup['nodegroupName'] = 'production'
            elif case == 'missing_mng': remote.nodegroups = []
            elif case == 'single_node_wrong_private': remote.nodegroup['subnets'] = ['subnet-priv2']
            else: remote.instances[0]['NetworkInterfaces'] = [{'Association': {'PublicIp': '8.8.8.8'}}]
            with self.subTest(case=case):
                with self.assertRaises(ValueError):
                    load('session').start(live_args(), remote, MemoryJournal(), ROOT)
                self.assertEqual(remote.applied, [])
                self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_exact_alb_security_group_must_allow_only_host_http_including_nat_eip(self):
        for case in ('missing', 'duplicate', 'wrong_vpc', 'wrong_name', 'wrong_nat',
                     'global', 'broad', 'ipv6_global', 'all_protocols', 'wrong_port', 'group_source'):
            remote = LifecycleRemote()
            group = remote.security_groups[0]
            rule = group['IpPermissions'][0]
            if case == 'missing': remote.security_groups = []
            elif case == 'duplicate': remote.security_groups *= 2
            elif case == 'wrong_vpc': group['VpcId'] = 'vpc-foreign'
            elif case == 'wrong_name': group['GroupName'] = 'production-alb'
            elif case == 'wrong_nat': rule['IpRanges'].pop()
            elif case == 'global': rule['IpRanges'].append({'CidrIp': '0.0.0.0/0'})
            elif case == 'broad': rule['IpRanges'][0]['CidrIp'] = '1.1.1.0/24'
            elif case == 'ipv6_global': rule['Ipv6Ranges'] = [{'CidrIpv6': '::/0'}]
            elif case == 'all_protocols': rule['IpProtocol'] = '-1'
            elif case == 'wrong_port': rule['FromPort'] = rule['ToPort'] = 443
            else: rule['UserIdGroupPairs'] = [{'GroupId': 'sg-foreign'}]
            with self.subTest(case=case):
                with self.assertRaises(ValueError):
                    load('session').start(live_args(), remote, MemoryJournal(), ROOT)
                self.assertEqual(remote.applied, [])
                self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_inventory_covers_all_live_vpc_instances_and_requires_a_real_node_and_owner(self):
        session = load('session')
        remote = PreflightRemote()
        session.preflight(live_args(), remote)
        calls = [c[1] for c in remote.calls if c[0] == 'aws' and c[1][:2] == ('ec2', 'describe-instances')]
        self.assertEqual(len(calls), 1)
        self.assertIn('Name=vpc-id,Values=vpc-1234', calls[0])
        self.assertIn('Name=instance-state-name,Values=pending,running', calls[0])
        self.assertFalse(any('tag:' in arg for arg in calls[0]))
        for case in ('empty', 'foreign_owner', 'untagged_public', 'only_untagged', 'stopped'):
            remote = LifecycleRemote()
            if case == 'empty': remote.instances = []
            elif case == 'foreign_owner': remote.vpc_owner = '999999999999'
            elif case == 'untagged_public': remote.instances.append({'InstanceId': 'i-rogue',
                'VpcId': 'vpc-1234', 'SubnetId': 'subnet-pub1', 'State': {'Name': 'running'},
                'PublicIpAddress': '8.8.4.4', 'Tags': []})
            elif case == 'only_untagged': remote.instances[0]['Tags'] = []
            else: remote.instances[0]['State']['Name'] = 'stopped'
            with self.subTest(case=case):
                with self.assertRaises(ValueError):
                    session.start(live_args(), remote, MemoryJournal(), ROOT)
                self.assertEqual(remote.applied, [])
                self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_two_az_private_nodegroups_and_documented_minimal_responses_pass(self):
        import copy
        remote = PreflightRemote()
        remote.nodegroups.append('manaleague-eks-lab-az-2')
        second = copy.deepcopy(remote.nodegroup)
        second.update(nodegroupName=remote.nodegroups[1], subnets=['subnet-priv2'])
        original = remote.aws
        def aws(*args):
            if args[:2] == ('eks', 'describe-nodegroup') and args[-1] == remote.nodegroups[1]:
                remote.calls.append(('aws', args))
                return {'nodegroup': second}
            return original(*args)
        remote.aws = aws
        second_node = copy.deepcopy(remote.instances[0])
        second_node.update(InstanceId='i-second', SubnetId='subnet-priv2', State={'Name': 'pending'})
        remote.instances.append(second_node)
        self.assertEqual(load('session').preflight(live_args(), remote)['job_id'], 42)
        permitted = {'get-caller-identity', 'describe-cluster', 'describe-vpcs', 'describe-subnets',
            'describe-route-tables', 'describe-nat-gateways', 'describe-addresses',
            'describe-internet-gateways', 'describe-security-groups', 'describe-instances',
            'list-nodegroups', 'describe-nodegroup', 'describe-network-interfaces'}
        self.assertEqual({c[1][1] for c in remote.calls if c[0] == 'aws'}, permitted)

    def test_multicast_cannot_be_a_nat_eip_or_an_alb_client(self):
        for case in ('nat', 'sg'):
            remote = PreflightRemote()
            if case == 'nat':
                remote.nats[0]['NatGatewayAddresses'][0]['PublicIp'] = '224.0.0.1'
                remote.addresses[0]['PublicIp'] = '224.0.0.1'
                remote.security_groups[0]['IpPermissions'][0]['IpRanges'].append({'CidrIp': '224.0.0.1/32'})
            else:
                remote.security_groups[0]['IpPermissions'][0]['IpRanges'].append({'CidrIp': '224.0.0.1/32'})
            with self.subTest(case=case), self.assertRaises(ValueError):
                load('session').preflight(live_args(), remote)

    def test_provenance_file_tampering_is_rejected(self):
        session = load('session')
        session.verify_provenance()
        with mock.patch.object(pathlib.Path, 'read_bytes', return_value=b'{}'), self.assertRaises(ValueError):
            session.verify_provenance()


def tls_remote():
    """All AWS metadata here is synthetic, never a live account snapshot."""
    import copy
    remote = LifecycleRemote()
    remote.app_url = 'https://eks.manaleague.com.br'
    remote.certificate_arn = 'arn:aws:acm:us-east-1:123456789012:certificate/12345678-1234-1234-1234-123456789abc'
    remote.certificate = {'CertificateArn': remote.certificate_arn, 'DomainName': 'eks.manaleague.com.br',
        'SubjectAlternativeNames': ['eks.manaleague.com.br'], 'Status': 'ISSUED', 'Type': 'AMAZON_ISSUED', 'InUseBy': []}
    remote.certificate_tags = [{'Key': 'Project', 'Value': 'manaleague-eks-lab'},
                               {'Key': 'Environment', 'Value': 'eks-lab'}]
    rules = remote.security_groups[0]['IpPermissions']
    rules.append(copy.deepcopy(rules[0]))
    rules[1]['FromPort'] = rules[1]['ToPort'] = 443
    arn = 'arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/manaleague-eks-lab/0123456789abcdef'
    remote.balancers = [{'LoadBalancerArn': arn, 'LoadBalancerName': 'manaleague-eks-lab',
        'VpcId': 'vpc-1234', 'Type': 'application', 'Scheme': 'internet-facing',
        'AvailabilityZones': [{'SubnetId': 'subnet-pub1'}, {'SubnetId': 'subnet-pub2'}],
        'SecurityGroups': ['sg-lab']}]
    prefix = arn.replace(':loadbalancer/', ':listener/') + '/'
    remote.listeners = [
        {'LoadBalancerArn': arn, 'ListenerArn': prefix + '1111111111111111', 'Protocol': 'HTTP', 'Port': 80,
         'DefaultActions': [{'Type': 'redirect', 'RedirectConfig': {'Protocol': 'HTTPS', 'Port': '443',
             'Host': '#{host}', 'Path': '/#{path}', 'Query': '#{query}', 'StatusCode': 'HTTP_301'}}]},
        {'LoadBalancerArn': arn, 'ListenerArn': prefix + '2222222222222222', 'Protocol': 'HTTPS', 'Port': 443,
         'SslPolicy': 'ELBSecurityPolicy-TLS13-1-2-Res-2021-06',
         'Certificates': [{'CertificateArn': remote.certificate_arn}],
         'DefaultActions': [{'Type': 'fixed-response', 'FixedResponseConfig': {'StatusCode': '404'}}]}]
    original = remote.aws
    def aws(*args):
        responses = {('acm', 'describe-certificate'): {'Certificate': remote.certificate},
            ('acm', 'list-tags-for-certificate'): {'Tags': remote.certificate_tags},
            ('elbv2', 'describe-load-balancers'): {'LoadBalancers': remote.balancers},
            ('elbv2', 'describe-listeners'): {'Listeners': remote.listeners}}
        if args[:2] in responses:
            remote.calls.append(('aws', args))
            return responses[args[:2]]
        return original(*args)
    remote.aws = aws
    return remote


class HTTPSPreflightTests(unittest.TestCase):
    def test_cli_rejects_tls_before_creating_private_root_or_temporary_state(self):
        session = load('session')
        remote = tls_remote()
        remote.listeners[1]['SslPolicy'] = 'wrong-policy'
        with mock.patch.object(session, 'Remote', return_value=remote), \
             mock.patch.object(session, 'private_root', side_effect=AssertionError('premature state')), \
             mock.patch('tempfile.TemporaryDirectory', side_effect=AssertionError('premature temporary state')), \
             self.assertRaisesRegex(ValueError, 'TLS policy'):
            session.main(['start'] + BASE + GATES)

    def test_tls_policy_is_rechecked_before_registration_token(self):
        session = load('session')
        remote = tls_remote()
        journal = MemoryJournal()
        original = remote.create
        def create(obj):
            result = original(obj)
            if obj['kind'] == 'NetworkPolicy':
                remote.listeners[1]['SslPolicy'] = 'ELBSecurityPolicy-2016-08'
            return result
        remote.create = create
        with self.assertRaisesRegex(ValueError, 'TLS policy'):
            session.start(live_args(), remote, journal, ROOT)
        self.assertEqual([obj['kind'] for obj in remote.applied], ['Namespace', 'ServiceAccount', 'NetworkPolicy'])
        self.assertFalse(any(c[-1] == 'POST' for c in remote.calls))

    def test_plan_and_http_preflight_never_load_https_contract(self):
        session = load('session')
        args = live_args()
        with mock.patch.object(session.importlib.util, 'spec_from_file_location', side_effect=AssertionError('HTTPS import')):
            self.assertFalse(session.plan(args)['cloud_actions'])
            self.assertEqual(session.preflight(args, PreflightRemote())['job_id'], 42)

    def test_tls_cleanup_ignores_gate_nat_certificate_and_origin_after_start(self):
        session = load('session')
        remote = tls_remote()
        journal = MemoryJournal()
        session.POLL_ATTEMPTS = 1
        self.assertEqual(session.start(live_args(), remote, journal, ROOT)['status'], 'online')
        remote.gate = 'false'
        remote.nats = []
        remote.app_url = 'https://production.invalid'
        remote.certificate_arn = ''
        remote.calls = []
        args = live_args()
        args.action = 'cleanup'
        self.assertEqual(session.cleanup(args, remote, journal, ROOT)['status'], 'cleaned')
        self.assertEqual({c[1][1] for c in remote.calls if c[0] == 'aws'}, {'get-caller-identity', 'describe-cluster'})
        self.assertFalse(any('/variables/' in str(c) for c in remote.calls))

    def assert_refused_before_state(self, remote):
        journal = MemoryJournal()
        with self.assertRaises(ValueError):
            load('session').start(live_args(), remote, journal, ROOT)
        self.assertIsNone(journal.value)
        self.assertEqual(remote.applied, [])
        self.assertFalse(any(c[0] == 'configure' or c[-1] == 'POST' for c in remote.calls))

    def test_https_balancer_identity_is_exact_before_listener_lookup(self):
        for case in ('missing', 'duplicate', 'name', 'account', 'region', 'suffix', 'vpc',
                     'scheme', 'type', 'private_subnet', 'missing_subnet', 'duplicate_subnet', 'extra_sg'):
            remote = tls_remote()
            balancer = remote.balancers[0]
            if case == 'missing': remote.balancers = []
            elif case == 'duplicate': remote.balancers *= 2
            elif case == 'name': balancer['LoadBalancerName'] = 'production'
            elif case == 'account': balancer['LoadBalancerArn'] = balancer['LoadBalancerArn'].replace('123456789012', '999999999999')
            elif case == 'region': balancer['LoadBalancerArn'] = balancer['LoadBalancerArn'].replace('us-east-1', 'us-east-2')
            elif case == 'suffix': balancer['LoadBalancerArn'] += '/foreign'
            elif case == 'vpc': balancer['VpcId'] = 'vpc-other'
            elif case == 'scheme': balancer['Scheme'] = 'internal'
            elif case == 'type': balancer['Type'] = 'network'
            elif case == 'private_subnet': balancer['AvailabilityZones'][0]['SubnetId'] = 'subnet-priv1'
            elif case == 'missing_subnet': balancer['AvailabilityZones'].pop()
            elif case == 'duplicate_subnet': balancer['AvailabilityZones'][1]['SubnetId'] = 'subnet-pub1'
            else: balancer['SecurityGroups'].append('sg-foreign')
            with self.subTest(case=case):
                self.assert_refused_before_state(remote)
                self.assertFalse(any(c[0] == 'aws' and c[1][:2] == ('elbv2', 'describe-listeners') for c in remote.calls))

    def test_https_listeners_require_scoped_arns_redirect_and_exact_policy_certificate(self):
        for case in ('missing443', 'duplicate', 'otherport', 'protocol', 'lbarn', 'listenerarn',
                     'listeneraccount', 'duplicatearn', 'policy', 'wrongcert', 'extracert', 'notdefault',
                     'redirecttype', 'redirectprotocol', 'redirectport', 'redirecthost', 'redirectpath'):
            remote = tls_remote()
            http, tls = remote.listeners
            if case == 'missing443': remote.listeners.pop()
            elif case == 'duplicate': remote.listeners.append(tls)
            elif case == 'otherport': tls['Port'] = 8443
            elif case == 'protocol': tls['Protocol'] = 'HTTP'
            elif case == 'lbarn': tls['LoadBalancerArn'] += '0'
            elif case == 'listenerarn': tls['ListenerArn'] = tls['ListenerArn'].replace('/manaleague-eks-lab/', '/production/')
            elif case == 'listeneraccount': http['ListenerArn'] = http['ListenerArn'].replace('123456789012', '999999999999')
            elif case == 'duplicatearn': tls['ListenerArn'] = http['ListenerArn']
            elif case == 'policy': tls['SslPolicy'] = 'ELBSecurityPolicy-2016-08'
            elif case == 'wrongcert': tls['Certificates'][0]['CertificateArn'] += '0'
            elif case == 'extracert': tls['Certificates'].append(tls['Certificates'][0])
            elif case == 'notdefault': tls['Certificates'][0]['IsDefault'] = False
            elif case == 'redirecttype': http['DefaultActions'][0]['Type'] = 'forward'
            elif case == 'redirectprotocol': http['DefaultActions'][0]['RedirectConfig']['Protocol'] = 'HTTP'
            elif case == 'redirectport': http['DefaultActions'][0]['RedirectConfig']['Port'] = '80'
            elif case == 'redirecthost': http['DefaultActions'][0]['RedirectConfig']['Host'] = 'manaleague.com.br'
            else: http['DefaultActions'][0]['RedirectConfig']['Path'] = '/other'
            with self.subTest(case=case):
                self.assert_refused_before_state(remote)

    def test_certificate_arn_rejects_foreign_or_malformed_before_acm_lookup(self):
        for value in ('', None, [], 'arn:aws:acm:us-east-2:123456789012:certificate/12345678-1234-1234-1234-123456789abc',
                      'arn:aws:acm:us-east-1:999999999999:certificate/12345678-1234-1234-1234-123456789abc',
                      'arn:aws:acm:us-east-1:123456789012:certificate/not-a-uuid'):
            remote = tls_remote()
            remote.certificate_arn = value
            with self.subTest(value=value):
                self.assert_refused_before_state(remote)
                self.assertFalse(any(c[0] == 'aws' and c[1][0] == 'acm' for c in remote.calls))

    def test_certificate_exact_host_status_tags_and_usage_are_validated_by_real_contract(self):
        for case in ('san', 'wildcard', 'domain', 'pending', 'imported', 'foreignarn', 'project', 'environment', 'foreignuse'):
            remote = tls_remote()
            cert = remote.certificate
            if case == 'san': cert['SubjectAlternativeNames'].append('www.manaleague.com.br')
            elif case == 'wildcard': cert['SubjectAlternativeNames'] = ['*.manaleague.com.br']
            elif case == 'domain': cert['DomainName'] = 'manaleague.com.br'
            elif case == 'pending': cert['Status'] = 'PENDING_VALIDATION'
            elif case == 'imported': cert['Type'] = 'IMPORTED'
            elif case == 'foreignarn': cert['CertificateArn'] = cert['CertificateArn'].replace('123456789012', '999999999999')
            elif case == 'project': remote.certificate_tags[0]['Value'] = 'production'
            elif case == 'environment': remote.certificate_tags[1]['Value'] = 'production'
            else: cert['InUseBy'] = [remote.balancers[0]['LoadBalancerArn'].replace('/manaleague-eks-lab/', '/production/')]
            with self.subTest(case=case):
                self.assert_refused_before_state(remote)

    def test_missing_tls_ingress_fails_before_any_objects(self):
        remote = tls_remote()
        remote.security_groups[0]['IpPermissions'].pop()
        self.assert_refused_before_state(remote)

    def test_exact_https_reads_explicit_certificate_and_scoped_listeners_before_objects(self):
        session = load('session')
        remote = tls_remote()
        with mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('socket.socket', side_effect=AssertionError('network')):
            self.assertEqual(session.preflight(live_args(), remote)['job_id'], 42)
        self.assertEqual(remote.applied, [])
        self.assertTrue(all(c[-1] == 'GET' for c in remote.calls if c[0] == 'gh'))
        self.assertIn(('gh', 'repos/viniciusVitorio/manaleague/environments/eks-lab/variables/ACM_CERTIFICATE_ARN', 'GET'), remote.calls)
        for action in ('describe-certificate', 'list-tags-for-certificate'):
            self.assertIn(('aws', ('acm', action, '--certificate-arn', remote.certificate_arn)), remote.calls)
        self.assertIn(('aws', ('elbv2', 'describe-load-balancers', '--names', 'manaleague-eks-lab')), remote.calls)
        self.assertIn(('aws', ('elbv2', 'describe-listeners', '--load-balancer-arn', remote.balancers[0]['LoadBalancerArn'])), remote.calls)
        remote.certificate['InUseBy'] = [remote.balancers[0]['LoadBalancerArn']]
        remote.listeners[1]['Certificates'][0]['IsDefault'] = True
        self.assertEqual(session.preflight(live_args(), remote)['job_id'], 42)


class MemoryJournal:
    def __init__(self):
        self.value = None
        self.history = []
    def reserve(self, value):
        if self.value is not None:
            raise ValueError('session already exists')
        self.save(value)
    def save(self, value):
        import copy
        self.value = copy.deepcopy(value)
        self.history.append(copy.deepcopy(value))
    def read(self):
        import copy
        return copy.deepcopy(self.value)


class LifecycleRemote(PreflightRemote):
    def __init__(self):
        super().__init__()
        self.objects = {}
        self.deleted = []
        self.applied = []
        self.token = 'SECRET-REGISTRATION-SENTINEL'
        from datetime import datetime, timedelta, timezone
        self.expires = (datetime.now(timezone.utc) + timedelta(minutes=60)).isoformat()
        self.runner_created = False
        self.init_ok = True
        self.terminal = False
    def configure(self, cluster, directory):
        self.calls.append(('configure', cluster['arn']))
    def get(self, kind, name):
        import copy
        return copy.deepcopy(self.objects.get((kind, name)))
    def create(self, obj):
        import copy
        obj = copy.deepcopy(obj)
        key = obj['kind'], obj['metadata']['name']
        if key in self.objects:
            raise ValueError('exists')
        obj['metadata']['uid'] = 'uid-' + obj['kind']
        if obj['kind'] == 'Job':
            obj['status'] = {'succeeded': 1} if self.terminal else {'active': 1}
            name = obj['spec']['template']['spec']['initContainers'][-1]['env'][-1]['value']
            self.runners = [{'id': 901, 'name': name, 'status': 'online', 'busy': False,
                            'labels': [{'name': 'eks-lab-44421131546'}, {'name': 'self-hosted'}, {'name': 'linux'}]}]
            self.runner_created = True
        self.objects[key] = obj
        self.applied.append(copy.deepcopy(obj))
        return copy.deepcopy(obj)
    def pods(self, job_uid):
        job = next(o for (k, n), o in self.objects.items() if k == 'Job')
        return [{'metadata': {'name': 'pod-1', 'namespace': 'manaleague-eks-lab-runner', 'uid': 'pod-uid',
                    'ownerReferences': [{'kind': 'Job', 'name': job['metadata']['name'], 'uid': job_uid, 'controller': True}]},
                 'status': {'initContainerStatuses': [{'name': 'register', 'state': {'terminated': {'exitCode': 0 if self.init_ok else 1}}}]}}]
    def delete(self, kind, name, uid):
        if self.objects[(kind, name)]['metadata']['uid'] != uid:
            raise ValueError('foreign UID')
        self.deleted.append((kind, name, uid))
        del self.objects[(kind, name)]
    def gh(self, path, method='GET', collection=None):
        if path.endswith('/registration-token'):
            self.calls.append(('gh', path, method))
            return {'token': self.token, 'expires_at': self.expires}
        if '/runners/' in path and method == 'DELETE':
            self.calls.append(('gh', path, method))
            self.runners = [r for r in self.runners if r['id'] != int(path.split('/')[-1])]
            return None
        return super().gh(path, method, collection)


class LifecycleTests(unittest.TestCase):
    def start(self, remote=None, journal=None):
        session = load('session')
        session.POLL_ATTEMPTS = 1
        remote = remote or LifecycleRemote()
        journal = journal or MemoryJournal()
        with mock.patch.object(session, 'pause', return_value=None):
            result = session.start(live_args(), remote, journal, ROOT)
        return session, remote, journal, result

    def test_start_orders_prerequisites_stdin_secret_job_then_verifies_online_and_deletes_secret(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            session, remote, journal, result = self.start()
        self.assertEqual(result['status'], 'online')
        self.assertEqual([o['kind'] for o in remote.applied],
                         ['Namespace', 'ServiceAccount', 'NetworkPolicy', 'Secret', 'Job'])
        self.assertEqual(journal.value['runner_id'], 901)
        self.assertTrue(journal.value['registration_removed'])
        self.assertEqual([x[0] for x in remote.deleted], ['Secret'])
        self.assertNotIn(remote.token, json.dumps(journal.history) + out.getvalue())
        self.assertNotIn(remote.token, str(remote.calls))
        self.assertIn(remote.token, remote.applied[3]['stringData']['token'])
        with self.assertRaises(ValueError):
            self.start(remote, journal)

    def test_start_refuses_foreign_namespace_or_existing_runner_before_registration(self):
        for foreign in ('namespace', 'runner'):
            remote = LifecycleRemote()
            if foreign == 'namespace':
                remote.objects[('Namespace', 'manaleague-eks-lab-runner')] = {'metadata': {'uid': 'foreign'}}
            else:
                remote.runners = [{'id': 33, 'name': 'old', 'labels': [{'name': 'eks-lab-44421131546'}]}]
            with self.subTest(foreign=foreign), self.assertRaises(ValueError):
                self.start(remote)
            self.assertEqual(remote.applied, [])
            self.assertFalse(any(c[-1] == 'POST' for c in remote.calls))

    def test_registration_api_empty_or_wrong_shape_refuses_without_secret(self):
        for value in (None, [], 'unexpected-body'):
            remote = LifecycleRemote()
            original = remote.gh
            def gh(path, method='GET', collection=None):
                if path.endswith('/registration-token'):
                    return value
                return original(path, method, collection)
            remote.gh = gh
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.start(remote)
            self.assertNotIn('Secret', [o['kind'] for o in remote.applied])

    def test_registration_token_must_expire_soon_and_not_be_expired(self):
        for expires in ('2000-01-01T00:00:00Z', '2099-01-01T00:00:00Z', 'invalid', None):
            remote = LifecycleRemote()
            remote.expires = expires
            with self.subTest(expires=expires), self.assertRaises(ValueError):
                self.start(remote)
            self.assertNotIn('Secret', [o['kind'] for o in remote.applied])

    def test_init_failure_retains_secret_and_reports_non_online(self):
        remote = LifecycleRemote()
        remote.init_ok = False
        session, remote, journal, result = self.start(remote)
        self.assertEqual(result['status'], 'registration_failed')
        self.assertEqual(remote.deleted, [])
        self.assertFalse(journal.value['registration_removed'])

    def test_terminal_job_reports_terminal_not_online(self):
        remote = LifecycleRemote()
        remote.terminal = True
        session, remote, journal, result = self.start(remote)
        self.assertEqual(result['status'], 'job_complete')

    def test_cleanup_is_uid_bound_verified_and_keeps_namespace(self):
        session, remote, journal, result = self.start()
        args = live_args()
        args.action = 'cleanup'
        result = session.cleanup(args, remote, journal, ROOT)
        self.assertEqual(result['status'], 'cleaned')
        self.assertEqual(remote.runners, [])
        self.assertIn(('Namespace', 'manaleague-eks-lab-runner'), remote.objects)
        self.assertEqual([d[0] for d in remote.deleted], ['Secret', 'Job'])
        self.assertEqual(session.cleanup(args, remote, journal, ROOT)['status'], 'cleaned')

    def test_cleanup_after_job_ignores_closed_deploy_gate_and_unavailable_nat(self):
        remote = LifecycleRemote()
        remote.terminal = True
        session, remote, journal, result = self.start(remote)
        remote.gate = 'false'
        remote.nats = []
        remote.routes = []
        remote.instances = []
        remote.calls = []
        args = live_args()
        args.action = 'cleanup'
        result = session.cleanup(args, remote, journal, ROOT)
        self.assertEqual(result['status'], 'cleaned')
        self.assertEqual({c[1][1] for c in remote.calls if c[0] == 'aws'},
                         {'get-caller-identity', 'describe-cluster'})
        self.assertFalse(any('/variables/' in str(c) for c in remote.calls))

    def test_network_metadata_is_rechecked_before_registration_token(self):
        remote = LifecycleRemote()
        original = remote.create
        def create(obj):
            result = original(obj)
            if obj['kind'] == 'NetworkPolicy':
                remote.nats[0]['State'] = 'failed'
            return result
        remote.create = create
        with self.assertRaises(ValueError):
            self.start(remote)
        self.assertEqual([o['kind'] for o in remote.applied], ['Namespace', 'ServiceAccount', 'NetworkPolicy'])
        self.assertFalse(any(c[-1] == 'POST' for c in remote.calls))

    def test_cleanup_refuses_unrecorded_namespace_uid_or_runner_id_renamed(self):
        for mutation in ('missing_uid', 'renamed_runner'):
            session, remote, journal, result = self.start()
            if mutation == 'missing_uid':
                journal.value['namespace_uid'] = None
            else:
                remote.runners[0]['name'] = 'different-name'
            before = list(remote.deleted)
            args = live_args()
            args.action = 'cleanup'
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                session.cleanup(args, remote, journal, ROOT)
            self.assertEqual(before, remote.deleted)

    def test_cleanup_rejects_foreign_job_or_namespace_uid_before_any_delete(self):
        for kind, key in (('Job', 'job_name'), ('Namespace', 'namespace')):
            session, remote, journal, result = self.start()
            remote.objects[(kind, journal.value[key])]['metadata']['uid'] = 'FOREIGN'
            before = list(remote.deleted)
            args = live_args()
            args.action = 'cleanup'
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                session.cleanup(args, remote, journal, ROOT)
            self.assertEqual(remote.deleted, before)
            self.assertEqual(len(remote.runners), 1)


class TransportTests(unittest.TestCase):
    def test_operator_aws_reads_pin_profile_and_region_not_the_deploy_role(self):
        session = load('session')
        remote = session.Remote(live_args())
        with mock.patch.object(remote, 'command', return_value='{"Reservations": []}') as command:
            remote.aws('ec2', 'describe-instances')
        self.assertEqual(command.call_args.args[0], ['aws', '--profile', 'eks-lab',
            '--region', 'us-east-1', '--output', 'json', 'ec2', 'describe-instances'])

    def test_transport_passes_registration_secret_only_stdin_and_suppresses_failures(self):
        import subprocess
        session = load('session')
        remote = session.Remote(live_args())
        remote.kube = ['kubectl', '--kubeconfig', 'PRIVATE', '--context', 'EXACT']
        secret = {'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': 'runner-registration-token',
                  'namespace': 'manaleague-eks-lab-runner'}, 'stringData': {'token': 'SENTINEL'}}
        with mock.patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0, '{}', '')) as run:
            remote.create(secret)
            args, kwargs = run.call_args
            self.assertNotIn('SENTINEL', str(args) + str(kwargs.get('env')))
            self.assertIn('SENTINEL', kwargs['input'])
            self.assertTrue(kwargs['capture_output'])
            self.assertNotIn('--save-config', args[0])
        with mock.patch('subprocess.run', side_effect=subprocess.CalledProcessError(
                1, ['kubectl', 'SENTINEL'], output='SENTINEL', stderr='SENTINEL')):
            with self.assertRaises(ValueError) as error:
                remote.create(secret)
            self.assertNotIn('SENTINEL', str(error.exception))
            self.assertIsNone(error.exception.__cause__)

    def test_gh_uses_stored_auth_not_pat_flags_or_token_environment(self):
        import subprocess
        session = load('session')
        remote = session.Remote(live_args())
        with mock.patch.dict('os.environ', {'GH_TOKEN': 'CREDENTIAL-SENTINEL',
                'GITHUB_TOKEN': 'CREDENTIAL-SENTINEL', 'GH_DEBUG': 'api', 'PATH': 'test-bin'}, clear=True), \
             mock.patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0,
                    'STORED-CREDENTIAL', '')) as run:
            self.assertEqual(remote.credential(), 'STORED-CREDENTIAL')
            cmd = run.call_args.args[0]
            env = run.call_args.kwargs['env']
            self.assertEqual(cmd, ['gh', 'auth', 'token', '--hostname', 'github.com'])
            self.assertNotIn('CREDENTIAL-SENTINEL', str(cmd) + str(env))
            self.assertNotIn('GH_DEBUG', env)

    def test_delete_uses_atomic_uid_precondition_in_api_body(self):
        session = load('session')
        remote = session.Remote(live_args())
        remote.kube = ['kubectl', '--kubeconfig', 'PRIVATE', '--context', 'EXACT']
        with mock.patch.object(remote, 'command', return_value='{}') as command:
            remote.delete('Job', 'runner-44421131546-12345678', 'uid-exact')
            cmd = command.call_args.args[0]
            body = json.loads(command.call_args.kwargs['input'])
            self.assertIn('--raw', cmd)
            self.assertEqual(body['preconditions'], {'uid': 'uid-exact'})
            self.assertEqual(body['propagationPolicy'], 'Foreground')

    def test_exclusive_kubeconfig_is_generated_without_human_context_or_credentials(self):
        import tempfile
        session = load('session')
        remote = session.Remote(live_args())
        cluster = PreflightRemote().cluster
        with tempfile.TemporaryDirectory(dir=ROOT) as temp:
            with mock.patch.object(remote, 'command', return_value='{}'):
                remote.configure(cluster, pathlib.Path(temp))
            config = json.loads((pathlib.Path(temp) / 'kubeconfig.json').read_text())
            self.assertEqual(config['clusters'][0]['cluster']['server'], cluster['endpoint'])
            self.assertEqual(config['clusters'][0]['cluster']['certificate-authority-data'], 'Y2VydA==')
            self.assertEqual(config['current-context'], cluster['arn'])
            self.assertNotIn('token', config['users'][0]['user'])
            self.assertIn('eks-lab', config['users'][0]['user']['exec']['args'])
            with self.assertRaises(ValueError):
                remote.configure(cluster, pathlib.Path(temp))

    def test_private_journal_is_exclusive_atomic_and_rejects_symlinks(self):
        import tempfile
        session = load('session')
        with tempfile.TemporaryDirectory(dir=ROOT) as temp:
            path = pathlib.Path(temp) / '.local' / 'session-44421131546.json'
            journal = session.Journal(path)
            journal.reserve({'status': 'reserved'})
            with self.assertRaises(ValueError):
                journal.reserve({'status': 'overwrite'})
            journal.save({'status': 'online'})
            self.assertEqual(journal.read(), {'status': 'online'})
            self.assertEqual(list(path.parent.glob('*.tmp')), [])
            with mock.patch.object(pathlib.Path, 'is_symlink', return_value=True), self.assertRaises(ValueError):
                journal.read()

    def test_cli_live_dispatch_uses_only_explicitly_gated_transport(self):
        session = load('session')
        with mock.patch.object(session, 'Remote') as remote, \
             mock.patch.object(session, 'preflight') as preflight, \
             mock.patch.object(session, 'Journal'), mock.patch.object(session, 'private_root', return_value=ROOT), \
             mock.patch.object(session, 'session_lock', return_value=contextlib.nullcontext()), \
             mock.patch.object(session, 'start', return_value={'status': 'online'}) as start, \
             contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(session.main(['start'] + BASE + GATES), 0)
            start.assert_called_once()
            remote.assert_called_once()
            preflight.assert_called_once_with(start.call_args.args[0], remote.return_value)


class WindowsBridgeTests(unittest.TestCase):
    def test_explicit_bridge_uses_fixed_powershell_and_stdin_captured_in_memory(self):
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        with mock.patch.object(remote, 'command', return_value='protocol=https\nhost=github.com\nusername=fixture\npassword=TOKEN-SENTINEL\n\n') as command:
            self.assertEqual(remote.windows_credential(), 'TOKEN-SENTINEL')
            argv = command.call_args.args[0]
            self.assertEqual(argv[:4], ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command'])
            self.assertNotIn('TOKEN-SENTINEL', str(argv) + str(command.call_args.kwargs))
            self.assertEqual(command.call_args.kwargs['input'], 'protocol=https\nhost=github.com\n\n')
            self.assertIn('$env:HOME = $env:USERPROFILE', argv[-1])
            self.assertIn("$env:GCM_INTERACTIVE = 'Never'", argv[-1])
            self.assertIn("$env:GIT_TERMINAL_PROMPT = '0'", argv[-1])
            self.assertIn('-C $env:USERPROFILE credential fill', argv[-1])
            self.assertNotIn('config --', argv[-1])

    def test_bridge_http_disables_redirects_and_never_logs_auth_or_error_bodies(self):
        import urllib.error
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        response = mock.MagicMock()
        response.read.return_value = b'{"name":"LAB_DEPLOY_ENABLED","value":"true"}'
        response.__enter__.return_value = response
        opener = mock.Mock()
        opener.open.return_value = response
        with mock.patch.object(remote, 'windows_credential', return_value='TOKEN-SENTINEL'), \
             mock.patch('urllib.request.build_opener', return_value=opener) as build:
            result = remote.gh('repos/viniciusVitorio/manaleague/environments/eks-lab/variables/LAB_DEPLOY_ENABLED')
            self.assertEqual(result['value'], 'true')
            request = opener.open.call_args.args[0]
            self.assertEqual(request.get_header('Authorization'), 'Bearer TOKEN-SENTINEL')
            self.assertTrue(request.full_url.startswith('https://api.github.com/repos/viniciusVitorio/manaleague/'))
            handler = build.call_args.args[0]
            with self.assertRaises(ValueError):
                handler.redirect_request(request, None, 302, 'redirect', {}, 'https://attacker.invalid')
            opener.open.side_effect = urllib.error.URLError('TOKEN-SENTINEL')
            with self.assertRaises(ValueError) as error:
                remote.gh('repos/viniciusVitorio/manaleague/actions/runners')
            self.assertNotIn('TOKEN-SENTINEL', str(error.exception))

    def test_bridge_pagination_stays_on_fixed_host_and_checks_total(self):
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        pages = [{'total_count': 101, 'runners': [{'id': i} for i in range(100)]},
                 {'total_count': 101, 'runners': [{'id': 100}]}]
        with mock.patch.object(remote, 'http', side_effect=pages) as http:
            self.assertEqual(len(remote.gh('repos/viniciusVitorio/manaleague/actions/runners', collection='runners')), 101)
            self.assertIn('page=2', http.call_args.args[0])
            self.assertTrue(all('https:' not in call.args[0] for call in http.call_args_list))


class RunLabelTests(unittest.TestCase):
    def test_renderer_cli_accepts_optional_run_label_without_remote_io(self):
        helper = load('operator')
        out = io.StringIO()
        with contextlib.redirect_stdout(out), mock.patch('subprocess.run', side_effect=AssertionError('process')):
            helper.main(['render', '--account', '123456789012', '--region', 'us-east-1',
                '--vpc-cidr', '10.42.0.0/16', '--run-id', '44421131546'])
        self.assertIn('--labels eks-lab,eks-lab-44421131546', out.getvalue())

    def test_optional_label_is_exact_and_defaults_unchanged(self):
        renderer = load('operator')
        default = renderer.render('123456789012', 'us-east-1', '10.42.0.0/16')
        objects = renderer.render('123456789012', 'us-east-1', '10.42.0.0/16', run_id='44421131546')
        script = objects[-1]['spec']['template']['spec']['initContainers'][-1]['command'][-1]
        self.assertIn('--labels eks-lab,eks-lab-44421131546', script)
        self.assertNotIn('eks-lab-44421131546', str(default))
        for value in ('0', '01', ' 123', '123;id', 123, '', '1' * 21):
            with self.subTest(value=value), self.assertRaises(ValueError):
                renderer.render('123456789012', 'us-east-1', '10.42.0.0/16', run_id=value)
