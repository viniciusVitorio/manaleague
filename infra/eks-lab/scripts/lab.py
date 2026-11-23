#!/usr/bin/env python3
"""Fail-closed operator for the isolated ManaLeague EKS lab. No implicit action."""
import argparse
import json
import os
import re
import shutil
import tempfile
import hashlib
import base64
import time
from pathlib import Path
import subprocess
import sys
import importlib.util

# File-based loading also supports offline callers importing this operator by path.
_contract_spec = importlib.util.spec_from_file_location('lab_https_contract', Path(__file__).with_name('https_contract.py'))
https_contract = importlib.util.module_from_spec(_contract_spec)
_contract_spec.loader.exec_module(https_contract)

ROOT = Path(__file__).resolve().parents[3]
LAB = ROOT / 'infra/eks-lab'
NAME = 'manaleague-eks-lab'
CLOUD = ('plan', 'apply', 'bootstrap', 'publish', 'deploy', 'destroy-plan', 'destroy')

def run(args, *, input=None, env=None):
    if str(args[0]) == 'aws':
        env = {**(env or os.environ), 'AWS_DEFAULT_OUTPUT': 'json', 'AWS_PAGER': '', 'AWS_CLI_AUTO_PROMPT': 'off', 'AWS_EC2_METADATA_DISABLED': 'true'}
        if os.environ.get('AWS_REGION') and '--region' not in args:
            args = [*args, '--region', os.environ['AWS_REGION']]
    return subprocess.run([str(x) for x in args], input=input, text=True, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env).stdout.strip()

def require(name):
    value = os.environ.get(name, '')
    if not value or '\n' in value or '\r' in value:
        raise ValueError(f'Missing/invalid {name}')
    return value

def workflow_guard():
    if os.environ.get('GITHUB_ACTIONS') == 'true':
        if os.environ.get('GITHUB_REF') != 'refs/heads/eks-lab' or os.environ.get('GITHUB_EVENT_NAME') not in ('push', 'workflow_dispatch'):
            raise ValueError('Cloud action requires exact eks-lab branch and trusted event')
        if os.environ.get('LAB_DEPLOY_ENABLED') != 'true':
            raise ValueError('Cloud jobs disabled: set eks-lab Environment LAB_DEPLOY_ENABLED=true only after provisioning')

def cloud_guard(expected):
    workflow_guard()
    account = require('AWS_ACCOUNT_ID')
    region = require('AWS_REGION')
    if not re.fullmatch(r'[0-9]{12}', expected) or account != expected:
        raise ValueError('expected account must match AWS_ACCOUNT_ID exactly')
    if not re.fullmatch(r'[a-z]{2}-[a-z]+-[0-9]', region) or require('EKS_CLUSTER_NAME') != NAME:
        raise ValueError('Only exact manaleague-eks-lab target and a valid AWS_REGION are accepted')
    actual = run(['aws', 'sts', 'get-caller-identity', '--query', 'Account', '--output', 'text'])
    if actual != expected:
        raise ValueError('AWS caller account does not match expected account')
    return account, region

def replace_tokens(text, values):
    def replace(match):
        name = match.group(2)
        if name not in values:
            raise ValueError(f'Unresolved template token {name}')
        return json.dumps(values[name])
    return re.sub(r'''(["']?)__([A-Z][A-Z0-9_]*)__\1''', replace, text)

def image_guard(image, account, region, target):
    repo = f'{account}.dkr.ecr.{region}.amazonaws.com/{NAME}-{target}'
    if not re.fullmatch(re.escape(repo) + r'@sha256:[a-f0-9]{64}', image):
        raise ValueError(f'Only digest images in exact lab {target} ECR repository allowed')
    return image

def publish_one(local, repository, release):
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('RELEASE must be the exact full commit SHA')
    repo_name = repository.split('/', 1)[1]
    description = json.loads(run(['aws', 'ecr', 'describe-repositories', '--repository-names', repo_name]))['repositories'][0]
    if description['repositoryUri'] != repository or description['imageTagMutability'] != 'IMMUTABLE':
        raise ValueError('ECR repository must be exact target and IMMUTABLE (no exclusions)')
    tested_id = run(['docker', 'image', 'inspect', local, '--format', '{{.Id}}'])
    def remote_digest():
        try:
            entries = json.loads(run(['aws', 'ecr', 'describe-images', '--repository-name', repo_name, '--image-ids', f'imageTag={release}']))['imageDetails']
            digest = entries[0]['imageDigest']
            if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
                raise ValueError('Invalid registry digest')
            return digest
        except subprocess.CalledProcessError as error:
            if 'ImageNotFoundException' not in (error.stderr or ''):
                raise
            return None
    digest = remote_digest()
    if digest is None:
        run(['docker', 'tag', local, f'{repository}:{release}'])
        try:
            run(['docker', 'push', f'{repository}:{release}'])
        except subprocess.CalledProcessError:
            # A concurrent retry may have won. Never delete/overwrite its immutable tag.
            if remote_digest() is None:
                raise
        digest = remote_digest()
    if digest is None:
        raise ValueError('Published image not visible in ECR')
    image = f'{repository}@{digest}'
    run(['docker', 'pull', image])
    remote_id = run(['docker', 'image', 'inspect', image, '--format', '{{.Id}}'])
    if remote_id != tested_id:
        raise ValueError('Existing immutable SHA differs from tested image. Reuse original artifact; never overwrite tags.')
    return image

def publish(account, region):
    registry = f'{account}.dkr.ecr.{region}.amazonaws.com'
    password = run(['aws', 'ecr', 'get-login-password', '--region', region])
    run(['docker', 'login', '--username', 'AWS', '--password-stdin', registry], input=password)
    outputs = {}
    for target in ('app', 'web'):
        repository = require(f'ECR_{target.upper()}_REPOSITORY')
        expected = f'{registry}/{NAME}-{target}'
        if repository != expected:
            raise ValueError(f'ECR_{target.upper()}_REPOSITORY must be full URL {expected}')
        outputs[f'{target}_image'] = publish_one(f'lab-{target}:dev', repository, require('RELEASE'))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as stream:
            for key, value in outputs.items():
                stream.write(f'{key}={value}\n')
    print(json.dumps(outputs))

