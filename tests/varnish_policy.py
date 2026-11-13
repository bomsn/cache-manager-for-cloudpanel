"""Exercise the policy with the actual companion controller, not an imitation.

Usage: python3 tests/varnish_policy.py /path/to/cloudpanel-companion
Requires the disposable Linux lab, varnishd and varnishadm. No VMOD is required.
"""
import argparse
from pathlib import Path
import os
import subprocess
import sys


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('companion', type=Path)
    args = parser.parse_args()
    companion = args.companion.resolve(strict=True)
    policy = Path(__file__).resolve().parents[1] / 'server/cache-policy.vcl'
    environment = dict(os.environ, PYTHONPATH=str(companion))
    raise SystemExit(subprocess.run([sys.executable, str(companion / 'tests/cache_integration.py'),
                                    str(policy)], cwd=companion, env=environment).returncode)
