"""Real manifest contracts with explicit offline command fakes (no cloud writes)."""
import base64
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import unittest
from unittest.mock import patch
from test_rds import ENV as RDS_ENV

ROOT = Path(__file__).resolve().parents[3]

class IntegrationTests(unittest.TestCase):
    def setUp(self):
        deny = patch('subprocess.run', side_effect=AssertionError('Unmocked external command'))
        deny.start()
        self.addCleanup(deny.stop)
        spec = importlib.util.spec_from_file_location('lab_integration', ROOT / 'infra/eks-lab/scripts/lab.py')
        assert spec and spec.loader
        self.lab = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.lab)

    def test_bootstrap_reserves_both_manifest_secret_names_with_narrow_rbac(self):
        lab = self.lab
        resources = {}
        def command(args, **kwargs):
            if 'create' in args or 'apply' in args:
                obj = json.loads(kwargs['input'])
                resources[(obj['kind'].lower(), obj['metadata']['name'])] = obj
                return ''
            if 'get' in args:
                idx = args.index('get')
                obj = resources.get((args[idx+1], args[idx+2]))
                return json.dumps(obj) if obj else ''
            self.fail(str(args))
        with patch.object(lab, 'run', side_effect=command):
            lab.bootstrap_secret_access(['kubectl'])
        self.assertIn(('secret', 'manaleague-demo'), resources)
        role = resources[('role', 'lab-secret-updater')]
        self.assertEqual(role['rules'], [{'apiGroups': [''], 'resources': ['secrets'], 'resourceNames': ['manaleague-secrets', 'manaleague-demo'], 'verbs': ['get', 'update', 'patch']}])
        seed = json.loads((lab.LAB / 'kubernetes/jobs/seed.yaml').read_text())
        refs = [env['valueFrom']['secretKeyRef'] for container in seed['spec']['template']['spec']['containers'] for env in container.get('env', []) if 'secretKeyRef' in env.get('valueFrom', {})]
        self.assertIn({'name': 'manaleague-demo', 'key': 'DEMO_PASSWORD'}, refs)

    def runtime_fake(self, cron_names, foreign_job=False):
        lab = self.lab
        crons = [{'metadata': {'name': name, 'namespace': lab.NAME, 'uid': 'uid-' + name}, 'spec': {}} for name in cron_names]
        jobs = [{'metadata': {'name': name + '-1234', 'namespace': lab.NAME, 'uid': 'job-' + name, 'ownerReferences': [{'kind': 'CronJob', 'name': name, 'uid': 'WRONG' if foreign_job else 'uid-' + name, 'controller': True}]}} for name in cron_names]
        writes = []
        def command(args, **kwargs):
            if args[:3] == ['aws', 'elbv2', 'describe-load-balancers']:
                return json.dumps({'LoadBalancers': []})
            if args[:3] == ['aws', 'ec2', 'describe-volumes']:
                return json.dumps({'Volumes': []})
            if 'get' in args:
                idx = args.index('get')
                kind = args[idx+1]
                if kind == 'cronjob':
                    return json.dumps(next(obj for obj in crons if obj['metadata']['name'] == args[idx+2]))
                return json.dumps({'items': {'cronjobs': crons, 'jobs': jobs}.get(kind, [])})
            if 'patch' in args:
                writes.append(args)
                name = args[args.index('patch')+2]
                next(obj for obj in crons if obj['metadata']['name'] == name)['spec']['suspend'] = True
                return ''
            if 'delete' in args or 'scale' in args:
                writes.append(args)
                self.assertTrue(all(obj['spec'].get('suspend') is True for obj in crons))
                return ''
            self.fail(str(args))
        return command, writes

    def test_teardown_suspends_every_checked_in_cron_before_runtime_deletes(self):
        lab = self.lab
        names = ['scheduler', 'cards-sync-bans', 'cards-sync-legality', 'challenges-auto-confirm']
        command, writes = self.runtime_fake(names)
        with patch.object(lab, 'kube_config', return_value=['kubectl']), patch.object(lab, 'run', side_effect=command), contextlib.redirect_stdout(io.StringIO()):
            lab.cleanup_runtime('123456789012', 'us-east-1')
        self.assertEqual(len([args for args in writes if 'patch' in args]), 4)
        self.assertEqual(len([args for args in writes if 'delete' in args]), 4)

    def test_unknown_cron_or_wrong_job_owner_fails_before_any_write(self):
        lab = self.lab
        for names, foreign in [(['unexpected'], False), (['scheduler'], True)]:
            command, writes = self.runtime_fake(names, foreign)
            with self.subTest(names=names, foreign=foreign), patch.object(lab, 'kube_config', return_value=['kubectl']), patch.object(lab, 'run', side_effect=command):
                with self.assertRaises(ValueError):
                    lab.cleanup_runtime('123456789012', 'us-east-1')
                self.assertEqual(writes, [])

    def test_default_endpoint_is_provisional_but_custom_production_is_rejected(self):
        lab = self.lab
        env = {**RDS_ENV, 'AVATAR_BUCKET': lab.NAME + '-test', 'AWS_REGION': 'us-east-1', 'VPC_CIDR': '10.73.0.0/16', 'APP_ROLE_ARN': 'arn:aws:iam::123456789012:role/' + lab.NAME + '-app', 'ALLOWED_CIDRS': '192.0.2.1/32', 'SUBNET_IDS': 'subnet-abc', 'ALB_SECURITY_GROUP_ID': 'sg-abc'}
        with patch.dict(os.environ, env, clear=True):
            self.assertEqual(lab.deployment_values('123456789012')['APP_URL'], 'http://eks-lab.invalid')
        for url in ('https://manaleague.com.br', 'https://admin.manaleague.com.br', 'https://user:password@lab.test', 'https://lab.test/?secret=value'):
            with self.subTest(url=url), patch.dict(os.environ, {**env, 'APP_URL': url}, clear=True):
                with self.assertRaises(ValueError):
                    lab.deployment_values('123456789012')
        with patch.dict(os.environ, {key: value for key, value in env.items() if key != 'ALB_SECURITY_GROUP_ID'}, clear=True):
            with self.assertRaisesRegex(ValueError, 'ALB_SECURITY_GROUP_ID'):
                lab.deployment_values('123456789012')

    def test_final_endpoint_updates_config_and_rolls_both_deployments_before_smoke(self):
        lab = self.lab
        import tempfile
        hostname = 'k8s-lab-123.us-east-1.elb.amazonaws.com'
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / 'base'
            values = {**RDS_ENV, 'APP_URL': 'http://eks-lab.invalid', 'AVATAR_BUCKET': lab.NAME+'-test', 'AWS_REGION': 'us-east-1', 'VPC_CIDR': '10.73.0.0/16', 'APP_ROLE_ARN': 'arn:aws:iam::123456789012:role/'+lab.NAME+'-app', 'ALLOWED_CIDRS': '192.0.2.1/32', 'SUBNET_IDS': 'subnet-abc', 'ALB_SECURITY_GROUP_ID': 'sg-abc'}
            lab.render_tree(lab.LAB / 'kubernetes/base', base, values)
            config = {}
            def command(args, **kwargs):
                if 'ingress' in args:
                    return json.dumps({'status': {'loadBalancer': {'ingress': [{'hostname': hostname}]}}})
                if args[:2] == ['kubectl', 'kustomize']:
                    return 'kind: Deployment\n'
                if 'configmap' in args and 'get' in args:
                    return json.dumps(config)
                if 'apply' in args:
                    config.update(json.loads((base / 'app-config.yaml').read_text()))
                return ''
            with patch.object(lab, 'run', side_effect=command) as commands, patch.object(lab, 'postdeploy_smoke') as smoke:
                result = lab.finalize_endpoint(['kubectl'], base, values, {'APP_KEY': 'private', 'DB_PASSWORD': 'private'})
            self.assertEqual(result, 'http://' + hostname)
            self.assertEqual(config['data']['APP_URL'], result)
            self.assertEqual(config['data']['REVERB_ALLOWED_ORIGINS'], hostname)
            for source in ('app.yaml', 'reverb.yaml', 'scheduler.yaml', 'daily-tasks.yaml'):
                for document in re.split(r'^---\s*$', (base / source).read_text(), flags=re.MULTILINE):
                    obj = json.loads(document)
                    if obj['kind'] not in ('Deployment', 'CronJob'):
                        continue
                    template = obj['spec']['jobTemplate']['spec']['template'] if obj['kind'] == 'CronJob' else obj['spec']['template']
                    self.assertIn('eks-lab.manaleague.test/runtime-config', template['metadata']['annotations'])
            self.assertEqual(len([c for c in commands.call_args_list if 'rollout' in c.args[0] and 'status' in c.args[0]]), 2)
            smoke.assert_called_once_with(result, 'private')

    def test_foreign_pod_owner_blocks_teardown_before_suspend(self):
        lab = self.lab
        command, writes = self.runtime_fake(['scheduler'])
        pod = {'metadata': {'name': 'foreign', 'namespace': lab.NAME, 'uid': 'pod-uid', 'ownerReferences': [{'kind': 'Job', 'name': 'scheduler-1234', 'uid': 'WRONG', 'controller': True}]}}
        def fake(args, **kwargs):
            if 'get' in args and args[args.index('get') + 1] == 'pods':
                return json.dumps({'items': [pod]})
            return command(args, **kwargs)
        with patch.object(lab, 'kube_config', return_value=['kubectl']), patch.object(lab, 'run', side_effect=fake):
            with self.assertRaisesRegex(ValueError, 'Pod|pod'):
                lab.cleanup_runtime('123456789012', 'us-east-1')
        self.assertEqual(writes, [])

if __name__ == '__main__':
    unittest.main()
