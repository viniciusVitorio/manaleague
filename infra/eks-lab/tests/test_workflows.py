"""Offline workflow policy tests; no actions/AWS commands are executed."""
from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]

class WorkflowPolicy(unittest.TestCase):
    def test_production_cannot_deploy_releases_or_lab_branch(self):
        text = (ROOT / '.github/workflows/pipeline.yml').read_text(encoding='utf-8')
        condition = text.split('  deploy:', 1)[1].split('    environment:', 1)[0]
        self.assertIn("github.ref == 'refs/heads/main'", condition)
        self.assertNotIn("|| github.event_name == 'release'", condition)
        daily = (ROOT / '.github/workflows/daily-tasks.yml').read_text(encoding='utf-8')
        self.assertIn("if: github.ref == 'refs/heads/main'", daily)

    def test_workflow_always_validates_offline_infra_and_manifests(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        checks = text.split('  checks:', 1)[1].split('  images:', 1)[0]
        self.assertIn('scripts/verify.sh', checks)
        self.assertIn('azure/setup-helm@', checks)
        self.assertIn('terraform_version: \'1.17.2\'', checks)
        verifier = (ROOT / 'infra/eks-lab/scripts/verify.sh').read_text()
        self.assertIn('scripts/validate_manifests.py', verifier)
        self.assertIn('actionlint', verifier)
        self.assertIn('addons/validate_offline.py', verifier)
        self.assertIn('unittest discover -s infra/eks-lab/runner/tests', verifier)
        self.assertIn('runner/operator.py render', verifier)
        offline = (ROOT / 'infra/eks-lab/addons/validate_offline.py').read_text()
        self.assertIn('[args.terraform, "test", "-no-color"]', offline)

    def test_lab_no_static_credentials_or_provisioning(self):
        path = ROOT / '.github/workflows/eks-lab.yml'
        self.assertTrue(path.exists(), 'isolated EKS workflow missing')
        text = path.read_text(encoding='utf-8')
        for forbidden in ('AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'terraform apply', 'terraform destroy', 'authorize-security-group', 'update-cluster-config'):
            self.assertNotIn(forbidden, text)
        for required in ('branches: [eks-lab]', "github.ref == 'refs/heads/eks-lab'", 'environment: eks-lab', 'cancel-in-progress: false', 'LAB_DEPLOY_ENABLED', 'id-token: write', '[self-hosted, linux, eks-lab]', 'composer audit', 'node --test', 'trivy', 'docker save', 'docker load'):
            self.assertIn(required, text)
        self.assertEqual(text.count('id-token: write'), 2)

    def test_actions_use_recorded_immutable_commit_pins(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        actions = re.findall(r'^\s+- uses:\s+([^@\s]+)@([^\s]+)', text, re.MULTILINE)
        self.assertTrue(actions)
        for action, ref in actions:
            self.assertRegex(ref, r'^[0-9a-f]{40}$', action)
        pins = json.loads((ROOT / 'infra/eks-lab/tests/action-pins.json').read_text())
        self.assertEqual({action for action, _ in actions}, set(pins['actions']))
        for action, ref in actions:
            self.assertEqual(ref, pins['actions'][action]['sha'])

    def test_ci_prepares_an_isolated_testing_env_from_the_public_example(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        checks = text.split('  checks:', 1)[1].split('  images:', 1)[0]
        self.assertIn('APP_ENV: testing', checks)
        self.assertIn('cp .env.example .env', checks)
        self.assertLess(checks.index('cp .env.example .env'), checks.index('php artisan test'))
        self.assertNotIn('cp .env ', checks)

    def test_image_transfer_blocks_partial_pipeline_success(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        for step in ('Export the exact smoke-tested images', 'Verify and load tested image artifact'):
            block = text.split('name: ' + step, 1)[1].split('      - ', 1)[0]
            self.assertIn('set -euo pipefail', block, step)

    def test_offline_ci_runs_image_checks_but_does_not_store_unused_cloud_artifacts(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        images = text.split('  images:', 1)[1].split('  gate:', 1)[0]
        self.assertIn('needs: [checks, gate]', images)
        # A skipped gate on pull requests must not skip build/smoke/scans.
        self.assertIn("!cancelled() && needs.checks.result == 'success'", images)
        for name in ('Smoke tested local images', 'Verify PostgreSQL TLS'):
            self.assertIn(name, images)
        export = images.split('name: Export the exact smoke-tested images', 1)[1].split('      - ', 1)[0]
        upload = images.split('uses: actions/upload-artifact@', 1)[1].split('      - ', 1)[0]
        for block in (export, upload):
            self.assertIn("if: needs.gate.outputs.enabled == 'true'", block)

    def test_kubectl_and_schema_match_default_cluster_minor(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        self.assertEqual(text.count('version: v1.37.0'), 2, 'pin kubectl in both checks and deploy jobs')
        validator = (ROOT / 'infra/eks-lab/scripts/validate_manifests.py').read_text()
        self.assertIn("'-kubernetes-version', '1.37.0'", validator)

if __name__ == '__main__':
    unittest.main()
