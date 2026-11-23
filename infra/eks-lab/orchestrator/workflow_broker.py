#!/usr/bin/env python3
"""Validate workflow inputs and invoke the fixed lab deploy broker."""
import os
import re
import sys

from broker_client import BrokerError, deploy as broker_deploy


def validate(env):
    if env.get('LAB_DEPLOY_ENABLED') != 'true' or env.get('LAB_DEPLOY_BROKER_ENABLED') != 'true':
        raise ValueError('Deployment gates are not enabled')
    account = env.get('AWS_ACCOUNT_ID', '')
    region = env.get('AWS_REGION', '')
    if not re.fullmatch(r'[0-9]{12}', account) or not re.fullmatch(r'[a-z]{2}-[a-z]+-[0-9]', region):
        raise ValueError('Invalid lab account or region')
    if env.get('LAB_DEPLOY_BROKER_ROLE_ARN') != f'arn:aws:iam::{account}:role/manaleague-eks-lab-deploy-broker-invoker':
        raise ValueError('Invalid broker invoker role')
    release = env.get('RELEASE', '')
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('Invalid release')
    digests = []
    for target in ('app', 'web'):
        prefix = f'{account}.dkr.ecr.{region}.amazonaws.com/manaleague-eks-lab-{target}@'
        image = env.get(target.upper() + '_IMAGE', '')
        match = re.fullmatch(re.escape(prefix) + r'(sha256:[a-f0-9]{64})', image)
        if not match:
            raise ValueError('Invalid published image')
        digests.append(match.group(1))
    source = env.get('SOURCE_SHA256', '')
    if not re.fullmatch(r'[a-f0-9]{64}', source):
        raise ValueError('Invalid source digest')
    return ('manaleague-eks-lab-deploy-broker', release, *digests, source)


def main(env=None, *, deploy=broker_deploy, check_only=False):
    try:
        args = validate(os.environ if env is None else env)
        if not check_only:
            deploy(*args, timeout=2700)
    except (ValueError, BrokerError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    if sys.argv[1:] not in ([], ['--check']):
        sys.exit(2)
    sys.exit(main(check_only=sys.argv[1:] == ['--check']))
