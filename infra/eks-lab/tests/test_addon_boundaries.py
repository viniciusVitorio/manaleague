"""Offline regression against freshly mock-rendered Terraform, NOT AWS authorization.

Only terraform init -backend=false -lockfile=readonly and terraform test with a
mock provider run here. No AWS credentials, Docker, apply, or AWS API calls.
The evaluator is intentionally small and fails closed on unsupported grammar.
AWS action/resource context must still be verified by live disposable lab tests.
"""
import fnmatch
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
LAB = 'manaleague-eks-lab'
PREFIX = 'arn:aws:ec2:us-east-1:123456789012:'
VPC_ID = 'vpc-0123456789abcdef0'
VPC = PREFIX + 'vpc/' + VPC_ID
GUARD = 'manaleague.io/eks-lab-vpc'
OWNER = 'manaleague.io/cluster'
EBS = 'ebs.csi.aws.com/cluster'
NODE = {'ec2:ResourceTag/' + GUARD: VPC_ID,
        'ec2:ResourceTag/eks:eks-cluster-name': LAB}
ENI = {'ec2:Vpc': VPC, 'ec2:ResourceTag/' + GUARD: VPC_ID,
       'ec2:Subnet': PREFIX + 'subnet/subnet-1123456789abcdef0'}
OWNED = {'ec2:ResourceTag/' + OWNER: LAB, 'ec2:ResourceTag/' + EBS: 'true', 'ec2:Owner': '123456789012'}
REQUEST = {'aws:RequestTag/' + OWNER: LAB, 'aws:RequestTag/' + EBS: 'true'}


def arn_for(kind, suffix='lab'):
    # EC2 snapshot ARNs have an EMPTY account component (AWS SAR).
    prefix = PREFIX.replace('123456789012:', ':') if kind == 'snapshot' else PREFIX
    if suffix == 'lab' and kind in ('volume', 'snapshot'):
        suffix = ('vol-' if kind == 'volume' else 'snap-') + suffix
    return prefix + kind + '/' + suffix


def seq(value):
    return value if isinstance(value, list) else [value]


def allowed(policy, action, resource, context):
    decision = False
    for s in policy['Statement']:
        if set(s) - {'Sid', 'Effect', 'Action', 'Resource', 'Condition'}:
            raise AssertionError('Unsupported IAM statement')
        if not any(fnmatch.fnmatchcase(action.lower(), a.lower()) for a in seq(s['Action'])):
            continue
        if not any(fnmatch.fnmatchcase(resource, r) for r in seq(s['Resource'])):
            continue
        matches = True
        for operator, pairs in s.get('Condition', {}).items():
            for key, expected in pairs.items():
                actual, expected = seq(context[key]) if key in context else [], seq(expected)
                if operator == 'StringEquals':
                    match = bool(actual) and all(v in expected for v in actual)
                elif operator == 'StringLike':
                    match = bool(actual) and all(any(fnmatch.fnmatchcase(v, p) for p in expected) for v in actual)
                elif operator == 'Null':
                    match = (key not in context) == (expected == ['true'])
                elif operator == 'ForAllValues:StringNotEquals':
                    match = all(v not in expected for v in actual)
                else:
                    raise AssertionError('Unsupported IAM condition: ' + operator)
                matches = matches and match
        if matches:
            if s['Effect'] == 'Deny':
                return False
            if s['Effect'] != 'Allow':
                raise AssertionError('Unsupported effect')
            decision = True
    return decision


