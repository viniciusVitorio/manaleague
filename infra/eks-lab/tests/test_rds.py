"""RDS boundary tests: all remote operations are explicit offline fakes."""
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ACCOUNT = '123456789012'
REGION = 'us-east-1'
NAME = 'manaleague-eks-lab'
HOST = NAME + '.abcdefghijkl.us-east-1.rds.amazonaws.com'
SECRET = f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:rds!db-00000000-0000-0000-0000-000000000000-AbCd12'
ENV = {'DB_HOST': HOST, 'VPC_ID': 'vpc-abc', 'RDS_MASTER_SECRET_ARN': SECRET}
INSTANCE = {'DBInstanceIdentifier': NAME, 'DBInstanceArn': f'arn:aws:rds:{REGION}:{ACCOUNT}:db:{NAME}', 'DBName': 'manaleague_eks_lab', 'MasterUsername': 'manaleague_lab', 'Engine': 'postgres', 'EngineVersion': '17.6', 'Endpoint': {'Address': HOST, 'Port': 5432}, 'DBSubnetGroup': {'VpcId': 'vpc-abc'}, 'PubliclyAccessible': False, 'StorageEncrypted': True, 'DBInstanceStatus': 'available', 'MasterUserSecret': {'SecretArn': SECRET, 'SecretStatus': 'active'}}

