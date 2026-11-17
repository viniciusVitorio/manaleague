"""Offline safety tests: no cluster, registry, credentials or external API required."""
import importlib.util
import pathlib
import unittest
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[1]


def load_helper():
    spec = importlib.util.spec_from_file_location('runner_operator', ROOT / 'operator.py')
    assert spec is not None and spec.loader is not None
    helper = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(helper)
    return helper


class ValidationTests(unittest.TestCase):
    def test_invalid_parameters_are_rejected_not_normalized(self):
        helper = load_helper()
        invalid = [
            ('123', 'us-east-1', '10.42.0.0/16'),
            ('12345678901x', 'us-east-1', '10.42.0.0/16'),
            (' 123456789012', 'us-east-1', '10.42.0.0/16'),
            (123456789012, 'us-east-1', '10.42.0.0/16'),
            ('000000000000', 'us-east-1', '10.42.0.0/16'),
            ('123456789012', 'us-east-1; touch injected', '10.42.0.0/16'),
            ('123456789012', 'US-EAST-1', '10.42.0.0/16'),
            ('123456789012', 'cn-north-1', '10.42.0.0/16'),
            ('123456789012', None, '10.42.0.0/16'),
            ('123456789012', 'us-east-1', '0.0.0.0/0'),
            ('123456789012', 'us-east-1', '169.254.0.0/16'),
            ('123456789012', 'us-east-1', '10.42.0.1/16'),
            ('123456789012', 'us-east-1', '10.0.0.0/8'),
            ('123456789012', 'us-east-1', '192.0.2.0/24'),
            ('123456789012', 'us-east-1', 'fd00::/64'),
            ('123456789012', 'us-east-1', None),
        ]
        for args in invalid:
            with self.subTest(args=args), self.assertRaises(ValueError):
                helper.render(*args)


class PolicyTests(unittest.TestCase):
    def test_private_api_requires_explicit_lab_host_routes(self):
        helper = load_helper()
        objects = helper.render('123456789012', 'us-east-1', '10.42.0.0/16',
                                api_cidrs=('10.42.1.8/32', '10.42.2.9/32'))
        policy = next(o for o in objects if o['kind'] == 'NetworkPolicy')['spec']
        self.assertEqual(policy['ingress'], [])
        self.assertEqual(policy['podSelector'], {})
        self.assertEqual(set(policy['policyTypes']), {'Ingress', 'Egress'})
        private = policy['egress'][2:]
        self.assertEqual(private, [{
            'to': [{'ipBlock': {'cidr': '10.42.1.8/32'}}, {'ipBlock': {'cidr': '10.42.2.9/32'}}],
            'ports': [{'protocol': 'TCP', 'port': 443}],
        }])
        for cidrs in [('10.99.1.8/32',), ('169.254.169.254/32',), ('10.42.0.0/16',),
                      ('10.42.1.8',), ('0.0.0.0/0',), ('fd00::1/128',), '10.42.1.8/32', None]:
            with self.subTest(cidrs=cidrs), self.assertRaises(ValueError):
                helper.render('123456789012', 'us-east-1', '10.42.0.0/16', api_cidrs=cidrs)

    def test_default_public_policy_excludes_private_production_and_imds(self):
        import ipaddress
        objects = load_helper().render('123456789012', 'us-east-1', '10.42.0.0/16')
        egress = next(o for o in objects if o['kind'] == 'NetworkPolicy')['spec']['egress']
        self.assertEqual(len(egress), 2, 'default must fail closed for unknown API IPs')
        dns = egress[0]
        self.assertEqual(dns['to'], [{'namespaceSelector': {'matchLabels': {
            'kubernetes.io/metadata.name': 'kube-system'}},
            'podSelector': {'matchLabels': {'k8s-app': 'kube-dns'}}}])
        self.assertEqual(dns['ports'], [{'protocol': 'UDP', 'port': 53}, {'protocol': 'TCP', 'port': 53}])
        public = egress[1]
        self.assertEqual(public['ports'], [{'protocol': 'TCP', 'port': 443}, {'protocol': 'TCP', 'port': 80}])
        block = public['to'][0]['ipBlock']
        self.assertEqual(block['cidr'], '0.0.0.0/0')
        excluded = [ipaddress.ip_network(c) for c in block['except']]
        for address in ['10.42.1.8', '10.99.1.8', '172.16.1.1', '192.168.1.1',
                        '169.254.169.254', '169.254.170.2', '127.0.0.1', '100.100.1.1',
                        '0.0.0.1', '224.0.0.1', '240.1.1.1']:
            self.assertTrue(any(ipaddress.ip_address(address) in n for n in excluded), address)
        self.assertFalse(any(ipaddress.ip_address('1.1.1.1') in n for n in excluded))


