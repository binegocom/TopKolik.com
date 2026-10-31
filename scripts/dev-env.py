#!/usr/bin/env python3
"""Create a local-only Compose .env without committing or printing secrets."""
import argparse
import os
import secrets
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXAMPLE = ROOT / 'infrastructure' / '.env.example'
OUTPUT = ROOT / 'infrastructure' / '.env'

def create_env() -> None:
    if OUTPUT.exists():
        raise SystemExit('Refusing to overwrite infrastructure/.env. Existing credentials and data must be preserved.')
    lines = []
    for line in EXAMPLE.read_text(encoding='utf-8').splitlines():
        if line.startswith('POSTGRES_PASSWORD='):
            line = 'POSTGRES_PASSWORD=' + secrets.token_urlsafe(32)
        elif line.startswith('REDIS_PASSWORD='):
            line = 'REDIS_PASSWORD=' + secrets.token_urlsafe(32)
        lines.append(line)
    fd = os.open(OUTPUT, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines) + '\n')
    os.chmod(OUTPUT, 0o600)
    print('Created infrastructure/.env (mode 0600). Secrets not displayed.')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['init'])
    parser.parse_args()
    create_env()
