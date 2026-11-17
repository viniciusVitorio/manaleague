"""Offline safety tests. subprocess mocks are EXPLICIT; never contact AWS/Kubernetes."""
import importlib.util
import json
import contextlib
import io
import os
from pathlib import Path
import unittest
from unittest.mock import patch
from test_rds import ENV as RDS_ENV, INSTANCE as RDS_INSTANCE, SECRET as RDS_SECRET

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / 'infra/eks-lab/scripts/lab.py'

class SafetyTests(unittest.TestCase):
    def setUp(self):
        # Defense in depth: an accidentally unmocked command must fail, never reach the OS.
        deny = patch('subprocess.run', side_effect=AssertionError('Unexpected external command: add an EXPLICIT mock'))
        deny.start()
        self.addCleanup(deny.stop)

    def load(self):
        self.assertTrue(SCRIPT.exists(), 'safe operational controller is missing')
        spec = importlib.util.spec_from_file_location('lab_ops', SCRIPT)
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_cloud_actions_require_explicit_consent_before_any_command(self):
        lab = self.load()
        for verb in ('plan', 'apply', 'bootstrap', 'publish', 'deploy', 'destroy-plan', 'destroy'):
            with self.subTest(verb=verb), patch.object(lab, 'run') as explicit_mock, contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises((SystemExit, ValueError)):
                    lab.main([verb])
                explicit_mock.assert_not_called()

