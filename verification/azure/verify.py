#!/usr/bin/env python3
"""Compare sanitized Azure CLI snapshots with an explicit deployment profile.

Offline only. Does not invoke Azure CLI, read app settings, or contact any host.
"""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import sys
from uuid import UUID

GUIDE = 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/frontdoor-setup-guide.md'
FAMILIES = {
    'AccountAdminPaths': ['user', 'admin'], 'RuntimePaths': ['batch', 'cron'],
    'AjaxPrivatePaths': ['system/ajax', 'system/files'], 'OEmbedPaths': ['media/oembed'],
    'EditPaths': ['edit'], 'AddDeletePaths': ['add', 'delete'],
    'RevisionPaths': ['revision', 'revisions'], 'PreviewRevertPaths': ['preview', 'revert'],
}
BYPASSES = ['SessionCookie', 'Authorization', *FAMILIES]
STATIC_PREFIXES = ['core/', 'themes/', 'modules/', 'libraries/', 'sites/default/files/']
STATIC_EXTENSIONS = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf']


def equal(actual, expected):
    """JSON equality that never treats booleans as numbers."""
    if isinstance(actual, bool) or isinstance(expected, bool):
        return type(actual) is type(expected) and actual == expected
    if isinstance(actual, dict) and isinstance(expected, dict):
        return actual.keys() == expected.keys() and all(equal(actual[k], expected[k]) for k in actual)
    if isinstance(actual, list) and isinstance(expected, list):
        return len(actual) == len(expected) and all(equal(a, e) for a, e in zip(actual, expected))
    return type(actual) is type(expected) and actual == expected


def at(obj, *keys):
    for key in keys:
        if not isinstance(obj, dict) or key not in obj:
            return None
        obj = obj[key]
    return obj


def condition(name, operator, values, selector=None, lowercase=False):
    result = {'name': name, 'operator': operator, 'values': sorted(values),
              'negate': False, 'transforms': ['Lowercase'] if lowercase else []}
    if selector:
        result['selector'] = selector.lower()
    return result


def conditions(rule):
    raw = rule.get('conditions')
    if not isinstance(raw, list):
        return None
    result = []
    for item in raw:
        if not isinstance(item, dict) or not isinstance(item.get('parameters'), dict):
            return None
        p = item['parameters']
        values, transforms = p.get('matchValues'), p.get('transforms')
        values = [] if values is None else values
        transforms = [] if transforms is None else transforms
        if not isinstance(values, list) or not all(isinstance(v, str) for v in values):
            return None
        if not isinstance(transforms, list) or not all(isinstance(v, str) for v in transforms):
            return None
        c = {'name': item.get('name'), 'operator': p.get('operator'), 'values': sorted(values),
             'negate': False if p.get('negateCondition') is None else p['negateCondition'], 'transforms': sorted(transforms)}
        if 'selector' in p:
            if not isinstance(p['selector'], str):
                return None
            c['selector'] = p['selector'].lower()
        result.append(c)
    return sorted(result, key=lambda c: str(c['name']))


def condition_display(items):
    if not isinstance(items, list):
        return 'missing or invalid conditions'
    # Header match values can contain credentials in a misconfigured deployment.
    return [{**c, 'values': '[header values withheld]' if c.get('selector') else c['values']}
            for c in items]


def cache_actions(rule):
    actions = rule.get('actions')
    if not isinstance(actions, list):
        return []
    return [a.get('parameters') for a in actions if isinstance(a, dict)
            and a.get('name') == 'RouteConfigurationOverride']


def duration_seconds(value):
    if not isinstance(value, str):
        return None
    match = re.fullmatch(r'(?:(\d+)\.)?(\d+):(\d{2}):(\d{2})', value)
    if not match:
        return None
    days, hours, minutes, seconds = map(int, [match[1] or 0, match[2], match[3], match[4]])
    if minutes > 59 or seconds > 59:
        return None
    return days * 86400 + hours * 3600 + minutes * 60 + seconds


