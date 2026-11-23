#!/usr/bin/env python3
"""Fail-closed deploy harness. All configuration except the three release inputs is trusted project configuration."""
import hashlib
import http.client
import json
import ssl
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tempfile
import zipfile

NAME = 'manaleague-eks-lab'
ARCHIVE = Path('/opt/manaleague-deploy/deploy.zip')
ARCHIVE_HASH = Path('/opt/manaleague-deploy/deploy.sha256')
REQUIRED_FILES = frozenset('infra/eks-lab/' + name for name in (
    'scripts/lab.sh', 'scripts/lab.py', 'scripts/https_contract.py',
    'scripts/check_endpoint.py', 'kubernetes/jobs/migrate.yaml',
    'kubernetes/jobs/seed.yaml', 'kubernetes/base/app.yaml',
    'kubernetes/base/app-config.yaml', 'kubernetes/base/daily-tasks.yaml',
    'kubernetes/base/ingress.yaml', 'kubernetes/base/kustomization.yaml',
    'kubernetes/base/network-policies.yaml', 'kubernetes/base/reverb.yaml',
    'kubernetes/base/scheduler.yaml'))


def source_key(release, source_sha256):
    if not re.fullmatch(r'[a-f0-9]{40}', release) or not re.fullmatch(r'[a-f0-9]{64}', source_sha256):
        raise ValueError('invalid source binding')
    return f'releases/{release}/{source_sha256}.zip'


