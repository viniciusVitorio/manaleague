"""Direct-invoke broker. IAM must restrict callers; the project must pin trusted code and image repositories."""
import re

PROJECT = 'manaleague-eks-lab-deploy'
PATTERNS = {
    'release': r'[0-9a-f]{40}',
    'source_sha256': r'[0-9a-f]{64}',
    'app_digest': r'sha256:[0-9a-f]{64}',
    'web_digest': r'sha256:[0-9a-f]{64}',
}
STATUSES = frozenset({'SUCCEEDED', 'FAILED', 'FAULT', 'TIMED_OUT', 'IN_PROGRESS', 'STOPPED'})


def _valid_build_id(value):
    return isinstance(value, str) and bool(re.fullmatch(re.escape(PROJECT) + r':[A-Za-z0-9-]{1,128}', value))


def start(event, client):
    if not isinstance(event, dict) or set(event) != set(PATTERNS):
        raise ValueError('invalid deploy request fields')
    if any(not isinstance(event[key], str) or not re.fullmatch(pattern, event[key])
           for key, pattern in PATTERNS.items()):
        raise ValueError('invalid deploy request values')
    try:
        response = client.start_build(
            projectName=PROJECT,
            environmentVariablesOverride=[
                {'name': key.upper(), 'value': event[key], 'type': 'PLAINTEXT'}
                for key in PATTERNS
            ],
        )
        build_id = response['build']['id']
        if not _valid_build_id(build_id):
            raise ValueError('invalid response')
    except Exception:
        raise RuntimeError('deploy start failed') from None
    return {'build_id': build_id}


def status(event, client):
    if (not isinstance(event, dict) or set(event) != {'build_id'}
            or not _valid_build_id(event['build_id'])):
        raise ValueError('invalid status request')
    build_id = event['build_id']
    try:
        response = client.batch_get_builds(ids=[build_id])
        builds = response['builds']
        if (not isinstance(builds, list) or len(builds) != 1
                or response.get('buildsNotFound', []) != []):
            raise ValueError('invalid response')
        build = builds[0]
        if (build['id'] != build_id or build['projectName'] != PROJECT
                or build['buildStatus'] not in STATUSES):
            raise ValueError('invalid response')
        build_status = build['buildStatus']
    except Exception:
        raise RuntimeError('deploy status unavailable') from None
    return {'build_id': build_id, 'status': build_status}


def stop(event, client):
    current = status(event, client)
    if current['status'] != 'IN_PROGRESS':
        return current
    build_id = current['build_id']
    try:
        build = client.stop_build(id=build_id)['build']
        if (build['id'] != build_id or build['projectName'] != PROJECT
                or build['buildStatus'] not in STATUSES):
            raise ValueError('invalid response')
        return {'build_id': build_id, 'status': build['buildStatus']}
    except Exception:
        raise RuntimeError('deploy stop unavailable') from None


def lambda_handler(event, context):
    if not isinstance(event, dict) or event.get('action') not in ('start', 'status', 'stop'):
        raise ValueError('invalid broker action')
    action = event['action']
    payload = {key: value for key, value in event.items() if key != 'action'}
    import boto3
    client = boto3.client('codebuild')
    return {'start': start, 'status': status, 'stop': stop}[action](payload, client)