"""Offline IAM regression: evaluate the REAL mock-rendered boundary + pinned policy.

Run: python -m unittest discover -s infra/eks-lab/tests -p test_controller_boundary.py -v
Terraform must be installed (Windows uses WSL manaleague-docker). Only init
(-backend=false) and mock-provider terraform test are executed, never AWS APIs.
The deliberately small evaluator supports exactly the rendered policy grammar;
unknown IAM constructs raise rather than silently weakening assertions. This is
not a replacement for AWS authorization or a live integration test.
"""
import fnmatch
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

LAB = 'manaleague-eks-lab'
PREFIX = 'arn:aws:elasticloadbalancing:us-east-1:123456789012:'
TAG = 'elbv2.k8s.aws/cluster'
REQUEST = 'aws:RequestTag/' + TAG
EXISTING = 'aws:ResourceTag/' + TAG
CREATE = 'elasticloadbalancing:CreateAction'
SUBNET = 'elasticloadbalancing:Subnet'
SECURITY_GROUP = 'elasticloadbalancing:SecurityGroup'
LAB_SUBNETS = ['subnet-0123456789abcdef0', 'subnet-0123456789abcdef1']
LAB_ALB_GROUP = 'sg-0123456789abcdef0'
LAB_NETWORK = {SUBNET: LAB_SUBNETS, SECURITY_GROUP: [LAB_ALB_GROUP]}
ROOT = Path(__file__).resolve().parents[1]
OWN = {
    'lb': PREFIX + 'loadbalancer/app/' + LAB + '/lb-id',
    'tg': PREFIX + 'targetgroup/k8s-manaleag-manaleag-0123456789/tg-id',
    'listener': PREFIX + 'listener/app/' + LAB + '/lb-id/listener-id',
    'rule': PREFIX + 'listener-rule/app/' + LAB + '/lb-id/listener-id/rule-id',
}
FOREIGN = {kind: arn.replace(LAB, 'production').replace('k8s-manaleag-manaleag', 'k8s-prod-prod')
           for kind, arn in OWN.items()}


def sequence(value):
    return value if isinstance(value, list) else [value]


def condition_matches(condition, context):
    for operator, entries in condition.items():
        if operator not in ('StringEquals', 'Null', 'ForAllValues:StringEquals', 'ForAnyValue:StringEquals'):
            raise AssertionError('Unsupported condition: ' + operator)
        for key, expected in entries.items():
            values = sequence(expected)
            if operator == 'Null':
                if values not in (['true'], ['false']):
                    raise AssertionError('Unsupported Null operand')
                if (key not in context) != (values == ['true']):
                    return False
            elif operator in ('ForAllValues:StringEquals', 'ForAnyValue:StringEquals'):
                actual = sequence(context[key]) if key in context else []
                matches = [value in values for value in actual]
                # Match IAM's vacuous-All behavior; policies also require Any/Null.
                if not (all(matches) if operator.startswith('ForAll') else any(matches)):
                    return False
            elif key not in context or context[key] not in values:
                return False
    return True


def allowed(policy, action, resource, context):
    """IAM Allow union with explicit-Deny precedence and implicit deny."""
    result = False
    for statement in policy['Statement']:
        unknown = set(statement) - {'Sid', 'Effect', 'Action', 'Resource', 'Condition'}
        if unknown:
            raise AssertionError('Unsupported IAM elements: ' + repr(unknown))
        if statement['Effect'] not in ('Allow', 'Deny'):
            raise AssertionError('Invalid effect')
        if not any(fnmatch.fnmatchcase(action.lower(), item.lower())
                   for item in sequence(statement['Action'])):
            continue
        if not any(fnmatch.fnmatchcase(resource, item)
                   for item in sequence(statement['Resource'])):
            continue
        if not condition_matches(statement.get('Condition', {}), context):
            continue
        if statement['Effect'] == 'Deny':
            return False
        result = True
    return result


def linux_path(path):
    text = str(path.resolve()).replace('\\', '/')
    return '/mnt/' + text[0].lower() + text[2:] if os.name == 'nt' else text