def digest(path):
    h = hashlib.sha256()
    with open(path, 'rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def download_source(account, region, release, source_sha256, path, env, *, run=None):
    run = invoke if run is None else run
    key = source_key(release, source_sha256)
    bucket = f'manaleague-eks-lab-releases-{account}-{region}'
    data = json.loads(run(['aws', 's3api', 'head-object', '--bucket', bucket, '--key', key,
                           '--region', region], env).stdout)
    size = data.get('ContentLength')
    if not isinstance(size, int) or not 0 < size <= 50_000_000:
        raise ValueError('oversized deploy artifact')
    run(['aws', 's3api', 'get-object', '--bucket', bucket, '--key', key,
         '--region', region, '--range', f'bytes=0-{size - 1}', str(path)], env)
    if path.stat().st_size != size or digest(path) != source_sha256:
        raise ValueError('untrusted deploy artifact')


def github_json(kind, sha):
    if kind not in ('commits', 'trees') or not re.fullmatch(r'[a-f0-9]{40}', sha):
        raise ValueError('invalid GitHub request')
    suffix = '?recursive=1' if kind == 'trees' else ''
    connection = http.client.HTTPSConnection('api.github.com', timeout=20, context=ssl.create_default_context())
    try:
        connection.request('GET', f'/repos/viniciusVitorio/manaleague/git/{kind}/{sha}{suffix}',
                           headers={'User-Agent': 'manaleague-deploy', 'Accept': 'application/vnd.github+json'})
        response = connection.getresponse()
        if response.status != 200:
            raise ValueError('GitHub provenance request failed')
        data = response.read(10_000_001)
        if len(data) > 10_000_000:
            raise ValueError('oversized GitHub response')
        return json.loads(data)
    finally:
        connection.close()


def verify_provenance(archive, release):
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('invalid release')
    commit = github_json('commits', release)
    tree_sha = commit.get('tree', {}).get('sha', '')
    if commit.get('sha') != release or not re.fullmatch(r'[a-f0-9]{40}', tree_sha):
        raise ValueError('commit mismatch')
    tree = github_json('trees', tree_sha)
    if tree.get('sha') != tree_sha or tree.get('truncated') is not False or not isinstance(tree.get('tree'), list):
        raise ValueError('invalid Git tree')
    entries = {}
    for entry in tree['tree']:
        path = entry.get('path')
        if not isinstance(path, str) or path in entries:
            raise ValueError('invalid Git tree path')
        entries[path] = entry
        if entry.get('type') == 'commit':
            raise ValueError('submodules unsupported')
    with zipfile.ZipFile(archive) as z:
        names = set()
        infos = z.infolist()
        if len(infos) > 10000 or sum(i.file_size for i in infos) > 100_000_000:
            raise ValueError('oversized deploy artifact')
        for item in infos:
            name = item.filename
            path = PurePosixPath(name)
            mode = item.external_attr >> 16
            if (not name or '\\' in name or path.is_absolute() or '..' in path.parts
                    or str(path) != name.rstrip('/') or name in names or item.flag_bits & 1):
                raise ValueError('unsafe deploy artifact')
            names.add(name)
            entry = entries.get(name.rstrip('/'))
            if item.is_dir():
                if not entry or entry.get('type') != 'tree':
                    raise ValueError('untrusted directory')
                continue
            git_mode = '100755' if mode & 0o111 else '100644'
            if (stat.S_IFMT(mode) not in (0, stat.S_IFREG) or not entry or entry.get('type') != 'blob'
                    or entry.get('mode') != git_mode):
                raise ValueError('untrusted file mode or path')
            content = z.read(item)
            actual = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
            if entry.get('sha') != actual:
                raise ValueError('untrusted file content')
        if not REQUIRED_FILES.issubset(names):
            raise ValueError('missing deploy dependencies')


def extract_archive(archive, destination, expected):
    if not re.fullmatch(r'[a-f0-9]{64}', expected) or digest(archive) != expected:
        raise ValueError('untrusted deploy artifact')
    with zipfile.ZipFile(archive) as z:
        entries = z.infolist()
        names = set()
        if len(entries) > 10000 or sum(i.file_size for i in entries) > 100_000_000:
            raise ValueError('oversized deploy artifact')
        for item in entries:
            path = PurePosixPath(item.filename)
            mode = item.external_attr >> 16
            if (not item.filename or '\\' in item.filename or path.is_absolute() or '..' in path.parts
                    or item.filename in names or stat.S_ISLNK(mode) or item.flag_bits & 1):
                raise ValueError('unsafe deploy artifact')
            names.add(item.filename)
        if not REQUIRED_FILES.issubset(names) or any(z.getinfo(name).is_dir() for name in REQUIRED_FILES):
            raise ValueError('missing deploy dependencies')
        z.extractall(destination)


def invoke(args, env, *, check=True):
    result = subprocess.run(args, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if check and result.returncode:
        raise ValueError('deploy subprocess failed')
    return result


def deploy(env):
    account, region = env.get('AWS_ACCOUNT_ID', ''), env.get('AWS_REGION', '')
    release = env.get('RELEASE', '')
    if not re.fullmatch(r'[0-9]{12}', account) or not re.fullmatch(r'[a-z]{2}-[a-z]+-[0-9]', region):
        raise ValueError('invalid target')
    if not re.fullmatch(r'[a-f0-9]{40}', release):
        raise ValueError('invalid release')
    source_sha256 = env.get('SOURCE_SHA256', '')
    source_key(release, source_sha256)
    images = {}
    for target in ('app', 'web'):
        value = env.get(target.upper() + '_DIGEST', '')
        if not re.fullmatch(r'sha256:[a-f0-9]{64}', value):
            raise ValueError('invalid digest')
        images[target] = f'{account}.dkr.ecr.{region}.amazonaws.com/{NAME}-{target}@{value}'
    secret_arn = env.get('LAB_APP_SECRET_ARN', '')
    if not re.fullmatch(r'arn:aws:secretsmanager:' + re.escape(region) + ':' + account + r':secret:manaleague-eks-lab-app-[A-Za-z0-9]{6}', secret_arn):
        raise ValueError('invalid application secret binding')
    clean = dict(env)
    for key in ('APP_KEY', 'DEMO_PASSWORD', 'DB_PASSWORD', 'GITHUB_ACTIONS', 'GITHUB_OUTPUT', 'BASH_ENV', 'ENV', 'PYTHONPATH', 'PYTHONHOME'):
        clean.pop(key, None)
    clean.update({'AWS_PAGER': '', 'AWS_CLI_AUTO_PROMPT': 'off', 'AWS_DEFAULT_OUTPUT': 'json'})
    with tempfile.TemporaryDirectory(prefix='lab-runtime-') as work:
        root = Path(work)
        archive = root / 'source.zip'
        download_source(account, region, release, source_sha256, archive, clean)
        verify_provenance(archive, release)
        extract_archive(archive, root / 'source', source_sha256)
        for target in ('app', 'web'):
            repo = NAME + '-' + target
            repo_data = json.loads(invoke(['aws', 'ecr', 'describe-repositories', '--repository-names', repo, '--region', region], clean).stdout)
            repos = repo_data['repositories']
            if len(repos) != 1 or repos[0].get('imageTagMutability') != 'IMMUTABLE' or repos[0].get('repositoryUri') != images[target].split('@')[0]:
                raise ValueError('untrusted image repository')
            data = json.loads(invoke(['aws', 'ecr', 'describe-images', '--repository-name', repo, '--image-ids', 'imageTag=' + release, '--region', region], clean).stdout)
            details = data['imageDetails']
            if len(details) != 1 or details[0].get('imageDigest') != env[target.upper() + '_DIGEST']:
                raise ValueError('release image mismatch')
        response = json.loads(invoke(['aws', 'secretsmanager', 'get-secret-value', '--secret-id', secret_arn, '--version-stage', 'AWSCURRENT', '--region', region], clean).stdout)
        if response.get('ARN') != secret_arn or 'AWSCURRENT' not in response.get('VersionStages', []):
            raise ValueError('invalid application secret version')
        secret = json.loads(response['SecretString'])
        if not isinstance(secret, dict) or set(secret) != {'APP_KEY', 'DEMO_PASSWORD'} or any(not isinstance(v, str) or not v or '\n' in v or '\r' in v or '\x00' in v for v in secret.values()):
            raise ValueError('invalid application secret')
        clean.update(secret)
        clean.update({'RELEASE': release, 'APP_IMAGE': images['app'], 'WEB_IMAGE': images['web'], 'EKS_CLUSTER_NAME': NAME, 'HOME': str(root), 'TMPDIR': str(root)})
        invoke(['bash', str(root / 'source/infra/eks-lab/scripts/lab.sh'), 'deploy', '--allow-cloud', '--confirm-lab', NAME, '--expected-account', account], clean)


def main():
    try:
        deploy(os.environ)
    except Exception:
        print('Deploy failed; inspect sanitized runtime diagnostics and infrastructure.', file=sys.stderr)
        return 1
    print('Deploy command completed successfully.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
