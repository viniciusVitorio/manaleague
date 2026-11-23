"""Offline CD contracts; never dispatch Actions or contact cloud services."""
from pathlib import Path
import hashlib
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


def workflow(name):
    return (ROOT / '.github/workflows' / name).read_text(encoding='utf-8')


def jobs(text):
    return dict(re.findall(
        r'^  ([a-z][a-z0-9_-]*):\n(.*?)(?=^  [a-z][a-z0-9_-]*:$|\Z)',
        text.split('\njobs:', 1)[1], re.M | re.S))


def condition(block):
    match = re.search(r'^    if: (.*(?:\n      [^\n]+)*)', block, re.M)
    if not match:
        return 'true'
    return match.group(1).removeprefix('>-').strip().removeprefix('${{').removesuffix('}}').strip()


def allowed(expression, **contexts):
    """Evaluate only the simple boolean subset used by these job guards."""
    expression = expression.replace('!cancelled()', 'True').replace('!failure()', 'True')
    for token in sorted(contexts, key=len, reverse=True):
        expression = expression.replace(token, repr(contexts[token]))
    expression = expression.replace('&&', ' and ').replace('||', ' or ')
    if expression == 'true':
        return True
    import ast
    try:
        tree = ast.parse(expression, mode='eval')
    except SyntaxError as error:
        raise AssertionError('Unsupported guard syntax') from error

    def value(node):
        if isinstance(node, ast.Constant) and type(node.value) in (str, bool):
            return node.value
        if isinstance(node, ast.BoolOp) and isinstance(node.op, (ast.And, ast.Or)):
            children = [bool(value(child)) for child in node.values]
            return all(children) if isinstance(node.op, ast.And) else any(children)
        if isinstance(node, ast.Compare) and len(node.ops) == 1 and isinstance(node.ops[0], (ast.Eq, ast.NotEq)):
            equal = value(node.left) == value(node.comparators[0])
            return equal if isinstance(node.ops[0], ast.Eq) else not equal
        raise AssertionError('Unsupported guard node: ' + type(node).__name__)

    return bool(value(tree.body))


