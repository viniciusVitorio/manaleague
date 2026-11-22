"""Offline operator tests. All AWS/Kubernetes responses below are FAKE fixtures."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest import mock

LAB = Path(__file__).resolve().parents[1]
NAME = 'manaleague-eks-lab'
ACCOUNT = '123456789012'
REGION = 'us-east-1'
HOST = 'eks.manaleague.com.br'
ZONE = 'ZFAKE123456789'
NS = ['ns-605.awsdns-11.net', 'ns-16.awsdns-02.com', 'ns-1514.awsdns-61.org', 'ns-1580.awsdns-05.co.uk']
ARN = f'arn:aws:acm:{REGION}:{ACCOUNT}:certificate/12345678-1234-1234-1234-123456789012'


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def fixture():
    result = load(LAB / 'tests/test_github_bindings.py', 'fake_bindings_fixture').fixture()
    result.update({k: {'sensitive': False, 'value': v} for k, v in {
        'lab_https_enabled': True, 'lab_hostname': HOST, 'lab_url': 'https://' + HOST,
        'lab_certificate_arn': ARN, 'lab_dns_zone_id': ZONE, 'lab_dns_name_servers': NS,
    }.items()})
    return result


class DomainCase(unittest.TestCase):
    def module(self):
        self.assertTrue((LAB / 'scripts/domain.py').exists(), 'domain operator missing')
        return load(LAB / 'scripts/domain.py', 'domain_under_test')

    def args(self, action='plan'):
        args = [action, '--outputs', 'FAKE-explicit-outputs.json', '--expected-account', ACCOUNT, '--region', REGION]
        if action != 'plan':
            args += ['--allow-cloud', '--allow-lab-dns', '--confirm-lab', NAME]
        return args



class DomainTests(DomainCase):
    def test_authorization_and_literals_precede_all_io(self):
        d = self.module()
        for args in [self.args('prepare')[:-1], self.args('cleanup')[:-4],
                     self.args() + ['--region', 'us-west-2'],
                     self.args() + ['--expected-account', ACCOUNT + '\n']]:
            with self.subTest(args=args), mock.patch.object(Path, 'read_text', side_effect=AssertionError('file I/O')), \
                 mock.patch.object(d, 'Cli', side_effect=AssertionError('transport')), \
                 mock.patch('sys.stderr', new=io.StringIO()):
                with self.assertRaises(SystemExit):
                    d.main(args)

    def test_default_plan_is_pure_metadata_and_only_reads_explicit_input(self):
        d = self.module()
        reads = []
        def read(path, *args, **kwargs):
            reads.append(str(path))
            return json.dumps(fixture_data)
        fixture_data = fixture()
        # Load local code dependencies before banning arbitrary input reads.
        d.dependencies()
        with mock.patch.object(Path, 'read_text', read), \
             mock.patch.object(d, 'Cli', side_effect=AssertionError('transport')), \
             mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('sys.stdout', new=io.StringIO()) as out:
            self.assertEqual(d.main(self.args()[1:]), 0)
        result = json.loads(out.getvalue())
        self.assertEqual(reads, ['FAKE-explicit-outputs.json'])
        self.assertEqual(result['action'], 'plan')
        self.assertFalse(result['ready_for_cd'])
        self.assertFalse(result['application_verified'])
        self.assertEqual(result['hostname'], HOST)
        self.assertEqual(result['dns_names'], [HOST + '.', '_eks-lab.' + HOST + '.'])
        self.assertNotIn('commands', result)
        self.assertEqual(result['frontend_constraints']['host'], HOST)
        self.assertEqual(result['frontend_constraints']['scheme'], 'internet-facing')
        self.assertEqual(result['expected_dns'][0], {'Name': HOST + '.', 'Type': 'A', 'target': 'pending-verified-ALB'})
        self.assertEqual(result['frontend_constraints']['subnets'], fixture_data['subnet_ids']['value'])

    def test_output_validation_rejects_unsafe_https_metadata(self):
        d = self.module()
        for key, value in [('lab_https_enabled', False), ('lab_hostname', 'www.manaleague.com.br'),
                           ('lab_dns_zone_id', '/hostedzone/' + ZONE), ('lab_dns_name_servers', NS[:3]),
                           ('lab_certificate_arn', ARN.replace(ACCOUNT, '999999999999'))]:
            data = fixture()
            data[key]['value'] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                d.validate_outputs(data, ACCOUNT, REGION)


class FakeDNS:
    """In-memory FAKE Route53, with service-side exact DELETE CAS."""
    def __init__(self, records=()):
        self.records = copy.deepcopy(list(records))
        self.calls = []
        self.race = False

    def aws(self, service, operation, *args, payload=None):
        self.calls.append((service, operation, args, copy.deepcopy(payload)))
        if operation == 'get-caller-identity':
            return {'Account': ACCOUNT}
        if operation == 'get-hosted-zone':
            return {'HostedZone': {'Id': '/hostedzone/' + ZONE, 'Name': 'manaleague.com.br.', 'Config': {'PrivateZone': False}},
                    'DelegationSet': {'NameServers': NS}}
        if operation == 'list-resource-record-sets':
            name = payload['StartRecordName']
            return {'ResourceRecordSets': [r for r in self.records if r['Name'] == name], 'IsTruncated': False}
        if operation == 'change-resource-record-sets':
            if self.race:
                raise ValueError('FAKE concurrent change: DELETE no longer exact')
            proposed = copy.deepcopy(self.records)
            for change in payload['ChangeBatch']['Changes']:
                record = change['ResourceRecordSet']
                if change['Action'] == 'DELETE':
                    proposed.remove(record)
                elif change['Action'] == 'CREATE':
                    if any((r['Name'], r['Type']) == (record['Name'], record['Type']) for r in proposed):
                        raise ValueError('FAKE CREATE collision')
                    proposed.append(record)
                else:
                    raise AssertionError('Blind UPSERT forbidden')
            self.records = proposed
            return {'ChangeInfo': {'Id': '/change/CFAKE', 'Status': 'PENDING'}}
        if operation == 'get-change':
            return {'ChangeInfo': {'Id': '/change/CFAKE', 'Status': 'INSYNC'}}
        raise AssertionError((service, operation, args, payload))


class DNSTests(DomainCase):
    def test_dns_create_rebind_noop_and_exact_cleanup_without_cluster_or_alb(self):
        d = self.module()
        config = d.validate_outputs(fixture(), ACCOUNT, REGION)
        cli = FakeDNS()
        new = d.alias_records(config, 'manaleague-eks-lab-123.us-east-1.elb.amazonaws.com', 'ZFAKEALB')
        self.assertEqual(d.reconcile_dns(cli, config, new, sleep=lambda _: None), 'created')
        self.assertEqual(cli.records, new)
        self.assertEqual(d.reconcile_dns(cli, config, new, sleep=lambda _: None), 'unchanged')
        newer = d.alias_records(config, 'manaleague-eks-lab-456.us-east-1.elb.amazonaws.com', 'ZFAKEALB')
        self.assertEqual(d.reconcile_dns(cli, config, newer, sleep=lambda _: None), 'rebound')
        writes = [c for c in cli.calls if c[1] == 'change-resource-record-sets']
        self.assertEqual([x['Action'] for x in writes[-1][3]['ChangeBatch']['Changes']], ['DELETE', 'DELETE', 'CREATE', 'CREATE'])
        result = d.cleanup(config, cli, sleep=lambda _: None)
        self.assertEqual(cli.records, [])
        self.assertTrue(result['dns_absent'])
        self.assertFalse(result['application_verified'])
        self.assertFalse(any(c[0] in ('eks', 'elbv2', 'acm', 'ec2') for c in cli.calls))
        names = {x['ResourceRecordSet']['Name'] for c in writes for x in c[3]['ChangeBatch']['Changes']}
        self.assertEqual(names, {HOST + '.', '_eks-lab.' + HOST + '.'})

    def test_foreign_partial_or_malformed_dns_is_never_adopted(self):
        d = self.module()
        config = d.validate_outputs(fixture(), ACCOUNT, REGION)
        own = d.alias_records(config, 'manaleague-eks-lab-123.us-east-1.elb.amazonaws.com', 'ZFAKEALB')
        wrong_txt = copy.deepcopy(own)
        wrong_txt[1]['ResourceRecords'][0]['Value'] = '"foreign"'
        cases = [[own[0]], [own[1]], wrong_txt,
                 [{'Name': HOST + '.', 'Type': 'AAAA', 'TTL': 60, 'ResourceRecords': [{'Value': '::1'}]}],
                 own + [{'Name': HOST + '.', 'Type': 'TXT', 'TTL': 60, 'ResourceRecords': [{'Value': '"foreign"'}]}]]
        for records in cases:
            cli = FakeDNS(records)
            with self.subTest(records=records), self.assertRaises(ValueError):
                d.reconcile_dns(cli, config, own, sleep=lambda _: None)
            self.assertFalse(any(c[1] == 'change-resource-record-sets' for c in cli.calls))
        cli = FakeDNS(own)
        cli.race = True
        target = d.alias_records(config, 'manaleague-eks-lab-456.us-east-1.elb.amazonaws.com', 'ZFAKEALB')
        with self.assertRaises(ValueError):
            d.reconcile_dns(cli, config, target, sleep=lambda _: None)
        self.assertEqual(cli.records, own)

    def test_target_host_and_ownership_marker_are_literal_and_scoped(self):
        d = self.module()
        config = d.validate_outputs(fixture(), ACCOUNT, REGION)
        for host in ['www.manaleague.com.br', 'foreign.elb.amazonaws.com',
                     'manaleague-eks-lab-123.us-west-2.elb.amazonaws.com', 'x\n']:
            with self.subTest(host=host), self.assertRaises(ValueError):
                d.alias_records(config, host, 'ZFAKEALB')
        records = d.alias_records(config, 'manaleague-eks-lab-123.us-east-1.elb.amazonaws.com', 'ZFAKEALB')
        self.assertEqual(records[1]['TTL'], 60)
        self.assertIn(ACCOUNT, records[1]['ResourceRecords'][0]['Value'])
        self.assertIn(NAME, records[1]['ResourceRecords'][0]['Value'])


class FakePrepare(FakeDNS):
    def __init__(self, domain):
        super().__init__()
        self.domain = domain
        self.config = domain.validate_outputs(fixture(), ACCOUNT, REGION)
        v = self.config['values']
        self.cluster = {'arn': f'arn:aws:eks:{REGION}:{ACCOUNT}:cluster/{NAME}', 'name': NAME, 'status': 'ACTIVE',
                        'endpoint': 'https://FAKE.eks.amazonaws.com', 'certificateAuthority': {'data': 'RkFLRQ=='},
                        'resourcesVpcConfig': {'vpcId': v['VPC_ID']}}
        self.vpc = {'VpcId': v['VPC_ID'], 'OwnerId': ACCOUNT, 'CidrBlock': v['VPC_CIDR'],
                    'Tags': [{'Key': 'Project', 'Value': NAME}, {'Key': 'Environment', 'Value': 'eks-lab'}]}
        self.sg = {'GroupId': v['ALB_SECURITY_GROUP_ID'], 'VpcId': v['VPC_ID'], 'OwnerId': ACCOUNT,
                   'GroupName': NAME + '-alb', 'Tags': self.vpc['Tags']}
        self.cert = {'CertificateArn': ARN, 'DomainName': HOST, 'SubjectAlternativeNames': [HOST],
                     'Status': 'ISSUED', 'Type': 'AMAZON_ISSUED', 'InUseBy': []}
        self.lb = {'LoadBalancerName': NAME,
                   'LoadBalancerArn': f'arn:aws:elasticloadbalancing:{REGION}:{ACCOUNT}:loadbalancer/app/{NAME}/0123456789abcdef',
                   'DNSName': NAME + '-123.' + REGION + '.elb.amazonaws.com', 'CanonicalHostedZoneId': 'ZFAKEALB',
                   'VpcId': v['VPC_ID'], 'Scheme': 'internet-facing', 'Type': 'application',
                   'SecurityGroups': [v['ALB_SECURITY_GROUP_ID']], 'State': {'Code': 'active'},
                   'AvailabilityZones': [{'SubnetId': s} for s in fixture()['subnet_ids']['value']]}
        self.objects = {}
        self.ns = {'apiVersion': 'v1', 'kind': 'Namespace', 'metadata': {'name': NAME, 'uid': 'FAKE-ns-uid',
                   'labels': {'pod-security.kubernetes.io/enforce': 'restricted', 'pod-security.kubernetes.io/enforce-version': 'v1.31'}}}
        self.sa = {'apiVersion': 'v1', 'kind': 'ServiceAccount', 'metadata': {'name': 'lab-app', 'namespace': NAME,
                   'uid': 'FAKE-sa-uid', 'annotations': {'eks.amazonaws.com/role-arn': v['APP_ROLE_ARN']}}}
        self.uid_race = False
        # The real controller/API server also publishes the listener ports.
        self.lb_status_extra = {'ports': [{'port': 80, 'protocol': ''}, {'port': 443, 'protocol': ''}]}

    def aws(self, service, operation, *args, payload=None):
        if service in ('route53', 'sts'):
            return super().aws(service, operation, *args, payload=payload)
        self.calls.append((service, operation, args, payload))
        if operation == 'describe-cluster': return {'cluster': copy.deepcopy(self.cluster)}
        if operation == 'describe-vpcs': return {'Vpcs': [copy.deepcopy(self.vpc)]}
        if operation == 'describe-security-groups': return {'SecurityGroups': [copy.deepcopy(self.sg)]}
        if operation == 'describe-subnets':
            return {'Subnets': [{'SubnetId': s, 'VpcId': self.vpc['VpcId'], 'OwnerId': ACCOUNT, 'MapPublicIpOnLaunch': True}
                                for s in fixture()['subnet_ids']['value']]}
        if operation == 'describe-certificate': return {'Certificate': copy.deepcopy(self.cert)}
        if operation == 'list-tags-for-certificate': return {'Tags': copy.deepcopy(self.vpc['Tags'])}
        if operation == 'describe-load-balancers': return {'LoadBalancers': [copy.deepcopy(self.lb)]}
        if operation == 'describe-tags':
            return {'TagDescriptions': [{'ResourceArn': self.lb['LoadBalancerArn'], 'Tags': [
                {'Key': 'elbv2.k8s.aws/cluster', 'Value': NAME}, {'Key': 'ingress.k8s.aws/stack', 'Value': NAME + '/manaleague'},
                {'Key': 'Project', 'Value': NAME}]}]}
        raise AssertionError((service, operation))

    def bind_cluster(self, cluster):
        self.calls.append(('kube', 'bind', (), None))

    def kube(self, *args, payload=None):
        self.calls.append(('kube', args[0], args[1:], copy.deepcopy(payload)))
        if args[:2] == ('get', 'namespace'): return copy.deepcopy(self.ns)
        if args[:2] == ('get', 'serviceaccount'): return copy.deepcopy(self.sa)
        if args[0] == 'get' and len(args) == 2:
            kind = {'services': 'Service', 'ingresses': 'Ingress'}[args[1]]
            return {'items': [copy.deepcopy(v) for (k, n), v in self.objects.items() if k == kind]}
        if args[0] == 'get':
            key = (args[1], args[2])
            obj = copy.deepcopy(self.objects[key])
            if key[0] == 'Ingress':
                entry = {'hostname': self.lb['DNSName']}
                entry.update(copy.deepcopy(self.lb_status_extra))
                obj['status'] = {'loadBalancer': {'ingress': [entry]}}
                if self.uid_race: obj['metadata']['uid'] = 'FAKE-other-uid'
            return obj
        if args[0] == 'create':
            obj = copy.deepcopy(payload)
            key = (obj['kind'], obj['metadata']['name'])
            if key in self.objects: raise ValueError('FAKE object CREATE race')
            obj['metadata'].update(uid='FAKE-' + key[0] + '-' + key[1], resourceVersion='1')
            self.objects[key] = obj
            return copy.deepcopy(obj)
        raise AssertionError(args)


class PrepareTests(DomainCase):
    def test_prepare_first_cd_creates_only_frontend_and_verifies_dns(self):
        d = self.module()
        cli = FakePrepare(d)
        result = d.prepare(cli.config, cli, sleep=lambda _: None)
        self.assertTrue(result['ready_for_cd'])
        self.assertFalse(result['application_verified'])
        self.assertEqual(set(cli.objects), {('Service', 'manaleague'), ('Service', 'reverb'), ('Ingress', 'manaleague')})
        self.assertEqual(len(cli.records), 2)
        created = [c[3]['kind'] for c in cli.calls if c[:2] == ('kube', 'create')]
        self.assertEqual(created, ['Service', 'Service', 'Ingress'])
        result = d.prepare(cli.config, cli, sleep=lambda _: None)
        self.assertEqual(result['dns_result'], 'unchanged')
        self.assertEqual(len([c for c in cli.calls if c[:2] == ('kube', 'create')]), 3)

    def test_published_listener_ports_are_accepted_but_other_status_keys_are_not(self):
        d = self.module()
        cli = FakePrepare(d)
        self.assertTrue(d.prepare(cli.config, cli, sleep=lambda _: None)['ready_for_cd'])
        for extra in ({'ip': '203.0.113.10'}, {'unknown': 'x'},
                      {'ports': [{'port': 8080, 'protocol': ''}]},
                      {'ports': [{'port': 443, 'protocol': '', 'extra': 1}]},
                      {'ports': 'all'}, {'ports': []}):
            cli = FakePrepare(d)
            cli.lb_status_extra = extra
            with self.subTest(extra=extra), self.assertRaises(ValueError):
                d.prepare(cli.config, cli, sleep=lambda _: None)
            self.assertFalse(any(c[1] == 'change-resource-record-sets' for c in cli.calls))

    def test_foreign_cloud_and_kubernetes_objects_refuse_before_writes(self):
        d = self.module()
        mutations = [lambda c: c.cluster.update(arn='arn:aws:eks:us-east-1:999999999999:cluster/' + NAME),
                     lambda c: c.cluster.update(status='FAILED'), lambda c: c.vpc.update(OwnerId='999999999999'),
                     lambda c: c.vpc.update(CidrBlock='10.1.0.0/16'), lambda c: c.sg.update(VpcId='vpc-foreign'),
                     lambda c: c.cert.update(SubjectAlternativeNames=[HOST, 'www.manaleague.com.br']),
                     lambda c: c.cert.update(Status='PENDING_VALIDATION'),
                     lambda c: c.sa['metadata']['annotations'].update({'eks.amazonaws.com/role-arn': 'foreign'}),
                     lambda c: c.ns['metadata']['labels'].update({'pod-security.kubernetes.io/enforce': 'privileged'}),
                     lambda c: c.ns['metadata']['labels'].update({'foreign': 'true'}),
                     lambda c: c.sa['metadata'].update(labels={'foreign': 'true'}),
                     lambda c: c.objects.update({('Service', 'foreign'): {'kind': 'Service', 'metadata': {'name': 'foreign', 'namespace': NAME, 'uid': 'FAKE'}, 'spec': {}}}),
                     lambda c: c.records.append({'Name': HOST + '.', 'Type': 'CNAME', 'TTL': 60, 'ResourceRecords': [{'Value': 'foreign.'}]})]
        for index, change in enumerate(mutations):
            cli = FakePrepare(d)
            change(cli)
            with self.subTest(index=index), self.assertRaises(ValueError):
                d.prepare(cli.config, cli, sleep=lambda _: None)
            self.assertFalse(any(c[:2] == ('kube', 'create') or c[1] == 'change-resource-record-sets' for c in cli.calls))

    def test_existing_service_spec_and_labels_are_not_adopted_by_name(self):
        d = self.module()
        canonical = d.canonical_frontend(d.validate_outputs(fixture(), ACCOUNT, REGION))
        for mutate in [lambda s: s['spec']['selector'].update(app='production'),
                       lambda s: s['spec']['ports'][0].update(port=81),
                       lambda s: s['metadata'].update(labels={'foreign': 'true'}),
                       lambda s: s['spec'].update(externalIPs=['1.1.1.1'])]:
            cli = FakePrepare(d)
            service = copy.deepcopy(canonical[0])
            service['metadata']['uid'] = 'FAKE-existing'
            mutate(service)
            cli.objects[('Service', 'manaleague')] = service
            with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                d.prepare(cli.config, cli, sleep=lambda _: None)
            self.assertFalse(any(c[:2] == ('kube', 'create') for c in cli.calls))

    def test_noncanonical_service_ip_family_and_paginated_inventory_refuse_before_mutation(self):
        d = self.module()
        canonical = d.canonical_frontend(d.validate_outputs(fixture(), ACCOUNT, REGION))
        for fields in [{'ipFamilyPolicy': 'RequireDualStack'}, {'ipFamilies': ['IPv6']},
                       {'clusterIP': 'None'}, {'clusterIPs': ['not-an-address']}]:
            cli = FakePrepare(d)
            service = copy.deepcopy(canonical[0])
            service['metadata']['uid'] = 'FAKE-existing'
            service['spec'].update(fields)
            cli.objects[('Service', 'manaleague')] = service
            with self.subTest(fields=fields), self.assertRaises(ValueError):
                d.prepare(cli.config, cli, sleep=lambda _: None)
            self.assertFalse(any(c[:2] == ('kube', 'create') for c in cli.calls))
        cli = FakePrepare(d)
        original = cli.kube
        def kube(*args, payload=None):
            if args == ('get', 'services'):
                return {'items': [], 'metadata': {'continue': 'FAKE-more'}}
            return original(*args, payload=payload)
        cli.kube = kube
        with self.assertRaises(ValueError):
            d.prepare(cli.config, cli, sleep=lambda _: None)
        self.assertFalse(any(c[:2] == ('kube', 'create') for c in cli.calls))

    def test_provisioning_alb_is_waited_for_without_unbounded_retry(self):
        d = self.module()
        cli = FakePrepare(d)
        original = cli.aws
        polls = []
        def aws(service, operation, *args, payload=None):
            result = original(service, operation, *args, payload=payload)
            if operation == 'describe-load-balancers':
                polls.append(1)
                if len(polls) == 1:
                    result['LoadBalancers'][0]['State']['Code'] = 'provisioning'
            return result
        cli.aws = aws
        sleeps = []
        result = d.prepare(cli.config, cli, sleep=sleeps.append)
        self.assertTrue(result['ready_for_cd'])
        self.assertEqual(len(polls), 2)
        self.assertEqual(sleeps, [5])

    def test_ingress_hostname_change_after_alb_verification_refuses_dns(self):
        d = self.module()
        cli = FakePrepare(d)
        original = cli.kube
        reads = []
        def kube(*args, payload=None):
            result = original(*args, payload=payload)
            if args == ('get', 'Ingress', 'manaleague'):
                reads.append(1)
                if len(reads) >= 3:
                    result['status']['loadBalancer']['ingress'][0]['hostname'] = NAME + '-456.' + REGION + '.elb.amazonaws.com'
            return result
        cli.kube = kube
        with self.assertRaises(ValueError):
            d.prepare(cli.config, cli, sleep=lambda _: None)
        self.assertFalse(any(c[1] == 'change-resource-record-sets' for c in cli.calls))

    def test_ingress_uid_and_foreign_alb_fail_without_dns_write(self):
        d = self.module()
        for mutate in [lambda c: setattr(c, 'uid_race', True), lambda c: c.lb.update(VpcId='vpc-foreign'),
                       lambda c: c.lb.update(Scheme='internal'), lambda c: c.lb.update(SecurityGroups=['sg-foreign']),
                       lambda c: c.lb.update(LoadBalancerArn=c.lb['LoadBalancerArn'].replace(ACCOUNT, '999999999999'))]:
            cli = FakePrepare(d)
            mutate(cli)
            with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                d.prepare(cli.config, cli, sleep=lambda _: None)
            self.assertFalse(any(c[1] == 'change-resource-record-sets' for c in cli.calls))


class TransportTests(DomainCase):
    def test_main_dispatches_authorized_prepare_and_cleanup_only_after_validation(self):
        for action in ('prepare', 'cleanup'):
            d = self.module()
            d.dependencies()
            cli = FakePrepare(d)
            cli.close = lambda: None
            with self.subTest(action=action), mock.patch.object(Path, 'read_text', return_value=json.dumps(fixture())), \
                 mock.patch.object(d, 'Cli', return_value=cli) as factory, \
                 mock.patch.object(d, action, return_value={'action': action, 'application_verified': False}) as handler, \
                 mock.patch('sys.stdout', new=io.StringIO()):
                self.assertEqual(d.main(self.args(action)), 0)
                handler.assert_called_once()
                factory.assert_called_once()

    def test_transport_uses_fixed_profile_region_stdin_no_shell_and_sanitizes_failure(self):
        import subprocess
        d = self.module()
        cli = d.Cli(d.validate_outputs(fixture(), ACCOUNT, REGION))
        payload = {'HostedZoneId': ZONE}
        with mock.patch('subprocess.run', return_value=mock.Mock(stdout='{}')) as run:
            cli.aws('route53', 'list-resource-record-sets', payload=payload)
        args, kwargs = run.call_args
        self.assertNotIn('shell', kwargs)
        self.assertTrue(kwargs['check'])
        self.assertTrue(kwargs['capture_output'])
        self.assertEqual(json.loads(kwargs['input']), payload)
        argv = args[0]
        self.assertIn('--profile', argv)
        self.assertEqual(argv[argv.index('--profile') + 1], 'eks-lab')
        self.assertEqual(argv[argv.index('--region') + 1], REGION)
        self.assertNotIn('APP_KEY', kwargs['env'])
        with mock.patch('subprocess.run', side_effect=subprocess.CalledProcessError(1, ['FAKE'], stderr='SECRET-CANARY')):
            with self.assertRaises(ValueError) as raised:
                cli.aws('sts', 'get-caller-identity')
        self.assertNotIn('SECRET-CANARY', str(raised.exception))
        for service, operation in [('route53', 'delete-hosted-zone'), ('secretsmanager', 'get-secret-value')]:
            with mock.patch('subprocess.run', side_effect=AssertionError('must not spawn')), self.assertRaises(ValueError):
                cli.aws(service, operation)
        cli.close()

    def test_failure_reports_safe_stage_without_echoing_provider_data(self):
        d = self.module()
        data = fixture()
        d.dependencies()
        cli = FakePrepare(d)
        cli.close = lambda: None
        def fail(config, *args, **kwargs):
            config['phase'] = 'frontend-create-readback'
            raise ValueError('SECRET-CANARY raw provider output')
        with mock.patch.object(Path, 'read_text', return_value=json.dumps(data)), \
             mock.patch.object(d, 'Cli', return_value=cli), mock.patch.object(d, 'prepare', side_effect=fail), \
             mock.patch('sys.stderr', new=io.StringIO()) as error:
            with self.assertRaises(SystemExit):
                d.main(self.args('prepare'))
        self.assertIn('frontend-create-readback', error.getvalue())
        self.assertIn('no automatic rollback', error.getvalue())
        self.assertNotIn('SECRET-CANARY', error.getvalue())

    def test_unauthorized_or_invalid_input_never_constructs_transport(self):
        d = self.module()
        data = fixture()
        data['lab_hostname']['value'] = 'www.manaleague.com.br'
        d.dependencies()
        with mock.patch.object(Path, 'read_text', return_value=json.dumps(data)), \
             mock.patch.object(d, 'Cli', side_effect=AssertionError('transport')), \
             mock.patch('sys.stderr', new=io.StringIO()):
            with self.assertRaises(SystemExit):
                d.main(self.args('prepare'))


if __name__ == '__main__':
    unittest.main()
