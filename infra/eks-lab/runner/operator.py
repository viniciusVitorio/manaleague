"""Offline manifest renderer. Importing or rendering never contacts a service."""

NAMESPACE = 'manaleague-eks-lab-runner'
REPOSITORY = 'https://github.com/viniciusVitorio/manaleague'
RUNNER_IMAGE = 'ghcr.io/actions/actions-runner@sha256:e5496277be5d09bc968b3d64911b74e219ac4a3f2edce956a3ecf9271bea1ef4'
AWS_IMAGE = 'public.ecr.aws/aws-cli/aws-cli@sha256:f97d3b708124c28ffa2f6cf2ff6c4c7bf79eeac7c5942b0140a506d911245b81'

# This is NOT an FQDN allowlist. Standard NetworkPolicy is L3/L4 only.
PUBLIC_EXCEPT = [
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
    '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
    '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
    '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
]

REGISTER = r'''set -eu
umask 077
cp -R /home/runner/. /runner/
cd /runner
mkdir -p /runner/home /runner/_work
# Never put a registration secret into argv, a shared file, or a Pod env field.
ACTIONS_RUNNER_INPUT_TOKEN="$(cat /registration/token)"
test -n "$ACTIONS_RUNNER_INPUT_TOKEN"
export PATH="/tools/bin:$PATH"
export ACTIONS_RUNNER_INPUT_TOKEN
if ! ./config.sh --unattended --url "$REPOSITORY_URL" \
    --name "$POD_NAME" --labels eks-lab --work /runner/_work \
    --ephemeral --disableupdate >/dev/null 2>&1; then
    unset ACTIONS_RUNNER_INPUT_TOKEN
    rm -rf /runner/_diag
    echo 'Runner registration failed; obtain a fresh short-lived registration Secret.' >&2
    exit 1
fi
unset ACTIONS_RUNNER_INPUT_TOKEN
# Configuration diagnostics are not passed into the workload container.
rm -rf /runner/_diag
'''
COPY_AWS = '''set -eu
mkdir -p /tools/aws-cli /tools/bin
# Copy the dist bundle together; installer current/bin symlinks are not relocatable.
cp -R /usr/local/aws-cli/v2/current/dist/. /tools/aws-cli/
printf '%s\\n' '#!/bin/sh' 'exec /tools/aws-cli/aws "$@"' > /tools/bin/aws
chmod 755 /tools/bin/aws
'''
RUN = '''set -eu
cd /runner
export PATH="/tools/bin:$PATH"
# Fail before polling GitHub if the copied toolchain cannot execute.
python3 --version
aws --version
exec ./run.sh
'''