def kube_config(account, region, path):
    expected = f'arn:aws:eks:{region}:{account}:cluster/{NAME}'
    try:
        cluster = json.loads(run(['aws', 'eks', 'describe-cluster', '--name', NAME, '--region', region]))['cluster']
    except subprocess.CalledProcessError as error:
        if 'ResourceNotFoundException' in (error.stderr or ''):
            raise ValueError('Lab cluster absent. Provision the isolated lab manually; leave LAB_DEPLOY_ENABLED=false until bootstrap and runner access are ready.') from error
        raise
    if cluster['arn'] != expected or cluster['status'] != 'ACTIVE' or not cluster['endpoint'].startswith('https://'):
        raise ValueError('Exact lab cluster ARN and ACTIVE endpoint required; provision manually first')
    run(['aws', 'eks', 'update-kubeconfig', '--name', NAME, '--region', region, '--kubeconfig', path, '--alias', expected])
    config = json.loads(run(['kubectl', '--kubeconfig', path, 'config', 'view', '--raw', '-o', 'json']))
    if config['current-context'] != expected:
        raise ValueError('Kubernetes current context is not exact lab cluster ARN')
    context = next(c['context'] for c in config['contexts'] if c['name'] == expected)
    server = next(c['cluster'] for c in config['clusters'] if c['name'] == context['cluster'])
    if server['server'] != cluster['endpoint'] or server['certificate-authority-data'] != cluster['certificateAuthority']['data']:
        raise ValueError('Kubeconfig endpoint/CA do not match lab EKS API')
    return ['kubectl', '--kubeconfig', str(path), '--context', expected]

def tf_env(data_dir=None):
    env = {k: v for k, v in os.environ.items() if not k.startswith('TF_CLI_ARGS') and k not in ('TF_WORKSPACE', 'TF_DATA_DIR', 'TF_CLI_CONFIG_FILE')}
    env['TF_IN_AUTOMATION'] = 'true'
    env['TF_WORKSPACE'] = 'default'
    if data_dir:
        env['TF_DATA_DIR'] = str(data_dir)
    return env

def tf(*args, env=None):
    return run(['terraform', f'-chdir={LAB / "terraform"}', *args], env=env or tf_env())

def validate():
    # Provider download may use the registry; backend, state and AWS API are never initialized.
    with tempfile.TemporaryDirectory(prefix='eks-lab-validate-') as directory:
        env = tf_env(Path(directory) / 'providers')
        tf('fmt', '-check', '-recursive', env=env)
        tf('init', '-backend=false', '-input=false', '-lockfile=readonly', env=env)
        tf('validate', '-no-color', env=env)
    print(json.dumps({'terraform_validation': 'passed', 'backend': False, 'cloud_actions': False}))

