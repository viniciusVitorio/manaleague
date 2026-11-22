"""Session tests exercise real policy/orchestration; every remote call is fake."""
import contextlib
import importlib.util
import io
import json
import pathlib
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location('lab_' + name, ROOT / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


BASE = ['--account', '123456789012', '--region', 'us-east-1', '--cluster',
        'manaleague-eks-lab', '--vpc-cidr', '10.42.0.0/16', '--run-id', '44421131546',
        '--sha', 'a' * 40, '--api-cidr', '10.42.1.8/32']
GATES = ['--allow-cloud', '--confirm-lab', 'manaleague-eks-lab',
         '--ack-public-self-hosted-risk', '--ack-network-verified-in-vpc']


class OfflineSessionTests(unittest.TestCase):
    def test_default_is_deterministic_plan_without_external_io_or_secret(self):
        session = load('session')
        with mock.patch('subprocess.run', side_effect=AssertionError('process')), \
             mock.patch('socket.socket', side_effect=AssertionError('network')), \
             mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('read')), \
             mock.patch.object(pathlib.Path, 'mkdir', side_effect=AssertionError('write')):
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                self.assertEqual(session.main(BASE), 0)
            plan = json.loads(out.getvalue())
            self.assertEqual(plan['action'], 'plan')
            self.assertEqual(plan['run_label'], 'eks-lab-44421131546')
            self.assertEqual(len(plan['manifest']['items']), 4)
            self.assertNotIn('stringData', out.getvalue())

    def test_state_dir_is_explicit_absolute_local_and_plan_does_not_touch_it(self):
        session = load('session')
        for directory in ('relative/.local', 'relative', str(ROOT / 'not-private-state')):
            with self.subTest(directory=directory), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                session.parse(BASE + ['--state-dir', directory])
        with mock.patch.object(pathlib.Path, 'mkdir', side_effect=AssertionError('write')):
            args = session.parse(BASE + ['--state-dir', str(ROOT / '.local')])
            self.assertEqual(args.state_dir, str(ROOT / '.local'))

    def test_all_live_gates_precede_any_external_or_state_io(self):
        session = load('session')
        for action in ('start', 'cleanup'):
            for flags in ([], GATES[1:], GATES[:3], GATES[:4]):
                with self.subTest(action=action, flags=flags), \
                     mock.patch.object(session, 'Remote', side_effect=AssertionError('remote constructed')), \
                     mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('read')), \
                     contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as caught:
                    session.main([action] + BASE + flags)
                self.assertEqual(caught.exception.code, 2)

    def test_literal_validation_happens_before_remote(self):
        session = load('session')
        for key, value in (('--cluster', 'main'), ('--run-id', '01'), ('--sha', 'A' * 40),
                           ('--account', '123'), ('--region', 'us-east-1;id'),
                           ('--api-cidr', '10.99.1.8/32')):
            args = BASE.copy()
            args[args.index(key) + 1] = value
            with self.subTest(key=key), contextlib.redirect_stderr(io.StringIO()), \
                 mock.patch.object(session, 'Remote', side_effect=AssertionError('remote')), \
                 self.assertRaises(SystemExit):
                session.main(['start'] + args + GATES)
        args = BASE[:-2]
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            session.main(['start'] + args + GATES)


def live_args():
    return load('session').parse(['start'] + BASE + GATES)


