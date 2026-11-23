import importlib.util
import unittest
from pathlib import Path
from unittest.mock import Mock

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('broker_source', HERE.parent / 'deploy_broker.py')
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)

runtime_spec = importlib.util.spec_from_file_location('runtime_source', HERE / 'deploy.py')
runtime = importlib.util.module_from_spec(runtime_spec)
runtime_spec.loader.exec_module(runtime)

class ReleaseSourceTests(unittest.TestCase):
    def test_source_key_is_bound_to_release_and_digest(self):
        self.assertEqual(runtime.source_key('a'*40, 'b'*64), 'releases/' + 'a'*40 + '/' + 'b'*64 + '.zip')
        with self.assertRaises(ValueError):
            runtime.source_key('../bad', 'b'*64)

    def test_download_uses_scoped_key_and_rejects_wrong_hash(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'source.zip'
            def fake(args, env, **kwargs):
                if 'head-object' in args:
                    return Mock(stdout='{"ContentLength":8}', returncode=0)
                path.write_bytes(b'tampered')
                return Mock(returncode=0)
            with self.assertRaisesRegex(ValueError, 'untrusted'):
                runtime.download_source('123456789012', 'us-east-1', 'a'*40, 'b'*64, path, {}, run=fake)

    def test_broker_passes_archive_hash_to_build(self):
        client = Mock()
        client.start_build.return_value = {'build': {'id': broker.PROJECT + ':abc'}}
        broker.start({'release':'a'*40, 'app_digest':'sha256:'+'b'*64,
                      'web_digest':'sha256:'+'c'*64, 'source_sha256':'d'*64}, client)
        variables = client.start_build.call_args.kwargs['environmentVariablesOverride']
        self.assertIn({'name':'SOURCE_SHA256', 'value':'d'*64, 'type':'PLAINTEXT'}, variables)

    def test_download_requests_bounded_range_from_release_bucket(self):
        import tempfile, hashlib
        data = b'archive'
        calls = []
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'source.zip'
            def fake(args, env, **kwargs):
                calls.append(args)
                if 'head-object' in args:
                    return Mock(stdout=__import__('json').dumps({'ContentLength': 7}))
                path.write_bytes(data)
                return Mock(stdout='{}')
            runtime.download_source('123456789012', 'us-east-1', 'a'*40, hashlib.sha256(data).hexdigest(), path, {}, run=fake)
        self.assertIn('manaleague-eks-lab-releases-123456789012-us-east-1', calls[0])
        self.assertIn('bytes=0-6', calls[1])