def file_hash(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def verify_plan(path, account, region, destroy):
    metadata = json.loads(Path(str(path) + '.json').read_text())
    expected = {'account': account, 'region': region, 'sha256': file_hash(path), 'action': 'destroy-plan' if destroy else 'plan'}
    if destroy:
        expected['rds_identifier'] = NAME
    if metadata != expected:
        raise ValueError('Pinned plan hash/account/region/action mismatch; generate and review a new plan')

def terraform_action(action, account, region):
    state_dir = LAB / 'terraform/.state'
    state_dir.mkdir(parents=True, exist_ok=True)
    destroy = action.startswith('destroy')
    plan = state_dir / ('destroy.tfplan' if destroy else 'provision.tfplan')
    if action in ('plan', 'destroy-plan'):
        tf('init', '-input=false')
        args = ['plan', '-input=false', '-lock-timeout=60s', f'-out={plan}', f'-var=expected_account_id={account}', f'-var=aws_region={region}']
        if destroy:
            args.append('-destroy')
        print(tf(*args))
        metadata = {'account': account, 'region': region, 'sha256': file_hash(plan), 'action': action}
        if destroy:
            metadata['rds_identifier'] = NAME
        Path(str(plan) + '.json').write_text(json.dumps(metadata), encoding='utf-8')
        print(json.dumps({'review_plan': str(plan), 'apply_separately': True}))
    else:
        verify_plan(plan, account, region, destroy)
        print(tf('apply', '-input=false', '-lock-timeout=60s', str(plan)))
        plan.unlink()
        Path(str(plan) + '.json').unlink()

def render_tree(source, destination, values):
    shutil.copytree(source, destination)
    for path in destination.rglob('*'):
        if path.suffix in ('.yaml', '.yml'):
            text = path.read_text(encoding='utf-8')
            # Tokens are whole unquoted YAML scalars, not shell substitutions.
            path.write_text(replace_tokens(text, values), encoding='utf-8')

def rds_bindings(account, region, bindings):
    host, secret, vpc = (bindings[key] for key in ('DB_HOST', 'RDS_MASTER_SECRET_ARN', 'VPC_ID'))
    if not re.fullmatch(re.escape(NAME) + r'\.[a-z0-9]+\.' + re.escape(region) + r'\.rds\.amazonaws\.com', host):
        raise ValueError('DB_HOST must be the exact lab RDS endpoint host in the selected region')
    if not re.fullmatch(re.escape(f'arn:aws:secretsmanager:{region}:{account}:secret:rds!db-') + r'[a-f0-9-]{36}-[A-Za-z0-9]{6}', secret):
        raise ValueError('Only the same-account/region RDS-managed secret ARN is accepted')
    if not re.fullmatch(r'vpc-[a-f0-9]+', vpc):
        raise ValueError('VPC_ID must be the exclusive Terraform lab VPC')
    return host, secret, vpc


def verify_rds(account, region, bindings):
    """Read-only ownership/privacy preflight, shared by deploy and destruction."""
    host, secret, vpc = rds_bindings(account, region, bindings)
    try:
        instances = json.loads(run(['aws', 'rds', 'describe-db-instances', '--db-instance-identifier', NAME]))['DBInstances']
        if len(instances) != 1:
            raise ValueError('RDS identity is ambiguous')
        db = instances[0]
        expected = {'DBInstanceIdentifier': NAME, 'DBInstanceArn': f'arn:aws:rds:{region}:{account}:db:{NAME}', 'DBName': 'manaleague_eks_lab', 'MasterUsername': 'manaleague_lab', 'Engine': 'postgres', 'PubliclyAccessible': False, 'StorageEncrypted': True, 'DBInstanceStatus': 'available'}
        if any(db.get(key) != value or (isinstance(value, bool) and db.get(key) is not value) for key, value in expected.items()) or not re.fullmatch(r'17(?:\.[0-9]+)*', db.get('EngineVersion', '')):
            raise ValueError('RDS identity or privacy mismatch')
        if db.get('Endpoint', {}).get('Address') != host or db.get('Endpoint', {}).get('Port') != 5432 or db.get('DBSubnetGroup', {}).get('VpcId') != vpc:
            raise ValueError('RDS endpoint/port/VPC mismatch')
        if db.get('MasterUserSecret', {}).get('SecretArn') != secret or db.get('MasterUserSecret', {}).get('SecretStatus') != 'active':
            raise ValueError('RDS managed-secret binding is not active or exact')
    except (KeyError, TypeError, ValueError, subprocess.CalledProcessError):
        raise ValueError('RDS preflight failed: exact available private encrypted PostgreSQL 17 lab instance and managed secret required') from None
    return secret


def rds_password(account, region):
    bindings = {key: require(key) for key in ('DB_HOST', 'RDS_MASTER_SECRET_ARN', 'VPC_ID')}
    secret = verify_rds(account, region, bindings)
    try:
        result = json.loads(run(['aws', 'secretsmanager', 'get-secret-value', '--secret-id', secret, '--version-stage', 'AWSCURRENT']))
        if not isinstance(result, dict) or result.get('ARN') != secret or 'AWSCURRENT' not in result.get('VersionStages', []):
            raise ValueError('Wrong secret version')
        credential = json.loads(result['SecretString'])
        if not isinstance(credential, dict):
            raise ValueError('Invalid credential format')
        password = credential['password']
        if credential.get('username') != 'manaleague_lab' or not isinstance(password, str) or len(password) < 24 or any(c in password for c in ('\n', '\r', '\x00')):
            raise ValueError('Invalid credential')
        return password
    except (KeyError, TypeError, ValueError, subprocess.CalledProcessError):
        # Never propagate JSON decoding excerpts, AWS stderr or credential values.
        raise ValueError('Invalid or unavailable current RDS-managed lab credential') from None


def configure_ingress(base, values):
    """Configure only the rendered, existing lab Ingress; never create an ALB/DNS."""
    https_contract.validate_app_url(values['APP_URL'])
    if values['APP_URL'] != https_contract.ORIGIN:
        if values.get('ACM_CERTIFICATE_ARN'):
            raise ValueError('ACM certificate requires the exact lab HTTPS origin')
        return
    role = re.fullmatch(r'arn:aws:iam::([0-9]{12}):role/manaleague-eks-lab-app', values.get('APP_ROLE_ARN', ''))
    if not role:
        raise ValueError('Exact lab app role required for HTTPS certificate binding')
    arn = values.get('ACM_CERTIFICATE_ARN', '')
    https_contract.validate_certificate_arn(arn, account=role[1], region=values['AWS_REGION'])
    path = Path(base) / 'ingress.yaml'
    ingress = json.loads(path.read_text())
    annotations = ingress['metadata']['annotations']
    prefix = 'alb.ingress.kubernetes.io/'
    annotations.update({prefix + 'listen-ports': '[{"HTTP":80},{"HTTPS":443}]',
                        prefix + 'certificate-arn': arn,
                        prefix + 'ssl-policy': https_contract.SSL_POLICY,
                        prefix + 'ssl-redirect': '443'})
    for rule in ingress['spec']['rules']:
        rule['host'] = https_contract.HOSTNAME
    path.write_text(json.dumps(ingress, indent=2))


def manifest_guard(directory, db_host=None, region=None, *, values=None):
    """The checked-in manifests intentionally use JSON-compatible YAML for stdlib validation."""
    found_config = False
    ingresses = []
    allowed = {'ConfigMap', 'Deployment', 'Service', 'Ingress', 'NetworkPolicy', 'CronJob', 'PodDisruptionBudget'}
    for path in directory.rglob('*.yaml'):
        if path.name == 'kustomization.yaml':
            continue
        for document in re.split(r'^---\s*$', path.read_text(), flags=re.MULTILINE):
            if not document.strip():
                continue
            resource = json.loads(document)
            if resource.get('kind') not in allowed or resource.get('metadata', {}).get('namespace') != NAME:
                raise ValueError('Only approved namespaced lab resources may be deployed')
            if resource.get('kind') == 'Service' and (resource.get('metadata', {}).get('name') == 'postgres' or resource.get('spec', {}).get('selector', {}).get('app') == 'postgres'):
                raise ValueError('In-cluster PostgreSQL service forbidden in RDS database mode')
            if resource.get('kind') == 'Ingress':
                ingresses.append(resource)
            if resource.get('kind') == 'ConfigMap' and resource['metadata']['name'] == 'manaleague-config':
                found_config = True
                data = resource['data']
                expected_host = db_host or os.environ.get('DB_HOST', '')
                expected_region = region or os.environ.get('AWS_REGION', '')
                host_pattern = re.escape(NAME) + r'\.[a-z0-9]+\.' + re.escape(expected_region) + r'\.rds\.amazonaws\.com'
                if not expected_region or not re.fullmatch(host_pattern, expected_host) or data.get('DB_HOST') != expected_host or any(data.get(key) != value for key, value in {'APP_ENV': 'eks-lab', 'DB_DATABASE': 'manaleague_eks_lab', 'DB_USERNAME': 'manaleague_lab', 'DB_PORT': '5432', 'LAB_DATABASE_MODE': 'rds', 'DB_SSLMODE': 'verify-full'}.items()) or data.get('PGSSLROOTCERT') != '/var/www/html/infra/eks-lab/runtime/certs/global-bundle.pem' or data.get('DB_URL', ''):
                    raise ValueError('Lab environment/database/TLS guard failed')
                if values is None and data.get('APP_URL', '').startswith('https:'):
                    raise ValueError('HTTPS manifest validation requires explicit deployment bindings')
                if values is not None and any(data.get(key) != value for key, value in _runtime_config(values['APP_URL']).items()):
                    raise ValueError('Lab runtime origin/cookie/WebSocket TLS guard failed')
    if not found_config:
        raise ValueError('Missing guarded manaleague-config')
    if values is not None:
        https_contract.validate_app_url(values['APP_URL'])
        secure = values['APP_URL'] == https_contract.ORIGIN
        expected = json.loads(replace_tokens((LAB / 'kubernetes/base/ingress.yaml').read_text(), values))
        prefix = 'alb.ingress.kubernetes.io/'
        if secure:
            expected['spec']['rules'][0]['host'] = https_contract.HOSTNAME
            expected['metadata']['annotations'].update({
                prefix + 'listen-ports': '[{"HTTP":80},{"HTTPS":443}]',
                prefix + 'certificate-arn': values.get('ACM_CERTIFICATE_ARN', ''),
                prefix + 'ssl-policy': https_contract.SSL_POLICY, prefix + 'ssl-redirect': '443'})
        if len(ingresses) != 1 or ingresses[0].get('spec') != expected['spec'] or ingresses[0].get('metadata') != expected['metadata']:
            raise ValueError('Lab Ingress host/certificate/TLS/private publisher guard failed')


def deployment_values(account):
    import ipaddress
    from urllib.parse import urlparse
    values = {key: require(key) for key in ('AVATAR_BUCKET', 'AWS_REGION', 'VPC_CIDR', 'APP_ROLE_ARN', 'ALLOWED_CIDRS', 'SUBNET_IDS', 'ALB_SECURITY_GROUP_ID', 'DB_HOST', 'VPC_ID', 'RDS_MASTER_SECRET_ARN')}
    rds_bindings(account, values['AWS_REGION'], values)
    values['APP_URL'] = require('APP_URL') if os.environ.get('APP_URL') else 'http://eks-lab.invalid'
    https_contract.validate_app_url(values['APP_URL'])
    values['ACM_CERTIFICATE_ARN'] = os.environ.get('ACM_CERTIFICATE_ARN', '')
    if values['APP_URL'] == https_contract.ORIGIN:
        https_contract.validate_certificate_arn(values['ACM_CERTIFICATE_ARN'], account=account, region=values['AWS_REGION'])
    elif values['ACM_CERTIFICATE_ARN']:
        raise ValueError('ACM certificate requires the exact lab HTTPS origin')
    if not values['AVATAR_BUCKET'].startswith(NAME + '-') or values['APP_ROLE_ARN'] != f'arn:aws:iam::{account}:role/{NAME}-app':
        raise ValueError('Exact lab avatar bucket/IRSA role required')
    ipaddress.ip_network(values['VPC_CIDR'])
    for cidr in values['ALLOWED_CIDRS'].split(','):
        network = ipaddress.ip_network(cidr.strip())
        if network.version != 4 or network.prefixlen < 24:
            raise ValueError('ALLOWED_CIDRS must be trusted IPv4 /24 or narrower')
    if not all(re.fullmatch(r'subnet-[a-f0-9]+', subnet) for subnet in values['SUBNET_IDS'].split(',')):
        raise ValueError('SUBNET_IDS must be comma separated subnet IDs')
    if os.environ.get('ALB_SECURITY_GROUP_ID'):
        values['ALB_SECURITY_GROUP_ID'] = require('ALB_SECURITY_GROUP_ID')
        if not re.fullmatch(r'sg-[a-f0-9]+', values['ALB_SECURITY_GROUP_ID']):
            raise ValueError('ALB_SECURITY_GROUP_ID must be the exact Terraform lab ALB SG ID')
    return values

def _mail_config():
    """Keep mail local unless the exact lab sender is explicitly supplied."""
    import os
    sender = os.environ.get('MAIL_FROM_ADDRESS')
    if sender is None:
        return {}
    if sender != https_contract.MAIL_FROM:
        raise ValueError('Lab mail may only send as the fixed lab address on the verified domain')
    return {'MAIL_MAILER': 'ses', 'MAIL_FROM_ADDRESS': https_contract.MAIL_FROM}


def _runtime_config(app_url):
    from urllib.parse import urlparse
    https_contract.validate_app_url(app_url)
    secure = app_url == https_contract.ORIGIN
    return {'APP_URL': app_url.rstrip('/'),
            'SESSION_COOKIE': https_contract.COOKIE if secure else 'manaleague_eks_lab_session',
            'SESSION_SECURE_COOKIE': 'true' if secure else 'false',
            'SESSION_DOMAIN': '', 'SESSION_PATH': '/',
            'SESSION_HTTP_ONLY': 'true', 'SESSION_SAME_SITE': 'lax',
            'REVERB_ALLOWED_ORIGINS': urlparse(app_url).hostname,
            'REVERB_CLIENT_URL': 'wss://' + https_contract.HOSTNAME if secure else '',
            'REVERB_HOST': 'reverb', 'REVERB_PORT': '8080', 'REVERB_SCHEME': 'http'}


def configure_runtime(base, app_url, secrets):
    """Roll envFrom consumers on config/secret changes, including future CronJob Pods."""
    runtime = _runtime_config(app_url)
    runtime.update(_mail_config())
    path = base / 'app-config.yaml'
    config = json.loads(path.read_text())
    config['data'].update(runtime)
    path.write_text(json.dumps(config, indent=2))
    checksum = hashlib.sha256(json.dumps({'config': config['data'], 'secrets': {key: secrets[key] for key in ('APP_KEY', 'DB_PASSWORD')}}, sort_keys=True).encode()).hexdigest()
    for path in base.rglob('*.yaml'):
        if path.name == 'kustomization.yaml':
            continue
        resources = [json.loads(doc) for doc in re.split(r'^---\s*$', path.read_text(), flags=re.MULTILINE) if doc.strip()]
        for resource in resources:
            if resource['kind'] == 'Deployment':
                template = resource['spec']['template']
            elif resource['kind'] == 'CronJob':
                template = resource['spec']['jobTemplate']['spec']['template']
            else:
                continue
            template.setdefault('metadata', {}).setdefault('annotations', {})['eks-lab.manaleague.test/runtime-config'] = checksum
        path.write_text('\n---\n'.join(json.dumps(obj, indent=2) for obj in resources))


def postdeploy_smoke(url, app_key):
    import hmac
    public_key = 'ml' + hmac.new(app_key.encode(), b'reverb:key', hashlib.sha256).hexdigest()[:18]
    # Only the public Reverb key reaches the probe; never put secrets in arguments/logs.
    env = {key: value for key, value in os.environ.items() if key not in ('APP_KEY', 'DB_PASSWORD', 'DEMO_PASSWORD')}
    env['REVERB_PUBLIC_KEY'] = public_key
    try:
        print(run([sys.executable, LAB / 'scripts/check_endpoint.py', '--url', url], env=env))
    except subprocess.CalledProcessError as error:
        raise ValueError('Post-deploy HTTP/WebSocket probe failed; confirm runner egress is allowed by the Terraform ALB SG. ' + (error.stderr or '')[-500:]) from None


def endpoint_diagnostics(kube):
    # No pod logs, Secret values, environment dumps or response bodies.
    for resource in ('pods', 'ingress', 'events'):
        try:
            items = json.loads(run(kube + ['get', resource, '-o', 'json']))['items']
            summary = [{'name': item['metadata']['name'], 'phase': item.get('status', {}).get('phase'), 'reason': item.get('reason'), 'ready': [c.get('status') for c in item.get('status', {}).get('conditions', []) if c.get('type') == 'Ready']} for item in items]
            print(json.dumps({'diagnostic': resource, 'items': summary}), file=sys.stderr)
        except (ValueError, OSError, subprocess.CalledProcessError):
            print('Diagnostic unavailable: ' + resource, file=sys.stderr)


def finalize_endpoint(kube, base, values, secrets):
    hostname = None
    for attempt in range(60):
        ingress = json.loads(run(kube + ['get', 'ingress', 'manaleague', '-o', 'json']))
        hosts = [obj.get('hostname', '') for obj in ingress.get('status', {}).get('loadBalancer', {}).get('ingress', [])]
        if len(hosts) == 1 and re.fullmatch(r'[a-z0-9-]+\.[a-z0-9-]+\.elb\.amazonaws\.com', hosts[0]):
            hostname = hosts[0]
            break
        if attempt < 59:
            time.sleep(5)
    if not hostname:
        raise ValueError('Timed out waiting for exact ALB hostname on lab Ingress')
    url = 'http://' + hostname if values['APP_URL'] == 'http://eks-lab.invalid' else values['APP_URL'].rstrip('/')
    configure_runtime(base, url, secrets)
    rendered = run(['kubectl', 'kustomize', base])
    run(kube + ['apply', '-f', '-'], input=rendered)
    actual = json.loads(run(kube + ['get', 'configmap', 'manaleague-config', '-o', 'json']))
    expected = json.loads((base / 'app-config.yaml').read_text())['data']
    if actual.get('data') != expected:
        raise ValueError('Final application URL/origin ConfigMap read-back mismatch')
    if values['APP_URL'] == https_contract.ORIGIN:
        ingress = json.loads(run(kube + ['get', 'ingress', 'manaleague', '-o', 'json']))
        expected_ingress = json.loads((base / 'ingress.yaml').read_text())
        annotations = ingress.get('metadata', {}).get('annotations', {})
        if ingress.get('spec') != expected_ingress['spec'] or any(annotations.get(key) != value for key, value in expected_ingress['metadata']['annotations'].items()):
            raise ValueError('HTTPS Ingress host/certificate/TLS read-back mismatch')
    for deployment in ('manaleague', 'reverb'):
        run(kube + ['rollout', 'status', f'deployment/{deployment}', '--timeout=300s'])
    postdeploy_smoke(url, secrets['APP_KEY'])
    return url


def deploy(account, region):
    values = deployment_values(account)
    if values['AWS_REGION'] != region:
        raise ValueError('Deployment region must match validated bindings')
    if values['APP_URL'] == https_contract.ORIGIN:
        arn = values.get('ACM_CERTIFICATE_ARN', '')
        https_contract.validate_certificate_arn(arn, account=account, region=region)
        try:
            certificate = json.loads(run(['aws', 'acm', 'describe-certificate', '--certificate-arn', arn, '--region', region]))['Certificate']
            tags = json.loads(run(['aws', 'acm', 'list-tags-for-certificate', '--certificate-arn', arn, '--region', region]))['Tags']
            https_contract.validate_certificate(certificate, tags, account=account, region=region, arn=arn)
        except (KeyError, TypeError, ValueError, OSError, subprocess.CalledProcessError):
            raise ValueError('HTTPS certificate preflight failed: exact issued lab-owned host certificate required') from None
    secrets = {key: require(key) for key in ('APP_KEY', 'DEMO_PASSWORD')}
    try:
        if not secrets['APP_KEY'].startswith('base64:') or len(base64.b64decode(secrets['APP_KEY'][7:], validate=True)) != 32:
            raise ValueError('APP_KEY must contain 32 random bytes encoded as Laravel base64 key')
    except Exception as error:
        raise ValueError('Invalid lab APP_KEY') from error
    if len(secrets['DEMO_PASSWORD']) < 24:
        raise ValueError('Lab demo password must be at least 24 characters')
    images = {t: image_guard(require(f'{t.upper()}_IMAGE'), account, region, t) for t in ('app', 'web')}
    release = require('RELEASE')
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('RELEASE must be full commit SHA')
    with tempfile.TemporaryDirectory(prefix='eks-lab-deploy-') as directory:
        temp = Path(directory)
        base = temp / 'base'
        render_tree(LAB / 'kubernetes/base', base, values)
        for file in base.rglob('*.yaml'):
            text = file.read_text().replace('lab-app:dev', images['app']).replace('lab-web:dev', images['web'])
            file.write_text(text)
        # Credential stays in process memory and stdin only, never environment/argv/logs.
        secrets['DB_PASSWORD'] = rds_password(account, region)
        configure_ingress(base, values)
        configure_runtime(base, values['APP_URL'], secrets)
        manifest_guard(base, values['DB_HOST'], region, values=values)
        rendered = run(['kubectl', 'kustomize', base])
        kube = kube_config(account, region, temp / 'kubeconfig') + ['-n', NAME]
        if json.loads(run(kube + ['get', 'statefulset', '-o', 'json']))['items']:
            raise ValueError('Unexpected StatefulSet in RDS lab; refusing deployment')
        if json.loads(run(kube + ['get', 'pvc', '-o', 'json']))['items']:
            raise ValueError('Unexpected PVC in RDS lab; refusing deployment')
        run(kube + ['get', 'deployments', '-o', 'name'])
        # Bootstrap reserves both names; CI cannot create/list arbitrary Secrets.
        for name, keys in (('manaleague-secrets', ('APP_KEY', 'DB_PASSWORD')), ('manaleague-demo', ('DEMO_PASSWORD',))):
            run(kube + ['get', 'secret', name, '-o', 'name'])
            secret = {'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': name, 'namespace': NAME}, 'type': 'Opaque', 'data': {key: base64.b64encode(secrets[key].encode()).decode() for key in keys}}
            run(kube + ['apply', '-f', '-'], input=json.dumps(secret))
            actual = json.loads(run(kube + ['get', 'secret', name, '-o', 'json']))
            if actual.get('data') != secret['data']:
                raise ValueError('Secret read-back mismatch')
        run(kube + ['apply', '--dry-run=server', '-f', '-'], input=rendered)
        # Delay all Deployments/Ingress until schema succeeds. Kustomize emits canonical YAML
        # document separators and top-level kind, so no runtime YAML parser is required.
        documents = re.split(r'^---\s*$', rendered, flags=re.MULTILINE)
        prerequisites = '\n---\n'.join(doc for doc in documents if not re.search(r'^kind: (Deployment|Ingress|PodDisruptionBudget|CronJob)$', doc, re.MULTILINE))
        run(kube + ['apply', '-f', '-'], input=prerequisites)
        # RDS availability/identity was read and verified before the first namespace write.
        # Migration Job owns bounded connection retries; no in-cluster database rollout.
        for job in ('migrate', 'seed'):
            job_manifest = (LAB / f'kubernetes/jobs/{job}.yaml').read_text()
            job_manifest = job_manifest.replace('__RELEASE__', release).replace('lab-app:dev', images['app'])
            job_manifest = replace_tokens(job_manifest, values)
            job_name = f'{job}-{release}'
            # Completed immutable release jobs can be reused; failed jobs require operator review.
            run(kube + ['apply', '--dry-run=server', '-f', '-'], input=job_manifest)
            run(kube + ['apply', '-f', '-'], input=job_manifest)
            run(kube + ['wait', '--for=condition=complete', f'job/{job_name}', '--timeout=600s'])
        run(kube + ['apply', '-f', '-'], input=rendered)
        try:
            app_url = finalize_endpoint(kube, base, values, secrets)
        except (ValueError, OSError, subprocess.CalledProcessError):
            endpoint_diagnostics(kube)
            raise
        state = json.loads(run(kube + ['get', 'deployment', 'manaleague', '-o', 'json']))
        deployed = {c['image'] for c in state['spec']['template']['spec']['containers']}
        if not set(images.values()).issubset(deployed):
            raise ValueError('Deployment digest read-back differs from tested images')
    print(json.dumps({'deployed': release, 'namespace': NAME, 'images': images, 'app_url': app_url, 'http_websocket_smoke': 'passed'}))