def render_policy():
    """Fresh Terraform JSON, never copied/reconstructed HCL or cached fixtures."""
    log_root = Path(os.environ.get('CONTROLLER_BOUNDARY_LOG_DIR', tempfile.gettempdir()))
    log_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='controller-boundary-') as data:
        env = dict(os.environ, AWS_EC2_METADATA_DISABLED='true', TF_IN_AUTOMATION='true')
        base = ['terraform']
        if os.name == 'nt':
            base = ['wsl', '-d', 'manaleague-docker', '--cd', linux_path(ROOT / 'terraform'),
                    '--', 'env', 'TF_DATA_DIR=' + linux_path(Path(data)),
                    'AWS_EC2_METADATA_DISABLED=true', 'TF_IN_AUTOMATION=true', 'terraform']
        else:
            env['TF_DATA_DIR'] = data
        for name, args in (
            ('init', ['init', '-backend=false', '-input=false', '-no-color', '-lockfile=readonly']),
            ('render', ['test', '-filter=tests/controller_boundary.tftest.hcl', '-json', '-verbose']),
        ):
            run = subprocess.run(base + args, cwd=ROOT / 'terraform', env=env,
                                 text=True, capture_output=True, timeout=180)
            (log_root / ('controller-boundary-' + name + '.log')).write_text(
                run.stdout + run.stderr, encoding='utf-8')
            if run.returncode:
                # The verbose JSON includes the entire provider schema; retain the
                # full private log but report diagnostics, not megabytes of data.
                diagnostics = []
                for line in run.stdout.splitlines():
                    try:
                        event = json.loads(line)
                    except ValueError:
                        continue
                    if event.get('type') == 'diagnostic':
                        diagnostic = event.get('diagnostic', {})
                        diagnostics.append(diagnostic.get('summary', '') + ': ' + diagnostic.get('detail', ''))
                raise AssertionError(name + ' failed: ' + '\n'.join(diagnostics) + run.stderr[-1500:])
        events = [json.loads(line) for line in run.stdout.splitlines()]
        plans = [event['test_plan'] for event in events if event.get('type') == 'test_plan']
        if len(plans) != 1:
            raise AssertionError('Expected one fresh rendered plan')
        resources = {r['address']: r['change']['after'] for r in plans[0]['resource_changes']}
        return (json.loads(resources['aws_iam_policy.controller_boundary']['policy']),
                json.loads(resources['aws_iam_policy.load_balancer_controller']['policy']))


class ControllerBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.boundary, cls.upstream = render_policy()

    def decision(self, action, arn, context):
        action = 'elasticloadbalancing:' + action
        return (allowed(self.upstream, action, arn, context)
                and allowed(self.boundary, action, arn, context))

    def assert_decision(self, expected, action, arn, context):
        self.assertEqual(self.decision(action, arn, context), expected,
                         (action, arn, context))

    def test_foreign_listener_and_rule_cannot_be_adopted_or_mutated(self):
        # Pinned policy unconditionally permits these tags. The boundary must not.
        for kind, actions in (
            ('listener', ('AddTags', 'RemoveTags', 'DeleteListener', 'ModifyListener',
                          'ModifyListenerAttributes', 'CreateRule')),
            ('rule', ('AddTags', 'RemoveTags', 'DeleteRule', 'ModifyRule', 'SetRulePriorities')),
        ):
            for action in actions:
                for context in ({}, {REQUEST: LAB}, {EXISTING: 'production', REQUEST: LAB},
                                {EXISTING: LAB}, {EXISTING: LAB, REQUEST: LAB}):
                    with self.subTest(kind=kind, action=action, context=context):
                        self.assert_decision(False, action, FOREIGN[kind], context)

    def test_foreign_lb_and_tg_cannot_be_retagged_or_mutated(self):
        for kind, mutations in (
            ('lb', ('DeleteLoadBalancer', 'ModifyLoadBalancerAttributes', 'CreateListener')),
            ('tg', ('DeleteTargetGroup', 'ModifyTargetGroup', 'RegisterTargets', 'DeregisterTargets')),
        ):
            for action in ('AddTags', 'RemoveTags') + mutations:
                for context in ({}, {REQUEST: LAB}, {EXISTING: 'production'},
                                {EXISTING: 'production', REQUEST: LAB}):
                    with self.subTest(kind=kind, action=action, context=context):
                        self.assert_decision(False, action, FOREIGN[kind], context)

    def test_foreign_parent_cannot_create_listeners_even_with_forged_tag(self):
        # CreateListener is authorized against the LB, not the new listener ARN.
        self.assert_decision(False, 'CreateListener', FOREIGN['lb'], {EXISTING: LAB})

    def test_lb_creation_requires_exact_name_and_only_lab_subnets_and_security_group(self):
        self.assert_decision(True, 'CreateLoadBalancer', OWN['lb'], {REQUEST: LAB, **LAB_NETWORK})
        self.assert_decision(False, 'CreateLoadBalancer', FOREIGN['lb'], {REQUEST: LAB, **LAB_NETWORK})
        invalid = [
            {}, {SUBNET: LAB_SUBNETS}, {SECURITY_GROUP: [LAB_ALB_GROUP]},
            {SUBNET: [], SECURITY_GROUP: [LAB_ALB_GROUP]},
            {SUBNET: LAB_SUBNETS, SECURITY_GROUP: []},
            {SUBNET: ['subnet-foreign'], SECURITY_GROUP: [LAB_ALB_GROUP]},
            {SUBNET: LAB_SUBNETS + ['subnet-foreign'], SECURITY_GROUP: [LAB_ALB_GROUP]},
            {SUBNET: LAB_SUBNETS, SECURITY_GROUP: ['sg-foreign']},
            {SUBNET: LAB_SUBNETS, SECURITY_GROUP: [LAB_ALB_GROUP, 'sg-foreign']},
        ]
        for context in invalid:
            with self.subTest(context=context):
                self.assert_decision(False, 'CreateLoadBalancer', OWN['lb'], {REQUEST: LAB, **context})
        for action in ('AddTags', 'DeleteLoadBalancer', 'SetSubnets', 'SetSecurityGroups'):
            self.assert_decision(False, action, FOREIGN['lb'], {EXISTING: LAB, REQUEST: LAB})

    def test_tagged_new_lb_and_tg_creation(self):
        for kind, create in (('lb', 'CreateLoadBalancer'), ('tg', 'CreateTargetGroup')):
            with self.subTest(kind=kind):
                self.assert_decision(True, create, OWN[kind], {REQUEST: LAB, **(LAB_NETWORK if kind == 'lb' else {})})
                self.assert_decision(True, 'AddTags', OWN[kind], {REQUEST: LAB, CREATE: create})
                for context in ({}, {REQUEST: 'production'}):
                    self.assert_decision(False, create, OWN[kind], context)
                    self.assert_decision(False, 'AddTags', OWN[kind], {**context, CREATE: create})
                self.assert_decision(False, 'AddTags', OWN[kind], {REQUEST: LAB})
                wrong_create = 'CreateTargetGroup' if kind == 'lb' else 'CreateLoadBalancer'
                self.assert_decision(False, 'AddTags', OWN[kind], {REQUEST: LAB, CREATE: wrong_create})

    def test_owned_listener_rule_lifecycle_including_tagged_creation(self):
        # CreateListener checks the parent LB; CreateRule checks the parent listener.
        self.assert_decision(True, 'CreateListener', OWN['lb'], {})
        self.assert_decision(True, 'CreateRule', OWN['listener'], {})
        for kind, actions in (
            ('listener', ('AddTags', 'RemoveTags', 'DeleteListener', 'ModifyListener')),
            ('rule', ('AddTags', 'RemoveTags', 'DeleteRule', 'ModifyRule', 'SetRulePriorities')),
        ):
            for action in actions:
                for context in ({}, {REQUEST: LAB}, {EXISTING: LAB}):
                    with self.subTest(kind=kind, action=action, context=context):
                        self.assert_decision(True, action, OWN[kind], context)
        for action in ('ModifyListenerAttributes', 'AddListenerCertificates', 'RemoveListenerCertificates'):
            self.assert_decision(True, action, OWN['listener'], {EXISTING: LAB})
        for kind, create in (('listener', 'CreateListener'), ('rule', 'CreateRule')):
            self.assert_decision(True, 'AddTags', OWN[kind], {REQUEST: LAB, CREATE: create})

    def test_network_updates_require_only_approved_ids_and_nonempty_context(self):
        # AWS documents ID-valued ArrayOfString keys, not subnet/SG ARNs.
        for action, key, approved, foreign in (
            ('SetSubnets', SUBNET, LAB_SUBNETS, 'subnet-foreign'),
            ('SetSecurityGroups', SECURITY_GROUP, [LAB_ALB_GROUP], 'sg-foreign'),
        ):
            for values in (approved, approved[:1]):
                with self.subTest(action=action, approved=values):
                    self.assert_decision(True, action, OWN['lb'], {EXISTING: LAB, key: values})
            for network in ({}, {key: []}, {key: [foreign]}, {key: approved + [foreign]}):
                with self.subTest(action=action, invalid=network):
                    self.assert_decision(False, action, OWN['lb'], {EXISTING: LAB, **network})
            for context in ({key: approved}, {EXISTING: 'production', key: approved}):
                with self.subTest(action=action, unowned=context):
                    self.assert_decision(False, action, OWN['lb'], context)
            for arn in (FOREIGN['lb'], OWN['tg'], OWN['listener'],
                        OWN['lb'].replace('123456789012', '999999999999'),
                        OWN['lb'].replace('us-east-1', 'us-west-2'),
                        OWN['lb'].replace(LAB, LAB + '-production')):
                with self.subTest(action=action, resource=arn):
                    self.assert_decision(False, action, arn, {EXISTING: LAB, key: approved})

    def test_owned_tagged_lb_tg_reconciliation(self):
        for kind, actions in (
            ('lb', ('AddTags', 'RemoveTags', 'DeleteLoadBalancer', 'ModifyLoadBalancerAttributes')),
            ('tg', ('AddTags', 'RemoveTags', 'DeleteTargetGroup', 'ModifyTargetGroup', 'ModifyTargetGroupAttributes', 'RegisterTargets', 'DeregisterTargets')),
        ):
            for action in actions:
                with self.subTest(kind=kind, action=action):
                    self.assert_decision(True, action, OWN[kind], {EXISTING: LAB})

    def test_account_region_and_exact_name_are_not_wildcards(self):
        for arn in (OWN['listener'], OWN['rule']):
            for altered in (arn.replace('123456789012', '999999999999'),
                            arn.replace('us-east-1', 'us-west-2'),
                            arn.replace(LAB, LAB + '-production'),
                            arn.replace('/app/', '/net/')):
                for action in ('AddTags', 'RemoveTags', 'ModifyListener', 'ModifyRule'):
                    with self.subTest(arn=altered, action=action):
                        self.assert_decision(False, action, altered, {EXISTING: LAB, REQUEST: LAB})