class PreflightRemote:
    def __init__(self):
        self.calls = []
        self.account = '123456789012'
        self.cluster = {'name': 'manaleague-eks-lab',
            'arn': 'arn:aws:eks:us-east-1:123456789012:cluster/manaleague-eks-lab',
            'status': 'ACTIVE', 'endpoint': 'https://ABC.gr7.us-east-1.eks.amazonaws.com',
            'certificateAuthority': {'data': 'Y2VydA=='},
            'resourcesVpcConfig': {'vpcId': 'vpc-1234', 'endpointPrivateAccess': True}}
        self.run = {'id': 44421131546, 'repository': {'full_name': 'viniciusVitorio/manaleague'},
            'head_repository': {'full_name': 'viniciusVitorio/manaleague'}, 'head_branch': 'eks-lab',
            'head_sha': 'a' * 40, 'event': 'push', 'path': '.github/workflows/pipeline.yml',
            'status': 'in_progress', 'run_attempt': 1}
        self.jobs = [{'id': 42, 'run_id': 44421131546, 'head_sha': 'a' * 40,
            'name': 'eks-lab / deploy', 'status': 'queued', 'runner_id': None,
            'labels': ['self-hosted', 'linux', 'eks-lab-44421131546']}]
        self.runners = []
        self.gate = 'true'
        self.vpc = '10.42.0.0/16'
        self.eni_vpc = 'vpc-1234'

    def aws(self, *args):
        self.calls.append(('aws', args))
        if args[:2] == ('sts', 'get-caller-identity'):
            return {'Account': self.account}
        if args[:2] == ('eks', 'describe-cluster'):
            return {'cluster': self.cluster}
        if args[:2] == ('ec2', 'describe-vpcs'):
            return {'Vpcs': [{'VpcId': 'vpc-1234', 'CidrBlock': self.vpc}]}
        if args[:2] == ('ec2', 'describe-network-interfaces'):
            return {'NetworkInterfaces': [{'VpcId': self.eni_vpc, 'Description': 'Amazon EKS manaleague-eks-lab',
                'RequesterManaged': True, 'PrivateIpAddresses': [{'PrivateIpAddress': '10.42.1.8'}]}]}
        raise AssertionError(args)

    def gh(self, path, method='GET', collection=None):
        self.calls.append(('gh', path, method))
        if '/variables/' in path:
            return {'name': 'LAB_DEPLOY_ENABLED', 'value': self.gate}
        if path.endswith('/runners'):
            return self.runners
        if '/jobs?' in path:
            return self.jobs
        if '/runs/' in path:
            return self.run
        raise AssertionError(path)


class PreflightTests(unittest.TestCase):
    def test_readonly_preflight_binds_run_job_cluster_vpc_and_account(self):
        session = load('session')
        remote = PreflightRemote()
        proof = session.preflight(live_args(), remote)
        self.assertEqual(proof['job_id'], 42)
        self.assertEqual(proof['cluster']['arn'], remote.cluster['arn'])
        self.assertTrue(all(c[-1] == 'GET' for c in remote.calls if c[0] == 'gh'))

    def test_foreign_or_inactive_provenance_refuses_before_writes(self):
        session = load('session')
        mutations = [('account', None, '999999999999'), ('cluster', 'status', 'CREATING'),
            ('cluster', 'arn', 'arn:aws:eks:us-east-1:123456789012:cluster/main'),
            ('cluster', 'endpoint', 'http://not-tls'), ('run', 'head_sha', 'b' * 40),
            ('run', 'head_branch', 'main'), ('run', 'event', 'pull_request'),
            ('run', 'path', '.github/workflows/other.yml'), ('run', 'id', 1),
            ('run', 'head_repository', {'full_name': 'attacker/fork'}),
            ('run', 'repository', {'full_name': 'someone/other'}),
            ('run', 'status', 'completed'), ('gate', None, 'false'),
            ('vpc', None, '10.99.0.0/16'), ('eni_vpc', None, 'vpc-foreign')]
        for attr, key, value in mutations:
            remote = PreflightRemote()
            if key:
                getattr(remote, attr)[key] = value
            else:
                setattr(remote, attr, value)
            with self.subTest(attr=attr, key=key), self.assertRaises(ValueError):
                session.preflight(live_args(), remote)

    def test_only_single_queued_deploy_job_with_exact_unique_label_can_register(self):
        session = load('session')
        for patch in ({'labels': ['self-hosted', 'linux', 'eks-lab']}, {'status': 'in_progress'},
                      {'name': 'build'}, {'head_sha': 'b' * 40}, {'run_id': 1}, {'runner_id': 12}):
            remote = PreflightRemote()
            remote.jobs[0].update(patch)
            with self.subTest(patch=patch), self.assertRaises(ValueError):
                session.preflight(live_args(), remote)
        remote = PreflightRemote()
        remote.jobs *= 2
        with self.assertRaises(ValueError):
            session.preflight(live_args(), remote)

    def test_provenance_file_tampering_is_rejected(self):
        session = load('session')
        session.verify_provenance()
        with mock.patch.object(pathlib.Path, 'read_bytes', return_value=b'{}'), self.assertRaises(ValueError):
            session.verify_provenance()


class MemoryJournal:
    def __init__(self):
        self.value = None
        self.history = []
    def reserve(self, value):
        if self.value is not None:
            raise ValueError('session already exists')
        self.save(value)
    def save(self, value):
        import copy
        self.value = copy.deepcopy(value)
        self.history.append(copy.deepcopy(value))
    def read(self):
        import copy
        return copy.deepcopy(self.value)


