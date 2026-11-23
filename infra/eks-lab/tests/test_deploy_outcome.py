import pathlib
import re
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
WORKFLOW = ROOT / '.github/workflows/eks-lab.yml'


class DeployOutcomeTest(unittest.TestCase):
    def test_push_outcome_gate_fails_closed(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        match = re.search(r'^  deploy_outcome:\n(?P<job>(?:^(?:    .*|\s*)\n?)*)', text, re.M)
        self.assertIsNotNone(match, 'push deploy outcome job is missing')
        job = match.group('job')
        self.assertIn("always()", job)
        self.assertIn("github.event_name == 'push'", job)
        self.assertIn('needs: [gate, publish, deploy, deploy_broker]', job)
        script = re.search(r'        run: \|\n((?:          .*\n?)+)', job)
        self.assertIsNotNone(script)
        command = '\n'.join(line[10:] for line in script.group(1).splitlines())
        for gate, enabled, publish, deploy, broker, expected in [
            ('success', 'false', 'skipped', 'skipped', 'skipped', 1),
            ('success', 'true', 'success', 'success', 'skipped', 0),
            ('success', 'true', 'success', 'skipped', 'success', 0),
            ('success', 'true', 'success', 'skipped', 'skipped', 1),
            ('success', 'true', 'success', 'success', 'success', 1),
            ('failure', 'true', 'success', 'success', 'skipped', 1),
            ('success', 'true', 'failure', 'success', 'skipped', 1),
            ('success', 'true', 'success', 'failure', 'skipped', 1),
            ('success', 'true', 'success', 'cancelled', 'skipped', 1),
        ]:
            with self.subTest(gate=gate, enabled=enabled, publish=publish, deploy=deploy, broker=broker):
                import os
                import shutil
                import shlex
                shell = 'bash'
                if os.name == 'nt':
                    git = pathlib.Path(shutil.which('git') or '').resolve()
                    candidates = [parent / 'usr' / 'bin' / 'bash.exe' for parent in git.parents]
                    shell = str(next(path for path in candidates if path.is_file()))
                values = dict(GATE_RESULT=gate, ENABLED=enabled, PUBLISH_RESULT=publish,
                              DEPLOY_RESULT=deploy, BROKER_RESULT=broker)
                assignments = '; '.join(f'{key}={shlex.quote(value)}' for key, value in values.items())
                result = subprocess.run([shell, '-c', assignments + ';\n' + command], capture_output=True)
                self.assertEqual(result.returncode, expected, result.stderr)


if __name__ == '__main__':
    unittest.main()