def bootstrap_secret_access(kube):
    """Operator-only: reserve both Secrets; CI can patch only these exact names."""
    ns = kube + ['-n', NAME]
    names = ['manaleague-secrets', 'manaleague-demo']
    for name in names:
        existing = run(ns + ['get', 'secret', name, '--ignore-not-found', '-o', 'json'])
        if not existing:
            stub = {'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': name, 'namespace': NAME}, 'type': 'Opaque', 'data': {}}
            run(ns + ['create', '-f', '-'], input=json.dumps(stub))
        actual = json.loads(run(ns + ['get', 'secret', name, '-o', 'json']))
        if actual.get('metadata', {}).get('name') != name or actual['metadata'].get('namespace') != NAME or actual.get('type') != 'Opaque':
            raise ValueError('Reserved Secret read-back mismatch')
    role = {'apiVersion': 'rbac.authorization.k8s.io/v1', 'kind': 'Role', 'metadata': {'name': 'lab-secret-updater', 'namespace': NAME}, 'rules': [{'apiGroups': [''], 'resources': ['secrets'], 'resourceNames': names, 'verbs': ['get', 'update', 'patch']}]}
    binding = {'apiVersion': 'rbac.authorization.k8s.io/v1', 'kind': 'RoleBinding', 'metadata': {'name': 'lab-secret-updater', 'namespace': NAME}, 'roleRef': {'apiGroup': 'rbac.authorization.k8s.io', 'kind': 'Role', 'name': 'lab-secret-updater'}, 'subjects': [{'kind': 'Group', 'name': NAME + '-deploy', 'apiGroup': 'rbac.authorization.k8s.io'}]}
    for resource in (role, binding):
        run(ns + ['apply', '-f', '-'], input=json.dumps(resource))
        actual = json.loads(run(ns + ['get', resource['kind'].lower(), resource['metadata']['name'], '-o', 'json']))
        for key in ('rules', 'subjects', 'roleRef'):
            if key in resource and actual.get(key) != resource[key]:
                raise ValueError('Secret RBAC read-back mismatch')
    run(ns + ['get', 'secret', 'manaleague-secrets', '-o', 'name'])

