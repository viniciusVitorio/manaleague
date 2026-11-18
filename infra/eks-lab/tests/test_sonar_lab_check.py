"""Offline, read-only Sonar check verification regressions."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / '.github/scripts/verify-sonar-lab-check.py'


class SonarLabCheck(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('sonar_lab_check', SCRIPT)
        assert spec and spec.loader
        self.m = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.m)
        self.now = self.m.timestamp('2026-11-18T22:27:00Z')
        self.marker = self.m.timestamp('2026-11-18T22:25:55Z')
        self.check = dict(id=1, name='SonarCloud Code Analysis', app={'id': 12526},
                          head_sha='a'*40, status='completed', conclusion='success',
                          details_url='https://sonarcloud.io/dashboard?id=project&branch=eks-lab',
                          started_at='2026-11-18T22:25:56Z', completed_at='2026-11-18T22:26:20Z',
                          output={'title': 'Quality Gate passed'})
        self.env = dict(GITHUB_EVENT_NAME='push', GITHUB_REF='refs/heads/eks-lab',
                        GITHUB_REPOSITORY='viniciusVitorio/manaleague', GITHUB_SHA='a'*40,
                        GH_TOKEN='test-only', SONAR_PROJECT_KEY='project',
                        SONAR_LAB_SCAN_STARTED_AT='2026-11-18T22:25:55Z')

    def evaluate(self, checks):
        return self.m.evaluate(checks, 'a'*40, 'project', self.marker, self.now)

    def test_fail_closed_cases(self):
        self.assertFalse(self.evaluate([]))
        for change in ({'app': {'id': 99}}, {'name': 'forged'},
                       {'started_at': '2026-11-18T22:24:00Z'},
                       {'status': 'queued'}, {'status': 'in_progress'}):
            with self.subTest(change=change):
                self.assertFalse(self.evaluate([dict(self.check, **change)]))
        for conclusion in ('failure', 'skipped', 'neutral', 'cancelled', 'timed_out', None):
            with self.subTest(conclusion=conclusion), self.assertRaises(self.m.GateError):
                self.evaluate([dict(self.check, conclusion=conclusion)])
        for change in ({'head_sha': 'b'*40}, {'status': 'unknown'},
                       {'started_at': '2026-11-18T22:28:00Z'},
                       {'completed_at': '2026-11-18T22:25:54Z'},
                       {'completed_at': '2026-11-18T22:28:00Z'},
                       {'output': {'title': 'Analysis uploaded'}}):
            with self.subTest(change=change), self.assertRaises(self.m.GateError):
                self.evaluate([dict(self.check, **change)])

    def test_urls_and_ambiguity(self):
        for query in ('id=other&branch=eks-lab', 'id=project&branch=main',
                      'id=project&pullRequest=1', 'id=project&branch=eks-lab&branch=main'):
            with self.subTest(query=query), self.assertRaises(self.m.GateError):
                self.evaluate([dict(self.check, details_url='https://sonarcloud.io/dashboard?'+query)])
        for url in ('https://evil.example/dashboard?id=project&branch=eks-lab',
                    'http://sonarcloud.io/dashboard?id=project&branch=eks-lab'):
            with self.subTest(url=url), self.assertRaises(self.m.GateError):
                self.evaluate([dict(self.check, details_url=url)])
        with self.assertRaises(self.m.GateError):
            self.evaluate([self.check, dict(self.check, id=2)])
        old = dict(self.check, id=2, started_at='2026-11-18T22:24:00Z')
        self.assertTrue(self.evaluate([old, self.check]))
        self.assertFalse(self.evaluate([old, dict(self.check, app={'id': 99})]))

    def test_context(self):
        self.m.context(self.env, self.now)
        for change in ({'GITHUB_EVENT_NAME': 'pull_request'}, {'GITHUB_REF': 'refs/heads/main'},
                       {'GITHUB_REPOSITORY': 'other/manaleague'}, {'GITHUB_SHA': 'A'*40},
                       {'GITHUB_SHA': 'a'*39}, {'GH_TOKEN': ''}, {'SONAR_PROJECT_KEY': ''},
                       {'SONAR_LAB_SCAN_STARTED_AT': '2026-11-18T22:28:00Z'},
                       {'SONAR_LAB_SCAN_STARTED_AT': '2026-11-17T22:25:55Z'}):
            with self.subTest(change=change), self.assertRaises(self.m.GateError):
                self.m.context(dict(self.env, **change), self.now)

    def test_pagination(self):
        import json
        from unittest.mock import Mock, patch
        def page(count, checks):
            response = Mock()
            response.__enter__ = Mock(return_value=response)
            response.__exit__ = Mock(return_value=False)
            response.read.return_value = json.dumps(dict(total_count=count, check_runs=checks)).encode()
            return response
        first = [dict(self.check, id=i) for i in range(100)]
        with patch.object(self.m, 'urlopen', side_effect=[page(101, first), page(101, [dict(self.check, id=101)])]) as api:
            self.assertEqual(len(self.m.fetch_checks('viniciusVitorio/manaleague', 'a'*40, 'test')), 101)
            request = api.call_args_list[0].args[0]
            self.assertEqual(request.get_method(), 'GET')
            self.assertIn('/commits/'+'a'*40+'/check-runs?', request.full_url)
            self.assertIn('filter=all', request.full_url)
            self.assertIn('page=2', api.call_args_list[1].args[0].full_url)
        for pages in ([page(2, [self.check])], [page(1001, [])],
                      [page(2, [self.check, self.check])],
                      [page(101, first), page(102, [dict(self.check, id=101)])]):
            with patch.object(self.m, 'urlopen', side_effect=pages), self.assertRaises(self.m.GateError):
                self.m.fetch_checks('viniciusVitorio/manaleague', 'a'*40, 'test')

    def test_polling_and_timeout(self):
        from unittest.mock import patch
        with patch.object(self.m, 'fetch_checks', side_effect=[[], [dict(self.check, status='in_progress')], [self.check]]), patch.object(self.m.time, 'sleep') as sleep:
            self.m.enforce(self.env, now=lambda: self.now, attempts=3)
            self.assertEqual(sleep.call_count, 2)
        with patch.object(self.m, 'fetch_checks', return_value=[]), patch.object(self.m.time, 'sleep'), self.assertRaises(self.m.GateError):
            self.m.enforce(self.env, now=lambda: self.now, attempts=2)
        with patch.object(self.m, 'fetch_checks', side_effect=OSError('unreachable')), self.assertRaises(OSError):
            self.m.enforce(self.env, now=lambda: self.now)

    def test_workflow(self):
        text = (ROOT / '.github/workflows/pipeline.yml').read_text(encoding='utf-8')
        sonar = text.split('\n  sonar:', 1)[1].split('\n  waf:', 1)[0]
        for value in ('checks: read', 'contents: read', 'GH_TOKEN: ${{ github.token }}',
                      "if: github.event_name == 'push' && github.ref == 'refs/heads/eks-lab'",
                      "if: github.event_name != 'push' || github.ref != 'refs/heads/eks-lab'",
                      'python3 .github/scripts/verify-sonar-lab-check.py'):
            self.assertIn(value, sonar)
        self.assertLess(sonar.index('SONAR_LAB_SCAN_STARTED_AT='), sonar.index('SonarSource/sonarqube-scan-action@v6'))
        self.assertNotIn('continue-on-error', sonar)

    def test_current_official_success(self):
        self.assertTrue(self.evaluate([self.check]))

    def test_cli_missing_context_exits_nonzero_without_credentials(self):
        import os
        import subprocess
        import sys
        result = subprocess.run([sys.executable, str(SCRIPT)],
                                env=dict(os.environ, GITHUB_EVENT_NAME='pull_request',
                                         GH_TOKEN='must-not-be-printed'),
                                text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 1)
        self.assertIn('failed (closed)', result.stderr)
        self.assertNotIn('must-not-be-printed', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
