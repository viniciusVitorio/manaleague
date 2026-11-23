import shutil
import base64
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
spec = importlib.util.spec_from_file_location('deploy_runtime_actual', HERE / 'deploy.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)

class ActualLabTests(unittest.TestCase):
    def setUp(self):
        mock = patch.object(runtime, 'verify_provenance')
        mock.start()
        self.addCleanup(mock.stop)
    def test_real_lab_entrypoint_reaches_render_with_runtime_environment(self):
        account, region = '123456789012', 'us-east-1'
        secret = f'arn:aws:secretsmanager:{region}:{account}:secret:manaleague-eks-lab-app-Abc123'
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'source.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for folder in ('infra/eks-lab/scripts', 'infra/eks-lab/kubernetes'):
                    for path in (ROOT / folder).rglob('*'):
                        if path.is_file() and '__pycache__' not in path.parts:
                            z.write(path, path.relative_to(ROOT).as_posix())
            env = dict(os.environ, AWS_ACCOUNT_ID=account, AWS_REGION=region, RELEASE='a'*40,
                       APP_DIGEST='sha256:'+'b'*64, WEB_DIGEST='sha256:'+'c'*64,
                       LAB_ARTIFACT_PATH=str(archive), LAB_ARTIFACT_SHA256=runtime.digest(archive), SOURCE_SHA256=runtime.digest(archive),
                       LAB_APP_SECRET_ARN=secret, AVATAR_BUCKET='manaleague-eks-lab-avatars',
                       VPC_CIDR='10.0.0.0/16', APP_ROLE_ARN=f'arn:aws:iam::{account}:role/manaleague-eks-lab-app',
                       ALLOWED_CIDRS='10.0.0.0/24', SUBNET_IDS='subnet-abcd', ALB_SECURITY_GROUP_ID='sg-abcd',
                       DB_HOST=f'manaleague-eks-lab.abc.{region}.rds.amazonaws.com', VPC_ID='vpc-abcd',
                       RDS_MASTER_SECRET_ARN=f'arn:aws:secretsmanager:{region}:{account}:secret:rds!db-'+ 'a'*36 +'-Abc123')
            hash_file = Path(d) / 'hash'
            hash_file.write_text(runtime.digest(archive))
            calls = []
            def fake(args, child_env, **kwargs):
                calls.append(args)
                if args[0] == 'bash':
                    spec = importlib.util.spec_from_file_location('actual_lab', args[1][:-2] + 'py')
                    lab = importlib.util.module_from_spec(spec)
                    spec.loader.exec_module(lab)
                    def fake_child(cmd, **kwargs):
                        if cmd[:3] == ['aws', 'sts', 'get-caller-identity']:
                            return subprocess.CompletedProcess(cmd, 0, account, '')
                        if cmd[:3] == ['aws', 'rds', 'describe-db-instances']:
                            db = dict(DBInstanceIdentifier=runtime.NAME, DBInstanceArn=f'arn:aws:rds:{region}:{account}:db:{runtime.NAME}', DBName='manaleague_eks_lab', MasterUsername='manaleague_lab', Engine='postgres', PubliclyAccessible=False, StorageEncrypted=True, DBInstanceStatus='available', EngineVersion='17.2', Endpoint={'Address':env['DB_HOST'], 'Port':5432}, DBSubnetGroup={'VpcId':env['VPC_ID']}, MasterUserSecret={'SecretArn':env['RDS_MASTER_SECRET_ARN'], 'SecretStatus':'active'})
                            return subprocess.CompletedProcess(cmd, 0, json.dumps({'DBInstances':[db]}), '')
                        if cmd[:3] == ['aws', 'secretsmanager', 'get-secret-value']:
                            return subprocess.CompletedProcess(cmd, 0, json.dumps({'ARN':env['RDS_MASTER_SECRET_ARN'], 'VersionStages':['AWSCURRENT'], 'SecretString':json.dumps({'username':'manaleague_lab','password':'p'*24})}), '')
                        if cmd[:2] == ['kubectl', 'kustomize']:
                            raise RuntimeError('reached-real-render')
                        raise AssertionError(cmd)
                    with patch.dict(os.environ, child_env, clear=True), patch.object(lab.subprocess, 'run', side_effect=fake_child):
                        with self.assertRaisesRegex(RuntimeError, 'reached-real-render'):
                            lab.main(args[2:])
                    return subprocess.CompletedProcess(args, 0, '', '')
                if 'describe-repositories' in args:
                    repo = args[args.index('--repository-names')+1]
                    obj = {'repositories':[{'repositoryUri':f'{account}.dkr.ecr.{region}.amazonaws.com/{repo}','imageTagMutability':'IMMUTABLE'}]}
                elif 'describe-images' in args:
                    repo = args[args.index('--repository-name')+1]
                    obj = {'imageDetails':[{'imageDigest':env['APP_DIGEST' if repo.endswith('-app') else 'WEB_DIGEST']}]}
                else:
                    obj = {'ARN':secret, 'VersionStages':['AWSCURRENT'], 'SecretString':json.dumps({'APP_KEY':'base64:'+base64.b64encode(b'x'*32).decode(), 'DEMO_PASSWORD':'p'*24})}
                return subprocess.CompletedProcess(args, 0, json.dumps(obj), '')
            with patch.object(runtime, 'download_source', side_effect=lambda account, region, release, digest, path, env: shutil.copyfile(archive, path)), patch.object(runtime, 'invoke', side_effect=fake):
                runtime.deploy(env)
            self.assertEqual(calls[-1][0], 'bash')

    def test_archive_requires_real_runtime_dependencies(self):
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d)/'incomplete.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                z.writestr('infra/eks-lab/scripts/lab.sh', 'x')
                z.writestr('infra/eks-lab/scripts/lab.py', 'x')
            with self.assertRaisesRegex(ValueError, 'missing deploy'):
                runtime.extract_archive(archive, Path(d)/'out', runtime.digest(archive))

if __name__ == '__main__':
    unittest.main()
