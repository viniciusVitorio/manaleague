import importlib.util
import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
PATH = ROOT / 'infra/eks-lab/orchestrator/workflow_broker.py'

class BrokerWorkflowTest(unittest.TestCase):
    def test_workflow_routing_and_no_secrets(self):
        workflow = (ROOT / '.github/workflows/eks-lab.yml').read_text()
        self.assertIn('broker_enabled: ${{ steps.gate.outputs.broker_enabled }}', workflow)
        self.assertIn("needs.gate.outputs.broker_enabled != 'true'", workflow)
        broker = workflow.split('  deploy_broker:\n', 1)[1]
        self.assertIn("needs.gate.outputs.broker_enabled == 'true'", broker)
        self.assertIn('runs-on: ubuntu-latest', broker)
        self.assertNotIn('secrets.', broker)
        self.assertIn('workflow_broker.py', broker)

    def test_release_source_is_published_and_bound_to_broker(self):
        workflow = (ROOT / '.github/workflows/eks-lab.yml').read_text()
        self.assertIn('git archive --format=zip', workflow)
        self.assertIn('role/manaleague-eks-lab-release-publisher', workflow)
        self.assertIn('releases/${RELEASE}/${SOURCE_SHA256}.zip', workflow)
        self.assertIn('SOURCE_SHA256: ${{ needs.release_source.outputs.source_sha256 }}', workflow)
        self.assertIn('needs: [publish, gate, release_source]', workflow)

    def test_broker_deadlines_leave_room_for_queue_build_and_cleanup(self):
        import re
        workflow = (ROOT / '.github/workflows/eks-lab.yml').read_text().split('  deploy_broker:\n', 1)[1].split('  deploy_outcome:', 1)[0]
        terraform = (ROOT / 'infra/eks-lab/terraform/deploy_broker_infra.tf').read_text().split('resource "aws_codebuild_project" "deploy"', 1)[1]
        client = PATH.read_text()
        job = int(re.search(r'timeout-minutes:\s*(\d+)', workflow).group(1))
        build = int(re.search(r'build_timeout\s*=\s*(\d+)', terraform).group(1))
        queue = int(re.search(r'queued_timeout\s*=\s*(\d+)', terraform).group(1))
        wait = int(re.search(r'deploy\(\*args, timeout=(\d+)\)', client).group(1))
        self.assertGreaterEqual(queue, 5)  # CodeBuild API minimum
        self.assertEqual((queue, build, wait, job), (10, 30, 2700, 50))
        self.assertGreaterEqual(wait - (queue + build) * 60, 5 * 60)
        # Reserve at least four minutes for checkout/credentials and the stop call.
        self.assertGreaterEqual(job * 60 - wait, 4 * 60 + 20)

    def test_validation_and_execution_fail_closed(self):
        spec = importlib.util.spec_from_file_location('workflow_broker', PATH)
        assert spec is not None and spec.loader is not None
        module = importlib.util.module_from_spec(spec)
        sys.path.insert(0, str(PATH.parent))
        try:
            spec.loader.exec_module(module)
        finally:
            sys.path.pop(0)
        base = {'LAB_DEPLOY_ENABLED':'true', 'LAB_DEPLOY_BROKER_ENABLED':'true',
                'AWS_ACCOUNT_ID':'123456789012', 'AWS_REGION':'us-east-1',
                'LAB_DEPLOY_BROKER_ROLE_ARN':'arn:aws:iam::123456789012:role/manaleague-eks-lab-deploy-broker-invoker',
                'RELEASE':'a'*40, 'SOURCE_SHA256':'d'*64}
        for target in ('app','web'):
            base[target.upper()+'_IMAGE'] = '123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-'+target+'@sha256:'+'b'*64
        result = module.validate(base)
        self.assertEqual(result[1:], ('a'*40, 'sha256:'+'b'*64, 'sha256:'+'b'*64, 'd'*64))
        for key, value in [('SOURCE_SHA256',''), ('SOURCE_SHA256','A'*64), ('LAB_DEPLOY_ENABLED','false'), ('LAB_DEPLOY_BROKER_ENABLED',''), ('AWS_ACCOUNT_ID','bad'), ('AWS_REGION','x'), ('LAB_DEPLOY_BROKER_ROLE_ARN','bad'), ('RELEASE','a'*39), ('APP_IMAGE',base['APP_IMAGE']+'\n'), ('WEB_IMAGE',base['APP_IMAGE'])]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                module.validate(dict(base, **{key:value}))
        calls = []
        def fail(*args, **kwargs):
            calls.append((args, kwargs))
            raise module.BrokerError('failure')
        self.assertEqual(module.main(base, deploy=fail), 1)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][1], {'timeout': 2700})
        calls.clear()
        self.assertEqual(module.main(dict(base, LAB_DEPLOY_ENABLED='false'), deploy=fail), 1)
        self.assertEqual(calls, [])

if __name__ == '__main__':
    unittest.main()