class LifecycleRemote(PreflightRemote):
    def __init__(self):
        super().__init__()
        self.objects = {}
        self.deleted = []
        self.applied = []
        self.token = 'SECRET-REGISTRATION-SENTINEL'
        from datetime import datetime, timedelta, timezone
        self.expires = (datetime.now(timezone.utc) + timedelta(minutes=60)).isoformat()
        self.runner_created = False
        self.init_ok = True
        self.terminal = False
    def configure(self, cluster, directory):
        self.calls.append(('configure', cluster['arn']))
    def get(self, kind, name):
        import copy
        return copy.deepcopy(self.objects.get((kind, name)))
    def create(self, obj):
        import copy
        obj = copy.deepcopy(obj)
        key = obj['kind'], obj['metadata']['name']
        if key in self.objects:
            raise ValueError('exists')
        obj['metadata']['uid'] = 'uid-' + obj['kind']
        if obj['kind'] == 'Job':
            obj['status'] = {'succeeded': 1} if self.terminal else {'active': 1}
            name = obj['spec']['template']['spec']['initContainers'][-1]['env'][-1]['value']
            self.runners = [{'id': 901, 'name': name, 'status': 'online', 'busy': False,
                            'labels': [{'name': 'eks-lab-44421131546'}, {'name': 'self-hosted'}, {'name': 'linux'}]}]
            self.runner_created = True
        self.objects[key] = obj
        self.applied.append(copy.deepcopy(obj))
        return copy.deepcopy(obj)
    def pods(self, job_uid):
        job = next(o for (k, n), o in self.objects.items() if k == 'Job')
        return [{'metadata': {'name': 'pod-1', 'namespace': 'manaleague-eks-lab-runner', 'uid': 'pod-uid',
                    'ownerReferences': [{'kind': 'Job', 'name': job['metadata']['name'], 'uid': job_uid, 'controller': True}]},
                 'status': {'initContainerStatuses': [{'name': 'register', 'state': {'terminated': {'exitCode': 0 if self.init_ok else 1}}}]}}]
    def delete(self, kind, name, uid):
        if self.objects[(kind, name)]['metadata']['uid'] != uid:
            raise ValueError('foreign UID')
        self.deleted.append((kind, name, uid))
        del self.objects[(kind, name)]
    def gh(self, path, method='GET', collection=None):
        if path.endswith('/registration-token'):
            self.calls.append(('gh', path, method))
            return {'token': self.token, 'expires_at': self.expires}
        if '/runners/' in path and method == 'DELETE':
            self.calls.append(('gh', path, method))
            self.runners = [r for r in self.runners if r['id'] != int(path.split('/')[-1])]
            return None
        return super().gh(path, method, collection)


