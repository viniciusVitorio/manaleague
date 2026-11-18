"""Regressions for the requested main-like CI; no Docker or cloud calls."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


class MainCIAlignment(unittest.TestCase):
    def test_main_ssdlc_pipeline_runs_automatically_for_eks_lab(self):
        text = (ROOT / '.github/workflows/pipeline.yml').read_text(encoding='utf-8')
        events = text.split('\njobs:', 1)[0]
        for event in ('push', 'pull_request'):
            match = re.search(rf'^  {event}:\n((?:    .*\n)+)', events, re.M)
            self.assertIsNotNone(match, f'{event} event missing')
            assert match is not None
            block = match.group(1)
            self.assertRegex(block, r'branches: \[[^\]]*\beks-lab\b[^\]]*\]')
        self.assertEqual(re.findall(r'^  ([a-z_]+):$', text.split('\njobs:', 1)[1], re.M),
                         ['test', 'security', 'sonar', 'waf', 'deploy'])
        for required in ('--coverage-clover=coverage.xml', 'composer audit --locked',
                         'SonarSource/sonarqube-scan-action@v6',
                         'SonarSource/sonarqube-quality-gate-action@v1',
                         'docker/waf/waf-smoke.sh', 'needs: [security, sonar, waf]'):
            self.assertIn(required, text)
        deploy = text.split('\n  deploy:', 1)[1]
        self.assertIn("github.ref == 'refs/heads/main'", deploy.split('    environment:', 1)[0])

    def test_extra_lab_workflow_has_no_automatic_triggers(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        events = text.split('\npermissions:', 1)[0]
        self.assertIn('workflow_dispatch:', events)
        self.assertNotRegex(events, re.compile(r'^  (?:push|pull_request|release|schedule):', re.M))
        self.assertIn("github.ref == 'refs/heads/eks-lab'", text)


if __name__ == '__main__':
    unittest.main()
