"""Regressoes do empacotamento do ensaio CI; sem Docker/AWS nesta unidade.
A execucao Docker real do mesmo wrapper e uma verificacao separada.
"""
from pathlib import Path
import re
import unittest

LAB = Path(__file__).resolve().parents[1]


class CiSmokePackagingTests(unittest.TestCase):
    def test_all_local_smoke_dependencies_enter_the_private_ci_directory(self):
        wrapper = (LAB / 'scripts/smoke.sh').read_text()
        smoke = (LAB / 'local/smoke.sh').read_text()
        dependencies = re.findall(r'^sh (test-[a-z-]+\.sh)$', smoke, re.MULTILINE)
        self.assertTrue(dependencies, 'A verificacao deve encontrar dependencias reais do smoke')
        match = re.search(r'cp .*\{([^}]+)\}', wrapper)
        self.assertIsNotNone(match)
        assert match is not None
        copied_files = match.group(1).split(',')
        for dependency in dependencies:
            with self.subTest(dependency=dependency):
                self.assertIn(dependency, copied_files)

    def test_nonsecret_edge_bind_mount_is_readable_by_nonroot_nginx(self):
        wrapper = (LAB / 'scripts/smoke.sh').read_text()
        self.assertIn('umask 077', wrapper, 'Segredos continuam privados')
        self.assertRegex(wrapper, r'chmod (?:0644|644) .*edge\.conf')
        self.assertNotRegex(wrapper, r'chmod (?:0?777|0?666).*env')


if __name__ == '__main__':
    unittest.main()
