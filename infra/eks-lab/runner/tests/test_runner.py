import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class RunnerContract(unittest.TestCase):
    def test_renderer_defaults_are_offline_and_namespace_owned(self):
        self.assertTrue((ROOT / 'operator.py').exists(), 'operator helper not implemented')
        spec = importlib.util.spec_from_file_location('operator_helper', ROOT / 'operator.py')
        helper = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(helper)
        objects = helper.render('123456789012', 'us-east-1', '10.42.0.0/16')
        self.assertEqual(objects[0]['metadata']['name'], 'manaleague-eks-lab-runner')
        for obj in objects[1:]:
            self.assertEqual(obj['metadata']['namespace'], 'manaleague-eks-lab-runner')
        forbidden = {'PersistentVolume', 'PersistentVolumeClaim', 'Role', 'RoleBinding', 'ClusterRole', 'ClusterRoleBinding', 'Service', 'Ingress'}
        self.assertFalse({o['kind'] for o in objects} & forbidden)
        job = next(o for o in objects if o['kind'] == 'Job')
        pod = job['spec']['template']['spec']
        self.assertFalse(pod['automountServiceAccountToken'])
        self.assertEqual(pod['restartPolicy'], 'Never')
        self.assertTrue(pod['securityContext']['runAsNonRoot'])
        self.assertEqual(pod['securityContext']['runAsUser'], 1001)
        self.assertTrue(all('hostPath' not in v and 'persistentVolumeClaim' not in v for v in pod['volumes']))
        for c in pod['initContainers'] + pod['containers']:
            self.assertRegex(c['image'], r'@sha256:[0-9a-f]{64}$')
            self.assertFalse(c['securityContext']['allowPrivilegeEscalation'])
            self.assertTrue(c['securityContext']['readOnlyRootFilesystem'])
            self.assertEqual(c['securityContext']['capabilities']['drop'], ['ALL'])
        self.assertNotIn('registration-token', str(pod['containers']))

if __name__ == '__main__':
    unittest.main()
