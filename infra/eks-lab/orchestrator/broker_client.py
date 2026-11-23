#!/usr/bin/env python3
"""Invoke the deploy broker and wait for its build; no secret inputs.

The status broker contract is {action: status, build_id: ...} -> {status: ...}.
AWS CLI credentials and region are provided by the runner environment.
"""
import argparse
import json
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path


class BrokerError(Exception):
    pass


def deploy(function, release, app_digest, web_digest, source_sha256, *, timeout=1800, interval=10,
           run=subprocess.run, sleep=time.sleep, clock=time.monotonic):
    if not isinstance(function, str) or not re.fullmatch(r'[A-Za-z0-9_:/.-]{1,256}', function):
        raise BrokerError('Invalid broker function name')
    for value, pattern in ((release, r'[0-9a-f]{40}'),
                           (app_digest, r'sha256:[0-9a-f]{64}'),
                           (web_digest, r'sha256:[0-9a-f]{64}'),
                           (source_sha256, r'[0-9a-f]{64}')):
        if not isinstance(value, str) or not re.fullmatch(pattern, value):
            raise BrokerError('Invalid release or digest')
    if not 0 < timeout <= 86400 or not 0 < interval <= 3600:
        raise BrokerError('Invalid polling timing')
    deadline = clock() + timeout

    def invoke(payload, *, until=None):
        limit = deadline if until is None else until
        remaining = limit - clock()
        if remaining <= 0:
            raise BrokerError('Deployment timed out')
        try:
            with tempfile.TemporaryDirectory() as directory:
                output = Path(directory) / 'response.json'
                result = run(['aws', 'lambda', 'invoke', '--function-name', function,
                              '--invocation-type', 'RequestResponse', '--cli-binary-format',
                              'raw-in-base64-out', '--payload', json.dumps(payload),
                              '--output', 'json', str(output)], capture_output=True, text=True,
                             timeout=remaining, check=False)
                if clock() >= limit:
                    raise BrokerError('Deployment timed out')
                if result.returncode:
                    raise BrokerError('AWS CLI invocation failed')
                metadata = json.loads(result.stdout)
                if not isinstance(metadata, dict) or metadata.get('StatusCode') != 200 or metadata.get('FunctionError'):
                    raise BrokerError('Broker invocation failed')
                if output.stat().st_size > 65536:
                    raise BrokerError('Invalid broker response')
                response = json.loads(output.read_text(encoding='utf-8'))
                if not isinstance(response, dict):
                    raise BrokerError('Invalid broker response')
                return response
        except subprocess.TimeoutExpired:
            raise BrokerError('Deployment timed out') from None
        except (OSError, ValueError, TypeError):
            raise BrokerError('Invalid broker invocation or response') from None

    try:
        response = invoke({'action': 'start', 'release': release, 'app_digest': app_digest, 'web_digest': web_digest, 'source_sha256': source_sha256})
    except BrokerError as exc:
        raise BrokerError(f'{exc}; build may have started with unknown ID; reconcile project manually') from None
    build_id = response.get('build_id')
    if not isinstance(build_id, str) or not re.fullmatch(r'manaleague-eks-lab-deploy:[A-Za-z0-9-]{1,128}', build_id):
        raise BrokerError('Invalid build ID; build may have started; reconcile project manually')
    try:
        while True:
            response = invoke({'action': 'status', 'build_id': build_id})
            status = response.get('status')
            if status == 'SUCCEEDED':
                return status
            if status in ('FAILED', 'FAULT', 'STOPPED', 'TIMED_OUT'):
                raise BrokerError('Build did not succeed')
            if status not in ('IN_PROGRESS',):
                raise BrokerError('Invalid build status')
            remaining = deadline - clock()
            if remaining <= 0:
                raise BrokerError('Deployment timed out')
            sleep(min(interval, remaining))
    except BrokerError as exc:
        try:
            invoke({'action': 'stop', 'build_id': build_id}, until=clock() + 20)
        except BrokerError:
            pass
        raise BrokerError(f'{exc}; cancellation attempted but not guaranteed; reconcile build manually') from None


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--function', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--app-digest', required=True)
    parser.add_argument('--web-digest', required=True)
    parser.add_argument('--source-sha256', required=True)
    parser.add_argument('--timeout', type=float, default=1800)
    parser.add_argument('--interval', type=float, default=10)
    args = parser.parse_args(argv)
    try:
        deploy(args.function, args.release, args.app_digest, args.web_digest, args.source_sha256,
               timeout=args.timeout, interval=args.interval)
    except BrokerError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print('Deployment succeeded')
    return 0


if __name__ == '__main__':
    sys.exit(main())
