import hashlib
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch, MagicMock
import deploy


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class ProvenanceTests(unittest.TestCase):
    def test_archive_binds_to_remote_tree(self):
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'source.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for name in deploy.REQUIRED_FILES:
                    z.writestr(name, b'content')
            tree = [{'path': name, 'type': 'blob', 'mode': '100644', 'sha': blob(b'content')} for name in deploy.REQUIRED_FILES]
            with patch.object(deploy, 'github_json', side_effect=[{'sha': 'a'*40, 'tree': {'sha': 'b'*40}}, {'sha': 'b'*40, 'truncated': False, 'tree': tree}]):
                deploy.verify_provenance(archive, 'a'*40)
            tree[0]['sha'] = 'c'*40
            with patch.object(deploy, 'github_json', side_effect=[{'sha': 'a'*40, 'tree': {'sha': 'b'*40}}, {'sha': 'b'*40, 'truncated': False, 'tree': tree}]):
                with self.assertRaises(ValueError):
                    deploy.verify_provenance(archive, 'a'*40)

    def test_truncated_and_mismatched_tree_fail(self):
        commit = {'sha': 'a'*40, 'tree': {'sha': 'b'*40}}
        for tree in ({'sha': 'b'*40, 'truncated': True, 'tree': []},
                     {'sha': 'c'*40, 'truncated': False, 'tree': []}):
            with self.subTest(tree=tree), patch.object(deploy, 'github_json', side_effect=[commit, tree]):
                with self.assertRaises(ValueError):
                    deploy.verify_provenance('not-opened.zip', 'a'*40)

    def test_fixed_host_no_redirect_and_response_limit(self):
        conn = MagicMock()
        with patch.object(deploy.http.client, 'HTTPSConnection', return_value=conn) as factory:
            response = conn.getresponse.return_value
            response.status = 302
            with self.assertRaises(ValueError):
                deploy.github_json('commits', 'a'*40)
            factory.assert_called_once()
            self.assertEqual(factory.call_args.args[0], 'api.github.com')
            self.assertEqual(conn.request.call_args.args[1], '/repos/viniciusVitorio/manaleague/git/commits/' + 'a'*40)
            self.assertNotIn('Authorization', conn.request.call_args.kwargs['headers'])
            response.status = 200
            response.read.return_value = b'x' * 10_000_001
            with self.assertRaises(ValueError):
                deploy.github_json('trees', 'b'*40)
            response.read.assert_called_with(10_000_001)
            with self.assertRaises(ValueError):
                deploy.github_json('https://evil', 'a'*40)
            with self.assertRaises(ValueError):
                deploy.github_json('commits', '../evil')

    def test_archive_size_limit(self):
        item = MagicMock(file_size=100_000_001)
        commit = {'sha': 'a'*40, 'tree': {'sha': 'b'*40}}
        tree = {'sha': 'b'*40, 'truncated': False, 'tree': []}
        with patch.object(deploy, 'github_json', side_effect=[commit, tree]), patch.object(deploy.zipfile, 'ZipFile') as z:
            z.return_value.__enter__.return_value.infolist.return_value = [item]
            with self.assertRaisesRegex(ValueError, 'oversized'):
                deploy.verify_provenance('ignored.zip', 'a'*40)

    def test_submodule_rejected(self):
        commit = {'sha': 'a'*40, 'tree': {'sha': 'b'*40}}
        tree = {'sha': 'b'*40, 'truncated': False, 'tree': [{'path': 'sub', 'type': 'commit'}]}
        with patch.object(deploy, 'github_json', side_effect=[commit, tree]):
            with self.assertRaisesRegex(ValueError, 'submodules'):
                deploy.verify_provenance('ignored.zip', 'a'*40)

    def test_provenance_failure_stops_deploy(self):
        env = {'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1', 'RELEASE':'a'*40,
               'SOURCE_SHA256':'d'*64, 'APP_DIGEST':'sha256:'+'b'*64,
               'WEB_DIGEST':'sha256:'+'c'*64,
               'LAB_APP_SECRET_ARN':'arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Abc123'}
        with patch.object(deploy, 'download_source'), patch.object(deploy, 'github_json', side_effect=ValueError('offline')), patch.object(deploy, 'invoke') as invoke:
            with self.assertRaises(ValueError):
                deploy.deploy(env)
            invoke.assert_not_called()