def bootstrap(account, region):
    outputs = {key: value['value'] for key, value in json.loads(tf('output', '-json')).items()}
    if outputs['account_id'] != account or outputs['cluster_name'] != NAME or outputs['namespace'] != NAME:
        raise ValueError('Bootstrap Terraform outputs are not the exact lab target')
    with tempfile.TemporaryDirectory(prefix='eks-lab-bootstrap-') as directory:
        temp = Path(directory)
        kube = kube_config(account, region, temp / 'kubeconfig')
        values = {'APP_ROLE_ARN': outputs['app_role_arn']}
        manifest = temp / 'bootstrap'
        render_tree(LAB / 'kubernetes/bootstrap', manifest, values)
        run(kube + ['apply', '-f', manifest / 'resources.yaml'])
        run(kube + ['apply', '-f', manifest / 'rbac.yaml'])
        run(kube + ['get', 'namespace', NAME])
        bootstrap_secret_access(kube)
        config = json.loads((LAB / 'addons/versions.json').read_text())['aws_load_balancer_controller']
        helm_values = temp / 'helm-values.json'
        helm_values.write_text(json.dumps(outputs['load_balancer_controller_helm_values']))
        helm = ['helm', '--kubeconfig', str(temp / 'kubeconfig')]
        run(helm + ['upgrade', '--install', config['release_name'], config['chart'], '--repo', config['repository'], '--version', config['chart_version'], '--namespace', config['namespace'], '-f', LAB / 'addons/aws-load-balancer-controller.values.yaml', '-f', helm_values, '--wait', '--timeout', '5m'])
        run(kube + ['-n', 'kube-system', 'rollout', 'status', 'deployment/aws-load-balancer-controller', '--timeout=300s'])
        run(helm + ['status', config['release_name'], '-n', config['namespace'], '-o', 'json'])
    print(json.dumps({'bootstrap': 'passed', 'namespace': NAME}))

