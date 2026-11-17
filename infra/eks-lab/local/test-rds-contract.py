"""Offline RDS runtime/manifests contract: no AWS or Kubernetes network calls."""
import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class RdsContract(unittest.TestCase):
    def test_cloud_config_requires_private_rds_tls(self):
        data = json.loads((ROOT / 'kubernetes/base/app-config.yaml').read_text())['data']
        self.assertEqual('rds', data.get('LAB_DATABASE_MODE'))
        self.assertEqual('__DB_HOST__', data['DB_HOST'])
        self.assertEqual('verify-full', data['DB_SSLMODE'])
        self.assertEqual('/var/www/html/infra/eks-lab/runtime/certs/global-bundle.pem', data['PGSSLROOTCERT'])
        self.assertEqual('manaleague_eks_lab', data['DB_DATABASE'])
        self.assertEqual('manaleague_lab', data['DB_USERNAME'])
        self.assertEqual('', data['DB_URL'])

    def test_no_cloud_postgres_or_pvc(self):
        base = ROOT / 'kubernetes/base'
        customization = json.loads((base / 'kustomization.yaml').read_text())
        self.assertNotIn('postgres.yaml', customization['resources'])
        for path in (ROOT / 'kubernetes').rglob('*.yaml'):
            for doc in path.read_text().split('\n---\n'):
                resource = json.loads(doc)
                self.assertNotIn(resource.get('kind'), ['StatefulSet', 'PersistentVolumeClaim'])
                self.assertNotEqual('postgres-ingress', resource.get('metadata', {}).get('name'))
                if resource.get('kind') == 'Service':
                    self.assertNotEqual('postgres', resource.get('metadata', {}).get('name'))

    def test_deployer_only_observes_retired_postgres_resources(self):
        role = json.loads((ROOT / 'kubernetes/bootstrap/rbac.yaml').read_text().split('\n---\n')[0])
        def verbs(group, resource):
            granted = set()
            for rule in role['rules']:
                if group in rule['apiGroups'] and resource in rule['resources']:
                    granted.update(rule['verbs'])
            return granted
        self.assertEqual({'get', 'list', 'watch'}, verbs('apps', 'statefulsets'))
        self.assertEqual({'get', 'list', 'watch'}, verbs('', 'persistentvolumeclaims'))
        for resource in ['deployments', 'replicasets']:
            self.assertTrue({'create', 'update', 'patch', 'delete'} <= verbs('apps', resource))
        self.assertFalse(any('*' in rule['resources'] or '*' in rule['verbs'] for rule in role['rules']))

    def test_jobs_preserve_safety_entrypoint(self):
        for path in (ROOT / 'kubernetes/jobs').glob('*.yaml'):
            pod = json.loads(path.read_text())['spec']['template']['spec']
            for container in pod['containers']:
                self.assertNotIn('command', container)
                self.assertTrue(container['args'])

    def test_vendored_public_ca_checksum_and_source(self):
        certs = ROOT / 'runtime/certs'
        source = json.loads((certs / 'source.json').read_text())
        self.assertEqual('https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem', source['source'])
        data = (certs / 'global-bundle.pem').read_bytes()
        self.assertEqual(source['sha256'], hashlib.sha256(data).hexdigest())
        self.assertEqual(source['sha256'], (certs / 'global-bundle.pem.sha256').read_text().split()[0])
        self.assertIn(b'-----BEGIN CERTIFICATE-----', data)
        self.assertNotIn(b'PRIVATE KEY', data)


if __name__ == '__main__':
    unittest.main(verbosity=2)