def render():
    # A short Linux TMPDIR avoids the provider mock's Unix socket path limit.
    linux = '/root/.cache/hermes-scratch/eks-private'
    path = str(ROOT / 'terraform').replace('\\', '/')
    if os.name == 'nt':
        path = '/mnt/' + path[0].lower() + path[2:]
        prefix = ['wsl', '-d', 'manaleague-docker', '--cd', path, '--']
        subprocess.run(prefix + ['mkdir', '-p', linux], check=True, capture_output=True)
        data = subprocess.check_output(prefix + ['mktemp', '-d', linux + '/ab.XXXXXX'], text=True).strip()
        base = prefix + ['env', 'TF_DATA_DIR=' + data, 'TMPDIR=' + linux,
                         'AWS_CONFIG_FILE=/dev/null', 'AWS_SHARED_CREDENTIALS_FILE=/dev/null',
                         'AWS_EC2_METADATA_DISABLED=true', 'TF_IN_AUTOMATION=true', 'terraform']
    else:
        linux = tempfile.gettempdir()
        data = tempfile.mkdtemp(prefix='ab-', dir=linux)
        base = ['env', 'TF_DATA_DIR=' + data, 'TMPDIR=' + linux,
                'AWS_CONFIG_FILE=/dev/null', 'AWS_SHARED_CREDENTIALS_FILE=/dev/null',
                'AWS_EC2_METADATA_DISABLED=true', 'terraform']
    logs = Path(os.environ.get('ADDON_BOUNDARY_LOG_DIR', tempfile.gettempdir()))
    logs.mkdir(parents=True, exist_ok=True)
    for name, args in [('init', ['init', '-backend=false', '-lockfile=readonly', '-input=false', '-no-color']),
                       ('render', ['test', '-filter=tests/addon_boundaries.tftest.hcl', '-json', '-verbose'])]:
        p = subprocess.run(base + args, cwd=ROOT / 'terraform', capture_output=True, text=True, timeout=240)
        (logs / ('addon-boundary-' + name + '.log')).write_text(p.stdout + p.stderr, encoding='utf-8')
        if p.returncode:
            diagnostics = []
            for line in p.stdout.splitlines():
                try:
                    e = json.loads(line)
                except ValueError:
                    continue
                if e.get('type') == 'diagnostic':
                    diagnostics.append(e['diagnostic'].get('summary', '') + ': ' + e['diagnostic'].get('detail', ''))
            raise AssertionError(name + ': ' + '\n'.join(diagnostics) + p.stderr[-1500:])
    events = [json.loads(line) for line in p.stdout.splitlines()]
    plans = [e['test_plan'] for e in events if e.get('type') == 'test_plan']
    if len(plans) != 1:
        raise AssertionError('Expected one fresh mock plan')
    resources = {r['address']: r['change']['after'] for r in plans[0]['resource_changes']}
    if os.name == 'nt':
        subprocess.run(prefix + ['rm', '-rf', '--', data], check=True, capture_output=True)
    else:
        import shutil
        shutil.rmtree(data)
    return resources


