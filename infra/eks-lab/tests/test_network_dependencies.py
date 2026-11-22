"""Real Terraform plan-graph regression; never plans, refreshes, or contacts AWS.

Only copied configuration/lockfile and isolated backend/provider data are used.
DOT arcs point from a dependent to its prerequisite. The node-group expand node
covers every for_each instance (both supported one- and two-node topologies).
"""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest
from graphlib import TopologicalSorter

ROOT = Path(__file__).resolve().parents[1]


def plan_graph():
    with tempfile.TemporaryDirectory(prefix='network-graph-') as directory:
        scratch = Path(directory)
        for source in (ROOT / 'terraform').glob('*.tf'):
            shutil.copy2(source, scratch / source.name)
        shutil.copy2(ROOT / 'terraform/.terraform.lock.hcl', scratch / '.terraform.lock.hcl')
        path = scratch.as_posix()
        plugin_tmp = scratch.parent.as_posix()
        prefix = []
        if os.name == 'nt':
            path = '/mnt/' + path[0].lower() + path[2:]
            plugin_tmp = '/mnt/' + plugin_tmp[0].lower() + plugin_tmp[2:]
            prefix = ['wsl', '-d', 'manaleague-docker', '--']
        # Ignore injected CLI arguments/config, workspaces, and credentials.
        environment = os.environ.copy()
        for key in list(environment):
            if key.startswith(('TF_', 'AWS_')):
                environment.pop(key)
        search_path = '/usr/local/bin:/usr/bin:/bin' if os.name == 'nt' else os.environ['PATH']
        base = prefix + ['env', '-i', 'PATH=' + search_path, 'HOME=' + path,
                         'TMPDIR=' + plugin_tmp,
                         'TF_DATA_DIR=' + path + '/providers',
                         'TF_WORKSPACE=default', 'TF_IN_AUTOMATION=true',
                         'AWS_CONFIG_FILE=/dev/null', 'AWS_SHARED_CREDENTIALS_FILE=/dev/null',
                         'AWS_EC2_METADATA_DISABLED=true', 'terraform', '-chdir=' + path]
        for args in (['init', '-backend-config=path=' + path + '/graph.tfstate',
                      '-reconfigure', '-lockfile=readonly', '-input=false', '-no-color'],
                     ['graph', '-type=plan']):
            result = subprocess.run(base + args, env=environment, capture_output=True,
                                    text=True, timeout=240)
            if result.returncode:
                raise AssertionError(' '.join(args) + ':\n' + result.stdout + result.stderr)
        # Decode DOT quoted IDs (including escaped quotes); do not infer edges
        # from HCL text or labels. Require actual Terraform dependency arcs.
        quoted = r'"(?:[^"\\]|\\.)*"'
        graph = {}
        for dependent, dependency in re.findall('(' + quoted + r')\s*->\s*(' + quoted + ')', result.stdout):
            dependent, dependency = json.loads(dependent), json.loads(dependency)
            graph.setdefault(dependent, set()).add(dependency)
            graph.setdefault(dependency, set())
        if not graph:
            raise AssertionError('Terraform returned no plan-graph dependency arcs')
        return graph


def reaches(graph, dependent, dependency):
    pending = list(graph[dependent])
    visited = set()
    while pending:
        node = pending.pop()
        if node == dependency:
            return True
        if node not in visited:
            visited.add(node)
            pending.extend(graph[node])
    return False


class NetworkDependencies(unittest.TestCase):
    def test_every_managed_node_waits_for_complete_public_nat_egress(self):
        graph = plan_graph()
        # Raises CycleError if any real dependency cycle exists.
        self.assertEqual(len(tuple(TopologicalSorter(graph).static_order())), len(graph))

        def node(address):
            value = '[root] ' + address + ' (expand)'
            self.assertIn(value, graph, 'Missing real Terraform expand node: ' + value)
            return value

        mng = node('aws_eks_node_group.lab')
        private_route = node('aws_route.private_nat')
        nat = node('aws_nat_gateway.lab')
        association = node('aws_route_table_association.public')
        # for_each expansion is shared by all node_count=1 and node_count=2
        # instances; ensure that variable still drives this actual graph node.
        self.assertTrue(reaches(graph, mng, node('local.node_groups')))
        self.assertTrue(reaches(graph, node('local.node_groups'), '[root] var.node_count'))
        for dependent, prerequisite in ((mng, private_route), (private_route, nat),
                                        (nat, node('aws_internet_gateway.lab')),
                                        (nat, node('aws_route.internet')),
                                        (nat, association), (mng, association)):
            with self.subTest(dependent=dependent, prerequisite=prerequisite):
                self.assertTrue(reaches(graph, dependent, prerequisite),
                                dependent + ' has no dependency path to ' + prerequisite)


if __name__ == '__main__':
    unittest.main()
