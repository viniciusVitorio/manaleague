"""The lab-only reusable workflow forwards the reviewed certificate metadata."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class HttpsWorkflow(unittest.TestCase):
    def test_deploy_receives_exact_certificate_variable_not_a_secret_or_discovery(self):
        text = (ROOT / '.github/workflows/eks-lab.yml').read_text(encoding='utf-8')
        deploy = text.split('\n  deploy:\n', 1)[1]
        self.assertIn('ACM_CERTIFICATE_ARN: ${{ vars.ACM_CERTIFICATE_ARN }}', deploy)
        self.assertIn('APP_URL: ${{ vars.APP_URL }}', deploy)
        self.assertNotIn('route53', deploy.lower())
        self.assertNotIn('secrets.ACM_CERTIFICATE_ARN', deploy)


if __name__ == '__main__':
    unittest.main()