def verify_alb_owner(arn, ingress_name):
    tags = json.loads(run(['aws', 'elbv2', 'describe-tags', '--resource-arns', arn]))['TagDescriptions'][0]['Tags']
    tags = {t['Key']: t['Value'] for t in tags}
    if tags.get('elbv2.k8s.aws/cluster') != NAME or tags.get('ingress.k8s.aws/stack') != f'{NAME}/{ingress_name}':
        raise ValueError('ALB ownership unknown; refusing teardown')

def storage_guard(account, region):
    """Retention preflight only: never silently purge S3 objects, versions or ECR images."""
    outputs = {key: value['value'] for key, value in json.loads(tf('output', '-json')).items()}
    if outputs['account_id'] != account or outputs['aws_region'] != region or outputs['cluster_name'] != NAME or outputs['namespace'] != NAME:
        raise ValueError('Storage preflight requires exact Terraform-owned lab outputs')
    for target in ('app', 'web'):
        expected = f'{account}.dkr.ecr.{region}.amazonaws.com/{NAME}-{target}'
        if outputs[f'ecr_{target}_repository_url'] != expected:
            raise ValueError('Unknown ECR repository ownership')
        images = json.loads(run(['aws', 'ecr', 'list-images', '--repository-name', f'{NAME}-{target}', '--max-results', '1', '--no-paginate']))
        if images.get('imageIds'):
            raise ValueError(f'Non-empty ECR {NAME}-{target}: review and explicitly remove only owned lab data before teardown. No images were deleted.')
    bucket = f'{NAME}-{account}-{region}-avatars'
    if outputs['avatar_bucket_name'] != bucket:
        raise ValueError('Unknown avatar bucket ownership')
    objects = json.loads(run(['aws', 's3api', 'list-object-versions', '--bucket', bucket, '--expected-bucket-owner', account, '--max-keys', '1', '--no-paginate']))
    if objects.get('Versions') or objects.get('DeleteMarkers'):
        raise ValueError('Non-empty S3 avatar bucket: review and explicitly remove only owned lab data/versions before teardown. No objects were deleted.')
    uploads = json.loads(run(['aws', 's3api', 'list-multipart-uploads', '--bucket', bucket, '--expected-bucket-owner', account, '--max-uploads', '1', '--no-paginate']))
    if uploads.get('Uploads'):
        raise ValueError('Outstanding lab S3 multipart uploads must be reviewed before teardown')

