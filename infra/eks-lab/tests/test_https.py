"""Offline HTTPS contracts. All identities/certificates are explicit MOCK fixtures."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from test_rds import ENV as MOCK_RDS_ENV

LAB = Path(__file__).resolve().parents[1]
MOCK_ACCOUNT = '123456789012'
MOCK_REGION = 'us-east-1'
MOCK_HOST = 'eks.manaleague.com.br'
MOCK_ORIGIN = 'https://' + MOCK_HOST
MOCK_ARN = f'arn:aws:acm:{MOCK_REGION}:{MOCK_ACCOUNT}:certificate/11111111-2222-4333-8444-555555555555'
MOCK_ENV = {**MOCK_RDS_ENV, 'AVATAR_BUCKET': 'manaleague-eks-lab-mock', 'AWS_REGION': MOCK_REGION, 'VPC_CIDR': '10.73.0.0/16', 'APP_ROLE_ARN': f'arn:aws:iam::{MOCK_ACCOUNT}:role/manaleague-eks-lab-app', 'ALLOWED_CIDRS': '192.0.2.1/32', 'SUBNET_IDS': 'subnet-abc', 'ALB_SECURITY_GROUP_ID': 'sg-abc', 'APP_URL': MOCK_ORIGIN, 'ACM_CERTIFICATE_ARN': MOCK_ARN}
MOCK_CERT = {'CertificateArn': MOCK_ARN, 'DomainName': MOCK_HOST, 'SubjectAlternativeNames': [MOCK_HOST], 'Status': 'ISSUED', 'Type': 'AMAZON_ISSUED', 'InUseBy': []}
MOCK_TAGS = [{'Key': 'Project', 'Value': 'manaleague-eks-lab'}, {'Key': 'Environment', 'Value': 'eks-lab'}]

def load(name):
    spec = importlib.util.spec_from_file_location('mock_test_' + name, LAB / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class HttpsTests(unittest.TestCase):
    def setUp(self):
        deny = patch('subprocess.run', side_effect=AssertionError('Unmocked external command forbidden'))
        deny.start()
        self.addCleanup(deny.stop)
        self.lab = load('lab')

    def test_exact_origin_requires_single_same_account_region_certificate(self):
        with patch.dict(os.environ, MOCK_ENV, clear=True):
            values = self.lab.deployment_values(MOCK_ACCOUNT)
        self.assertEqual(values['APP_URL'], MOCK_ORIGIN)
        self.assertEqual(values['ACM_CERTIFICATE_ARN'], MOCK_ARN)
        for arn in ('', MOCK_ARN + ',' + MOCK_ARN, MOCK_ARN.replace(MOCK_ACCOUNT, '999999999999'), MOCK_ARN.replace(MOCK_REGION, 'eu-west-1'), MOCK_ARN + '\n', MOCK_ARN[:-1]):
            with self.subTest(arn=arn), patch.dict(os.environ, {**MOCK_ENV, 'ACM_CERTIFICATE_ARN': arn}, clear=True):
                with self.assertRaises(ValueError):
                    self.lab.deployment_values(MOCK_ACCOUNT)

    def test_noncanonical_or_production_urls_fail_closed_without_input_leak(self):
        blocked = [MOCK_ORIGIN + suffix for suffix in ('/', ':443', '/path', '?', '#', '?mock-secret=value', '#mock-secret', '\n', '\t')]
        blocked += ['http://' + MOCK_HOST, '//' + MOCK_HOST, MOCK_ORIGIN.upper(), 'https://' + MOCK_HOST + '.', 'https://user:mock-secret@' + MOCK_HOST, 'https://' + MOCK_HOST + '@evil.test', 'https://' + MOCK_HOST + '.evil.test', 'https://manaleague.com.br', 'http://www.manaleague.com.br', 'http://admin.manaleague.com.br', 'https://lab.test', 'http://MANaLEAGUE.com.br.', 'http://eks.manaleague.com.br.', 'http://lab.test:mock-secret', 'http://[mock-secret', 'http://lab.test\\@manaleague.com.br', ' http://lab.test', 'http://lab.test?', 'http://lab.test#']
        for url in blocked:
            with self.subTest(url=url), patch.dict(os.environ, {**MOCK_ENV, 'APP_URL': url, 'ACM_CERTIFICATE_ARN': ''}, clear=True):
                with self.assertRaises(ValueError) as raised:
                    self.lab.deployment_values(MOCK_ACCOUNT)
                self.assertNotIn('mock-secret', str(raised.exception))
        for url in ('', 'http://lab.example.test', 'http://lab.test:8080/', 'http://k8s-lab-123.us-east-1.elb.amazonaws.com'):
            with self.subTest(http=url), patch.dict(os.environ, {**MOCK_ENV, 'APP_URL': url, 'ACM_CERTIFICATE_ARN': ''}, clear=True):
                self.assertEqual(self.lab.deployment_values(MOCK_ACCOUNT)['APP_URL'], url or 'http://eks-lab.invalid')

    def test_https_runtime_host_cookie_wss_and_all_rollout_checksums(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / 'base'
            self.lab.render_tree(LAB / 'kubernetes/base', base, MOCK_ENV)
            secrets = {'APP_KEY': 'explicit-mock-key', 'DB_PASSWORD': 'explicit-mock-password'}
            def checksums():
                import re
                result = []
                for path in base.glob('*.yaml'):
                    if path.name == 'kustomization.yaml':
                        continue
                    for doc in re.split(r'^---\s*$', path.read_text(), flags=re.MULTILINE):
                        obj = json.loads(doc)
                        if obj['kind'] in ('Deployment', 'CronJob'):
                            template = obj['spec']['template'] if obj['kind'] == 'Deployment' else obj['spec']['jobTemplate']['spec']['template']
                            result.append(template['metadata']['annotations']['eks-lab.manaleague.test/runtime-config'])
                return result
            self.lab.configure_runtime(base, MOCK_ORIGIN, secrets)
            data = json.loads((base / 'app-config.yaml').read_text())['data']
            self.assertEqual(data['SESSION_SECURE_COOKIE'], 'true')
            self.assertEqual(data['SESSION_COOKIE'], '__Host-manaleague_eks_lab_session')
            self.assertEqual(data['SESSION_DOMAIN'], '')
            self.assertEqual(data['SESSION_PATH'], '/')
            self.assertEqual(data['REVERB_CLIENT_URL'], 'wss://' + MOCK_HOST)
            self.assertEqual(data['REVERB_ALLOWED_ORIGINS'], MOCK_HOST)
            self.assertEqual([data[key] for key in ('REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME')], ['reverb', '8080', 'http'])
            secure = checksums()
            self.assertEqual(len(secure), 6)
            self.assertEqual(len(set(secure)), 1)
            self.lab.configure_runtime(base, 'http://mock-lab.test', secrets)
            data = json.loads((base / 'app-config.yaml').read_text())['data']
            self.assertEqual(data['SESSION_SECURE_COOKIE'], 'false')
            self.assertEqual(data['SESSION_COOKIE'], 'manaleague_eks_lab_session')
            self.assertEqual(data['REVERB_CLIENT_URL'], '')
            self.assertTrue(all(a != b for a, b in zip(secure, checksums())))
            for url in ('https://other.test', MOCK_ORIGIN + '/'):
                before = (base / 'app-config.yaml').read_bytes()
                with self.assertRaises(ValueError):
                    self.lab.configure_runtime(base, url, secrets)
                self.assertEqual((base / 'app-config.yaml').read_bytes(), before)

    def test_mail_stays_in_the_log_driver_unless_the_exact_lab_sender_is_supplied(self):
        import os
        secrets = {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'}
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / 'base'
            self.lab.render_tree(LAB / 'kubernetes/base', base, MOCK_ENV)
            # No sender configured: delivery must stay local to the Pod log.
            self.lab.configure_runtime(base, MOCK_ORIGIN, secrets)
            data = json.loads((base / 'app-config.yaml').read_text())['data']
            self.assertEqual(data['MAIL_MAILER'], 'log')
            self.assertEqual(data['MAIL_FROM_ADDRESS'], 'demo@manaleague.test')
            previous = os.environ.get('MAIL_FROM_ADDRESS')
            try:
                os.environ['MAIL_FROM_ADDRESS'] = 'eks-lab@manaleague.com.br'
                self.lab.configure_runtime(base, MOCK_ORIGIN, secrets)
                data = json.loads((base / 'app-config.yaml').read_text())['data']
                self.assertEqual(data['MAIL_MAILER'], 'ses')
                self.assertEqual(data['MAIL_FROM_ADDRESS'], 'eks-lab@manaleague.com.br')
                for rejected in ('contato@manaleague.com.br', 'eks-lab@evil.com', 'eks-lab@manaleague.com.br ',
                                 'eks-lab@manaleague.com.br\n', 'not-an-address', ''):
                    os.environ['MAIL_FROM_ADDRESS'] = rejected
                    before = (base / 'app-config.yaml').read_bytes()
                    with self.subTest(rejected=rejected), self.assertRaises(ValueError):
                        self.lab.configure_runtime(base, MOCK_ORIGIN, secrets)
                    self.assertEqual((base / 'app-config.yaml').read_bytes(), before)
            finally:
                os.environ.pop('MAIL_FROM_ADDRESS', None)
                if previous is not None:
                    os.environ['MAIL_FROM_ADDRESS'] = previous

    def test_rendered_ingress_exact_tls_contract_preserves_base_and_private_publisher(self):
        self.assertTrue(callable(getattr(self.lab, 'configure_ingress', None)), 'missing rendered ingress configurator')
        original = (LAB / 'kubernetes/base/ingress.yaml').read_bytes()
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / 'base'
            self.lab.render_tree(LAB / 'kubernetes/base', base, MOCK_ENV)
            self.lab.configure_ingress(base, MOCK_ENV)
            ingress = json.loads((base / 'ingress.yaml').read_text())
            annotations = ingress['metadata']['annotations']
            prefix = 'alb.ingress.kubernetes.io/'
            self.assertEqual(json.loads(annotations[prefix + 'listen-ports']), [{'HTTP': 80}, {'HTTPS': 443}])
            self.assertEqual(annotations[prefix + 'certificate-arn'], MOCK_ARN)
            self.assertEqual(annotations[prefix + 'ssl-policy'], 'ELBSecurityPolicy-TLS13-1-2-Res-2021-06')
            self.assertEqual(annotations[prefix + 'ssl-redirect'], '443')
            self.assertEqual(ingress['spec']['rules'][0]['host'], MOCK_HOST)
            self.assertNotIn('tls', ingress['spec'])
            self.assertEqual(annotations[prefix + 'load-balancer-name'], 'manaleague-eks-lab')
            self.assertEqual(ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'], 'deny-api')
            self.lab.configure_runtime(base, MOCK_ORIGIN, {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'})
            self.lab.manifest_guard(base, MOCK_ENV['DB_HOST'], MOCK_REGION, values=MOCK_ENV)
            mutations = [lambda obj: obj['spec']['rules'][0].update(host='www.manaleague.com.br'),
                         lambda obj: obj['metadata']['annotations'].update({prefix + 'certificate-arn': MOCK_ARN + ',' + MOCK_ARN}),
                         lambda obj: obj['metadata']['annotations'].pop(prefix + 'certificate-arn'),
                         lambda obj: obj['metadata']['annotations'].update({prefix + 'ssl-redirect': '80'}),
                         lambda obj: obj['metadata']['annotations'].update({prefix + 'listen-ports': '[{"HTTP":80}]'}),
                         lambda obj: obj['metadata']['annotations'].update({prefix + 'ssl-policy': 'ELBSecurityPolicy-2016-08'}),
                         lambda obj: obj['spec']['rules'].append(copy.deepcopy(obj['spec']['rules'][0])),
                         lambda obj: obj['spec'].update(tls=[{'hosts': [MOCK_HOST]}]),
                         lambda obj: obj['spec']['rules'][0]['http']['paths'].pop(0)]
            for mutate in mutations:
                obj = copy.deepcopy(ingress)
                mutate(obj)
                (base / 'ingress.yaml').write_text(json.dumps(obj))
                with self.subTest(mutation=mutate), self.assertRaises(ValueError):
                    self.lab.manifest_guard(base, MOCK_ENV['DB_HOST'], MOCK_REGION, values=MOCK_ENV)
            (base / 'ingress.yaml').write_text(json.dumps(ingress))
            data = json.loads((base / 'app-config.yaml').read_text())
            data['data']['DB_SSLMODE'] = 'disable'
            (base / 'app-config.yaml').write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, 'database'):
                self.lab.manifest_guard(base, MOCK_ENV['DB_HOST'], MOCK_REGION, values=MOCK_ENV)
        self.assertEqual((LAB / 'kubernetes/base/ingress.yaml').read_bytes(), original)

    def test_https_deploy_certificate_preflight_precedes_secrets_and_writes(self):
        import base64
        mock_secrets = {'APP_KEY': 'base64:' + base64.b64encode(b'x' * 32).decode(), 'DEMO_PASSWORD': 'mock-p' * 6, 'DB_PASSWORD': 'mock-db-password'}
        mock_images = {t: f'{MOCK_ACCOUNT}.dkr.ecr.{MOCK_REGION}.amazonaws.com/manaleague-eks-lab-{t}@sha256:' + 'a' * 64 for t in ('app', 'web')}
        mock_env = {**MOCK_ENV, **mock_secrets, 'RELEASE': 'f' * 40, **{t.upper() + '_IMAGE': image for t, image in mock_images.items()}}
        for invalid in (True, False):
            calls, rendered_config, rendered_ingress = [], {}, {}
            def explicit_mock_run(args, **kwargs):
                calls.append(args)
                if args[:3] == ['aws', 'acm', 'describe-certificate']:
                    self.assertIn(MOCK_ARN, args)
                    self.assertIn(MOCK_REGION, args)
                    return json.dumps({'Certificate': {**MOCK_CERT, 'Status': 'PENDING_VALIDATION' if invalid else 'ISSUED'}})
                if args[:3] == ['aws', 'acm', 'list-tags-for-certificate']:
                    self.assertIn(MOCK_ARN, args)
                    return json.dumps({'Tags': MOCK_TAGS})
                if args[:2] == ['kubectl', 'kustomize']:
                    base = Path(args[2])
                    rendered_config.update(json.loads((base / 'app-config.yaml').read_text()))
                    rendered_ingress.update(json.loads((base / 'ingress.yaml').read_text()))
                    return 'kind: ConfigMap\n---\nkind: Deployment\n'
                if 'get' in args:
                    kind = args[args.index('get') + 1]
                    if kind in ('statefulset', 'pvc'):
                        return '{"items": []}'
                    if kind == 'secret' and args[-1] == 'json':
                        name = args[args.index('get') + 2]
                        keys = ('DEMO_PASSWORD',) if name == 'manaleague-demo' else ('APP_KEY', 'DB_PASSWORD')
                        return json.dumps({'data': {key: base64.b64encode(mock_secrets[key].encode()).decode() for key in keys}})
                    if kind == 'ingress':
                        return json.dumps({**rendered_ingress, 'status': {'loadBalancer': {'ingress': [{'hostname': 'k8s-mock-123.us-east-1.elb.amazonaws.com'}]}}})
                    if kind == 'configmap':
                        return json.dumps(rendered_config)
                    if kind == 'deployment':
                        return json.dumps({'spec': {'template': {'spec': {'containers': [{'image': image} for image in mock_images.values()]}}}})
                return ''
            import contextlib
            import io
            with self.subTest(invalid=invalid), patch.dict(os.environ, mock_env, clear=True), patch.object(self.lab, 'run', side_effect=explicit_mock_run), patch.object(self.lab, 'rds_password', return_value=mock_secrets['DB_PASSWORD']) as secret_read, patch.object(self.lab, 'kube_config', return_value=['kubectl']) as kube, patch.object(self.lab, 'postdeploy_smoke') as smoke, contextlib.redirect_stdout(io.StringIO()):
                if invalid:
                    with self.assertRaises(ValueError):
                        self.lab.deploy(MOCK_ACCOUNT, MOCK_REGION)
                    secret_read.assert_not_called()
                    kube.assert_not_called()
                    self.assertEqual([call[:3] for call in calls], [['aws', 'acm', 'describe-certificate'], ['aws', 'acm', 'list-tags-for-certificate']])
                else:
                    self.lab.deploy(MOCK_ACCOUNT, MOCK_REGION)
                    self.assertEqual([call[:3] for call in calls[:2]], [['aws', 'acm', 'describe-certificate'], ['aws', 'acm', 'list-tags-for-certificate']])
                    self.assertEqual(rendered_config['data']['SESSION_SECURE_COOKIE'], 'true')
                    smoke.assert_called_once_with(MOCK_ORIGIN, mock_secrets['APP_KEY'])
                    self.assertTrue(any('get' in call and 'ingress' in call for call in calls))
                self.assertFalse(any('route53' in call for call in calls))

    def test_final_https_ingress_and_config_readback_fail_before_smoke(self):
        for tamper in ('certificate', 'host', 'redirect', 'runtime', 'late-certificate', None):
            with self.subTest(tamper=tamper), tempfile.TemporaryDirectory() as directory:
                base = Path(directory) / 'base'
                self.lab.render_tree(LAB / 'kubernetes/base', base, MOCK_ENV)
                self.lab.configure_ingress(base, MOCK_ENV)
                self.lab.configure_runtime(base, MOCK_ORIGIN, {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'})
                expected_ingress = json.loads((base / 'ingress.yaml').read_text())
                mock_writes = []
                def explicit_mock_run(args, **kwargs):
                    if 'apply' in args:
                        mock_writes.append(args)
                    if 'get' in args and 'ingress' in args:
                        obj = copy.deepcopy(expected_ingress)
                        if tamper == 'certificate' or (tamper == 'late-certificate' and mock_writes):
                            obj['metadata']['annotations']['alb.ingress.kubernetes.io/certificate-arn'] = MOCK_ARN + ',foreign'
                        if tamper == 'redirect':
                            obj['metadata']['annotations'].pop('alb.ingress.kubernetes.io/ssl-redirect')
                        if tamper == 'host':
                            obj['spec']['rules'][0]['host'] = 'www.manaleague.com.br'
                        obj['status'] = {'loadBalancer': {'ingress': [{'hostname': 'k8s-mock-123.us-east-1.elb.amazonaws.com'}]}}
                        return json.dumps(obj)
                    if 'get' in args and 'configmap' in args:
                        obj = json.loads((base / 'app-config.yaml').read_text())
                        if tamper == 'runtime':
                            obj['data']['SESSION_SECURE_COOKIE'] = 'false'
                        return json.dumps(obj)
                    return ''
                with patch.object(self.lab, 'run', side_effect=explicit_mock_run), patch.object(self.lab, 'postdeploy_smoke') as smoke:
                    if tamper:
                        with self.assertRaises(ValueError):
                            self.lab.finalize_endpoint(['kubectl'], base, MOCK_ENV, {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'})
                        smoke.assert_not_called()
                    else:
                        self.assertEqual(self.lab.finalize_endpoint(['kubectl'], base, MOCK_ENV, {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'}), MOCK_ORIGIN)
                        smoke.assert_called_once_with(MOCK_ORIGIN, 'mock-key')

    def test_guard_rejects_runtime_tls_downgrades_and_http_tls_discovery(self):
        for secure in (False, True):
            values = {**MOCK_ENV, 'APP_URL': MOCK_ORIGIN if secure else 'http://mock.test', 'ACM_CERTIFICATE_ARN': MOCK_ARN if secure else ''}
            with self.subTest(secure=secure), tempfile.TemporaryDirectory() as directory:
                base = Path(directory) / 'base'
                self.lab.render_tree(LAB / 'kubernetes/base', base, values)
                self.lab.configure_ingress(base, values)
                self.lab.configure_runtime(base, values['APP_URL'], {'APP_KEY': 'mock-key', 'DB_PASSWORD': 'mock-password'})
                self.lab.manifest_guard(base, values['DB_HOST'], MOCK_REGION, values=values)
                config = json.loads((base / 'app-config.yaml').read_text())
                mutations = {'APP_URL': 'https://www.manaleague.com.br', 'SESSION_DOMAIN': '.manaleague.com.br', 'SESSION_COOKIE': 'manaleague_session', 'SESSION_PATH': '/bad', 'REVERB_HOST': MOCK_HOST, 'REVERB_SCHEME': 'https', 'REVERB_PORT': '443', 'REVERB_ALLOWED_ORIGINS': '*'}
                if secure:
                    mutations.update(SESSION_SECURE_COOKIE='false', REVERB_CLIENT_URL='ws://' + MOCK_HOST)
                else:
                    mutations.update(SESSION_SECURE_COOKIE='true', REVERB_CLIENT_URL='wss://' + MOCK_HOST)
                for key, value in mutations.items():
                    obj = copy.deepcopy(config)
                    obj['data'][key] = value
                    (base / 'app-config.yaml').write_text(json.dumps(obj))
                    with self.subTest(key=key), self.assertRaises(ValueError):
                        self.lab.manifest_guard(base, values['DB_HOST'], MOCK_REGION, values=values)
                (base / 'app-config.yaml').write_text(json.dumps(config))
                if secure:
                    with self.assertRaises(ValueError):
                        self.lab.manifest_guard(base, values['DB_HOST'], MOCK_REGION)
                else:
                    ingress = json.loads((base / 'ingress.yaml').read_text())
                    ingress['spec']['rules'][0]['host'] = MOCK_HOST
                    (base / 'ingress.yaml').write_text(json.dumps(ingress))
                    with self.assertRaises(ValueError):
                        self.lab.manifest_guard(base, values['DB_HOST'], MOCK_REGION, values=values)

    def test_shared_host_helpers_reject_parser_and_dns_canonicalization_confusion(self):
        contract = load('https_contract')
        for url in ('http://lab.test\\evil', 'http://manaleague。com.br', 'http://ｍanaleague.com.br', 'http://lab.test\x00', None, [], 'http://lab.test:', 'http://lab.test:080', 'http://lab.test:mock-secret'):
            with self.subTest(url=url), self.assertRaises(ValueError) as raised:
                contract.validate_app_url(url)
            self.assertNotIn('mock-secret', str(raised.exception))
        for arn, account, region in ((None, MOCK_ACCOUNT, MOCK_REGION), ([], MOCK_ACCOUNT, MOCK_REGION), (MOCK_ARN, [], MOCK_REGION), (MOCK_ARN, MOCK_ACCOUNT, None)):
            with self.subTest(arn=arn, account=account, region=region), self.assertRaises(ValueError):
                contract.validate_certificate_arn(arn, account=account, region=region)

    def test_certificate_ownership_identity_and_exclusive_host_contract(self):
        contract = load('https_contract')
        self.assertTrue(callable(getattr(contract, 'validate_certificate', None)), 'missing certificate validator')
        validate = lambda cert, tags=MOCK_TAGS: contract.validate_certificate(cert, tags, account=MOCK_ACCOUNT, region=MOCK_REGION, arn=MOCK_ARN)
        self.assertIsNone(validate(MOCK_CERT))
        lab_alb = f'arn:aws:elasticloadbalancing:{MOCK_REGION}:{MOCK_ACCOUNT}:loadbalancer/app/manaleague-eks-lab/0123456789abcdef'
        self.assertIsNone(validate({**MOCK_CERT, 'InUseBy': [lab_alb]}))
        changes = [
            {'CertificateArn': MOCK_ARN.replace(MOCK_ACCOUNT, '999999999999')},
            {'DomainName': 'manaleague.com.br'}, {'DomainName': '*.manaleague.com.br'},
            {'SubjectAlternativeNames': [MOCK_HOST, 'www.manaleague.com.br']},
            {'SubjectAlternativeNames': ['*.manaleague.com.br']}, {'SubjectAlternativeNames': []},
            {'Status': 'PENDING_VALIDATION'}, {'Type': 'IMPORTED'},
            {'InUseBy': [lab_alb.replace('manaleague-eks-lab/', 'production/')]},
            {'InUseBy': [lab_alb.replace(MOCK_ACCOUNT, '999999999999')]},
            {'InUseBy': [lab_alb.replace('manaleague-eks-lab/', 'manaleague-eks-lab-evil/')]},
            {'InUseBy': [lab_alb.replace(MOCK_REGION, 'eu-west-1')]},
        ]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(ValueError):
                validate({**MOCK_CERT, **change})
        for tags in ([], MOCK_TAGS[:1], [{'Key': 'Project', 'Value': 'production'}, MOCK_TAGS[1]], MOCK_TAGS + [MOCK_TAGS[0]], None):
            with self.subTest(tags=tags), self.assertRaises(ValueError):
                validate(MOCK_CERT, tags)
        for cert in (None, {}, [], {'CertificateArn': 'mock-secret\n'}):
            with self.subTest(cert=cert), self.assertRaises(ValueError) as raised:
                validate(cert)
            self.assertNotIn('mock-secret', str(raised.exception))

if __name__ == '__main__':
    unittest.main()