class AddonBoundaries(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.resources = render()
        cls.reference = json.loads((ROOT / 'tests/fixtures/addon-aws-authorization.json').read_text())

    def test_private_default_route_uses_single_public_nat_and_dedicated_eip(self):
        r = self.resources
        self.assertEqual([a for a in r if a.startswith('aws_nat_gateway.')], ['aws_nat_gateway.lab'])
        self.assertEqual([a for a in r if a.startswith('aws_eip.')], ['aws_eip.nat'])
        nat, eip = r['aws_nat_gateway.lab'], r['aws_eip.nat']
        self.assertEqual(nat['connectivity_type'], 'public')
        self.assertEqual(nat['subnet_id'], r['aws_subnet.public[0]']['id'])
        self.assertEqual(eip['domain'], 'vpc')
        public = r['aws_route.internet']
        private = r['aws_route.private_nat']
        self.assertEqual(public['destination_cidr_block'], '0.0.0.0/0')
        self.assertEqual(private['destination_cidr_block'], '0.0.0.0/0')
        self.assertIsNone(private.get('gateway_id'))
        self.assertEqual(len([a for a in r if a.startswith('aws_subnet.')]), 4)
        # Source checks cover dependency edges and absence of inline route
        # management, which a provider mock cannot establish from API state.
        network = (ROOT / 'terraform/network.tf').read_text()
        database = (ROOT / 'terraform/rds.tf').read_text()
        self.assertIn('allocation_id     = aws_eip.nat.id', network)
        self.assertIn('nat_gateway_id         = aws_nat_gateway.lab.id', network)
        self.assertRegex(network, r'depends_on\s*=\s*\[aws_internet_gateway.lab, aws_route.internet, aws_route_table_association.public\]')
        route_table = database.split('resource "aws_route_table" "database" {', 1)[1].split('}', 1)[0]
        self.assertNotRegex(route_table, r'\broute\s*=')

    def policy(self, name):
        resource = self.resources.get('aws_iam_policy.' + name + '_boundary')
        self.assertIsNotNone(resource, name + ' must have a permissions boundary')
        assert resource is not None
        managed = 'AmazonEKS_CNI_Policy' if name == 'vpc_cni' else 'AmazonEBSCSIDriverPolicyV2'
        self.upstream = self.reference['managed_policies'][managed]['document']
        role = self.resources['aws_iam_role.irsa["' + name + '"]']
        self.assertEqual(role.get('permissions_boundary'), resource['arn'])
        return json.loads(resource['policy'])

    def check(self, policy, expected, action, kind, context, suffix='lab'):
        arn = arn_for(kind, suffix)
        boundary = allowed(policy, 'ec2:' + action, arn, context)
        effective = boundary and allowed(self.upstream, 'ec2:' + action, arn, context)
        self.assertEqual(boundary, expected, ('boundary', action, arn, context))
        self.assertEqual(effective, expected, ('managed-policy intersection', action, arn, context))

    def test_cni_authorizes_both_private_subnets_never_public_or_production(self):
        p = self.policy('vpc_cni')
        for action in ('CreateNetworkInterface', 'ModifyNetworkInterfaceAttribute'):
            for suffix in ('subnet-1123456789abcdef0', 'subnet-1123456789abcdef1'):
                self.check(p, True, action, 'subnet', {}, suffix)
            for suffix in ('subnet-0123456789abcdef0', 'subnet-0123456789abcdef1', 'subnet-production'):
                self.check(p, False, action, 'subnet', {}, suffix)
            self.check(p, True, action, 'security-group', {}, 'sg-0123456789abcdef0')
            self.check(p, False, action, 'security-group', {}, 'sg-production')

    def test_cni_existing_enis_require_private_subnet_context(self):
        p = self.policy('vpc_cni')
        for action in ('AssignPrivateIpAddresses', 'UnassignPrivateIpAddresses', 'DeleteNetworkInterface',
                       'AttachNetworkInterface', 'DetachNetworkInterface', 'ModifyNetworkInterfaceAttribute'):
            for subnet in ('subnet-1123456789abcdef0', 'subnet-1123456789abcdef1'):
                self.check(p, True, action, 'network-interface', {**ENI, 'ec2:Subnet': arn_for('subnet', subnet)})
            for subnet in ('subnet-0123456789abcdef0', 'subnet-0123456789abcdef1', 'subnet-production'):
                self.check(p, False, action, 'network-interface', {**ENI, 'ec2:Subnet': arn_for('subnet', subnet)})
            self.check(p, False, action, 'network-interface', {k: v for k, v in ENI.items() if k != 'ec2:Subnet'})

    def test_cni_vpc_and_node_isolation_preserves_lifecycle(self):
        p = self.policy('vpc_cni')
        actions = ('AssignPrivateIpAddresses', 'UnassignPrivateIpAddresses', 'DeleteNetworkInterface',
                   'AttachNetworkInterface', 'DetachNetworkInterface', 'ModifyNetworkInterfaceAttribute')
        for action in actions:
            self.check(p, True, action, 'network-interface', ENI)
            for bad in ({}, {'ec2:Vpc': VPC}, {**ENI, 'ec2:Vpc': PREFIX + 'vpc/production'},
                        {**ENI, 'ec2:ResourceTag/' + GUARD: 'production'}):
                self.check(p, False, action, 'network-interface', bad)
        for action in ('AttachNetworkInterface', 'DetachNetworkInterface', 'ModifyNetworkInterfaceAttribute'):
            self.check(p, True, action, 'instance', NODE)
            for bad in ({}, {'ec2:ResourceTag/eks:eks-cluster-name': LAB},
                        {**NODE, 'ec2:ResourceTag/' + GUARD: 'vpc-production'},
                        {**NODE, 'ec2:ResourceTag/eks:eks-cluster-name': 'production'}):
                self.check(p, False, action, 'instance', bad)
        request = {'aws:RequestTag/' + GUARD: VPC_ID}
        self.check(p, True, 'CreateNetworkInterface', 'network-interface', request)
        self.check(p, False, 'CreateNetworkInterface', 'network-interface', {})
        for action in ('CreateNetworkInterface', 'ModifyNetworkInterfaceAttribute'):
            self.check(p, True, action, 'subnet', {}, 'subnet-1123456789abcdef0')
            self.check(p, True, action, 'security-group', {}, 'sg-0123456789abcdef0')
            self.check(p, False, action, 'subnet', request, 'subnet-production')
            self.check(p, False, action, 'security-group', request, 'sg-production')
        # Tag-on-create inherits the subnet/SG authorization of its parent API;
        # CreateTags itself has no supported ENI ec2:Vpc condition.
        self.check(p, True, 'CreateTags', 'network-interface', {**request, 'ec2:CreateAction': 'CreateNetworkInterface'})
        self.check(p, False, 'CreateTags', 'network-interface', request)
        self.check(p, True, 'CreateTags', 'network-interface',
                   {'ec2:ResourceTag/' + GUARD: VPC_ID, 'aws:TagKeys': ['node.k8s.amazonaws.com/createdAt']})
        self.check(p, False, 'CreateTags', 'network-interface',
                   {'ec2:ResourceTag/' + GUARD: VPC_ID, 'aws:TagKeys': [GUARD]})
        self.check(p, False, 'CreateTags', 'network-interface',
                   {'ec2:ResourceTag/' + GUARD: 'production', 'aws:TagKeys': [GUARD], **request})
        self.check(p, False, 'CreateTags', 'instance', {**NODE, **request})
        config = json.loads(self.resources['aws_eks_addon.cni']['configuration_values'])
        self.assertEqual(json.loads(config['env']['ADDITIONAL_ENI_TAGS'])[GUARD], VPC_ID)
        tags = self.resources['aws_launch_template.nodes']['tag_specifications']
        for kind in ('instance', 'network-interface'):
            self.assertEqual(next(t['tags'] for t in tags if t['resource_type'] == kind)[GUARD], VPC_ID)

    def test_ebs_owned_volume_and_snapshot_lifecycle_and_node_targets(self):
        p = self.policy('ebs_csi')
        for action in ('AttachVolume', 'DetachVolume', 'ModifyVolume', 'DeleteVolume', 'CreateSnapshot'):
            self.check(p, True, action, 'volume', OWNED)
            for bad in ({}, {'ec2:ResourceTag/' + EBS: 'true'},
                        {**OWNED, 'ec2:ResourceTag/' + OWNER: 'production'},
                        {'ec2:ResourceTag/kubernetes.io/created-for/pvc/name': 'production'}):
                self.check(p, False, action, 'volume', bad)
        for action in ('AttachVolume', 'DetachVolume'):
            self.check(p, True, action, 'instance', NODE)
            for bad in ({}, {'ec2:ResourceTag/eks:eks-cluster-name': LAB},
                        {**NODE, 'ec2:ResourceTag/' + GUARD: 'vpc-production'},
                        {**NODE, 'ec2:ResourceTag/eks:eks-cluster-name': 'production'}):
                self.check(p, False, action, 'instance', bad)
            # A request requires BOTH the instance and volume to pass separately.
            for volume, node, expected in ((OWNED, NODE, True), ({}, NODE, False),
                                           (OWNED, {}, False), ({}, {}, False)):
                decisions = [allowed(p, 'ec2:' + action, PREFIX + kind + '/lab', ctx)
                             for kind, ctx in [('volume', volume), ('instance', node)]]
                self.assertEqual(all(decisions), expected)
        for action, kind in (('CreateVolume', 'volume'), ('CopyVolumes', 'volume'), ('CreateSnapshot', 'snapshot')):
            self.check(p, True, action, kind, REQUEST)
            for bad in ({}, {'aws:RequestTag/' + EBS: 'true'},
                        {**REQUEST, 'aws:RequestTag/' + OWNER: 'production'}):
                self.check(p, False, action, kind, bad)
            self.check(p, True, 'CreateTags', kind, {**REQUEST, 'ec2:CreateAction': action})
            self.check(p, False, 'CreateTags', kind, REQUEST)
        for action in ('CreateVolume', 'DeleteSnapshot', 'LockSnapshot', 'EnableFastSnapshotRestores'):
            self.check(p, True, action, 'snapshot', OWNED)
            for bad in ({}, {'ec2:ResourceTag/' + EBS: 'true'},
                        {**OWNED, 'ec2:ResourceTag/' + OWNER: 'production'}):
                self.check(p, False, action, 'snapshot', bad)
        for action in ('CreateTags', 'DeleteTags'):
            self.check(p, True, action, 'volume', {**OWNED, 'aws:TagKeys': ['Name']})
            for tags in ([OWNER], [EBS], ['Name', OWNER]):
                self.check(p, False, action, 'volume', {**OWNED, 'aws:TagKeys': tags})
            for existing in ({}, {'ec2:ResourceTag/' + EBS: 'true'},
                             {**OWNED, 'ec2:ResourceTag/' + OWNER: 'production'}):
                self.check(p, False, action, 'volume', {**existing, **REQUEST, 'aws:TagKeys': [OWNER]})
            self.check(p, False, action, 'instance', {**NODE, **REQUEST, 'aws:TagKeys': [OWNER]})
        config = json.loads(self.resources['aws_eks_addon.runtime["aws-ebs-csi-driver"]']['configuration_values'])
        self.assertEqual(config['controller']['extraVolumeTags'][OWNER], LAB)
        self.assertEqual(config['controller']['replicaCount'], 1)
        # Root volumes are Terraform-owned, NOT CSI-owned or promotable by CSI.
        roots = next(t['tags'] for t in self.resources['aws_launch_template.nodes']['tag_specifications']
                     if t['resource_type'] == 'volume')
        self.assertNotIn(OWNER, roots)

    def test_statement_action_resource_keys_match_primary_reference(self):
        for role in ('vpc_cni', 'ebs_csi'):
            policy = self.policy(role)
            self.assertLess(len(json.dumps(policy, separators=(',', ':'))), 6144)
            for statement in policy['Statement']:
                for action in seq(statement['Action']):
                    if action == 'ec2:Describe*':
                        self.assertEqual(statement['Resource'], '*')
                        continue
                    reference = self.reference['ec2_actions'][action.split(':')[1]]
                    for arn in seq(statement['Resource']):
                        kind = arn.split(':', 5)[5].split('/')[0]
                        self.assertTrue(arn.startswith(PREFIX.replace('123456789012:', ':') if kind == 'snapshot' else PREFIX))
                        import re
                        formats = self.reference['ec2_resource_arns'][kind]
                        self.assertTrue(any(fnmatch.fnmatchcase(arn, re.sub(r'\$\{[^}]+\}', '*', fmt)) for fmt in formats), (kind, arn))
                        resource = next(r for r in reference['Resources'] if r['Name'] == kind)
                        keys = reference['ActionConditionKeys'] + resource['ConditionKeys']
                        for pairs in statement.get('Condition', {}).values():
                            for key in pairs:
                                supported = any(fnmatch.fnmatchcase(key, k.replace('${TagKey}', '*')) for k in keys)
                                # AWS's machine SAR omits CopyVolumes source ResourceTag
                                # keys, while AWS's live managed V2 v1 explicitly uses
                                # them (CopyManagedVolumes). Preserve that documented
                                # source/destination pattern, not ec2:Vpc/IfExists.
                                if action == 'ec2:CopyVolumes' and key.startswith('ec2:ResourceTag/'):
                                    upstream_source = next(s for s in self.upstream['Statement'] if s.get('Sid') == 'CopyManagedVolumes')
                                    self.assertIn('ec2:ResourceTag/ebs.csi.aws.com/cluster', upstream_source['Condition']['StringEquals'])
                                    supported = True
                                self.assertTrue(supported, (action, kind, key))

    def test_multi_resource_requests_require_every_authorized_resource(self):
        cases = [
            ('vpc_cni', 'AttachNetworkInterface', [('network-interface', ENI), ('instance', NODE)]),
            ('vpc_cni', 'CreateNetworkInterface', [('network-interface', {'aws:RequestTag/' + GUARD: VPC_ID}),
                                                ('subnet/subnet-1123456789abcdef0', {}),
                                                ('security-group/sg-0123456789abcdef0', {})]),
            ('ebs_csi', 'CreateSnapshot', [('snapshot', REQUEST), ('volume', OWNED)]),
            ('ebs_csi', 'CreateVolume', [('volume', REQUEST), ('snapshot', OWNED)]),
            ('ebs_csi', 'CopyVolumes', [('volume', REQUEST), ('volume', OWNED)]),
        ]
        for role, action, resources in cases:
            p = self.policy(role)
            good = [(arn_for(*kind.split('/', 1)), context) for kind, context in resources]
            def decision(parts):
                return all(allowed(p, 'ec2:' + action, arn, ctx) and
                           allowed(self.upstream, 'ec2:' + action, arn, ctx) for arn, ctx in parts)
            self.assertTrue(decision(good), (role, action))
            for i, (arn, ctx) in enumerate(good):
                bad = list(good)
                foreign_arn = arn.replace('123456789012', '999999999999')
                if ':snapshot/' in arn:
                    # Snapshot account isolation is an ec2:Owner condition, not
                    # an invented account-bearing ARN. Create uses the owned source.
                    if 'ec2:Owner' in ctx:
                        bad[i] = (arn, {**ctx, 'ec2:Owner': '999999999999'})
                        self.assertFalse(decision(bad), (role, action, 'foreign snapshot owner', i))
                else:
                    bad[i] = (foreign_arn, ctx)
                    self.assertFalse(decision(bad), (role, action, 'foreign account', i))
                bad[i] = (arn.replace('us-east-1', 'us-west-2'), ctx)
                self.assertFalse(decision(bad), (role, action, 'foreign region', i))
                if ctx:
                    bad[i] = (arn, {})
                    self.assertFalse(decision(bad), (role, action, 'missing context', i))

    def test_guard_keys_and_foreign_resource_adoption_are_denied(self):
        for role in ('vpc_cni', 'ebs_csi'):
            p = self.policy(role)
            for kind in ('volume', 'snapshot', 'instance', 'network-interface', 'security-group', 'subnet'):
                for context in ({}, REQUEST, {'aws:RequestTag/' + GUARD: VPC_ID},
                                {**REQUEST, 'aws:TagKeys': [OWNER, GUARD]},
                                {**OWNED, 'aws:TagKeys': [OWNER, GUARD]}):
                    for action in ('CreateTags', 'DeleteTags'):
                        self.check(p, False, action, kind, context)
            self.assertFalse(allowed(p, 'iam:PassRole', '*', {}))
            self.assertFalse(allowed(p, 'ec2:RunInstances', '*', {}))
            self.assertFalse(allowed(p, 'ec2:TerminateInstances', PREFIX + 'instance/lab', NODE))
        for role, kind, create, request in (
            ('vpc_cni', 'network-interface', 'CreateVolume', {'aws:RequestTag/' + GUARD: VPC_ID}),
            ('ebs_csi', 'snapshot', 'CreateVolume', REQUEST),
            ('ebs_csi', 'volume', 'CreateSnapshot', REQUEST),
        ):
            p = self.policy(role)
            self.check(p, False, 'CreateTags', kind, {**request, 'ec2:CreateAction': create})
        # These roles must not gain one another's mutation permissions.
        self.check(self.policy('vpc_cni'), False, 'AttachVolume', 'instance', NODE)
        self.check(self.policy('ebs_csi'), False, 'AttachNetworkInterface', 'instance', NODE)


class EvaluatorTests(unittest.TestCase):
    def test_union_explicit_deny_missing_context_and_unknown_grammar(self):
        s = {'Effect': 'Allow', 'Action': 'ec2:*', 'Resource': '*'}
        p = {'Statement': [s, {**s, 'Effect': 'Deny', 'Action': 'ec2:CreateTags'}]}
        self.assertFalse(allowed(p, 'ec2:CreateTags', '*', {}))
        self.assertTrue(allowed(p, 'ec2:DescribeVolumes', '*', {}))
        self.assertFalse(allowed({'Statement': [{**s, 'Condition': {'StringEquals': {'owner': LAB}}}]}, 'ec2:CreateTags', '*', {}))
        with self.assertRaisesRegex(AssertionError, 'Unsupported IAM condition'):
            allowed({'Statement': [{**s, 'Condition': {'MadeUpOperator': {'owner': LAB}}}]}, 'ec2:CreateTags', '*', {})


if __name__ == '__main__':
    unittest.main()