def check(profile, snapshots):
    rows = []

    def add(key, actual, expected, why, ok=None, anchor='1-route-caching'):
        status = 'BLOCKED' if actual is None else ('PASS' if (equal(actual, expected) if ok is None else ok) else 'FAIL')
        rows.append({'id': key, 'status': status, 'actual': actual if actual is not None else 'missing/invalid evidence',
                     'expected': expected, 'why': why, 'troubleshooting': GUIDE + '#' + anchor})

    required = ['environment', 'front_door_id', 'rule_set_ids', 'route_id', 'redis_container',
                'redis_cpu', 'redis_memory', 'redis_exposed_port', 'redis_target_port', 'redis_maxmemory', 'redis_id', 'web_id']
    missing = [k for k in required if k not in profile or profile[k] in ('', None, [])
               or 'REPLACE' in str(profile[k]) or '<' in str(profile[k])]
    valid = not missing and profile.get('environment') in ('qa', 'prod')
    try:
        UUID(str(profile.get('front_door_id', '')))
    except ValueError:
        valid = False
    valid = valid and isinstance(profile.get('rule_set_ids'), list)
    valid = valid and all(isinstance(x, str) and '/rulesets/' in x.lower() for x in profile.get('rule_set_ids', []))
    valid = valid and type(profile.get('public_video', True)) is bool
    add('profile.identity', True if valid else None, True,
        'QA and production must identify their own route, profile ID and approved capacity; placeholders cannot pass.')
    if not valid:
        return rows

    route = snapshots.get('route')
    if not isinstance(route, dict):
        route = {}
    for key, expected in [('id', profile['route_id']), ('enabledState', 'Enabled'), ('patternsToMatch', ['/*']),
                          ('httpsRedirect', 'Enabled'), ('forwardingProtocol', 'HttpsOnly')]:
        add('route.' + key, route.get(key), expected, 'The selected route must be enabled, catch public paths and use HTTPS.')
    add('route.query', at(route, 'cacheConfiguration', 'queryStringCachingBehavior'), 'UseQueryString',
        'Pagers, search and Drupal aggregates require meaningful query values in the cache key.')
    add('route.compression', at(route, 'cacheConfiguration', 'compressionSettings', 'isCompressionEnabled'), True,
        'Eligible text can be compressed; this does not guarantee every file is compressed.')
    sets = route.get('ruleSets')
    ids = [s.get('id') for s in sets if isinstance(s, dict)] if isinstance(sets, list) else None
    add('route.ruleSets', ids, profile['rule_set_ids'],
        'Associated rule sets run in order; a stopping bypass skips later sets.', anchor='3-bypass-the-cache-for-logged-in-users-and-admin-paths')

    groups = snapshots.get('rules')
    flattened, group_ids = [], []
    if isinstance(groups, list):
        for group in groups:
            if not isinstance(group, dict) or not isinstance(group.get('rules'), list):
                continue
            group_ids.append(group.get('ruleSetId'))
            rules = group['rules']
            orders = [r.get('order') for r in rules if isinstance(r, dict)]
            valid_orders = len(orders) == len(rules) and all(type(v) is int for v in orders) and len(set(orders)) == len(orders)
            add('rules.orders.' + str(len(group_ids)), valid_orders, True,
                'Each rule set needs distinct integer orders; absent order cannot prove precedence.')
            if valid_orders:
                flattened.extend(sorted(rules, key=lambda r: r['order']))
    add('rules.allAssociatedSets', group_ids if isinstance(groups, list) else None, profile['rule_set_ids'],
        'Export every associated set in route order, including sets containing headers and redirects.')
    mapping = profile.get('rule_names', {})
    positions = {}
    for logical in BYPASSES + ['StaticFiles'] + (['PublicVideo'] if profile.get('public_video', True) else []):
        name = mapping.get(logical, logical)
        matches = [(i, r) for i, r in enumerate(flattened) if r.get('name') == name]
        add('rules.' + logical + '.unique', len(matches) if groups is not None else None, 1,
            'Each logical rule must be present exactly once; map custom names explicitly.', anchor='3-bypass-the-cache-for-logged-in-users-and-admin-paths')
        if len(matches) != 1:
            continue
        index, rule = matches[0]
        positions[logical] = index
        if logical == 'SessionCookie':
            expected = [condition('RequestHeader', 'Contains', ['SESS'], 'Cookie')]
        elif logical == 'Authorization':
            expected = [condition('RequestHeader', 'Any', [], 'Authorization')]
        elif logical in FAMILIES:
            values = [v for p in FAMILIES[logical] for v in (p, p + '/*', '*/' + p, '*/' + p + '/*')]
            expected = [condition('UrlPath', 'Wildcard', values, lowercase=True)]
        else:
            video = logical == 'PublicVideo'
            expected = [condition('UrlPath', 'BeginsWith', ['sites/default/files/video/'] if video else STATIC_PREFIXES, lowercase=True),
                        condition('UrlFileExtension', 'Equal', ['mp4'] if video else STATIC_EXTENSIONS, lowercase=True)]
            expected.sort(key=lambda c: c['name'])
        actual_conditions = conditions(rule)
        add('rules.' + logical + '.conditions', condition_display(actual_conditions), condition_display(expected),
            'Values within one condition are OR; separate conditions are AND. Extra conditions can defeat an independent bypass.',
            ok=equal(actual_conditions, expected), anchor='3-bypass-the-cache-for-logged-in-users-and-admin-paths')
        actions = cache_actions(rule)
        is_bypass = logical in BYPASSES
        expected_cache = None if is_bypass else {'cacheBehavior': 'OverrideAlways', 'durationSeconds': 86400,
                'isCompressionEnabled': 'Disabled' if logical == 'PublicVideo' else 'Enabled',
                'queryStringCachingBehavior': 'UseQueryString'}
        actual_cache = None
        if len(actions) == 1 and isinstance(actions[0], dict):
            cfg = actions[0].get('cacheConfiguration')
            if isinstance(cfg, dict):
                actual_cache = {'cacheBehavior': cfg.get('cacheBehavior'), 'durationSeconds': duration_seconds(cfg.get('cacheDuration')),
                    'isCompressionEnabled': cfg.get('isCompressionEnabled'), 'queryStringCachingBehavior': cfg.get('queryStringCachingBehavior')}
            elif cfg is not None:
                actual_cache = 'invalid cacheConfiguration'
        good = len(actions) == 1 and isinstance(actions[0], dict) and equal(actual_cache, expected_cache)
        add('rules.' + logical + '.cache', {'overrides': len(actions), 'cache': actual_cache},
            {'overrides': 1, 'cache': expected_cache},
            'Omitted/null cacheConfiguration disables caching; static/video use a one-day edge override. Browser freshness is separate.',
            ok=good, anchor='4-static-files-and-keeping-pdfs-uncached')
        if is_bypass:
            add('rules.' + logical + '.stop', rule.get('matchProcessingBehavior'), 'Stop',
                'Stopping bypasses must prevent later cache-enabling rules from running.')
    order_ok = all(x in positions for x in BYPASSES + ['StaticFiles']) and all(positions[x] < positions['StaticFiles'] for x in BYPASSES)
    if profile.get('public_video', True):
        order_ok = order_ok and 'PublicVideo' in positions and all(positions[x] < positions.get('PublicVideo', -1) for x in BYPASSES)
    add('rules.bypassBeforeCache', order_ok, True, 'All independent bypasses must precede static/video cache overrides.')
    # Unknown actions cannot be certified by a narrow cache checker.
    checked_names = BYPASSES + ['StaticFiles'] + (['PublicVideo'] if profile.get('public_video', True) else [])
    known = set(mapping.get(x, x) for x in checked_names)
    extras = [r.get('name') for r in flattened if r.get('name') not in known]
    unreviewed_actions = any(not isinstance(r.get('actions'), list) or any(
        not isinstance(a, dict) or a.get('name') != 'RouteConfigurationOverride'
        for a in r.get('actions', [])) for r in flattened if r.get('name') in known)
    add('rules.additionalActionsReview', None if extras or unreviewed_actions else [], [],
        'Additional header/redirect/origin/PDF/custom rules need manual review of STOP and cache interactions; see checklist.')

    redis = snapshots.get('redis') if isinstance(snapshots.get('redis'), dict) else {}
    for key, expected in [('id', profile['redis_id']), ('container', profile['redis_container']), ('cpu', profile['redis_cpu']),
                          ('memory', profile['redis_memory']), ('minReplicas', 1), ('maxReplicas', 1),
                          ('transport', 'tcp'), ('external', True), ('targetPort', profile['redis_target_port']),
                          ('exposedPort', profile['redis_exposed_port']), ('maxmemory', profile['redis_maxmemory'])]:
        add('redis.' + key, redis.get(key), expected,
            'Match approved per-container capacity and exposed versus listener port; this single-node cache cannot scale to zero or independent replicas.',
            anchor='11-redis-cache-premium-environment')
    add('redis.internalEnvironment', redis.get('internalEnvironment'), True,
        'External TCP ingress is private to the VNet only when its Container Apps environment is internal.', anchor='11-redis-cache-premium-environment')

    web = snapshots.get('web') if isinstance(snapshots.get('web'), dict) else {}
    add('web.id', web.get('id'), profile['web_id'], 'Verify the App Service belongs to the intended environment.', anchor='5-lock-the-origin-to-this-front-door')
    add('web.defaultDeny', web.get('ipSecurityRestrictionsDefaultAction'), 'Deny',
        'Direct origin access must not bypass Front Door.', anchor='5-lock-the-origin-to-this-front-door')
    add('web.independentSCM', web.get('scmIpSecurityRestrictionsUseMain'), False,
        'SCM has a separately approved policy; copying origin-only rules blocks portal SSH and deployments.', anchor='keep-ssh-drush-and-deployments-working')
    restrictions = web.get('ipSecurityRestrictions')
    allowed = [r for r in restrictions if isinstance(r, dict) and r.get('action') == 'Allow'] if isinstance(restrictions, list) else None
    def own_fd(r):
        headers = r.get('headers', {})
        ids = headers.get('x-azure-fdid') if isinstance(headers, dict) else None
        return r.get('ipAddress') == 'AzureFrontDoor.Backend' and r.get('tag') == 'ServiceTag' and ids == [profile['front_door_id']]
    protected = all(own_fd(r) for r in allowed) and bool(allowed) if allowed is not None else None
    add('web.onlyOwnFrontDoorAllows', protected, True,
        'Every main-site Allow must combine the backend service tag and this environment FDID; other Allow rules need explicit security review.',
        anchor='5-lock-the-origin-to-this-front-door')
    return rows


