"""Synthetic fixtures only: no Azure resources or real identifiers are used."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('azure_verify', Path(__file__).with_name('verify.py'))
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)


def synthetic_fixture():
    # All resource names/IDs/FDID here are synthetic, never real Azure identifiers.
    set_id = '/subscriptions/synthetic/resourceGroups/synthetic/providers/Microsoft.Cdn/profiles/synthetic/ruleSets/synthetic'
    profile = {'environment': 'qa', 'front_door_id': '11111111-1111-1111-1111-111111111111',
        'route_id': '/synthetic/route', 'web_id': '/synthetic/web', 'redis_id': '/synthetic/redis', 'rule_set_ids': [set_id], 'redis_container': 'synthetic-redis',
        'redis_cpu': 0.5, 'redis_memory': '1Gi', 'redis_exposed_port': 6378, 'redis_target_port': 6379,
        'redis_maxmemory': '768mb', 'public_video': True}
    rules = []
    for logical in v.BYPASSES + ['StaticFiles', 'PublicVideo']:
        if logical == 'SessionCookie':
            cond = [v.condition('RequestHeader', 'Contains', ['SESS'], 'Cookie')]
        elif logical == 'Authorization':
            cond = [v.condition('RequestHeader', 'Any', [], 'Authorization')]
        elif logical in v.FAMILIES:
            values = [z for p in v.FAMILIES[logical] for z in (p, p+'/*', '*/'+p, '*/'+p+'/*')]
            cond = [v.condition('UrlPath', 'Wildcard', values, lowercase=True)]
        else:
            video = logical == 'PublicVideo'
            cond = [v.condition('UrlPath', 'BeginsWith', ['sites/default/files/video/'] if video else v.STATIC_PREFIXES, lowercase=True),
                v.condition('UrlFileExtension', 'Equal', ['mp4'] if video else v.STATIC_EXTENSIONS, lowercase=True)]
        azure_conditions = []
        for c in cond:
            p = {'operator': c['operator'], 'matchValues': c['values'], 'transforms': c['transforms'], 'negateCondition': False}
            if c.get('selector'):
                p['selector'] = c['selector']
            azure_conditions.append({'name': c['name'], 'parameters': p})
        params = {}
        if logical not in v.BYPASSES:
            params['cacheConfiguration'] = {'cacheBehavior': 'OverrideAlways', 'cacheDuration': '1.00:00:00',
                'isCompressionEnabled': 'Disabled' if logical == 'PublicVideo' else 'Enabled',
                'queryStringCachingBehavior': 'UseQueryString'}
        rules.append({'name': logical, 'order': len(rules)+1, 'conditions': azure_conditions,
            'actions': [{'name': 'RouteConfigurationOverride', 'parameters': params}],
            'matchProcessingBehavior': 'Stop' if logical in v.BYPASSES else 'Continue'})
    snapshots = {'route': {'id': profile['route_id'], 'enabledState': 'Enabled', 'patternsToMatch': ['/*'],
        'httpsRedirect': 'Enabled', 'forwardingProtocol': 'HttpsOnly', 'ruleSets': [{'id': set_id}],
        'cacheConfiguration': {'queryStringCachingBehavior': 'UseQueryString', 'compressionSettings': {'isCompressionEnabled': True}}},
        'rules': [{'ruleSetId': set_id, 'rules': rules}],
        'redis': {'id': '/synthetic/redis', 'container': 'synthetic-redis', 'cpu': 0.5, 'memory': '1Gi', 'minReplicas': 1, 'maxReplicas': 1,
            'transport': 'tcp', 'external': True, 'exposedPort': 6378, 'targetPort': 6379, 'maxmemory': '768mb', 'internalEnvironment': True},
        'web': {'id': '/synthetic/web', 'ipSecurityRestrictionsDefaultAction': 'Deny', 'scmIpSecurityRestrictionsUseMain': False,
            'ipSecurityRestrictions': [{'action': 'Allow', 'ipAddress': 'AzureFrontDoor.Backend', 'tag': 'ServiceTag',
                'headers': {'x-azure-fdid': [profile['front_door_id']]}}]}}
    return profile, snapshots


class VerifyTests(unittest.TestCase):
    def setUp(self):
        self.profile, self.snapshots = synthetic_fixture()

    def test_synthetic_complete_fixture(self):
        rows = v.check(self.profile, self.snapshots)
        self.assertTrue(all(r['status'] == 'PASS' for r in rows), [r for r in rows if r['status'] != 'PASS'])
        self.assertGreater(len(rows), 60)

    def test_independent_bypass_extra_condition_fails(self):
        rule = self.snapshots['rules'][0]['rules'][0]
        rule['conditions'].append(copy.deepcopy(rule['conditions'][0]))
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.SessionCookie.conditions')['status'], 'FAIL')

    def test_header_values_never_reported(self):
        self.snapshots['rules'][0]['rules'][0]['conditions'][0]['parameters']['matchValues'] = ['synthetic-secret-cookie']
        self.assertNotIn('synthetic-secret-cookie', json.dumps(v.check(self.profile, self.snapshots)))

    def test_cache_enabled_on_auth_fails(self):
        self.snapshots['rules'][0]['rules'][1]['actions'][0]['parameters']['cacheConfiguration'] = {'cacheBehavior': 'HonorOrigin'}
        self.assertEqual(next(r for r in v.check(self.profile, self.snapshots) if r['id'] == 'rules.Authorization.cache')['status'], 'FAIL')

    def test_missing_snapshot_blocks(self):
        del self.snapshots['web']
        self.assertTrue(any(r['status'] == 'BLOCKED' for r in v.check(self.profile, self.snapshots)))

    def test_unknown_extra_rule_blocks_review(self):
        self.snapshots['rules'][0]['rules'].append({'name': 'synthetic-extra-header', 'order': 99, 'conditions': [], 'actions': []})
        self.assertEqual(next(r for r in v.check(self.profile, self.snapshots) if r['id'] == 'rules.additionalActionsReview')['status'], 'BLOCKED')

    def test_duplicate_order_fails(self):
        self.snapshots['rules'][0]['rules'][1]['order'] = 1
        self.assertEqual(next(r for r in v.check(self.profile, self.snapshots) if r['id'] == 'rules.orders.1')['status'], 'FAIL')

    def test_qa_exposed6378_is_distinct_from_target6379(self):
        self.snapshots['redis']['exposedPort'] = 6379
        self.assertEqual(next(r for r in v.check(self.profile, self.snapshots) if r['id'] == 'redis.exposedPort')['status'], 'FAIL')

    def test_extra_origin_allow_fails(self):
        self.snapshots['web']['ipSecurityRestrictions'].append({'action': 'Allow', 'ipAddress': 'Any'})
        self.assertEqual(next(r for r in v.check(self.profile, self.snapshots) if r['id'] == 'web.onlyOwnFrontDoorAllows')['status'], 'FAIL')

    def test_placeholders_cannot_pass(self):
        self.profile['front_door_id'] = 'REPLACE_WITH_QA_ID'
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(rows[0]['status'], 'BLOCKED')
        self.assertEqual(len(rows), 1)

    def test_invalid_conditions_type_fails_without_echo(self):
        self.snapshots['rules'][0]['rules'][0]['conditions'] = {'synthetic-secret': 'synthetic-secret'}
        rows = v.check(self.profile, self.snapshots)
        self.assertNotIn('synthetic-secret', json.dumps(rows))
        self.assertTrue(any(r['status'] == 'FAIL' for r in rows))

    def test_extra_action_inside_known_rule_blocks(self):
        self.snapshots['rules'][0]['rules'][0]['actions'].append({'name': 'UrlRedirect', 'parameters': {}})
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.additionalActionsReview')['status'], 'BLOCKED')

    def test_unselected_video_still_deployed_requires_review(self):
        self.profile['public_video'] = False
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.additionalActionsReview')['status'], 'BLOCKED')

    def test_optional_null_header_fields_equal_omitted(self):
        params = self.snapshots['rules'][0]['rules'][1]['conditions'][0]['parameters']
        params.update({'matchValues': None, 'transforms': None, 'negateCondition': None})
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.Authorization.conditions')['status'], 'PASS')

    def test_static_directory_and_extension_required(self):
        rule = self.snapshots['rules'][0]['rules'][-2]
        rule['conditions'] = rule['conditions'][:1]
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.StaticFiles.conditions')['status'], 'FAIL')

    def test_bypass_order_after_static_fails(self):
        rules = self.snapshots['rules'][0]['rules']
        rules[0]['order'], rules[-2]['order'] = rules[-2]['order'], rules[0]['order']
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.bypassBeforeCache')['status'], 'FAIL')

    def test_boolean_does_not_equal_integer(self):
        self.snapshots['redis']['minReplicas'] = True
        self.snapshots['route']['cacheConfiguration']['compressionSettings']['isCompressionEnabled'] = 1
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'redis.minReplicas')['status'], 'FAIL')
        self.assertEqual(next(r for r in rows if r['id'] == 'route.compression')['status'], 'FAIL')

    def test_numeric_negate_does_not_equal_boolean(self):
        self.snapshots['rules'][0]['rules'][0]['conditions'][0]['parameters']['negateCondition'] = 0
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'rules.SessionCookie.conditions')['status'], 'FAIL')

    def test_wrong_resource_identity_fails(self):
        self.snapshots['web']['id'] = '/synthetic/other-web'
        rows = v.check(self.profile, self.snapshots)
        self.assertEqual(next(r for r in rows if r['id'] == 'web.id')['status'], 'FAIL')

    def test_cli_writes_report_and_nonzero_bad_input(self):
        # Preserve owned temporary artifacts; do not delete directories.
        root = Path(tempfile.mkdtemp(prefix='synthetic-azure-verify-'))
        (root / 'profile.json').write_text(json.dumps(self.profile))
        snapshots = root / 'snapshots'
        snapshots.mkdir()
        for key, value in self.snapshots.items():
            (snapshots / (key + '.json')).write_text(json.dumps(value))
        command = [sys.executable, str(Path(__file__).with_name('verify.py')), '--profile', str(root/'profile.json'),
                   '--snapshots', str(snapshots), '--output', str(root/'reports')]
        completed = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertTrue((root/'reports'/'azure-report.md').is_file())
        self.assertEqual(json.loads((root/'reports'/'azure-report.json').read_text())['summary']['FAIL'], 0)
        (snapshots / 'route.json').write_text('{ invalid synthetic secret')
        completed = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(completed.returncode, 1)
        self.assertNotIn('invalid synthetic secret', (root/'reports'/'azure-report.md').read_text())


if __name__ == '__main__':
    unittest.main()
