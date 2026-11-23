import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('image_build', HERE / 'build_image.py')
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)

class ImageBuildTests(unittest.TestCase):
    def test_dockerfile_uses_only_supplied_base_and_runtime(self):
        dockerfile = (HERE / 'Dockerfile').read_text()
        self.assertIn('FROM ${BASE_IMAGE}', dockerfile)
        self.assertIn('COPY deploy.py /opt/manaleague-deploy/deploy.py', dockerfile)
        self.assertIn('"/opt/manaleague-deploy/deploy.py"', dockerfile)
        for tool in ('bash', 'python3', 'aws', 'kubectl'):
            self.assertIn('command -v ' + tool, dockerfile)
        self.assertNotRegex(dockerfile, r'(?im)^\s*(ADD|ONBUILD)\b')

    def test_rejects_mutable_and_malformed_inputs_before_docker(self):
        for base in ('ubuntu:latest', 'example.com/repo:tag', 'example.com/repo@sha256:abc', 'example.com/repo@sha256:' + 'A'*64):
            with self.subTest(base=base), patch.object(build.subprocess, 'run') as run:
                with self.assertRaises(ValueError):
                    build.build(base, 'lab-runtime:local', 'missing.zip', 'a'*64)
                run.assert_not_called()

    def test_reviewed_archive_is_copied_and_digest_bound(self):
        import hashlib, tempfile, zipfile
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'reviewed.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for name in build.runtime.REQUIRED_FILES:
                    z.writestr(name, 'reviewed')
            expected = hashlib.sha256(archive.read_bytes()).hexdigest()
            def docker(args, **kwargs):
                context = Path(args[-1])
                self.assertEqual({p.name for p in context.iterdir()}, {'Dockerfile', 'deploy.py', 'deploy.zip', 'deploy.sha256'})
                self.assertIn('--network=none', args)
                self.assertNotIn('secret', ' '.join(args).lower())
                self.assertEqual((context / 'deploy.zip').read_bytes(), archive.read_bytes())
                self.assertEqual((context / 'deploy.sha256').read_text().strip(), expected)
                return subprocess.CompletedProcess(args, 0)
            with patch.object(build.subprocess, 'run', side_effect=docker):
                build.build('example.com/base@sha256:' + 'a'*64, 'lab:local', archive, expected)
            with patch.object(build.subprocess, 'run') as run, self.assertRaises(ValueError):
                build.build('example.com/base@sha256:' + 'a'*64, 'lab:local', archive, '0'*64)
            run.assert_not_called()

    def test_rejects_option_injection_in_tag(self):
        with patch.object(build.subprocess, 'run') as run:
            with self.assertRaises(ValueError):
                build.build('example.com/base@sha256:' + 'a'*64, '--help', 'missing.zip', 'a'*64)
            run.assert_not_called()

if __name__ == '__main__':
    unittest.main()