def markdown(report):
    def cell(value):
        return json.dumps(value, ensure_ascii=False).replace('|', '\\|').replace('\n', ' ')
    lines = ['# Azure settings comparison', '',
             'Offline snapshot comparison only. Not proof of live propagation, headers, WAF, purge, secrets, DNS or runtime Redis use.', '',
             '| Check | Result | Actual | Expected | Why / troubleshooting |', '|---|---|---|---|---|']
    for r in report['checks']:
        lines.append(f"| {r['id']} | {r['status']} | {cell(r['actual'])} | {cell(r['expected'])} | {r['why']} [Help]({r['troubleshooting']}) |")
    return '\n'.join(lines) + '\n'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--profile', required=True, type=Path)
    parser.add_argument('--snapshots', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    try:
        profile = json.loads(args.profile.read_text())
        if not isinstance(profile, dict):
            raise ValueError('profile object required')
        data = {}
        for name in ('route', 'rules', 'redis', 'web'):
            path = args.snapshots / (name + '.json')
            data[name] = json.loads(path.read_text()) if path.is_file() else None
        rows = check(profile, data)
    except (OSError, ValueError, TypeError, AttributeError, KeyError):
        # Do not echo parse excerpts: a wrong input file can contain credentials.
        rows = [{'id': 'inputs', 'status': 'BLOCKED', 'actual': 'unreadable, malformed or unsupported input',
                 'expected': 'explicit profile and sanitized Azure JSON snapshots',
                 'why': 'See export instructions; raw input and parse excerpts are deliberately withheld.',
                 'troubleshooting': GUIDE}]
    report = {'generated_at': datetime.now(timezone.utc).isoformat(), 'scope': 'offline Azure settings snapshot comparison',
              'checks': rows, 'summary': {s: sum(r['status'] == s for r in rows) for s in ('PASS', 'FAIL', 'BLOCKED')}}
    args.output.mkdir(parents=True, exist_ok=True)
    (args.output / 'azure-report.json').write_text(json.dumps(report, indent=2) + '\n')
    (args.output / 'azure-report.md').write_text(markdown(report))
    print(json.dumps(report['summary']))
    return 0 if rows and all(r['status'] == 'PASS' for r in rows) else 1


if __name__ == '__main__':
    sys.exit(main())
