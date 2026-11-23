import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1] / 'terraform'

class CodeBuildRunnerContract(unittest.TestCase):
    def test_opt_in_runner_terraform_exists(self):
        self.assertTrue((ROOT / 'codebuild_runner.tf').is_file(), 'optional CodeBuild runner Terraform is missing')