class EvaluatorTests(unittest.TestCase):
    def test_iam_allow_union_and_explicit_deny(self):
        allow = {'Effect': 'Allow', 'Action': 'elasticloadbalancing:*', 'Resource': '*'}
        policy = {'Statement': [dict(allow, Condition={'StringEquals': {EXISTING: LAB}}), allow]}
        self.assertTrue(allowed(policy, 'elasticloadbalancing:AddTags', FOREIGN['listener'], {}))
        policy['Statement'].append(dict(allow, Effect='Deny'))
        self.assertFalse(allowed(policy, 'elasticloadbalancing:AddTags', OWN['listener'], {EXISTING: LAB}))

    def test_missing_context_and_multivalue_conditions(self):
        self.assertFalse(condition_matches({'StringEquals': {CREATE: ['CreateLoadBalancer', 'CreateTargetGroup']}}, {}))
        self.assertTrue(condition_matches({'StringEquals': {CREATE: ['CreateLoadBalancer', 'CreateTargetGroup']}}, {CREATE: 'CreateTargetGroup'}))
        self.assertTrue(condition_matches({'Null': {REQUEST: 'true', EXISTING: 'false'}}, {EXISTING: LAB}))
        self.assertFalse(condition_matches({'Null': {REQUEST: 'true', EXISTING: 'false'}}, {EXISTING: LAB, REQUEST: LAB}))

    def test_unknown_grammar_fails_closed(self):
        with self.assertRaisesRegex(AssertionError, 'Unsupported condition'):
            condition_matches({'StringLike': {REQUEST: '*'}}, {})
        with self.assertRaisesRegex(AssertionError, 'Unsupported IAM'):
            allowed({'Statement': [{'Effect': 'Allow', 'NotAction': '*', 'Resource': '*'}]}, 'x', '*', {})


if __name__ == '__main__':
    unittest.main()
