"""Offline contract for rebinding a recreated lab, never production."""
import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
MODULE = ROOT / 'infra/eks-lab/scripts/github_bindings.py'
NAME = 'manaleague-eks-lab'
ACCOUNT = '123456789012'
REGION = 'us-east-1'


def fixture():
    values = {
        'lab_identity': {'name': NAME, 'namespace': NAME, 'region': REGION, 'account_id': ACCOUNT},
        'account_id': ACCOUNT, 'aws_region': REGION, 'cluster_name': NAME,
        'github_deploy_role_arn': f'arn:aws:iam::{ACCOUNT}:role/{NAME}-github-deploy',
        'ecr_app_repository_url': f'{ACCOUNT}.dkr.ecr.{REGION}.amazonaws.com/{NAME}-app',
        'ecr_web_repository_url': f'{ACCOUNT}.dkr.ecr.{REGION}.amazonaws.com/{NAME}-web',
        'avatar_bucket_name': f'{NAME}-{ACCOUNT}-{REGION}-avatars',
        'app_role_arn': f'arn:aws:iam::{ACCOUNT}:role/{NAME}-app',
        'vpc_cidr': '10.73.0.0/16', 'vpc_id': 'vpc-0123456789abcdef0',
        'rds_endpoint': f'{NAME}.abcdefghijkl.{REGION}.rds.amazonaws.com',
        'rds_master_secret_arn': f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:rds!db-12345678-1234-1234-1234-123456789012-AbCdEf',
        'rds_identifier': NAME, 'rds_database': 'manaleague_eks_lab',
        'subnet_ids': ['subnet-0123456789abcdef0', 'subnet-1123456789abcdef0'],
        'private_subnet_ids': ['subnet-2123456789abcdef0', 'subnet-3123456789abcdef0'],
        'node_subnet_ids': ['subnet-2123456789abcdef0'],
        'nat_gateway_id': 'nat-0123456789abcdef0',
        'nat_public_ip': '8.8.8.8', 'nat_egress_cidr': '8.8.8.8/32',
        'allowed_client_cidrs': ['1.1.1.1/32', '8.8.8.8/32'],
        'alb_security_group_id': 'sg-0123456789abcdef0',
        'lab_https_enabled': False,
        'lab_hostname': 'eks.manaleague.com.br',
        'lab_url': '', 'lab_certificate_arn': '', 'lab_dns_zone_id': '',
        'lab_dns_name_servers': [],
        'lab_mail_enabled': False, 'lab_mail_from': '',
    }
    return {k: {'value': v, 'sensitive': False} for k, v in values.items()}