def rds_destroy_guard(account, region):
    outputs = {key: value['value'] for key, value in json.loads(tf('output', '-json')).items()}
    expected = {'account_id': account, 'aws_region': region, 'cluster_name': NAME, 'namespace': NAME, 'rds_identifier': NAME, 'rds_database': 'manaleague_eks_lab', 'rds_username': 'manaleague_lab', 'rds_port': 5432}
    if any(outputs.get(key) != value for key, value in expected.items()):
        raise ValueError('Destruction requires exact Terraform-owned RDS lab outputs')
    try:
        bindings = {'DB_HOST': outputs['rds_endpoint'], 'RDS_MASTER_SECRET_ARN': outputs['rds_master_secret_arn'], 'VPC_ID': outputs['vpc_id']}
    except KeyError:
        raise ValueError('Missing RDS Terraform outputs; refusing data loss') from None
    verify_rds(account, region, bindings)


def runtime_identity(resource, allowed=None):
    metadata = resource.get('metadata', {})
    name = metadata.get('name', '')
    if metadata.get('namespace') != NAME or not metadata.get('uid') or (allowed is not None and name not in allowed):
        raise ValueError('Unexpected runtime identity/namespace/UID; review before teardown')
    return name


def validate_runtime_jobs(jobs, cronjobs):
    cron_uids = {runtime_identity(obj): obj['metadata']['uid'] for obj in cronjobs}
    for job in jobs:
        name = runtime_identity(job)
        owners = job['metadata'].get('ownerReferences', [])
        release_job = re.fullmatch(r'(migrate|seed)-[a-f0-9]{40}', name) and not owners
        cron_job = len(owners) == 1 and any(
            owner.get('kind') == 'CronJob' and owner.get('controller') is True
            and owner.get('name') in cron_uids and owner.get('uid') == cron_uids[owner['name']]
            and re.fullmatch(re.escape(owner['name']) + r'-[0-9]+', name)
            for owner in owners)
        if not release_job and not cron_job:
            raise ValueError('Unexpected job owner/UID; review before teardown')


def validate_runtime_pods(ns, workloads, jobs):
    kinds = {'deployment': 'Deployment', 'statefulset': 'StatefulSet'}
    owners = {(kinds[kind], name, uid) for kind, name, uid in workloads}
    owners.update(('Job', runtime_identity(job), job['metadata']['uid']) for job in jobs)
    def owned(resource):
        refs = resource.get('metadata', {}).get('ownerReferences', [])
        return len(refs) == 1 and refs[0].get('controller') is True and (refs[0].get('kind'), refs[0].get('name'), refs[0].get('uid')) in owners
    for replica in json.loads(run(ns + ['get', 'replicasets', '-o', 'json']))['items']:
        runtime_identity(replica)
        if not owned(replica):
            raise ValueError('ReplicaSet owner/UID unknown; review before teardown')
        owners.add(('ReplicaSet', replica['metadata']['name'], replica['metadata']['uid']))
    for pod in json.loads(run(ns + ['get', 'pods', '-o', 'json']))['items']:
        runtime_identity(pod)
        if not owned(pod):
            raise ValueError('Pod owner/UID unknown; review before teardown')