class LifecycleTests(unittest.TestCase):
    def start(self, remote=None, journal=None):
        session = load('session')
        session.POLL_ATTEMPTS = 1
        remote = remote or LifecycleRemote()
        journal = journal or MemoryJournal()
        with mock.patch.object(session, 'pause', return_value=None):
            result = session.start(live_args(), remote, journal, ROOT)
        return session, remote, journal, result

    def test_start_orders_prerequisites_stdin_secret_job_then_verifies_online_and_deletes_secret(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            session, remote, journal, result = self.start()
        self.assertEqual(result['status'], 'online')
        self.assertEqual([o['kind'] for o in remote.applied],
                         ['Namespace', 'ServiceAccount', 'NetworkPolicy', 'Secret', 'Job'])
        self.assertEqual(journal.value['runner_id'], 901)
        self.assertTrue(journal.value['registration_removed'])
        self.assertEqual([x[0] for x in remote.deleted], ['Secret'])
        self.assertNotIn(remote.token, json.dumps(journal.history) + out.getvalue())
        self.assertNotIn(remote.token, str(remote.calls))
        self.assertIn(remote.token, remote.applied[3]['stringData']['token'])
        with self.assertRaises(ValueError):
            self.start(remote, journal)

    def test_start_refuses_foreign_namespace_or_existing_runner_before_registration(self):
        for foreign in ('namespace', 'runner'):
            remote = LifecycleRemote()
            if foreign == 'namespace':
                remote.objects[('Namespace', 'manaleague-eks-lab-runner')] = {'metadata': {'uid': 'foreign'}}
            else:
                remote.runners = [{'id': 33, 'name': 'old', 'labels': [{'name': 'eks-lab-44421131546'}]}]
            with self.subTest(foreign=foreign), self.assertRaises(ValueError):
                self.start(remote)
            self.assertEqual(remote.applied, [])
            self.assertFalse(any(c[-1] == 'POST' for c in remote.calls))

    def test_registration_api_empty_or_wrong_shape_refuses_without_secret(self):
        for value in (None, [], 'unexpected-body'):
            remote = LifecycleRemote()
            original = remote.gh
            def gh(path, method='GET', collection=None):
                if path.endswith('/registration-token'):
                    return value
                return original(path, method, collection)
            remote.gh = gh
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.start(remote)
            self.assertNotIn('Secret', [o['kind'] for o in remote.applied])

    def test_registration_token_must_expire_soon_and_not_be_expired(self):
        for expires in ('2000-01-01T00:00:00Z', '2099-01-01T00:00:00Z', 'invalid', None):
            remote = LifecycleRemote()
            remote.expires = expires
            with self.subTest(expires=expires), self.assertRaises(ValueError):
                self.start(remote)
            self.assertNotIn('Secret', [o['kind'] for o in remote.applied])

    def test_init_failure_retains_secret_and_reports_non_online(self):
        remote = LifecycleRemote()
        remote.init_ok = False
        session, remote, journal, result = self.start(remote)
        self.assertEqual(result['status'], 'registration_failed')
        self.assertEqual(remote.deleted, [])
        self.assertFalse(journal.value['registration_removed'])

    def test_terminal_job_reports_terminal_not_online(self):
        remote = LifecycleRemote()
        remote.terminal = True
        session, remote, journal, result = self.start(remote)
        self.assertEqual(result['status'], 'job_complete')

    def test_cleanup_is_uid_bound_verified_and_keeps_namespace(self):
        session, remote, journal, result = self.start()
        args = live_args()
        args.action = 'cleanup'
        result = session.cleanup(args, remote, journal, ROOT)
        self.assertEqual(result['status'], 'cleaned')
        self.assertEqual(remote.runners, [])
        self.assertIn(('Namespace', 'manaleague-eks-lab-runner'), remote.objects)
        self.assertEqual([d[0] for d in remote.deleted], ['Secret', 'Job'])
        self.assertEqual(session.cleanup(args, remote, journal, ROOT)['status'], 'cleaned')

    def test_cleanup_refuses_unrecorded_namespace_uid_or_runner_id_renamed(self):
        for mutation in ('missing_uid', 'renamed_runner'):
            session, remote, journal, result = self.start()
            if mutation == 'missing_uid':
                journal.value['namespace_uid'] = None
            else:
                remote.runners[0]['name'] = 'different-name'
            before = list(remote.deleted)
            args = live_args()
            args.action = 'cleanup'
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                session.cleanup(args, remote, journal, ROOT)
            self.assertEqual(before, remote.deleted)

    def test_cleanup_rejects_foreign_job_or_namespace_uid_before_any_delete(self):
        for kind, key in (('Job', 'job_name'), ('Namespace', 'namespace')):
            session, remote, journal, result = self.start()
            remote.objects[(kind, journal.value[key])]['metadata']['uid'] = 'FOREIGN'
            before = list(remote.deleted)
            args = live_args()
            args.action = 'cleanup'
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                session.cleanup(args, remote, journal, ROOT)
            self.assertEqual(remote.deleted, before)
            self.assertEqual(len(remote.runners), 1)


class TransportTests(unittest.TestCase):
    def test_transport_passes_registration_secret_only_stdin_and_suppresses_failures(self):
        import subprocess
        session = load('session')
        remote = session.Remote(live_args())
        remote.kube = ['kubectl', '--kubeconfig', 'PRIVATE', '--context', 'EXACT']
        secret = {'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': 'runner-registration-token',
                  'namespace': 'manaleague-eks-lab-runner'}, 'stringData': {'token': 'SENTINEL'}}
        with mock.patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0, '{}', '')) as run:
            remote.create(secret)
            args, kwargs = run.call_args
            self.assertNotIn('SENTINEL', str(args) + str(kwargs.get('env')))
            self.assertIn('SENTINEL', kwargs['input'])
            self.assertTrue(kwargs['capture_output'])
            self.assertNotIn('--save-config', args[0])
        with mock.patch('subprocess.run', side_effect=subprocess.CalledProcessError(
                1, ['kubectl', 'SENTINEL'], output='SENTINEL', stderr='SENTINEL')):
            with self.assertRaises(ValueError) as error:
                remote.create(secret)
            self.assertNotIn('SENTINEL', str(error.exception))
            self.assertIsNone(error.exception.__cause__)

    def test_gh_uses_stored_auth_not_pat_flags_or_token_environment(self):
        import subprocess
        session = load('session')
        remote = session.Remote(live_args())
        with mock.patch.dict('os.environ', {'GH_TOKEN': 'CREDENTIAL-SENTINEL',
                'GITHUB_TOKEN': 'CREDENTIAL-SENTINEL', 'GH_DEBUG': 'api', 'PATH': 'test-bin'}, clear=True), \
             mock.patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0,
                    'STORED-CREDENTIAL', '')) as run:
            self.assertEqual(remote.credential(), 'STORED-CREDENTIAL')
            cmd = run.call_args.args[0]
            env = run.call_args.kwargs['env']
            self.assertEqual(cmd, ['gh', 'auth', 'token', '--hostname', 'github.com'])
            self.assertNotIn('CREDENTIAL-SENTINEL', str(cmd) + str(env))
            self.assertNotIn('GH_DEBUG', env)

    def test_delete_uses_atomic_uid_precondition_in_api_body(self):
        session = load('session')
        remote = session.Remote(live_args())
        remote.kube = ['kubectl', '--kubeconfig', 'PRIVATE', '--context', 'EXACT']
        with mock.patch.object(remote, 'command', return_value='{}') as command:
            remote.delete('Job', 'runner-44421131546-12345678', 'uid-exact')
            cmd = command.call_args.args[0]
            body = json.loads(command.call_args.kwargs['input'])
            self.assertIn('--raw', cmd)
            self.assertEqual(body['preconditions'], {'uid': 'uid-exact'})
            self.assertEqual(body['propagationPolicy'], 'Foreground')

    def test_exclusive_kubeconfig_is_generated_without_human_context_or_credentials(self):
        import tempfile
        session = load('session')
        remote = session.Remote(live_args())
        cluster = PreflightRemote().cluster
        with tempfile.TemporaryDirectory(dir=ROOT) as temp:
            with mock.patch.object(remote, 'command', return_value='{}'):
                remote.configure(cluster, pathlib.Path(temp))
            config = json.loads((pathlib.Path(temp) / 'kubeconfig.json').read_text())
            self.assertEqual(config['clusters'][0]['cluster']['server'], cluster['endpoint'])
            self.assertEqual(config['clusters'][0]['cluster']['certificate-authority-data'], 'Y2VydA==')
            self.assertEqual(config['current-context'], cluster['arn'])
            self.assertNotIn('token', config['users'][0]['user'])
            self.assertIn('eks-lab', config['users'][0]['user']['exec']['args'])
            with self.assertRaises(ValueError):
                remote.configure(cluster, pathlib.Path(temp))

    def test_private_journal_is_exclusive_atomic_and_rejects_symlinks(self):
        import tempfile
        session = load('session')
        with tempfile.TemporaryDirectory(dir=ROOT) as temp:
            path = pathlib.Path(temp) / '.local' / 'session-44421131546.json'
            journal = session.Journal(path)
            journal.reserve({'status': 'reserved'})
            with self.assertRaises(ValueError):
                journal.reserve({'status': 'overwrite'})
            journal.save({'status': 'online'})
            self.assertEqual(journal.read(), {'status': 'online'})
            self.assertEqual(list(path.parent.glob('*.tmp')), [])
            with mock.patch.object(pathlib.Path, 'is_symlink', return_value=True), self.assertRaises(ValueError):
                journal.read()

    def test_cli_live_dispatch_uses_only_explicitly_gated_transport(self):
        session = load('session')
        with mock.patch.object(session, 'Remote') as remote, \
             mock.patch.object(session, 'Journal'), mock.patch.object(session, 'private_root', return_value=ROOT), \
             mock.patch.object(session, 'session_lock', return_value=contextlib.nullcontext()), \
             mock.patch.object(session, 'start', return_value={'status': 'online'}) as start, \
             contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(session.main(['start'] + BASE + GATES), 0)
            start.assert_called_once()
            remote.assert_called_once()


