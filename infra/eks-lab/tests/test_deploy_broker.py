import importlib.util
import pathlib
import unittest
from unittest import mock

PATH = pathlib.Path(__file__).resolve().parents[1] / 'orchestrator' / 'deploy_broker.py'


def module():
    spec = importlib.util.spec_from_file_location('deploy_broker', PATH)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class BrokerTests(unittest.TestCase):
    def test_stop_validates_project_and_sanitizes_response(self):
        broker = module()
        build_id = broker.PROJECT + ':123'
        client = mock.Mock()
        client.batch_get_builds.return_value = {'builds': [{'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': 'IN_PROGRESS'}]}
        client.stop_build.return_value = {'build': {'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': 'IN_PROGRESS', 'secret': 'hidden'}}
        with mock.patch.dict('sys.modules', {'boto3': mock.Mock(client=mock.Mock(return_value=client))}):
            self.assertEqual(broker.lambda_handler({'action': 'stop', 'build_id': build_id}, None), {'build_id': build_id, 'status': 'IN_PROGRESS'})
        client.stop_build.assert_called_once_with(id=build_id)
        client.reset_mock()
        client.batch_get_builds.return_value = {'builds': [{'id': build_id, 'projectName': 'other', 'buildStatus': 'IN_PROGRESS'}]}
        with self.assertRaises(RuntimeError):
            broker.stop({'build_id': build_id}, client)
        client.stop_build.assert_not_called()

    def test_lambda_handler_delegates_to_fixed_start(self):
        broker = module()
        event = {'release': 'a' * 40, 'app_digest': 'sha256:' + 'b' * 64, 'web_digest': 'sha256:' + 'c' * 64, 'source_sha256': 'd' * 64}
        client = mock.Mock()
        client.start_build.return_value = {'build': {'id': 'manaleague-eks-lab-deploy:123'}}
        with mock.patch.dict('sys.modules', {'boto3': mock.Mock(client=mock.Mock(return_value=client))}):
            self.assertEqual(broker.lambda_handler(dict(event, action='start'), None), {'build_id': 'manaleague-eks-lab-deploy:123'})
        client.start_build.assert_called_once()

    def test_starts_only_fixed_project_with_validated_nonsecret_values(self):
        broker = module()
        client = mock.Mock()
        client.start_build.return_value = {'build': {'id': 'manaleague-eks-lab-deploy:123'}}
        event = {'release': 'a' * 40, 'app_digest': 'sha256:' + 'b' * 64, 'web_digest': 'sha256:' + 'c' * 64, 'source_sha256': 'd' * 64}
        self.assertEqual(broker.start(event, client), {'build_id': 'manaleague-eks-lab-deploy:123'})
        args = client.start_build.call_args.kwargs
        self.assertEqual(args['projectName'], 'manaleague-eks-lab-deploy')
        self.assertEqual({x['name']: x['value'] for x in args['environmentVariablesOverride']}, {'RELEASE': event['release'], 'APP_DIGEST': event['app_digest'], 'WEB_DIGEST': event['web_digest'], 'SOURCE_SHA256': event['source_sha256']})
        self.assertTrue(all(x['type'] == 'PLAINTEXT' for x in args['environmentVariablesOverride']))
        self.assertEqual(set(args), {'projectName', 'environmentVariablesOverride'})

    def test_rejects_unexpected_fields_and_invalid_values_before_aws_call(self):
        broker = module()
        valid = {'release': 'a' * 40, 'app_digest': 'sha256:' + 'b' * 64, 'web_digest': 'sha256:' + 'c' * 64, 'source_sha256': 'd' * 64}
        for event in [dict(valid, buildspecOverride='evil'), dict(valid, release='a' * 40 + '\n'), dict(valid, app_digest='sha256:' + 'B' * 64), dict(valid, web_digest=1), {}, None]:
            with self.subTest(event=event):
                client = mock.Mock()
                with self.assertRaises(ValueError):
                    broker.start(event, client)
                client.start_build.assert_not_called()

    def test_status_returns_only_validated_status_for_fixed_project(self):
        broker = module()
        client = mock.Mock()
        build_id = broker.PROJECT + ':123'
        client.batch_get_builds.return_value = {'builds': [{'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': 'SUCCEEDED', 'secret': 'do-not-return'}], 'buildsNotFound': []}
        self.assertEqual(broker.status({'build_id': build_id}, client), {'build_id': build_id, 'status': 'SUCCEEDED'})
        client.batch_get_builds.assert_called_once_with(ids=[build_id])

    def test_handler_dispatches_status_and_rejects_invalid_actions(self):
        broker = module()
        client = mock.Mock()
        build_id = broker.PROJECT + ':123'
        client.batch_get_builds.return_value = {'builds': [{'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': 'IN_PROGRESS'}], 'buildsNotFound': []}
        with mock.patch.dict('sys.modules', {'boto3': mock.Mock(client=mock.Mock(return_value=client))}):
            self.assertEqual(broker.lambda_handler({'action': 'status', 'build_id': build_id}, None), {'build_id': build_id, 'status': 'IN_PROGRESS'})
            for event in [None, {}, {'action': 'other'}, {'action': []}, {'action': 'status', 'build_id': build_id, 'projectName': 'evil'}]:
                with self.subTest(event=event), self.assertRaises(ValueError):
                    broker.lambda_handler(event, None)
        client.start_build.assert_not_called()
        client.batch_get_builds.assert_called_once()

    def test_status_rejects_bad_requests_without_calling_aws(self):
        broker = module()
        for event in [None, {}, {'build_id': 1}, {'build_id': 'other:123'}, {'build_id': broker.PROJECT + ':123' + chr(10)}, {'build_id': broker.PROJECT + ':'}, {'build_id': broker.PROJECT + ':123', 'extra': 'x'}]:
            with self.subTest(event=event):
                client = mock.Mock()
                with self.assertRaises(ValueError):
                    broker.status(event, client)
                client.batch_get_builds.assert_not_called()

    def test_status_fails_closed_for_untrusted_results(self):
        broker = module()
        build_id = broker.PROJECT + ':123'
        good = {'id': build_id, 'projectName': broker.PROJECT, 'buildStatus': 'SUCCEEDED'}
        cases = [{}, {'builds': []}, {'builds': [dict(good, projectName='other')]}, {'builds': [dict(good, id=broker.PROJECT + ':other')]}, {'builds': [dict(good, buildStatus='secret')]}, {'builds': [good, good]}, {'builds': [good], 'buildsNotFound': [build_id]}]
        for result in cases:
            with self.subTest(result=result):
                client = mock.Mock()
                client.batch_get_builds.return_value = result
                with self.assertRaises(RuntimeError):
                    broker.status({'build_id': build_id}, client)

    def test_aws_errors_and_invalid_start_result_do_not_leak(self):
        broker = module()
        event = {'release': 'a' * 40, 'app_digest': 'sha256:' + 'b' * 64, 'web_digest': 'sha256:' + 'c' * 64, 'source_sha256': 'd' * 64}
        client = mock.Mock()
        for result in [{}, {'build': {'id': 'other:123'}}, {'build': {'id': broker.PROJECT + ':bad' + chr(10)}}]:
            client.start_build.return_value = result
            with self.assertRaises(RuntimeError):
                broker.start(event, client)
        client.start_build.side_effect = Exception('sensitive data')
        with self.assertRaises(RuntimeError) as caught:
            broker.start(event, client)
        self.assertNotIn('sensitive data', str(caught.exception))
        client.batch_get_builds.side_effect = Exception('sensitive data')
        with self.assertRaises(RuntimeError) as caught:
            broker.status({'build_id': broker.PROJECT + ':123'}, client)
        self.assertNotIn('sensitive data', str(caught.exception))
