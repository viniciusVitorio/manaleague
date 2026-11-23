import shutil
import importlib.util
import io
import hashlib
import json
import os
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('runtime', HERE / 'deploy.py')
try:
    runtime = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(runtime)
except FileNotFoundError:
    runtime = None


class RuntimeTests(unittest.TestCase):
    def setUp(self):
        mock = patch.object(runtime, 'verify_provenance')
        mock.start()
        self.addCleanup(mock.stop)
    def test_verified_archive_extracts_and_rejects_traversal(self):
        self.assertIsNotNone(runtime, 'deploy runtime is missing')
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'source.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                z.writestr('infra/eks-lab/scripts/lab.sh', 'safe')
                z.writestr('../escape', 'bad')
            with self.assertRaises(ValueError):
                runtime.extract_archive(archive, Path(d) / 'out', runtime.digest(archive))
            self.assertFalse((Path(d) / 'escape').exists())

    def test_deploy_flow_and_failures_do_not_expose_secrets(self):
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'code.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for name in runtime.REQUIRED_FILES:
                    z.writestr(name, 'safe')
            env = {'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1',
                   'RELEASE':'a'*40, 'APP_DIGEST':'sha256:'+'b'*64,
                   'WEB_DIGEST':'sha256:'+'c'*64, 'LAB_ARTIFACT_PATH':str(archive),
                   'LAB_ARTIFACT_SHA256':hashlib.sha256(archive.read_bytes()).hexdigest(), 'SOURCE_SHA256':runtime.digest(archive),
                   'LAB_APP_SECRET_ARN':'arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Abc123'}
            hash_file = Path(d) / 'hash'
            hash_file.write_text(runtime.digest(archive))
            calls = []
            def fake(args, child_env, **kwargs):
                calls.append((args, dict(child_env)))
                if 'describe-repositories' in args:
                    repo = args[args.index('--repository-names')+1]
                    obj = {'repositories':[{'repositoryUri':'123456789012.dkr.ecr.us-east-1.amazonaws.com/'+repo,'imageTagMutability':'IMMUTABLE'}]}
                elif 'describe-images' in args:
                    repo = args[args.index('--repository-name')+1]
                    obj = {'imageDetails':[{'imageDigest':env['APP_DIGEST' if repo.endswith('-app') else 'WEB_DIGEST']}]}
                elif 'get-secret-value' in args:
                    obj = {'ARN':env['LAB_APP_SECRET_ARN'], 'VersionStages':['AWSCURRENT'], 'SecretString':json.dumps({'APP_KEY':'secret-key', 'DEMO_PASSWORD':'secret-password'})}
                else:
                    self.assertEqual(args[0], 'bash')
                    self.assertEqual(child_env['APP_KEY'], 'secret-key')
                    self.assertNotIn('secret-key', repr(args))
                    obj = {}
                return SimpleNamespace(stdout=json.dumps(obj), returncode=0)
            with patch.object(runtime, 'download_source', side_effect=lambda account, region, release, digest, path, env: shutil.copyfile(archive, path)), patch.object(runtime, 'invoke', side_effect=fake):
                runtime.deploy(env)
            self.assertEqual(len(calls), 6)
            self.assertFalse(Path(calls[-1][1]['HOME']).exists())
            for bad in ('RELEASE', 'APP_DIGEST'):
                broken = dict(env, **{bad:'invalid'})
                with patch.object(runtime, 'invoke', side_effect=fake), self.assertRaises(ValueError):
                    runtime.deploy(broken)

    def test_embedded_archive_tampering_stops_before_aws(self):
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'deploy.zip'
            archive.write_bytes(b'tampered')
            hash_file = Path(d) / 'hash'
            hash_file.write_text('a'*64)
            env = {'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1', 'RELEASE':'a'*40,
                   'APP_DIGEST':'sha256:'+'b'*64, 'WEB_DIGEST':'sha256:'+'c'*64, 'SOURCE_SHA256':'a'*64,
                   'LAB_APP_SECRET_ARN':'arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Abc123'}
            with patch.object(runtime, 'download_source', side_effect=lambda account, region, release, digest, path, env: shutil.copyfile(archive, path)), patch.object(runtime, 'invoke') as invoke:
                with self.assertRaisesRegex(ValueError, 'untrusted deploy artifact'):
                    runtime.deploy(env)
                invoke.assert_not_called()

    def test_caller_archive_overrides_are_ignored(self):
        env = {'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1', 'RELEASE':'a'*40,
               'APP_DIGEST':'sha256:'+'b'*64, 'WEB_DIGEST':'sha256:'+'c'*64,
               'LAB_ARTIFACT_PATH':'attacker.zip', 'LAB_ARTIFACT_SHA256':'0'*64, 'SOURCE_SHA256':'1'*64,
               'LAB_APP_SECRET_ARN':'arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Abc123'}
        with patch.object(runtime, 'download_source'), patch.object(runtime, 'extract_archive', side_effect=ValueError('stopped')) as extract:
            with self.assertRaises(ValueError):
                runtime.deploy(env)
        self.assertEqual(extract.call_args.args[0].name, 'source.zip')
        self.assertEqual(extract.call_args.args[2], '1'*64)

    def test_main_sanitizes_failure(self):
        with patch.object(runtime, 'deploy', side_effect=ValueError('secret-value')), patch('sys.stderr', new_callable=io.StringIO) as err:
            self.assertEqual(runtime.main(), 1)
            self.assertNotIn('secret-value', err.getvalue())

    def test_invoke_does_not_echo_child_secrets_on_failure(self):
        import sys
        with self.assertRaises(ValueError) as caught:
            runtime.invoke([sys.executable, '-c', "import sys; print('secret-value'); print('secret-value', file=sys.stderr); sys.exit(1)"], dict(os.environ))
        self.assertNotIn('secret-value', str(caught.exception))

    def test_digest_mismatch_never_invokes_deploy(self):
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'code.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for name in runtime.REQUIRED_FILES:
                    z.writestr(name, 'safe')
            env = {'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1', 'RELEASE':'a'*40,
                   'APP_DIGEST':'sha256:'+'b'*64, 'WEB_DIGEST':'sha256:'+'c'*64,
                   'LAB_ARTIFACT_PATH':str(archive), 'LAB_ARTIFACT_SHA256':runtime.digest(archive), 'SOURCE_SHA256':runtime.digest(archive),
                   'LAB_APP_SECRET_ARN':'arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Abc123'}
            hash_file = Path(d) / 'hash'
            hash_file.write_text(runtime.digest(archive))
            from types import SimpleNamespace
            responses = [SimpleNamespace(stdout=json.dumps({'repositories':[{'repositoryUri':'123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-app', 'imageTagMutability':'IMMUTABLE'}]})),
                         SimpleNamespace(stdout=json.dumps({'imageDetails':[{'imageDigest':'sha256:'+'d'*64}]}))]
            with patch.object(runtime, 'download_source', side_effect=lambda account, region, release, digest, path, env: shutil.copyfile(archive, path)), patch.object(runtime, 'invoke', side_effect=responses) as inv, self.assertRaises(ValueError):
                runtime.deploy(env)
            self.assertEqual(inv.call_count, 2)

if __name__ == '__main__':
    unittest.main()