def render(account, region, vpc_cidr, *, api_cidrs=()):
    """Return namespace-owned objects only; no Secret value is generated."""
    import ipaddress
    import re

    if not isinstance(account, str) or not re.fullmatch(r'[0-9]{12}', account) or account == '000000000000':
        raise ValueError('account must be an unmodified 12-digit AWS account ID')
    if not isinstance(region, str) or not re.fullmatch(
        r'(?:us|eu|ap|sa|ca|me|af|il|mx)-(?:central|north|south|east|west|northeast|southeast)-[1-9]', region
    ):
        raise ValueError('region must have commercial AWS region syntax')
    try:
        if not isinstance(vpc_cidr, str):
            raise ValueError('VPC CIDR must be a string')
        vpc = ipaddress.IPv4Network(vpc_cidr, strict=True)
        private = [ipaddress.IPv4Network(c) for c in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')]
        if str(vpc) != vpc_cidr or not 16 <= vpc.prefixlen <= 28 or not any(vpc.subnet_of(p) for p in private):
            raise ValueError('VPC CIDR must be canonical RFC1918 IPv4 /16 through /28')
    except (ValueError, TypeError) as error:
        raise ValueError('invalid lab VPC CIDR') from error

    if not isinstance(api_cidrs, (tuple, list)):
        raise ValueError('api_cidrs must be a list or tuple of explicit lab API /32 routes')
    for cidr in api_cidrs:
        try:
            network = ipaddress.IPv4Network(cidr, strict=True)
            if not isinstance(cidr, str) or str(network) != cidr or network.prefixlen != 32 or not network.subnet_of(vpc):
                raise ValueError('API route must be a canonical /32 inside the lab VPC')
        except (ValueError, TypeError) as error:
            raise ValueError('invalid lab API route') from error
    api_egress = ([{'to': [{'ipBlock': {'cidr': c}} for c in sorted(set(api_cidrs))],
                    'ports': [{'protocol': 'TCP', 'port': 443}]}] if api_cidrs else [])

    def meta(name):
        return {'name': name, 'namespace': NAMESPACE}

    def container(name, image, script, mounts):
        return {
            'name': name, 'image': image, 'imagePullPolicy': 'IfNotPresent',
            'command': ['/bin/sh', '-c', script],
            'securityContext': {
                'allowPrivilegeEscalation': False, 'readOnlyRootFilesystem': True,
                'capabilities': {'drop': ['ALL']},
            },
            'resources': {
                'requests': {'cpu': '100m', 'memory': '256Mi', 'ephemeral-storage': '256Mi'},
                'limits': {'cpu': '1', 'memory': '1Gi', 'ephemeral-storage': '4Gi'},
            },
            'volumeMounts': mounts,
        }

    state = {'name': 'runner-state', 'mountPath': '/runner'}
    tools_write = {'name': 'tools', 'mountPath': '/tools'}
    tools_read = dict(tools_write, readOnly=True)
    tmp = {'name': 'tmp', 'mountPath': '/tmp'}
    registration = container('register', RUNNER_IMAGE, REGISTER, [
        state, tmp, {'name': 'registration', 'mountPath': '/registration', 'readOnly': True},
    ])
    registration['env'] = [
        {'name': 'HOME', 'value': '/runner/home'},
        {'name': 'REPOSITORY_URL', 'value': REPOSITORY},
        {'name': 'POD_NAME', 'valueFrom': {'fieldRef': {'fieldPath': 'metadata.name'}}},
    ]
    main = container('runner', RUNNER_IMAGE, RUN, [state, tools_read, tmp])
    main['workingDir'] = '/runner'
    main['env'] = [
        {'name': 'RUNNER_TOOL_CACHE', 'value': '/runner/_work/_tool'},
        {'name': 'HOME', 'value': '/runner/home'},
        {'name': 'AWS_REGION', 'value': region},
        {'name': 'AWS_ACCOUNT_ID', 'value': account},
        {'name': 'AWS_EC2_METADATA_DISABLED', 'value': 'true'},
        {'name': 'AWS_SHARED_CREDENTIALS_FILE', 'value': '/runner/home/.aws/credentials'},
        {'name': 'AWS_CONFIG_FILE', 'value': '/runner/home/.aws/config'},
    ]
    pod = {
        'serviceAccountName': 'runner', 'automountServiceAccountToken': False,
        'restartPolicy': 'Never', 'enableServiceLinks': False,
        'hostNetwork': False, 'hostPID': False, 'hostIPC': False,
        'nodeSelector': {'kubernetes.io/os': 'linux', 'kubernetes.io/arch': 'amd64'},
        'securityContext': {
            'runAsNonRoot': True, 'runAsUser': 1001, 'runAsGroup': 1001,
            'fsGroup': 1001, 'seccompProfile': {'type': 'RuntimeDefault'},
        },
        'volumes': [
            {'name': 'runner-state', 'emptyDir': {'sizeLimit': '4Gi'}},
            {'name': 'tools', 'emptyDir': {'sizeLimit': '2Gi'}},
            {'name': 'tmp', 'emptyDir': {'sizeLimit': '256Mi'}},
            {'name': 'registration', 'secret': {
                'secretName': 'runner-registration-token', 'defaultMode': 288,
                'items': [{'key': 'token', 'path': 'token'}], 'optional': False,
            }},
        ],
        'initContainers': [
            container('copy-aws', AWS_IMAGE, COPY_AWS, [tools_write, tmp]),
            registration,
        ],
        'containers': [main],
        'terminationGracePeriodSeconds': 60,
    }
    return [
        {'apiVersion': 'v1', 'kind': 'Namespace', 'metadata': {
            'name': NAMESPACE, 'labels': {
                'pod-security.kubernetes.io/enforce': 'restricted',
                'pod-security.kubernetes.io/enforce-version': 'v1.37',
                'pod-security.kubernetes.io/audit': 'restricted',
                'pod-security.kubernetes.io/warn': 'restricted',
            },
        }},
        {'apiVersion': 'v1', 'kind': 'ServiceAccount', 'metadata': meta('runner'),
         'automountServiceAccountToken': False},
        {'apiVersion': 'networking.k8s.io/v1', 'kind': 'NetworkPolicy',
         'metadata': meta('runner-egress'), 'spec': {
             'podSelector': {}, 'policyTypes': ['Ingress', 'Egress'], 'ingress': [],
             'egress': [
                 {'to': [{'namespaceSelector': {'matchLabels': {
                     'kubernetes.io/metadata.name': 'kube-system'}},
                     'podSelector': {'matchLabels': {'k8s-app': 'kube-dns'}}}],
                  'ports': [{'protocol': 'UDP', 'port': 53}, {'protocol': 'TCP', 'port': 53}]},
                 {'to': [{'ipBlock': {'cidr': '0.0.0.0/0', 'except': list(PUBLIC_EXCEPT)}}],
                  'ports': [{'protocol': 'TCP', 'port': 443}, {'protocol': 'TCP', 'port': 80}]},
             ] + api_egress,
         }},
        {'apiVersion': 'batch/v1', 'kind': 'Job', 'metadata': meta('runner'),
         'spec': {'backoffLimit': 0, 'completions': 1, 'parallelism': 1,
                  'activeDeadlineSeconds': 7200,
                  'template': {'metadata': {'labels': {'app': NAMESPACE}}, 'spec': pod}}},
    ]


def main(argv=None):
    """Render JSON to stdout; deliberately no apply/register/cloud subcommands."""
    import argparse
    import json

    parser = argparse.ArgumentParser(description=__doc__, allow_abbrev=False)
    commands = parser.add_subparsers(dest='command', required=True)
    command = commands.add_parser('render', help='render only; no external calls', allow_abbrev=False)
    command.add_argument('--account', required=True)
    command.add_argument('--region', required=True)
    command.add_argument('--vpc-cidr', required=True)
    command.add_argument('--api-cidr', action='append', default=[],
                         help='explicit current private EKS API IPv4 /32 inside lab VPC; repeatable')
    args = parser.parse_args(argv)
    try:
        objects = render(args.account, args.region, args.vpc_cidr, api_cidrs=args.api_cidr)
    except ValueError as error:
        parser.error(str(error))
    print(json.dumps({'apiVersion': 'v1', 'kind': 'List', 'items': objects}, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