def cleanup_runtime(account, region):
    """Delete only proven lab runtime resources while LB/EBS controllers still run."""
    with tempfile.TemporaryDirectory(prefix='eks-lab-teardown-') as directory:
        kube = kube_config(account, region, Path(directory) / 'kubeconfig')
        ns = kube + ['-n', NAME]
        # RDS mode has no database workload or persistent namespace data. Never
        # auto-migrate or auto-delete unexpected remnants from an older lab.
        if json.loads(run(ns + ['get', 'statefulset', '-o', 'json']))['items']:
            raise ValueError('Unexpected StatefulSet in RDS lab; review manually before teardown')
        if json.loads(run(ns + ['get', 'pvc', '-o', 'json']))['items']:
            raise ValueError('Unexpected PVC in RDS lab; review manually before teardown')
        cronjobs = json.loads(run(ns + ['get', 'cronjobs', '-o', 'json']))['items']
        allowed_crons = {'scheduler', 'cards-sync-bans', 'cards-sync-legality', 'challenges-auto-confirm'}
        for cronjob in cronjobs:
            runtime_identity(cronjob, allowed_crons)
            if cronjob['metadata'].get('ownerReferences'):
                raise ValueError('Unexpected CronJob owner')
        jobs = json.loads(run(ns + ['get', 'jobs', '-o', 'json']))['items']
        validate_runtime_jobs(jobs, cronjobs)
        workloads = []
        for kind, names in (('deployment', {'manaleague', 'reverb'}),):
            for resource in json.loads(run(ns + ['get', kind, '-o', 'json']))['items']:
                name = runtime_identity(resource, names)
                if resource['metadata'].get('ownerReferences'):
                    raise ValueError('Unexpected workload owner')
                workloads.append((kind, name, resource['metadata']['uid']))
        validate_runtime_pods(ns, workloads, jobs)
        ingresses = json.loads(run(ns + ['get', 'ingress', '-o', 'json']))['items']
        load_balancers = json.loads(run(['aws', 'elbv2', 'describe-load-balancers', '--region', region]))['LoadBalancers']
        owned_albs = []
        for ingress in ingresses:
            runtime_identity(ingress, {'manaleague'})
            name = ingress['metadata']['name']
            hosts = [entry['hostname'] for entry in ingress.get('status', {}).get('loadBalancer', {}).get('ingress', []) if 'hostname' in entry]
            if not hosts:
                raise ValueError('Ingress ALB identity unresolved; reconcile controller before destroy')
            for host in hosts:
                matching = [lb for lb in load_balancers if lb['DNSName'] == host]
                if len(matching) != 1:
                    raise ValueError('Cannot identify exact ingress ALB')
                arn = matching[0]['LoadBalancerArn']
                if not arn.startswith(f'arn:aws:elasticloadbalancing:{region}:{account}:loadbalancer/app/'):
                    raise ValueError('Foreign ALB account/region')
                verify_alb_owner(arn, name)
                owned_albs.append(arn)
        if json.loads(run(ns + ['get', 'pvc', '-o', 'json']))['items']:
            raise ValueError('Unexpected PVC in RDS lab; review manually before teardown')
        volumes = []
        # Complete ownership/orphan checks before even suspending a scheduler.
        for lb in load_balancers:
            arn = lb['LoadBalancerArn']
            tags = json.loads(run(['aws', 'elbv2', 'describe-tags', '--resource-arns', arn]))['TagDescriptions'][0]['Tags']
            if arn not in owned_albs and any(tag['Key'] == 'elbv2.k8s.aws/cluster' and tag['Value'] == NAME for tag in tags):
                raise ValueError('Orphan lab ALB; restore reconciliation before teardown')
        namespace_volumes = json.loads(run(['aws', 'ec2', 'describe-volumes', '--region', region, '--filters', f'Name=tag:kubernetes.io/created-for/pvc/namespace,Values={NAME}']))['Volumes']
        if any(volume['VolumeId'] not in volumes for volume in namespace_volumes):
            raise ValueError('Orphan lab namespace EBS volume; investigate before teardown')
        for cronjob in cronjobs:
            name = cronjob['metadata']['name']
            patch = {'metadata': {'uid': cronjob['metadata']['uid']}, 'spec': {'suspend': True}}
            run(ns + ['patch', 'cronjob', name, '--type=merge', '-p', json.dumps(patch)])
            actual = json.loads(run(ns + ['get', 'cronjob', name, '-o', 'json']))
            if runtime_identity(actual, {name}) != name or actual['metadata']['uid'] != cronjob['metadata']['uid'] or actual.get('spec', {}).get('suspend') is not True:
                raise ValueError('CronJob suspension/UID not confirmed')
        # A controller might have scheduled a final Job during suspension: revalidate it.
        jobs = json.loads(run(ns + ['get', 'jobs', '-o', 'json']))['items']
        validate_runtime_jobs(jobs, cronjobs)
        for ingress in ingresses:
            name = ingress['metadata']['name']
            run(ns + ['delete', 'ingress', name, '--wait=true', '--timeout=600s'])
            remaining = json.loads(run(ns + ['get', 'ingress', '-o', 'json']))['items']
            if any(item['metadata']['name'] == name for item in remaining):
                raise ValueError('Ingress still exists after deletion')
        for arn in owned_albs:
            run(['aws', 'elbv2', 'wait', 'load-balancers-deleted', '--load-balancer-arns', arn, '--region', region])
        # Catch orphan lab ALBs even if no Ingress status remains. Never delete them by tag.
        remaining_lbs = json.loads(run(['aws', 'elbv2', 'describe-load-balancers', '--region', region]))['LoadBalancers']
        for lb in remaining_lbs:
            arn = lb['LoadBalancerArn']
            tags = json.loads(run(['aws', 'elbv2', 'describe-tags', '--resource-arns', arn]))['TagDescriptions'][0]['Tags']
            if any(tag['Key'] == 'elbv2.k8s.aws/cluster' and tag['Value'] == NAME for tag in tags):
                raise ValueError('Lab ALB remains; restore controller reconciliation before Terraform destroy')
        for kind, name, uid in workloads:
            actual = json.loads(run(ns + ['get', kind, name, '-o', 'json']))
            if actual.get('metadata', {}).get('uid') != uid:
                raise ValueError('Workload UID changed since teardown preflight')
            run(ns + ['scale', f'{kind}/{name}', '--replicas=0'])
        for job in jobs:
            run(ns + ['delete', 'job', job['metadata']['name'], '--wait=true', '--timeout=300s'])
        pods = json.loads(run(ns + ['get', 'pods', '-o', 'json']))['items']
        for pod in pods:
            run(ns + ['wait', '--for=delete', f'pod/{pod["metadata"]["name"]}', '--timeout=300s'])
        if json.loads(run(ns + ['get', 'pvc', '-o', 'json']))['items']:
            raise ValueError('Unexpected PVC remains; refusing RDS lab teardown')
        remaining_volumes = json.loads(run(['aws', 'ec2', 'describe-volumes', '--region', region, '--filters', f'Name=tag:kubernetes.io/created-for/pvc/namespace,Values={NAME}']))['Volumes']
        if remaining_volumes:
            raise ValueError('Lab namespace EBS volumes remain; investigate orphaned data before controller destruction')
    print(json.dumps({'runtime_cleanup': 'verified', 'deleted_volumes': volumes, 'deleted_albs': owned_albs}))

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('validate',) + CLOUD)
    parser.add_argument('--allow-cloud', action='store_true')
    parser.add_argument('--confirm-lab')
    parser.add_argument('--expected-account')
    parser.add_argument('--delete-lab-data', action='store_true', help='Required for destroy: permanently delete the isolated lab RDS database and its managed secret (no final snapshot)')
    args = parser.parse_args(argv)
    if args.action in CLOUD:
        if not args.allow_cloud or args.confirm_lab != NAME or not args.expected_account:
            parser.error('Cloud operations require --allow-cloud --confirm-lab manaleague-eks-lab --expected-account ACCOUNT')
        if args.action == 'destroy' and not args.delete_lab_data:
            parser.error('destroy additionally requires --delete-lab-data; review backups and deletion first')
        account, region = cloud_guard(args.expected_account)
        if args.action in ('plan', 'apply', 'destroy-plan'):
            terraform_action(args.action, account, region)
        elif args.action == 'destroy':
            verify_plan(LAB / 'terraform/.state/destroy.tfplan', account, region, True)
            storage_guard(account, region)
            rds_destroy_guard(account, region)
            cleanup_runtime(account, region)
            storage_guard(account, region)
            rds_destroy_guard(account, region)
            terraform_action('destroy', account, region)
        elif args.action == 'publish':
            publish(account, region)
        elif args.action == 'bootstrap':
            bootstrap(account, region)
        elif args.action == 'deploy':
            deploy(account, region)
    else:
        validate()

if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