class GuardTests(SafetyTests):
    def test_invalid_target_rejected_before_external_command(self):
        lab = self.load()
        for env in ({}, {'AWS_REGION': 'us-east-1', 'AWS_ACCOUNT_ID': '123456789012', 'EKS_CLUSTER_NAME': 'production'}):
            with patch.dict(os.environ, env, clear=True), patch.object(lab, 'run') as explicit_mock:
                with self.assertRaises(ValueError):
                    lab.cloud_guard('123456789012')
                explicit_mock.assert_not_called()

    def test_wrong_account_rejected(self):
        lab = self.load()
        env = {'AWS_REGION': 'us-east-1', 'AWS_ACCOUNT_ID': '123456789012', 'EKS_CLUSTER_NAME': 'manaleague-eks-lab'}
        with patch.dict(os.environ, env, clear=True), patch.object(lab, 'run', return_value='999999999999'):
            with self.assertRaisesRegex(ValueError, 'account'):
                lab.cloud_guard('123456789012')

    def test_branch_guard_blocks_pr_and_main(self):
        lab = self.load()
        for ref, event in [('refs/heads/main', 'push'), ('refs/pull/1/merge', 'pull_request')]:
            with patch.dict(os.environ, {'GITHUB_ACTIONS': 'true', 'GITHUB_REF': ref, 'GITHUB_EVENT_NAME': event}, clear=True):
                with self.assertRaises(ValueError):
                    lab.workflow_guard()

    def test_existing_image_retry_reuses_verified_registry_image_without_push(self):
        lab = self.load()
        repository = '123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-app'
        digest = 'sha256:' + 'a' * 64
        def explicit_mock_command(command, **kwargs):
            if command[:3] == ['aws', 'ecr', 'describe-repositories']:
                return json.dumps({'repositories': [{'repositoryUri': repository, 'imageTagMutability': 'IMMUTABLE'}]})
            if command[:3] == ['aws', 'ecr', 'describe-images']:
                return json.dumps({'imageDetails': [{'imageDigest': digest}]})
            if command[:3] == ['docker', 'image', 'inspect']:
                return 'sha256:' + 'b' * 64
            return ''
        with patch.object(lab, 'run', side_effect=explicit_mock_command) as commands:
            self.assertEqual(lab.publish_one('lab-app:dev', repository, 'f' * 40), repository + '@' + digest)
            self.assertFalse(any(c.args[0][:2] == ['docker', 'push'] for c in commands.call_args_list))

    def test_mutable_or_foreign_repository_rejected(self):
        lab = self.load()
        repository = '123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-app'
        with patch.object(lab, 'run', return_value=json.dumps({'repositories': [{'repositoryUri': repository, 'imageTagMutability': 'MUTABLE'}]})):
            with self.assertRaises(ValueError):
                lab.publish_one('lab-app:dev', repository, 'f' * 40)
        with self.assertRaises(ValueError):
            lab.image_guard('123456789012.dkr.ecr.us-east-1.amazonaws.com/production@sha256:' + 'a' * 64, '123456789012', 'us-east-1', 'app')

    def test_wrong_cluster_arn_and_endpoint_fail_closed(self):
        lab = self.load()
        description = {'cluster': {'arn': 'arn:aws:eks:us-east-1:123456789012:cluster/production', 'status': 'ACTIVE', 'endpoint': 'https://example', 'certificateAuthority': {'data': 'abc'}}}
        with patch.object(lab, 'run', return_value=json.dumps(description)):
            with self.assertRaises(ValueError):
                lab.kube_config('123456789012', 'us-east-1', Path('unused-kubeconfig'))

    def test_validate_is_backend_disabled_and_uses_isolated_data_dir(self):
        lab = self.load()
        with patch.object(lab, 'run', return_value='') as commands, contextlib.redirect_stdout(io.StringIO()):
            lab.validate()
            init = [c for c in commands.call_args_list if 'init' in c.args[0]][0]
            self.assertIn('-backend=false', init.args[0])
            self.assertIn('-input=false', init.args[0])
            self.assertNotEqual(init.kwargs['env']['TF_DATA_DIR'], str(lab.LAB / 'terraform/.terraform'))
            self.assertFalse(any('apply' in c.args[0] or 'destroy' in c.args[0] for c in commands.call_args_list))

    def test_tampered_plan_rejected_before_terraform(self):
        lab = self.load()
        import tempfile
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'plan'
            path.write_bytes(b'tampered')
            Path(str(path) + '.json').write_text(json.dumps({'account': '123456789012', 'region': 'us-east-1', 'sha256': 'bad', 'action': 'plan'}))
            with patch.object(lab, 'run') as commands:
                with self.assertRaises(ValueError):
                    lab.verify_plan(path, '123456789012', 'us-east-1', False)
                commands.assert_not_called()

    def test_renderer_accepts_quoted_json_yaml_tokens_without_double_quotes(self):
        lab = self.load()
        self.assertEqual(json.loads(lab.replace_tokens('{"url":"__APP_URL__"}', {'APP_URL': 'http://lab/"test'})), {'url': 'http://lab/"test'})

    def test_rendered_app_config_refuses_production_database_before_kubectl(self):
        lab = self.load()
        import tempfile
        unsafe = {'kind': 'ConfigMap', 'metadata': {'name': 'manaleague-config', 'namespace': lab.NAME}, 'data': {'APP_ENV': 'eks-lab', 'DB_HOST': 'postgres', 'DB_DATABASE': 'manaleague'}}
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, 'app-config.yaml').write_text(json.dumps(unsafe))
            with self.assertRaisesRegex(ValueError, 'database'):
                lab.manifest_guard(Path(directory))

    def test_deploy_migrates_and_seeds_before_deployment_apply_with_explicit_command_mocks(self):
        lab = self.load()
        values = {**RDS_ENV, 'APP_URL': 'http://lab.example.test', 'AVATAR_BUCKET': lab.NAME + '-avatars', 'AWS_REGION': 'us-east-1', 'VPC_CIDR': '10.73.0.0/16', 'APP_ROLE_ARN': 'arn:aws:iam::123456789012:role/' + lab.NAME + '-app', 'ALLOWED_CIDRS': '192.0.2.1/32', 'SUBNET_IDS': 'subnet-abc,subnet-def', 'ALB_SECURITY_GROUP_ID': 'sg-abc'}
        import base64
        secrets = {'APP_KEY': 'base64:' + base64.b64encode(b'x' * 32).decode(), 'DB_PASSWORD': 'd' * 32, 'DEMO_PASSWORD': 'p' * 32}
        images = {target: '123456789012.dkr.ecr.us-east-1.amazonaws.com/' + lab.NAME + '-' + target + '@sha256:' + 'a' * 64 for target in ('app', 'web')}
        env = {**RDS_ENV, **{key: value for key, value in secrets.items() if key != 'DB_PASSWORD'}, 'RELEASE': 'f' * 40, 'APP_IMAGE': images['app'], 'WEB_IMAGE': images['web']}
        config = {}
        output = io.StringIO()
        def explicit_mock_command(command, **kwargs):
            if command == ['aws', 'rds', 'describe-db-instances', '--db-instance-identifier', lab.NAME]:
                return json.dumps({'DBInstances': [RDS_INSTANCE]})
            if command == ['aws', 'secretsmanager', 'get-secret-value', '--secret-id', RDS_SECRET, '--version-stage', 'AWSCURRENT']:
                return json.dumps({'ARN': RDS_SECRET, 'VersionStages': ['AWSCURRENT'], 'SecretString': json.dumps({'username': 'manaleague_lab', 'password': secrets['DB_PASSWORD']})})
            if command[:2] == ['kubectl', 'kustomize']:
                config.update(json.loads((Path(command[2]) / 'app-config.yaml').read_text()))
                return 'apiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: manaleague-config\n---\napiVersion: apps/v1\nkind: Deployment\nmetadata:\n  name: manaleague\n'
            if 'get' in command and command[command.index('get') + 1] in ('statefulset', 'pvc'):
                return json.dumps({'items': []})
            if 'secret' in command and 'json' in command:
                name = command[command.index('secret') + 1]
                keys = ('DEMO_PASSWORD',) if name == 'manaleague-demo' else ('APP_KEY', 'DB_PASSWORD')
                return json.dumps({'data': {key: base64.b64encode(secrets[key].encode()).decode() for key in keys}})
            if 'ingress' in command and 'json' in command:
                return json.dumps({'status': {'loadBalancer': {'ingress': [{'hostname': 'k8s-lab-123.us-east-1.elb.amazonaws.com'}]}}})
            if 'configmap' in command and 'json' in command:
                return json.dumps(config)
            if 'deployment' in command and 'json' in command:
                return json.dumps({'spec': {'template': {'spec': {'containers': [{'image': value} for value in images.values()]}}}})
            return ''
        with patch.dict(os.environ, env, clear=True), patch.object(lab, 'deployment_values', return_value=values), patch.object(lab, 'kube_config', return_value=['kubectl', '--context', 'EXPLICIT-MOCK-LAB']), patch.object(lab, 'run', side_effect=explicit_mock_command) as commands, patch.object(lab, 'manifest_guard', wraps=lab.manifest_guard) as guard, patch.object(lab, 'postdeploy_smoke') as smoke, contextlib.redirect_stdout(output):
            lab.deploy('123456789012', 'us-east-1')
            self.assertNotIn(secrets['DB_PASSWORD'], output.getvalue())
            self.assertNotIn(base64.b64encode(secrets['DB_PASSWORD'].encode()).decode(), output.getvalue())
            smoke.assert_called_once_with(values['APP_URL'], secrets['APP_KEY'])
            guard.assert_called_once()
            seed_wait = next(i for i, c in enumerate(commands.call_args_list) if any(str(arg).startswith('job/seed-') for arg in c.args[0]))
            deployment_apply = [i for i, c in enumerate(commands.call_args_list) if c.args[0][-3:] == ['apply', '-f', '-'] and 'kind: Deployment' in c.kwargs.get('input', '')]
            self.assertTrue(deployment_apply and all(i > seed_wait for i in deployment_apply))
            secret_read = next(i for i, c in enumerate(commands.call_args_list) if 'get-secret-value' in c.args[0])
            first_write = next(i for i, c in enumerate(commands.call_args_list) if 'apply' in c.args[0])
            self.assertLess(secret_read, first_write)
            self.assertFalse(any('statefulset/postgres' in c.args[0] for c in commands.call_args_list))
            payloads = [json.loads(c.kwargs['input']) for c in commands.call_args_list if c.kwargs.get('input', '').startswith('{') and '"kind": "Secret"' in c.kwargs['input']]
            app_secret = next(obj for obj in payloads if obj['metadata']['name'] == 'manaleague-secrets')
            self.assertEqual(app_secret['type'], 'Opaque')
            self.assertEqual(base64.b64decode(app_secret['data']['DB_PASSWORD']).decode(), secrets['DB_PASSWORD'])
            for call in commands.call_args_list:
                self.assertNotIn(secrets['DB_PASSWORD'], str(call.args[0]))
                self.assertNotIn(secrets['DB_PASSWORD'], str(call.kwargs.get('env', {})))

    def test_absent_cluster_reports_manual_provisioning_instructions(self):
        lab = self.load()
        import subprocess
        error = subprocess.CalledProcessError(254, ['aws'], stderr='ResourceNotFoundException')
        with patch.object(lab, 'run', side_effect=error):
            with self.assertRaisesRegex(ValueError, 'Provision.*manually'):
                lab.kube_config('123456789012', 'us-east-1', Path('unused'))

    def test_nonempty_registry_blocks_teardown_without_data_deletes(self):
        lab = self.load()
        outputs = {'account_id': '123456789012', 'namespace': lab.NAME, 'cluster_name': lab.NAME, 'aws_region': 'us-east-1', 'avatar_bucket_name': lab.NAME + '-123456789012-us-east-1-avatars'}
        outputs.update({f'ecr_{target}_repository_url': '123456789012.dkr.ecr.us-east-1.amazonaws.com/' + lab.NAME + '-' + target for target in ('app', 'web')})
        with patch.object(lab, 'tf', return_value=json.dumps({key: {'value': value} for key, value in outputs.items()})), patch.object(lab, 'run', return_value=json.dumps({'imageIds': [{'imageDigest': 'sha256:' + 'a' * 64}]})) as commands:
            with self.assertRaisesRegex(ValueError, 'Non-empty ECR'):
                lab.storage_guard('123456789012', 'us-east-1')
            self.assertFalse(any('delete' in str(c.args[0]) for c in commands.call_args_list))

    def test_environment_gate_defaults_off(self):
        lab = self.load()
        env = {'GITHUB_ACTIONS': 'true', 'GITHUB_REF': 'refs/heads/eks-lab', 'GITHUB_EVENT_NAME': 'push'}
        for enabled in ('', 'false', 'TRUE'):
            with patch.dict(os.environ, {**env, 'LAB_DEPLOY_ENABLED': enabled}, clear=True):
                with self.assertRaises(ValueError):
                    lab.workflow_guard()
        with patch.dict(os.environ, {**env, 'LAB_DEPLOY_ENABLED': 'true'}, clear=True):
            lab.workflow_guard()

    def test_destroy_requires_data_consent_before_any_command(self):
        lab = self.load()
        with patch.object(lab, 'run') as commands, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                lab.main(['destroy', '--allow-cloud', '--confirm-lab', lab.NAME, '--expected-account', '123456789012'])
            commands.assert_not_called()

    def test_cleanup_must_succeed_before_terraform_destroy(self):
        lab = self.load()
        with patch.object(lab, 'cloud_guard', return_value=('123456789012', 'us-east-1')), patch.object(lab, 'verify_plan'), patch.object(lab, 'storage_guard'), patch.object(lab, 'rds_destroy_guard'), patch.object(lab, 'cleanup_runtime', side_effect=ValueError('EXPLICIT MOCK: ALB not deleted')), patch.object(lab, 'terraform_action') as terraform:
            with self.assertRaisesRegex(ValueError, 'ALB not deleted'):
                lab.main(['destroy', '--allow-cloud', '--confirm-lab', lab.NAME, '--expected-account', '123456789012', '--delete-lab-data'])
            terraform.assert_not_called()

    def test_existing_sha_with_different_tested_image_rejected(self):
        lab = self.load()
        repository = '123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-app'
        def explicit_mock_command(command, **kwargs):
            if command[:3] == ['aws', 'ecr', 'describe-repositories']:
                return json.dumps({'repositories': [{'repositoryUri': repository, 'imageTagMutability': 'IMMUTABLE'}]})
            if command[:3] == ['aws', 'ecr', 'describe-images']:
                return json.dumps({'imageDetails': [{'imageDigest': 'sha256:' + 'a' * 64}]})
            if command[:3] == ['docker', 'image', 'inspect']:
                return 'tested-id' if command[3] == 'lab-app:dev' else 'different-id'
            return ''
        with patch.object(lab, 'run', side_effect=explicit_mock_command) as commands:
            with self.assertRaisesRegex(ValueError, 'differs'):
                lab.publish_one('lab-app:dev', repository, 'f' * 40)
            self.assertFalse(any(c.args[0][:2] == ['docker', 'push'] for c in commands.call_args_list))

    def test_deploy_rejects_missing_or_nonlab_secrets_before_commands(self):
        lab = self.load()
        with patch.dict(os.environ, {}, clear=True), patch.object(lab, 'run') as commands:
            with self.assertRaises(ValueError):
                lab.deploy('123456789012', 'us-east-1')
            commands.assert_not_called()

    def test_teardown_refuses_orphaned_namespace_ebs_volume(self):
        lab = self.load()
        def explicit_mock_command(command, **kwargs):
            if command[:3] == ['aws', 'elbv2', 'describe-load-balancers']:
                return json.dumps({'LoadBalancers': []})
            if command[:3] == ['aws', 'ec2', 'describe-volumes']:
                return json.dumps({'Volumes': [{'VolumeId': 'vol-abc'}]})
            return json.dumps({'items': []})
        with patch.object(lab, 'kube_config', return_value=['kubectl', '--context', 'EXPLICIT-MOCK-LAB']), patch.object(lab, 'run', side_effect=explicit_mock_command), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(ValueError, 'EBS'):
                lab.cleanup_runtime('123456789012', 'us-east-1')

    def test_teardown_refuses_unowned_alb_before_delete(self):
        lab = self.load()
        with patch.object(lab, 'run', return_value=json.dumps({'TagDescriptions': [{'Tags': [{'Key': 'elbv2.k8s.aws/cluster', 'Value': 'production'}]}]})):
            with self.assertRaises(ValueError):
                lab.verify_alb_owner('arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/lab/id', 'manaleague')

    def test_renderer_quotes_whole_yaml_scalar(self):
        lab = self.load()
        self.assertEqual(lab.replace_tokens('value: __APP_URL__', {'APP_URL': 'http://lab/path#x'}), 'value: "http://lab/path#x"')
        with self.assertRaises(ValueError):
            lab.replace_tokens('value: __UNKNOWN__', {})

if __name__ == '__main__':
    unittest.main()