class RdsTests(unittest.TestCase):
    def setUp(self):
        deny = patch('subprocess.run', side_effect=AssertionError('Unexpected external command'))
        deny.start()
        self.addCleanup(deny.stop)
        path = Path(__file__).resolve().parents[1] / 'scripts/lab.py'
        spec = importlib.util.spec_from_file_location('lab_rds', path)
        assert spec and spec.loader
        self.lab = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.lab)

    def fake(self, instance=None, credential=None):
        def command(args, **kwargs):
            if args == ['aws', 'rds', 'describe-db-instances', '--db-instance-identifier', NAME]:
                return json.dumps({'DBInstances': [INSTANCE if instance is None else instance]})
            if args == ['aws', 'secretsmanager', 'get-secret-value', '--secret-id', SECRET, '--version-stage', 'AWSCURRENT']:
                return json.dumps({'ARN': SECRET, 'VersionStages': ['AWSCURRENT'], 'SecretString': json.dumps({'username': 'manaleague_lab', 'password': 'r' * 32} if credential is None else credential)})
            self.fail('Unexpected command: ' + str(args))
        return command

    def test_valid_private_instance_reads_only_exact_current_managed_secret(self):
        lab = self.lab
        with patch.dict(os.environ, ENV, clear=True), patch.object(lab, 'run', side_effect=self.fake()) as commands:
            self.assertEqual(lab.rds_password(ACCOUNT, REGION), 'r' * 32)
        self.assertEqual(commands.call_count, 2)
        self.assertNotIn('r' * 32, str(commands.call_args_list))

    def test_wrong_instance_binding_refused_before_secret_read(self):
        mutations = [('DBInstanceIdentifier', 'production'), ('DBInstanceArn', f'arn:aws:rds:{REGION}:999999999999:db:{NAME}'), ('DBName', 'production'), ('MasterUsername', 'postgres'), ('PubliclyAccessible', True), ('StorageEncrypted', False), ('DBInstanceStatus', 'creating'), ('Engine', 'mysql'), ('EngineVersion', '16.9'), ('Endpoint', {'Address': 'production.example.test', 'Port': 5432}), ('Endpoint', {'Address': HOST, 'Port': 5433}), ('DBSubnetGroup', {'VpcId': 'vpc-foreign'}), ('MasterUserSecret', {'SecretArn': SECRET.replace('AbCd12', 'Other1'), 'SecretStatus': 'active'})]
        for key, value in mutations:
            instance = copy.deepcopy(INSTANCE)
            instance[key] = value
            with self.subTest(key=key, value=value), patch.dict(os.environ, ENV, clear=True), patch.object(self.lab, 'run', side_effect=self.fake(instance)) as commands:
                with self.assertRaises(ValueError):
                    self.lab.rds_password(ACCOUNT, REGION)
                self.assertEqual(commands.call_count, 1)

    def test_invalid_host_or_foreign_secret_rejected_before_remote_read(self):
        for key, value in [('DB_HOST', 'production.example.test'), ('DB_HOST', HOST + ':5432'), ('DB_HOST', HOST.replace('us-east-1', 'us-west-2')), ('RDS_MASTER_SECRET_ARN', SECRET.replace(ACCOUNT, '999999999999')), ('RDS_MASTER_SECRET_ARN', SECRET.replace('rds!db-', 'production-')), ('VPC_ID', 'production')]:
            with self.subTest(key=key), patch.dict(os.environ, {**ENV, key: value}, clear=True), patch.object(self.lab, 'run') as commands:
                with self.assertRaises(ValueError):
                    self.lab.rds_password(ACCOUNT, REGION)
                commands.assert_not_called()

    def test_invalid_secret_never_leaks_password_in_error(self):
        for credential in [{'username': 'production', 'password': 'sensitive-value' * 3}, {'username': 'manaleague_lab', 'password': 'short'}, {'username': 'manaleague_lab', 'password': ['sensitive-value']}, {'username': 'manaleague_lab', 'password': 'sensitive-value\n' * 3}]:
            with self.subTest(credential_type=type(credential['password']).__name__), patch.dict(os.environ, ENV, clear=True), patch.object(self.lab, 'run', side_effect=self.fake(credential=credential)):
                with self.assertRaises(ValueError) as error:
                    self.lab.rds_password(ACCOUNT, REGION)
                self.assertNotIn('sensitive-value', str(error.exception))

    def test_manifest_refuses_in_cluster_database_and_unverified_tls(self):
        lab = self.lab
        config = {'kind': 'ConfigMap', 'metadata': {'name': 'manaleague-config', 'namespace': NAME}, 'data': {'APP_ENV': 'eks-lab', 'DB_HOST': HOST, 'DB_DATABASE': 'manaleague_eks_lab', 'DB_USERNAME': 'manaleague_lab', 'DB_PORT': '5432', 'LAB_DATABASE_MODE': 'rds', 'DB_SSLMODE': 'verify-full', 'PGSSLROOTCERT': '/var/www/html/infra/eks-lab/runtime/certs/global-bundle.pem'}}
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {**ENV, 'AWS_REGION': REGION}, clear=True):
            base = Path(directory)
            (base / 'config.yaml').write_text(json.dumps(config))
            lab.manifest_guard(base, HOST, REGION)
            for kind in ('StatefulSet', 'PersistentVolumeClaim'):
                (base / 'db.yaml').write_text(json.dumps({'kind': kind, 'metadata': {'name': 'postgres', 'namespace': NAME}}))
                with self.assertRaises(ValueError):
                    lab.manifest_guard(base, HOST, REGION)
            (base / 'db.yaml').unlink()
            for key, value in [('DB_SSLMODE', 'require'), ('LAB_DATABASE_MODE', 'local'), ('DB_HOST', 'postgres'), ('DB_USERNAME', 'postgres'), ('DB_PORT', '5433'), ('PGSSLROOTCERT', '')]:
                unsafe = copy.deepcopy(config)
                unsafe['data'][key] = value
                (base / 'config.yaml').write_text(json.dumps(unsafe))
                with self.subTest(key=key), self.assertRaises(ValueError):
                    lab.manifest_guard(base, HOST, REGION)

    def test_cleanup_rejects_any_statefulset_or_pvc_before_first_write(self):
        lab = self.lab
        for kind in ('statefulset', 'pvc'):
            def command(args, **kwargs):
                if 'get' in args:
                    target = args[args.index('get') + 1]
                    return json.dumps({'items': [{'metadata': {'name': 'postgres', 'namespace': NAME, 'uid': 'uid'}}] if target == kind else []})
                if args[:3] == ['aws', 'elbv2', 'describe-load-balancers']:
                    return json.dumps({'LoadBalancers': []})
                if args[:3] == ['aws', 'ec2', 'describe-volumes']:
                    return json.dumps({'Volumes': []})
                self.fail('Write before rejection: ' + str(args))
            with self.subTest(kind=kind), patch.object(lab, 'kube_config', return_value=['kubectl']), patch.object(lab, 'run', side_effect=command):
                with self.assertRaisesRegex(ValueError, 'StatefulSet|PVC'):
                    lab.cleanup_runtime(ACCOUNT, REGION)

    def test_destroy_verifies_terraform_rds_outputs_and_live_ownership_without_secret_read(self):
        outputs = {'account_id': ACCOUNT, 'aws_region': REGION, 'cluster_name': NAME, 'namespace': NAME, 'vpc_id': ENV['VPC_ID'], 'rds_identifier': NAME, 'rds_endpoint': HOST, 'rds_master_secret_arn': SECRET, 'rds_port': 5432, 'rds_database': 'manaleague_eks_lab', 'rds_username': 'manaleague_lab'}
        with patch.object(self.lab, 'tf', return_value=json.dumps({k: {'value': v} for k, v in outputs.items()})), patch.object(self.lab, 'run', side_effect=self.fake()) as commands:
            self.lab.rds_destroy_guard(ACCOUNT, REGION)
            self.assertEqual(commands.call_count, 1)
        outputs['rds_identifier'] = 'production'
        with patch.object(self.lab, 'tf', return_value=json.dumps({k: {'value': v} for k, v in outputs.items()})), patch.object(self.lab, 'run') as commands:
            with self.assertRaises(ValueError):
                self.lab.rds_destroy_guard(ACCOUNT, REGION)
            commands.assert_not_called()

    def test_destroy_checks_rds_before_cleanup_and_again_before_apply(self):
        lab = self.lab
        order = []
        with patch.object(lab, 'cloud_guard', return_value=(ACCOUNT, REGION)), patch.object(lab, 'verify_plan'), patch.object(lab, 'storage_guard'), patch.object(lab, 'rds_destroy_guard', side_effect=lambda *a: order.append('rds')), patch.object(lab, 'cleanup_runtime', side_effect=lambda *a: order.append('cleanup')), patch.object(lab, 'terraform_action', side_effect=lambda *a: order.append('apply')):
            lab.main(['destroy', '--allow-cloud', '--confirm-lab', NAME, '--expected-account', ACCOUNT, '--delete-lab-data'])
        self.assertEqual(order, ['rds', 'cleanup', 'rds', 'apply'])

    def test_deploy_refuses_existing_database_workloads_before_first_write(self):
        import base64
        lab = self.lab
        values = {**ENV, 'AWS_REGION': REGION, 'APP_URL': 'http://lab.example.test', 'AVATAR_BUCKET': NAME + '-test', 'VPC_CIDR': '10.73.0.0/16', 'APP_ROLE_ARN': f'arn:aws:iam::{ACCOUNT}:role/{NAME}-app', 'ALLOWED_CIDRS': '192.0.2.1/32', 'SUBNET_IDS': 'subnet-abc', 'ALB_SECURITY_GROUP_ID': 'sg-abc'}
        env = {**values, 'APP_KEY': 'base64:' + base64.b64encode(b'x' * 32).decode(), 'DEMO_PASSWORD': 'd' * 32, 'RELEASE': 'f' * 40, **{f'{target.upper()}_IMAGE': f'{ACCOUNT}.dkr.ecr.{REGION}.amazonaws.com/{NAME}-{target}@sha256:' + 'a' * 64 for target in ('app', 'web')}}
        for kind in ('statefulset', 'pvc'):
            def command(args, **kwargs):
                if args[:2] == ['kubectl', 'kustomize']:
                    return 'kind: ConfigMap\n'
                if 'get' in args and args[args.index('get') + 1] in ('statefulset', 'pvc'):
                    return json.dumps({'items': [{}] if args[args.index('get') + 1] == kind else []})
                self.fail('Write or unrelated command before database preflight: ' + str(args))
            with self.subTest(kind=kind), patch.dict(os.environ, env, clear=True), patch.object(lab, 'rds_password', return_value='r' * 32), patch.object(lab, 'kube_config', return_value=['kubectl']), patch.object(lab, 'run', side_effect=command):
                with self.assertRaisesRegex(ValueError, 'StatefulSet|PVC'):
                    lab.deploy(ACCOUNT, REGION)

    def test_destroy_plan_metadata_pins_rds_loss_target(self):
        lab = self.lab
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            plan = target / 'terraform/.state/destroy.tfplan'
            def terraform(*args, **kwargs):
                if args[0] == 'plan':
                    plan.write_bytes(b'explicit-offline-plan-fixture')
                return ''
            with patch.object(lab, 'LAB', target), patch.object(lab, 'tf', side_effect=terraform), contextlib.redirect_stdout(io.StringIO()):
                lab.terraform_action('destroy-plan', ACCOUNT, REGION)
            metadata = json.loads(Path(str(plan) + '.json').read_text())
            self.assertEqual(metadata['rds_identifier'], NAME)
            lab.verify_plan(plan, ACCOUNT, REGION, True)
            metadata['rds_identifier'] = 'production'
            Path(str(plan) + '.json').write_text(json.dumps(metadata))
            with self.assertRaises(ValueError):
                lab.verify_plan(plan, ACCOUNT, REGION, True)

    def test_service_for_postgres_is_not_a_valid_rds_base_resource(self):
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, 'postgres.yaml').write_text(json.dumps({'kind': 'Service', 'metadata': {'name': 'postgres', 'namespace': NAME}}))
            with self.assertRaisesRegex(ValueError, 'database|PostgreSQL'):
                self.lab.manifest_guard(Path(directory), HOST, REGION)

    def test_secret_response_errors_do_not_echo_aws_stderr_or_json_excerpt(self):
        import subprocess
        for response in ('[]', 'null', json.dumps({'ARN': SECRET, 'VersionStages': ['AWSCURRENT'], 'SecretString': '[]'}), '{"SecretString": "sensitive-value"}', '{"SecretString":"sensitive-value", "ARN":'):
            def command(args, **kwargs):
                return json.dumps({'DBInstances': [INSTANCE]}) if args[1] == 'rds' else response
            with patch.dict(os.environ, ENV, clear=True), patch.object(self.lab, 'run', side_effect=command):
                with self.assertRaises(ValueError) as error:
                    self.lab.rds_password(ACCOUNT, REGION)
                self.assertNotIn('sensitive-value', str(error.exception))
        def failed(args, **kwargs):
            if args[1] == 'rds':
                return json.dumps({'DBInstances': [INSTANCE]})
            raise subprocess.CalledProcessError(1, args, stderr='sensitive-value')
        with patch.dict(os.environ, ENV, clear=True), patch.object(self.lab, 'run', side_effect=failed):
            with self.assertRaises(ValueError) as error:
                self.lab.rds_password(ACCOUNT, REGION)
            self.assertNotIn('sensitive-value', str(error.exception))

    def test_workflow_requires_rds_bindings_not_github_database_password(self):
        workflow = (Path(__file__).resolve().parents[3] / '.github/workflows/eks-lab.yml').read_text()
        self.assertNotIn('secrets.DB_PASSWORD', workflow)
        for name in ENV:
            self.assertIn('${{ vars.' + name + ' }}', workflow)
        self.assertNotIn('terraform apply', workflow)

if __name__ == '__main__':
    unittest.main()