class CDEntrypoint(unittest.TestCase):
    def test_guard_model_rejects_attributes_operators_and_executable_input(self):
        for expression in ('1 .__class__', '2 ** 1000', 'unknown_name', "'a'.upper()", '__import__("os")'):
            with self.subTest(expression=expression), self.assertRaises(AssertionError):
                allowed(expression)

    def test_ssdlc_calls_same_commit_only_after_all_quality_jobs(self):
        pipeline = workflow('pipeline.yml')
        blocks = jobs(pipeline)
        self.assertIn('deploy-lab', blocks)
        self.assertEqual(len(blocks), 6)
        call = blocks['deploy-lab']
        self.assertIn('needs: [test, security, sonar, waf]', call)
        self.assertIn('uses: ./.github/workflows/eks-lab.yml', call)
        self.assertIn('contents: read', call)
        self.assertIn('id-token: write', call)
        self.assertNotIn('secrets: inherit', call)
        # Environment secrets do not cross a workflow_call boundary, so exactly
        # the two lab-only secrets are passed and nothing else, especially not
        # the production APP_KEY that also exists at repository level.
        self.assertIn('LAB_APP_KEY: ${{ secrets.LAB_APP_KEY }}', call)
        self.assertIn('LAB_DEMO_PASSWORD: ${{ secrets.LAB_DEMO_PASSWORD }}', call)
        passed = [line.split(':')[0].strip() for line in call.splitlines()
                  if line.startswith('      ') and 'secrets.' in line]
        self.assertEqual(sorted(passed), ['LAB_APP_KEY', 'LAB_DEMO_PASSWORD'])
        self.assertNotIn('environment:', call)
        for event, ref, repo, expected in (
            ('push', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', True),
            ('pull_request', 'refs/pull/1/merge', 'viniciusVitorio/manaleague', False),
            ('pull_request', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', False),
            ('push', 'refs/heads/eks-lab', 'attacker/manaleague', False),
            ('push', 'refs/heads/main', 'viniciusVitorio/manaleague', False),
            ('release', 'refs/tags/v1.0.0', 'viniciusVitorio/manaleague', False),
            ('workflow_dispatch', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', False),
        ):
            with self.subTest(event=event, ref=ref, repo=repo):
                self.assertEqual(allowed(condition(call), **{
                    'github.event_name': event, 'github.ref': ref,
                    'github.repository': repo}), expected)

    def test_reusable_gate_precedes_checks_and_disables_called_extra_ci(self):
        text = workflow('eks-lab.yml')
        events = text.split('\npermissions:', 1)[0]
        self.assertIn('  workflow_call:', events)
        self.assertIn('  workflow_dispatch:', events)
        self.assertNotRegex(events, r'(?m)^  (push|pull_request|pull_request_target|release|schedule):')
        blocks = jobs(text)
        gate = blocks['gate']
        self.assertIn('environment: eks-lab', gate)
        self.assertNotIn('needs:', gate)
        self.assertNotIn('uses:', gate)
        self.assertNotIn('id-token:', gate)
        self.assertIn('LAB_DEPLOY_ENABLED: ${{ vars.LAB_DEPLOY_ENABLED }}', gate)
        self.assertIn('[[ "$LAB_DEPLOY_ENABLED" == true ]]', gate)
        self.assertIn('needs: gate', blocks['checks'])
        self.assertIn('needs: [checks, gate]', blocks['images'])
        for event, enabled, expected in (
            ('push', 'false', False), ('push', '', False),
            ('push', 'true', True), ('workflow_dispatch', 'false', True),
            ('workflow_dispatch', 'true', True),
        ):
            with self.subTest(event=event, enabled=enabled):
                context = {'github.event_name': event, 'needs.gate.result': 'success',
                           'needs.gate.outputs.enabled': enabled,
                           'needs.checks.result': 'success'}
                self.assertEqual(allowed(condition(blocks['checks']), **context), expected)
                self.assertEqual(allowed(condition(blocks['images']), **context), expected)
                context['needs.checks.result'] = 'failure'
                self.assertFalse(allowed(condition(blocks['images']), **context))
        self.assertIn('scripts/verify.sh', blocks['checks'])
        for required in ('scripts/smoke.sh', 'local/test-tls-fixture.sh',
                         'lab-app:dev', 'lab-web:dev', 'sha256sum images.tar.gz',
                         'set -euo pipefail', 'actions/upload-artifact@'):
            self.assertIn(required, blocks['images'])
        self.assertEqual(blocks['images'].count("if: needs.gate.outputs.enabled == 'true'"), 2)
        self.assertEqual(blocks['images'].count('severity: HIGH,CRITICAL'), 2)

    def test_cloud_jobs_fail_closed_and_deploy_targets_only_this_run(self):
        text = workflow('eks-lab.yml')
        blocks = jobs(text)
        self.assertIn('needs: [publish, gate]', blocks['deploy'])
        self.assertIn('runs-on: [self-hosted, linux, "eks-lab-${{ github.run_id }}"]', blocks['deploy'])
        self.assertNotIn('runs-on: [self-hosted, linux, eks-lab]', text)
        self.assertEqual(text.split('\njobs:', 1)[0].count('id-token'), 0)
        self.assertIn('needs: [checks, images, gate]', blocks['publish'])
        for job in ('gate', 'publish', 'deploy'):
            for event, ref, repo, enabled, expected in (
                ('push', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', 'true', True),
                ('workflow_dispatch', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', 'true', True),
                ('push', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', 'false', job == 'gate'),
                ('workflow_dispatch', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', 'false', job == 'gate'),
                ('push', 'refs/heads/eks-lab', 'fork/manaleague', 'true', False),
                ('pull_request', 'refs/heads/eks-lab', 'viniciusVitorio/manaleague', 'true', False),
                ('push', 'refs/heads/main', 'viniciusVitorio/manaleague', 'true', False),
                ('release', 'refs/tags/v1', 'viniciusVitorio/manaleague', 'true', False),
            ):
                with self.subTest(job=job, event=event, ref=ref, repo=repo, enabled=enabled):
                    self.assertEqual(allowed(condition(blocks[job]), **{
                        'github.event_name': event, 'github.ref': ref,
                        'github.repository': repo, 'needs.gate.outputs.enabled': enabled}), expected)
        for job in ('publish', 'deploy'):
            block = blocks[job]
            self.assertIn('environment: eks-lab', block)
            self.assertIn('unset-current-credentials: true', block)
            self.assertIn('role/manaleague-eks-lab-github-deploy', block.replace(':role/', 'role/'))
            self.assertLess(block.index('Reject wrong lab role'), block.index('aws-actions/configure-aws-credentials@'))
            self.assertNotIn('AWS_ACCESS_KEY_ID', block)
            self.assertNotIn('AWS_SECRET_ACCESS_KEY', block)
        for required in ('sha256sum -c images.tar.gz.sha256', 'gzip -dc images.tar.gz | docker load',
                         'steps.publish.outputs.app_image', 'steps.publish.outputs.web_image'):
            self.assertIn(required, blocks['publish'])
        self.assertIn('APP_IMAGE: ${{ needs.publish.outputs.app_image }}', blocks['deploy'])
        self.assertIn('WEB_IMAGE: ${{ needs.publish.outputs.web_image }}', blocks['deploy'])

    def test_original_ssdlc_jobs_are_unchanged(self):
        # SHA256 of original ba2af7a job bodies, with portable LF line endings.
        expected = {
            'test': 'f1a5cb12cc0094508709621daad27ba64708fb0c763528b2d3ae4fc0e85bf3e1',
            'security': 'b8279ac1796e3c9cea0702d5023e4a43c6f32b18eeb3a138e167a36987867cf6',
            'sonar': 'c865e7ecd92094fab46cb68391deaff8440761bb1955501015ff374d7e691396',
            'waf': '34dee77086cf1d3d4ffc8b8e9fca65e15d78921ee9d4b481c9df1afe7982fdbc',
            'deploy': '56aa1cbb22460a73adedffaea1666e711654f4c1949b0d5d816fddc2cb088015',
        }
        actual = jobs(workflow('pipeline.yml'))
        for name, digest in expected.items():
            with self.subTest(job=name):
                self.assertEqual(hashlib.sha256(actual[name].rstrip('\n').encode()).hexdigest(), digest)


if __name__ == '__main__':
    unittest.main()
