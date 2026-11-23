import pathlib
import re
import unittest

LAB = pathlib.Path(__file__).resolve().parents[1]
REPO = LAB.parents[1]
TF = (LAB / 'terraform' / 'codebuild_runner.tf').read_text()
WORKFLOW = (REPO / '.github/workflows/eks-lab.yml').read_text()
CALLER = (REPO / '.github/workflows/pipeline.yml').read_text()
VERSIONS = (LAB / 'terraform/versions.tf').read_text()


class IntegratedRunnerContract(unittest.TestCase):
    def test_runner_label_uses_terraform_project_name(self):
        base = re.search(r'name\s*=\s*"(manaleague-eks-lab)"', VERSIONS).group(1)
        suffix = re.search(r'runner_name\s*=\s*"\$\{local.name\}([^" ]+)"', TF).group(1)
        label = re.search(r'CODEBUILD_RUNNER_LABEL:\s*codebuild-(.+?)-\$\{\{ github.run_id', WORKFLOW).group(1)
        self.assertEqual(base + suffix, label)

    def test_webhook_matches_calling_workflow(self):
        caller_name = re.search(r'^name:\s*(.+)$', CALLER, re.M).group(1).strip()
        self.assertIn('uses: ./.github/workflows/eks-lab.yml', CALLER)
        direct_name = re.search(r'^name:\s*(.+)$', WORKFLOW, re.M).group(1).strip()
        self.assertIn('pattern = "^(' + caller_name + '|' + direct_name + ')$"', TF)

    def test_http_smoke_has_runner_egress(self):
        self.assertRegex(TF, r'resource "aws_vpc_security_group_egress_rule" "runner_http"\s*\{[^}]*from_port\s*=\s*80\s+to_port\s*=\s*80')

    def test_runner_cannot_be_enabled_until_security_redesign(self):
        variable = re.search(r'variable "enable_codebuild_runner"\s*\{(.*?)\n\}', TF, re.S).group(1)
        self.assertRegex(variable, r'condition\s*=\s*!var.enable_codebuild_runner')
        self.assertIn('validation', variable)


if __name__ == '__main__':
    unittest.main()
