"""Lint every PHP file, then run the plugin's unit checks.

Usage: python3 tests/php_checks.py [--php /path/to/php]
Needs only a PHP 8.1+ command line; no WordPress, database or network.
"""
import argparse
from pathlib import Path
import subprocess
import sys


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    failed = False
    for path in sorted(root.rglob('*.php')):
        lint = subprocess.run([args.php, '-l', str(path)], capture_output=True, text=True)
        if lint.returncode != 0:
            failed = True
            print(lint.stdout + lint.stderr, end='')
    print('Lint: ' + ('failed' if failed else 'all PHP files parse'))
    unit = subprocess.run([args.php, str(root / 'tests/plugin_unit.php')], cwd=root)
    raise SystemExit(1 if failed or unit.returncode != 0 else 0)