class WindowsBridgeTests(unittest.TestCase):
    def test_explicit_bridge_uses_fixed_powershell_and_stdin_captured_in_memory(self):
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        with mock.patch.object(remote, 'command', return_value='protocol=https\nhost=github.com\nusername=fixture\npassword=TOKEN-SENTINEL\n\n') as command:
            self.assertEqual(remote.windows_credential(), 'TOKEN-SENTINEL')
            argv = command.call_args.args[0]
            self.assertEqual(argv[:4], ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command'])
            self.assertNotIn('TOKEN-SENTINEL', str(argv) + str(command.call_args.kwargs))
            self.assertEqual(command.call_args.kwargs['input'], 'protocol=https\nhost=github.com\n\n')
            self.assertIn('$env:HOME = $env:USERPROFILE', argv[-1])
            self.assertIn("$env:GCM_INTERACTIVE = 'Never'", argv[-1])
            self.assertIn("$env:GIT_TERMINAL_PROMPT = '0'", argv[-1])
            self.assertIn('-C $env:USERPROFILE credential fill', argv[-1])
            self.assertNotIn('config --', argv[-1])

    def test_bridge_http_disables_redirects_and_never_logs_auth_or_error_bodies(self):
        import urllib.error
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        response = mock.MagicMock()
        response.read.return_value = b'{"name":"LAB_DEPLOY_ENABLED","value":"true"}'
        response.__enter__.return_value = response
        opener = mock.Mock()
        opener.open.return_value = response
        with mock.patch.object(remote, 'windows_credential', return_value='TOKEN-SENTINEL'), \
             mock.patch('urllib.request.build_opener', return_value=opener) as build:
            result = remote.gh('repos/viniciusVitorio/manaleague/environments/eks-lab/variables/LAB_DEPLOY_ENABLED')
            self.assertEqual(result['value'], 'true')
            request = opener.open.call_args.args[0]
            self.assertEqual(request.get_header('Authorization'), 'Bearer TOKEN-SENTINEL')
            self.assertTrue(request.full_url.startswith('https://api.github.com/repos/viniciusVitorio/manaleague/'))
            handler = build.call_args.args[0]
            with self.assertRaises(ValueError):
                handler.redirect_request(request, None, 302, 'redirect', {}, 'https://attacker.invalid')
            opener.open.side_effect = urllib.error.URLError('TOKEN-SENTINEL')
            with self.assertRaises(ValueError) as error:
                remote.gh('repos/viniciusVitorio/manaleague/actions/runners')
            self.assertNotIn('TOKEN-SENTINEL', str(error.exception))

    def test_bridge_pagination_stays_on_fixed_host_and_checks_total(self):
        session = load('session')
        args = session.parse(['start'] + BASE + GATES + ['--github-auth', 'windows-gcm'])
        remote = session.Remote(args)
        pages = [{'total_count': 101, 'runners': [{'id': i} for i in range(100)]},
                 {'total_count': 101, 'runners': [{'id': 100}]}]
        with mock.patch.object(remote, 'http', side_effect=pages) as http:
            self.assertEqual(len(remote.gh('repos/viniciusVitorio/manaleague/actions/runners', collection='runners')), 101)
            self.assertIn('page=2', http.call_args.args[0])
            self.assertTrue(all('https:' not in call.args[0] for call in http.call_args_list))


class RunLabelTests(unittest.TestCase):
    def test_renderer_cli_accepts_optional_run_label_without_remote_io(self):
        helper = load('operator')
        out = io.StringIO()
        with contextlib.redirect_stdout(out), mock.patch('subprocess.run', side_effect=AssertionError('process')):
            helper.main(['render', '--account', '123456789012', '--region', 'us-east-1',
                '--vpc-cidr', '10.42.0.0/16', '--run-id', '44421131546'])
        self.assertIn('--labels eks-lab,eks-lab-44421131546', out.getvalue())

    def test_optional_label_is_exact_and_defaults_unchanged(self):
        renderer = load('operator')
        default = renderer.render('123456789012', 'us-east-1', '10.42.0.0/16')
        objects = renderer.render('123456789012', 'us-east-1', '10.42.0.0/16', run_id='44421131546')
        script = objects[-1]['spec']['template']['spec']['initContainers'][-1]['command'][-1]
        self.assertIn('--labels eks-lab,eks-lab-44421131546', script)
        self.assertNotIn('eks-lab-44421131546', str(default))
        for value in ('0', '01', ' 123', '123;id', 123, '', '1' * 21):
            with self.subTest(value=value), self.assertRaises(ValueError):
                renderer.render('123456789012', 'us-east-1', '10.42.0.0/16', run_id=value)
