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
        'allowed_client_cidrs': ['1.1.1.1/32'],
        'alb_security_group_id': 'sg-0123456789abcdef0',
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