class OfflineTests(unittest.TestCase):
    def test_import_and_render_do_not_read_credentials_or_use_network_or_processes(self):
        import builtins
        import json
        import os
        import socket
        import subprocess
        import urllib.request
        from unittest import mock
        # Pre-import validation dependencies before denying external operations.
        import ipaddress  # noqa: F401
        import re  # noqa: F401
        source = (ROOT / 'operator.py').read_text(encoding='utf-8')
        sentinel = 'DO-NOT-READ-HUMAN-CREDENTIALS'
        with mock.patch.dict(os.environ, {'AWS_SECRET_ACCESS_KEY': sentinel, 'GH_TOKEN': sentinel}), \
                mock.patch.object(builtins, 'open', side_effect=AssertionError('filesystem access')), \
                mock.patch.object(socket, 'socket', side_effect=AssertionError('network access')), \
                mock.patch.object(subprocess, 'Popen', side_effect=AssertionError('subprocess access')), \
                mock.patch.object(urllib.request, 'urlopen', side_effect=AssertionError('HTTP access')), \
                mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('credential read')), \
                mock.patch.object(pathlib.Path, 'read_bytes', side_effect=AssertionError('credential read')):
            namespace: dict[str, Any] = {'__name__': 'offline_test'}
            exec(compile(source, 'operator.py', 'exec'), namespace)
            first = namespace['render']('123456789012', 'us-east-1', '10.42.0.0/16')
            second = namespace['render']('123456789012', 'us-east-1', '10.42.0.0/16')
            self.assertEqual(first, second)
            self.assertNotIn(sentinel, json.dumps(first))
        first[0]['metadata']['name'] = 'mutated'
        self.assertNotEqual(first, second)

    def test_cli_only_renders_json_and_rejects_live_commands_and_bad_inputs(self):
        import json
        import subprocess
        import sys
        command = [sys.executable, '-I', '-B', str(ROOT / 'operator.py')]
        args = ['render', '--account', '123456789012', '--region', 'us-east-1',
                '--vpc-cidr', '10.42.0.0/16']
        result = subprocess.run(command + args, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        document = json.loads(result.stdout)
        self.assertEqual(document, {'apiVersion': 'v1', 'kind': 'List', 'items':
                                   load_helper().render('123456789012', 'us-east-1', '10.42.0.0/16')})
        result = subprocess.run(command + args + ['--api-cidr', '10.42.1.8/32'],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('10.42.1.8/32', result.stdout)
        for bad in [['apply'], ['register'], args + ['--allow-cloud'],
                    args[:-1] + ['0.0.0.0/0'], args + ['--api-cidr', '10.99.1.8/32']]:
            result = subprocess.run(command + bad, capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertEqual(result.stdout, '')
            self.assertNotIn('Traceback', result.stderr)


class PodTests(unittest.TestCase):
    def test_registration_secret_is_init_only_and_tool_path_is_saved_before_config(self):
        objects = load_helper().render('123456789012', 'us-east-1', '10.42.0.0/16')
        self.assertEqual([o['kind'] for o in objects], ['Namespace', 'ServiceAccount', 'NetworkPolicy', 'Job'])
        job = objects[-1]['spec']
        self.assertEqual(job['backoffLimit'], 0)
        self.assertEqual(job['completions'], 1)
        self.assertEqual(job['parallelism'], 1)
        self.assertLessEqual(job['activeDeadlineSeconds'], 7200)
        pod = job['template']['spec']
        registration = [v for v in pod['volumes'] if 'secret' in v]
        self.assertEqual(len(registration), 1)
        self.assertEqual(registration[0]['secret']['secretName'], 'runner-registration-token')
        self.assertFalse(registration[0]['secret']['optional'])
        mounts = [(c['name'], m) for c in pod['initContainers'] + pod['containers']
                  for m in c['volumeMounts'] if m['name'] == registration[0]['name']]
        self.assertEqual(len(mounts), 1)
        self.assertEqual(mounts[0][0], 'register')
        self.assertTrue(mounts[0][1]['readOnly'])
        script = pod['initContainers'][-1]['command'][-1]
        self.assertIn('ACTIONS_RUNNER_INPUT_TOKEN="$(cat /registration/token)"', script)
        self.assertNotIn('--token', script)
        self.assertNotIn('--pat', script)
        self.assertNotIn('--replace', script)
        self.assertIn('--ephemeral', script)
        self.assertIn('--disableupdate', script)
        self.assertIn('--labels eks-lab', script)
        self.assertIn('unset ACTIONS_RUNNER_INPUT_TOKEN', script)
        self.assertIn('rm -rf /runner/_diag', script)
        self.assertLess(script.index('export PATH="/tools/bin:$PATH"'), script.index('./config.sh'))
        main = pod['containers'][0]
        self.assertNotIn('registration', str(main))
        self.assertNotIn('secretKeyRef', str(main))
        self.assertNotIn('envFrom', main)
        self.assertIn({'name': 'RUNNER_TOOL_CACHE', 'value': '/runner/_work/_tool'}, main['env'])
        self.assertEqual(next(m for m in main['volumeMounts'] if m['name'] == 'tools')['readOnly'], True)
        for c in pod['initContainers'] + pod['containers']:
            self.assertNotIn('envFrom', c)
            self.assertEqual(c['securityContext']['capabilities']['drop'], ['ALL'])
            for field in ('requests', 'limits'):
                self.assertEqual(set(c['resources'][field]), {'cpu', 'memory', 'ephemeral-storage'})
        self.assertFalse(pod['automountServiceAccountToken'])
        for field in ('hostNetwork', 'hostPID', 'hostIPC'):
            self.assertFalse(pod[field])
        self.assertEqual(pod['securityContext']['seccompProfile'], {'type': 'RuntimeDefault'})
        self.assertFalse(any('projected' in v or 'hostPath' in v or 'persistentVolumeClaim' in v
                             for v in pod['volumes']))
        self.assertEqual(objects[1]['automountServiceAccountToken'], False)
        self.assertNotIn('annotations', objects[1]['metadata'])

    def test_aws_copy_keeps_executable_beside_libraries_without_absolute_install_links(self):
        helper = load_helper()
        self.assertIn('cp -R /usr/local/aws-cli/v2/current/dist/. /tools/aws-cli/', helper.COPY_AWS)
        self.assertNotIn('cp -RL', helper.COPY_AWS)
        self.assertIn('exec /tools/aws-cli/aws "$@"', helper.COPY_AWS)
        self.assertNotIn('exec /tools/aws-cli/v2/current', helper.COPY_AWS,
                         'the installer current symlink targets an absolute /usr/local path')
        self.assertIn('aws --version', helper.RUN)

    def test_embedded_shell_programs_are_syntactically_valid(self):
        import shutil
        import subprocess
        helper = load_helper()
        shell = shutil.which('bash')
        self.assertIsNotNone(shell, 'Bash is required to validate embedded Linux shell scripts')
        assert shell is not None
        for script in [helper.REGISTER, helper.COPY_AWS, helper.RUN]:
            result = subprocess.run([shell, '-n'], input=script, capture_output=True,
                                    text=True, errors='replace', check=False)
            self.assertEqual(result.returncode, 0, result.stderr)


class ProvenanceTests(unittest.TestCase):
    def test_pins_are_backed_by_recorded_public_registry_manifests(self):
        import hashlib
        import json
        helper = load_helper()
        path = ROOT / 'images.json'
        self.assertTrue(path.exists(), 'public image provenance has not been recorded')
        provenance = json.loads(path.read_text(encoding='utf-8'))
        self.assertEqual({row['image'] for row in provenance},
                         {helper.RUNNER_IMAGE, helper.AWS_IMAGE})
        for row in provenance:
            raw = (ROOT / row['manifest_file']).read_bytes()
            self.assertEqual('sha256:' + hashlib.sha256(raw).hexdigest(), row['index_digest'])
            index = json.loads(raw)
            amd64 = [m['digest'] for m in index['manifests'] if
                     m['platform'] == {'architecture': 'amd64', 'os': 'linux'}]
            self.assertEqual(amd64, [row['amd64_digest']])
            self.assertIn(row['image'].split('@')[1], [row['index_digest'], row['amd64_digest']])
            self.assertTrue(row['source'].startswith('https://'))


if __name__ == '__main__':
    unittest.main()