class GithubBindings(unittest.TestCase):
    def load(self):
        self.assertTrue(MODULE.exists(), 'Missing offline renderer for fresh lab Environment bindings')
        spec = importlib.util.spec_from_file_location('lab_github_bindings', MODULE)
        assert spec is not None and spec.loader is not None
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_fresh_outputs_render_only_lab_variables_and_force_gate_closed(self):
        module = self.load()
        data = fixture()
        data['APP_URL'] = {'value': 'https://manaleague.com.br', 'sensitive': False}
        data['LAB_DEPLOY_ENABLED'] = {'value': 'true', 'sensitive': False}
        result = module.render(data, ACCOUNT, REGION)
        self.assertEqual(result['repository'], 'viniciusVitorio/manaleague')
        self.assertEqual(result['environment'], 'eks-lab')
        variables = {item['name']: item['value'] for item in result['variables']}
        self.assertEqual(variables['LAB_DEPLOY_ENABLED'], 'false')
        self.assertEqual(variables['APP_URL'], '')
        self.assertEqual(variables['EKS_CLUSTER_NAME'], NAME)
        self.assertEqual(variables['SUBNET_IDS'], 'subnet-0123456789abcdef0,subnet-1123456789abcdef0')
        self.assertNotIn('DB_PASSWORD', variables)
        self.assertNotIn('APP_KEY', variables)


    def test_foreign_missing_sensitive_or_unsafe_outputs_fail_closed(self):
        module = self.load()
        import copy
        bad = [
            ('cluster_name', 'production'), ('account_id', '999999999999'),
            ('aws_region', 'us-west-2'), ('lab_identity', {}),
            ('github_deploy_role_arn', f'arn:aws:iam::{ACCOUNT}:role/AdministratorAccess'),
            ('ecr_app_repository_url', f'{ACCOUNT}.dkr.ecr.{REGION}.amazonaws.com/production'),
            ('ecr_web_repository_url', 'https://example.com/repository'),
            ('avatar_bucket_name', 'production-avatars'),
            ('app_role_arn', f'arn:aws:iam::{ACCOUNT}:role/production-app'),
            ('rds_identifier', 'production'), ('rds_database', 'manaleague'),
            ('rds_endpoint', 'production.rds.amazonaws.com'),
            ('rds_master_secret_arn', f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:production'),
            ('vpc_id', 'vpc-not-an-id'), ('vpc_cidr', '0.0.0.0/0'),
            ('subnet_ids', []), ('subnet_ids', ['subnet-0123456789abcdef0'] * 2),
            ('subnet_ids', ['subnet-0123456789abcdef0', 'bad']),
            ('allowed_client_cidrs', ['0.0.0.0/0']),
            ('allowed_client_cidrs', ['1.1.1.1/32\nMALICIOUS=true']),
            ('alb_security_group_id', 'sg-production'),
        ]
        for key, value in bad:
            with self.subTest(key=key, value=value):
                data = copy.deepcopy(fixture())
                data[key]['value'] = value
                with self.assertRaises(ValueError):
                    module.render(data, ACCOUNT, REGION)
        for key in fixture():
            with self.subTest(missing=key):
                data = fixture()
                del data[key]
                with self.assertRaises(ValueError):
                    module.render(data, ACCOUNT, REGION)
        data = fixture()
        data['rds_master_secret_arn']['sensitive'] = True
        with self.assertRaises(ValueError):
            module.render(data, ACCOUNT, REGION)
        for account in ('123', '0123456789012', '123456789012\n', 123456789012):
            with self.subTest(account=account), self.assertRaises(ValueError):
                module.render(fixture(), account, REGION)


    def test_private_nat_contract_is_required_without_adding_environment_variables(self):
        module = self.load()
        data = fixture()
        result = module.render(data, ACCOUNT, REGION)
        self.assertEqual(len(result['variables']), 19)
        for key in ('private_subnet_ids', 'node_subnet_ids', 'nat_gateway_id', 'nat_public_ip', 'nat_egress_cidr'):
            missing = dict(data)
            del missing[key]
            with self.subTest(missing=key), self.assertRaises(ValueError):
                module.render(missing, ACCOUNT, REGION)

    def test_rejects_public_nodes_foreign_nat_or_mismatched_egress(self):
        module = self.load()
        bad = [
            ('private_subnet_ids', fixture()['subnet_ids']['value']),
            ('private_subnet_ids', ['subnet-2123456789abcdef0'] * 2),
            ('private_subnet_ids', ['subnet-2123456789abcdef0', 'bad']),
            ('node_subnet_ids', fixture()['subnet_ids']['value']),
            ('node_subnet_ids', ['subnet-4123456789abcdef0']),
            ('node_subnet_ids', []), ('node_subnet_ids', ['subnet-2123456789abcdef0'] * 2),
            ('nat_gateway_id', 'nat-foreign'), ('nat_gateway_id', None),
            ('nat_public_ip', '10.73.1.2'), ('nat_public_ip', '192.0.2.1'),
            ('nat_public_ip', '224.0.0.1'),
            ('nat_public_ip', '2606:4700:4700::1111'), ('nat_public_ip', '8.8.8.8\n'),
            ('nat_egress_cidr', '1.1.1.1/32'), ('nat_egress_cidr', '8.8.8.0/24'),
            ('allowed_client_cidrs', ['1.1.1.1/32']),
        ]
        for key, value in bad:
            data = fixture()
            data[key]['value'] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                module.render(data, ACCOUNT, REGION)
        from unittest import mock
        with mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('socket.socket', side_effect=AssertionError('network')), \
             mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('credentials')):
            module.render(fixture(), ACCOUNT, REGION)
        data = fixture()
        data['node_subnet_ids']['value'] = data['private_subnet_ids']['value']
        self.assertEqual(len(module.render(data, ACCOUNT, REGION)['variables']), 19)

    def test_multicast_is_not_a_public_nat_eip_even_if_ipaddress_marks_it_global(self):
        data = fixture()
        data['nat_public_ip']['value'] = '224.0.0.1'
        data['nat_egress_cidr']['value'] = '224.0.0.1/32'
        data['allowed_client_cidrs']['value'].append('224.0.0.1/32')
        with self.assertRaises(ValueError):
            self.load().render(data, ACCOUNT, REGION)

    def test_https_outputs_render_only_exact_lab_url_and_certificate_with_gate_closed(self):
        data = fixture()
        cert = f'arn:aws:acm:{REGION}:{ACCOUNT}:certificate/12345678-1234-1234-1234-123456789abc'
        tls = {'lab_https_enabled': True, 'lab_hostname': 'eks.manaleague.com.br',
               'lab_url': 'https://eks.manaleague.com.br', 'lab_certificate_arn': cert,
               'lab_dns_zone_id': 'ZABCDEFGHIJKL',
               'lab_dns_name_servers': ['ns-605.awsdns-11.net', 'ns-16.awsdns-02.com',
                   'ns-1514.awsdns-61.org', 'ns-1580.awsdns-05.co.uk']}
        data.update({k: {'value': v, 'sensitive': False} for k, v in tls.items()})
        result = self.load().render(data, ACCOUNT, REGION)
        values = {row['name']: row['value'] for row in result['variables']}
        self.assertEqual(values['APP_URL'], tls['lab_url'])
        self.assertEqual(values['ACM_CERTIFICATE_ARN'], cert)
        self.assertEqual(values['LAB_DEPLOY_ENABLED'], 'false')
        self.assertEqual(len(values), 19)
        self.assertNotIn('ROUTE53_ZONE_ID', values)
        self.assertNotIn('lab_dns_name_servers', values)

    def test_mail_renders_only_the_exact_lab_sender_and_stays_empty_when_disabled(self):
        module = self.load()
        disabled = {row['name']: row['value'] for row in module.render(fixture(), ACCOUNT, REGION)['variables']}
        self.assertEqual(disabled['MAIL_FROM_ADDRESS'], '')
        enabled = fixture()
        enabled.update({k: {'value': v, 'sensitive': False} for k, v in
                        {'lab_mail_enabled': True, 'lab_mail_from': 'eks-lab@manaleague.com.br'}.items()})
        values = {row['name']: row['value'] for row in module.render(enabled, ACCOUNT, REGION)['variables']}
        self.assertEqual(values['MAIL_FROM_ADDRESS'], 'eks-lab@manaleague.com.br')
        # Never the production sender, never a foreign domain, never a mismatch
        # between the enabled flag and the rendered address.
        for broken in ({'lab_mail_enabled': True, 'lab_mail_from': ''},
                       {'lab_mail_enabled': False, 'lab_mail_from': 'eks-lab@manaleague.com.br'},
                       {'lab_mail_enabled': True, 'lab_mail_from': 'contato@manaleague.com.br'},
                       {'lab_mail_enabled': True, 'lab_mail_from': 'eks-lab@evil.com'},
                       {'lab_mail_enabled': True, 'lab_mail_from': 'eks-lab@manaleague.com.br\n'},
                       {'lab_mail_enabled': 'true', 'lab_mail_from': 'eks-lab@manaleague.com.br'}):
            data = fixture()
            data.update({k: {'value': v, 'sensitive': False} for k, v in broken.items()})
            with self.subTest(broken=broken), self.assertRaises(ValueError):
                module.render(data, ACCOUNT, REGION)

    def test_https_metadata_rejects_foreign_and_inconsistent_domains_without_echoing_values(self):
        import copy
        valid = fixture()
        values = {'lab_https_enabled': True, 'lab_hostname': 'eks.manaleague.com.br',
            'lab_url': 'https://eks.manaleague.com.br',
            'lab_certificate_arn': f'arn:aws:acm:{REGION}:{ACCOUNT}:certificate/12345678-1234-1234-1234-123456789abc',
            'lab_dns_zone_id': 'ZABCDEFGHIJKL',
            'lab_dns_name_servers': ['ns-605.awsdns-11.net', 'ns-16.awsdns-02.com',
                'ns-1514.awsdns-61.org', 'ns-1580.awsdns-05.co.uk']}
        valid.update({k: {'value': v, 'sensitive': False} for k, v in values.items()})
        for key, value in [('lab_https_enabled', 'true'), ('lab_https_enabled', 1),
                ('lab_hostname', 'manaleague.com.br'), ('lab_hostname', '*.manaleague.com.br'),
                ('lab_url', 'http://eks.manaleague.com.br'), ('lab_url', 'https://www.manaleague.com.br'),
                ('lab_url', 'https://eks.manaleague.com.br/'), ('lab_url', 'https://eks.manaleague.com.br.evil.test'),
                ('lab_certificate_arn', values['lab_certificate_arn'].replace(ACCOUNT, '999999999999')),
                ('lab_certificate_arn', values['lab_certificate_arn'].replace(REGION, 'us-west-2')),
                ('lab_certificate_arn', 'arn:aws:acm:us-east-1:123456789012:certificate/*'),
                ('lab_certificate_arn', values['lab_certificate_arn'] + ',another'),
                ('lab_dns_zone_id', ''), ('lab_dns_zone_id', '/hostedzone/ZABCDEFGHIJKL'),
                ('lab_dns_zone_id', 'zabcdefghijk'), ('lab_dns_name_servers', []),
                ('lab_dns_name_servers', ['foreign.invalid'] * 4),
                ('lab_dns_name_servers', values['lab_dns_name_servers'] + [values['lab_dns_name_servers'][0]]),
                ('lab_dns_name_servers', [None] * 4)]:
            data = copy.deepcopy(valid)
            data[key]['value'] = value
            with self.subTest(field=key, value=value), self.assertRaises(ValueError):
                self.load().render(data, ACCOUNT, REGION)
        for key, value in [('lab_url', values['lab_url']),
                           ('lab_certificate_arn', values['lab_certificate_arn']),
                           ('lab_dns_zone_id', values['lab_dns_zone_id']),
                           ('lab_dns_name_servers', values['lab_dns_name_servers'])]:
            data = fixture()
            data[key]['value'] = value
            with self.subTest(disabled_with=key), self.assertRaises(ValueError):
                self.load().render(data, ACCOUNT, REGION)

    def test_cli_refuses_duplicate_json_without_echoing_input(self):
        import json
        import subprocess
        import sys
        import tempfile
        with tempfile.TemporaryDirectory(prefix='eks-bindings-') as directory:
            path = pathlib.Path(directory) / 'outputs.json'
            text = json.dumps(fixture())
            path.write_text('{"account_id":{"value":"secret-canary-not-for-logs","sensitive":false},' + text[1:])
            result = subprocess.run([sys.executable, '-I', '-B', str(MODULE), '--input', str(path),
                                     '--expected-account', ACCOUNT, '--region', REGION],
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 2, result.stdout)
            self.assertEqual(result.stdout, '')
            self.assertNotIn('secret-canary', result.stderr)
            self.assertNotIn('Traceback', result.stderr)


if __name__ == '__main__':
    unittest.main()
