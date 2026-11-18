#!/usr/bin/env python3
"""Fail-closed verification of Sonar's official check for the lab push only."""
from datetime import datetime, timezone, timedelta
import json
import os
import re
import sys
import time
from urllib.parse import urlsplit, parse_qs
from urllib.request import Request, urlopen


class GateError(Exception):
    pass


def timestamp(value):
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise GateError('Timestamp must include timezone')
    return parsed.astimezone(timezone.utc)


def evaluate(checks, sha, project, marker, now):
    candidates = []
    for check in checks:
        if check.get('name') != 'SonarCloud Code Analysis' or check.get('app', {}).get('id') != 12526:
            continue
        if check.get('head_sha') != sha:
            raise GateError('Official check SHA mismatch')
        started = timestamp(check['started_at'])
        if started < marker:
            continue
        if started > now:
            raise GateError('Official check starts in future')
        candidates.append(check)
    if len(candidates) > 1:
        raise GateError('Ambiguous current official checks')
    if not candidates:
        return False
    check = candidates[0]
    url = urlsplit(check['details_url'])
    if (url.scheme != 'https' or url.netloc != 'sonarcloud.io' or url.path != '/dashboard'
            or url.fragment or parse_qs(url.query, keep_blank_values=True) != {'id': [project], 'branch': ['eks-lab']}):
        raise GateError('Official check project/branch URL mismatch')
    if check.get('status') in ('queued', 'in_progress'):
        return False
    if check.get('status') != 'completed' or check.get('conclusion') != 'success':
        raise GateError('Official quality gate did not complete successfully')
    completed = timestamp(check['completed_at'])
    if not marker <= timestamp(check['started_at']) <= completed <= now:
        raise GateError('Official check completion is not current')
    if check.get('output', {}).get('title') != 'Quality Gate passed':
        raise GateError('Official check does not confirm the quality gate passed')
    return True


def context(env, now):
    if (env.get('GITHUB_EVENT_NAME') != 'push'
            or env.get('GITHUB_REF') != 'refs/heads/eks-lab'
            or env.get('GITHUB_REPOSITORY') != 'viniciusVitorio/manaleague'
            or not re.fullmatch(r'[0-9a-f]{40}', env.get('GITHUB_SHA', ''))
            or not env.get('GH_TOKEN') or not env.get('SONAR_PROJECT_KEY')):
        raise GateError('Verifier requires the exact trusted lab push context')
    marker = timestamp(env['SONAR_LAB_SCAN_STARTED_AT'])
    if not now - timedelta(hours=1) <= marker <= now:
        raise GateError('Pre-scan marker is missing, stale or in future')
    return marker


def fetch_checks(repository, sha, token):
    # filter=all is essential: latest alone can hide a conflicting rerun.
    checks = []
    total = None
    for page in range(1, 11):
        url = (f'https://api.github.com/repos/{repository}/commits/{sha}/check-runs'
               f'?filter=all&per_page=100&page={page}')
        request = Request(url, headers={'Accept': 'application/vnd.github+json',
                                       'Authorization': f'Bearer {token}',
                                       'X-GitHub-Api-Version': '2022-11-28'}, method='GET')
        with urlopen(request, timeout=10) as response:
            data = json.loads(response.read())
        count = data['total_count']
        batch = data['check_runs']
        if (type(count) is not int or not 0 <= count <= 1000
                or not isinstance(batch, list) or len(batch) > 100
                or (total is not None and total != count)):
            raise GateError('Invalid or changing check pagination')
        total = count
        checks.extend(batch)
        if len(checks) > total or len({c['id'] for c in checks}) != len(checks):
            raise GateError('Duplicate or excessive check results')
        if len(checks) == total:
            return checks
        if len(batch) != 100:
            raise GateError('Truncated check results')
    raise GateError('Check pagination limit exceeded')


def enforce(env, now=lambda: datetime.now(timezone.utc), attempts=48):
    marker = context(env, now())
    deadline = time.monotonic() + 240
    for attempt in range(attempts):
        if time.monotonic() >= deadline:
            break
        checks = fetch_checks(env['GITHUB_REPOSITORY'], env['GITHUB_SHA'], env['GH_TOKEN'])
        if evaluate(checks, env['GITHUB_SHA'], env['SONAR_PROJECT_KEY'], marker, now()):
            return
        if attempt + 1 < attempts:
            time.sleep(5)
    raise GateError('Timed out waiting for a fresh successful official Sonar quality gate')


if __name__ == '__main__':
    try:
        enforce(os.environ)
    except Exception:
        # Never echo remote bodies, project identifiers, environment or credentials.
        print('Sonar lab quality gate verification failed (closed).', file=sys.stderr)
        sys.exit(1)
    print('Fresh official Sonar quality gate passed for this lab push.')
