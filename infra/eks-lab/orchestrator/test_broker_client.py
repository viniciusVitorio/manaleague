import importlib.util
import json
import pathlib
import unittest
from unittest import mock

PATH = pathlib.Path(__file__).with_name('broker_client.py')


def load_client():
    spec = importlib.util.spec_from_file_location('broker_client', PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BrokerIntegrationTests(unittest.TestCase):
    def test_real_handler_start_and_status_through_injected_cli(self):
        client = load_client()
        spec = importlib.util.spec_from_file_location('deploy_broker', PATH.with_name('deploy_broker.py'))
        broker = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(broker)
        build_id = broker.PROJECT + ':abc-123'
        codebuild = mock.Mock()
        codebuild.start_build.return_value = {'build': {'id': build_id}}
        codebuild.batch_get_builds.side_effect = [
            {'builds': [{'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': status}]}
            for status in ('IN_PROGRESS', 'SUCCEEDED')
        ]
        events = []

        def invoke(command, **kwargs):
            event = json.loads(command[command.index('--payload') + 1])
            events.append(event)
            try:
                response = broker.lambda_handler(event, None)
                metadata = {'StatusCode': 200}
            except Exception as exc:
                response = {'errorMessage': str(exc)}
                metadata = {'StatusCode': 200, 'FunctionError': 'Unhandled'}
            pathlib.Path(command[-1]).write_text(json.dumps(response), encoding='utf-8')
            return mock.Mock(returncode=0, stdout=json.dumps(metadata), stderr='')

        with mock.patch.dict('sys.modules', {'boto3': mock.Mock(client=mock.Mock(return_value=codebuild))}):
            self.assertEqual(client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64,
                                           run=invoke, sleep=lambda _: None), 'SUCCEEDED')
        self.assertEqual([event['action'] for event in events], ['start', 'status', 'status'])
        codebuild.start_build.assert_called_once()
        self.assertEqual(codebuild.batch_get_builds.call_args_list, [mock.call(ids=[build_id])] * 2)


class BrokerClientTests(unittest.TestCase):
    def test_start_timeout_warns_unknown_build_and_stop_failure_is_sanitized(self):
        client = load_client()
        def timeout(command, **kwargs):
            raise client.subprocess.TimeoutExpired(command, 1, stderr='secret')
        with self.assertRaises(client.BrokerError) as caught:
            client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64, run=timeout)
        self.assertIn('reconcile', str(caught.exception))
        self.assertNotIn('secret', str(caught.exception))

    def test_timeout_attempts_stop_and_never_accepts_success(self):
        client = load_client()
        now, events = [0], []
        def invoke(command, **kwargs):
            event = json.loads(command[command.index('--payload') + 1])
            events.append(event)
            body = {'build_id': 'manaleague-eks-lab-deploy:abc'} if event['action'] == 'start' else {'status': 'SUCCEEDED'}
            if event['action'] == 'status':
                now[0] = 11
            pathlib.Path(command[-1]).write_text(json.dumps(body), encoding='utf-8')
            return mock.Mock(returncode=0, stdout='{"StatusCode":200}')
        with self.assertRaisesRegex(client.BrokerError, 'timed out'):
            client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64, timeout=10, run=invoke, clock=lambda: now[0])
        self.assertEqual([x['action'] for x in events], ['start', 'status', 'stop'])
        self.assertEqual(events[-1]['build_id'], 'manaleague-eks-lab-deploy:abc')

    def test_success_starts_and_polls_using_aws_cli(self):
        client = load_client()
        calls = []
        def invoke(command, **kwargs):
            calls.append(command)
            payload = json.loads(command[command.index('--payload') + 1])
            response = {'build_id': 'manaleague-eks-lab-deploy:abc'} if len(calls) == 1 else {'status': 'SUCCEEDED'}
            pathlib.Path(command[-1]).write_text(json.dumps(response), encoding='utf-8')
            return mock.Mock(returncode=0, stdout='{"StatusCode":200}', stderr='')
        result = client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64,
                               run=invoke, sleep=lambda _: None)
        self.assertEqual(result, 'SUCCEEDED')
        self.assertEqual(json.loads(calls[0][calls[0].index('--payload')+1]),
                         {'action':'start', 'release':'a'*40, 'app_digest':'sha256:'+'b'*64, 'web_digest':'sha256:'+'c'*64, 'source_sha256':'d'*64})
        self.assertEqual(json.loads(calls[1][calls[1].index('--payload')+1]),
                         {'action':'status', 'build_id':'manaleague-eks-lab-deploy:abc'})

    def test_result_after_deadline_is_not_accepted(self):
        client = load_client()
        now = [0]
        def invoke(command, **kwargs):
            payload = json.loads(command[command.index('--payload') + 1])
            body = {'status': 'SUCCEEDED'} if payload.get('action') == 'status' else {'build_id': 'manaleague-eks-lab-deploy:abc'}
            pathlib.Path(command[-1]).write_text(json.dumps(body), encoding='utf-8')
            if payload.get('action') == 'status':
                now[0] = 11
            return mock.Mock(returncode=0, stdout='{"StatusCode":200}', stderr='')
        with self.assertRaisesRegex(client.BrokerError, 'timed out'):
            client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64,
                          timeout=10, run=invoke, clock=lambda: now[0])

    def test_build_failure_and_errors_are_sanitized(self):
        client = load_client()
        calls = [0]
        def invoke(command, **kwargs):
            calls[0] += 1
            response = {'build_id':'manaleague-eks-lab-deploy:abc'} if calls[0] == 1 else {'status':'FAILED', 'message':'secret-value'}
            pathlib.Path(command[-1]).write_text(json.dumps(response), encoding='utf-8')
            return mock.Mock(returncode=0, stdout='{"StatusCode":200}', stderr='secret-value')
        with self.assertRaises(client.BrokerError) as error:
            client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64, run=invoke)
        self.assertNotIn('secret-value', str(error.exception))

    def test_rejects_function_error_and_invalid_status(self):
        client = load_client()
        for metadata, response in [({'StatusCode':200, 'FunctionError':'secret'}, {}),
                                   ({'StatusCode':200}, {'build_id':'manaleague-eks-lab-deploy:abc'})]:
            calls = [0]
            def invoke(command, **kwargs):
                calls[0] += 1
                body = response if calls[0] == 1 else {'status':'garbage-secret'}
                pathlib.Path(command[-1]).write_text(json.dumps(body), encoding='utf-8')
                return mock.Mock(returncode=0, stdout=json.dumps(metadata), stderr='')
            with self.subTest(metadata=metadata), self.assertRaises(client.BrokerError) as error:
                client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64, run=invoke)
            self.assertNotIn('secret', str(error.exception))

    def test_timeout_while_polling(self):
        client = load_client()
        now = [0]
        def invoke(command, **kwargs):
            payload = json.loads(command[command.index('--payload')+1])
            body = {'status':'IN_PROGRESS'} if payload.get('action') == 'status' else {'build_id':'manaleague-eks-lab-deploy:abc'}
            pathlib.Path(command[-1]).write_text(json.dumps(body), encoding='utf-8')
            return mock.Mock(returncode=0, stdout='{"StatusCode":200}', stderr='')
        def sleep(seconds):
            now[0] += seconds
        with self.assertRaisesRegex(client.BrokerError, 'timed out'):
            client.deploy('broker', 'a'*40, 'sha256:'+'b'*64, 'sha256:'+'c'*64, 'd'*64,
                          timeout=5, interval=2, run=invoke, clock=lambda: now[0], sleep=sleep)

if __name__ == '__main__':
    unittest.main()
