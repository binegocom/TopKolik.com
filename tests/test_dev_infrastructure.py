"""Static safety and bootstrapping regression tests, no Docker required."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
COMPOSE = yaml.safe_load((ROOT / 'infrastructure/compose.dev.yaml').read_text())

class InfrastructureChecks(unittest.TestCase):
    def test_expected_services(self):
        self.assertEqual(set(COMPOSE['services']), {'postgres', 'redis', 'mailpit', 'localstack'})

    def test_host_ports_bound_to_loopback(self):
        for name, config in COMPOSE['services'].items():
            for mapping in config['ports']:
                self.assertTrue(mapping.startswith('127.0.0.1:'), f'{name} publishes external port')

    def test_named_volumes_and_no_destructive_reset(self):
        self.assertEqual(set(COMPOSE['volumes']), {'pgdata', 'redisdata', 'maildata', 'localstackdata'})
        self.assertNotIn('down -v', (ROOT/'scripts/check-dev-infra.sh').read_text())

    def test_credentials_not_defaulted(self):
        text=(ROOT/'infrastructure/compose.dev.yaml').read_text()
        for key in ('POSTGRES_PASSWORD', 'REDIS_PASSWORD'):
            self.assertIn('$' + '{' + key + ':?', text)

    def test_localstack_profile_and_token(self):
        self.assertEqual(COMPOSE['services']['localstack']['profiles'], ['aws'])
        self.assertIn('LOCALSTACK_AUTH_TOKEN', (ROOT/'scripts/check-dev-tools.sh').read_text())

    def test_db_and_redis_healthchecks(self):
        self.assertIn('pg_isready', str(COMPOSE['services']['postgres']['healthcheck']))
        self.assertIn('redis-cli ping', str(COMPOSE['services']['redis']['healthcheck']))

    def test_random_secret_file_and_no_overwrite(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/'infrastructure').mkdir()
            (root/'scripts').mkdir()
            from shutil import copyfile
            copyfile(ROOT/'infrastructure/.env.example',root/'infrastructure/.env.example')
            copyfile(ROOT/'scripts/dev-env.py',root/'scripts/dev-env.py')
            command=[sys.executable,str(root/'scripts/dev-env.py'),'init']
            self.assertEqual(subprocess.run(command,capture_output=True).returncode,0)
            env=root/'infrastructure/.env'
            values=dict(line.split('=',1) for line in env.read_text().splitlines() if '=' in line and not line.startswith('#'))
            self.assertGreaterEqual(len(values['POSTGRES_PASSWORD']),32)
            self.assertGreaterEqual(len(values['REDIS_PASSWORD']),32)
            self.assertNotEqual(values['POSTGRES_PASSWORD'],values['REDIS_PASSWORD'])
            self.assertEqual(env.stat().st_mode & 0o777,0o600)
            self.assertNotEqual(subprocess.run(command,capture_output=True).returncode,0)
            self.assertEqual(values['POSTGRES_PASSWORD'],dict(line.split('=',1) for line in env.read_text().splitlines() if '=' in line and not line.startswith('#'))['POSTGRES_PASSWORD'])

if __name__ == '__main__':
    unittest.main()
