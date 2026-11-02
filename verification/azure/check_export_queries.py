#!/usr/bin/env python3
"""Execute README JMESPath projections on synthetic Azure-shaped data.

Test-only dependency: jmespath. Never invokes Azure CLI or makes requests.
"""
import json
from pathlib import Path
import re

import jmespath

from test_verify import synthetic_fixture


def main():
    profile, snapshots = synthetic_fixture()
    readme = Path(__file__).with_name('README.md').read_text()
    expressions = re.findall(r"--query\s+(?:'([^']+)'|([^\s\\]+))", readme)
    assert len(expressions) == 5, 'Expected the five documented projection commands; review new commands too.'
    expressions = [quoted or bare for quoted, bare in expressions]
    synthetic_secret = 'SYNTHETIC_TEST_SECRET_NEVER_EXPORT'
    container = {
        'name': profile['redis_container'], 'image': 'synthetic-registry/synthetic-image',
        'resources': {'cpu': 0.5, 'memory': '1Gi'},
        'env': [{'name': 'REDIS_PASSWORD', 'value': synthetic_secret},
                {'name': 'OTHER_SECRET', 'secretRef': synthetic_secret},
                {'name': 'REDIS_MAXMEMORY', 'value': '768mb'}],
    }
    container_app = {
        'id': profile['redis_id'], 'properties': {
            'configuration': {'secrets': [{'name': 'synthetic-secret', 'value': synthetic_secret}],
                              'ingress': {'transport': 'tcp', 'external': True, 'targetPort': 6379, 'exposedPort': 6378}},
            'template': {'containers': [container], 'scale': {'minReplicas': 1, 'maxReplicas': 1}},
        },
    }
    sources = [
        {**snapshots['route'], 'syntheticUnrelatedSecret': synthetic_secret},
        {**snapshots['web'], 'syntheticUnrelatedSecret': synthetic_secret},
        [{**r, 'syntheticUnrelatedSecret': synthetic_secret} for r in snapshots['rules'][0]['rules']],
        container_app,
        {'properties': {'vnetConfiguration': {'internal': True}, 'syntheticUnrelatedSecret': synthetic_secret}},
    ]
    expected = [snapshots['route'], snapshots['web'], snapshots['rules'][0]['rules'],
                {k: value for k, value in snapshots['redis'].items() if k != 'internalEnvironment'}, True]
    for index, (expression, source, wanted) in enumerate(zip(expressions, sources, expected), start=1):
        result = jmespath.search(expression, source)
        assert result == wanted, f'Synthetic projection {index} differs from expected snapshot shape.'
        assert synthetic_secret not in json.dumps(result), f'Synthetic projection {index} leaked an unrelated secret.'
        print(f'PASS synthetic README projection {index}')
    print('PASS 5 projections; maxmemory filter selects 768mb and withholds synthetic password/secretRef/configuration secrets.')


if __name__ == '__main__':
    main()
