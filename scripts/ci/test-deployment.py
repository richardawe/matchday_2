#!/usr/bin/env python3
"""Exercise deployment ordering, preserved state, and fail-closed SSH transport."""
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True

REPO = Path(__file__).resolve().parents[2]
APP = REPO / 'matchday-africa'
REAL_PHP = shutil.which('php')
FAKE_TOOL = r'''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
name=Path(sys.argv[0]).name
args=sys.argv[1:]
with open(os.environ['CALL_LOG'],'a') as log: log.write(json.dumps([name]+args)+'\n')
if os.environ.get('FAIL_COMMAND') == ' '.join([name]+args): sys.exit(23)
if name=='php' and args[:1]==['scripts/deployment-settings.php']:
    if args[1]=='renderer': print(os.environ.get('FAKE_RENDERER','off'))
    if args[1]=='node': print('node')
if name=='php' and args==['artisan','storage:link']:
    Path('public/storage').symlink_to(Path.cwd()/'storage/app/public')
if name=='npm': Path('node_modules').mkdir(exist_ok=True)
if name=='node' and args==['--version']: print('v22.0.0')
if name=='ssh': sys.stdin.read()
'''


class DeployTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for tool in ['php', 'composer', 'node', 'npm', 'ssh']:
            file = self.bin / tool
            file.write_text(FAKE_TOOL)
            file.chmod(0o755)
        for directory in ['public/build', 'storage/app/public', 'storage/framework', 'bootstrap/cache',
                          'reel/src', 'resources/fonts', 'resources/armies', 'scripts']:
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        for name in ['artisan', 'composer.json', 'reel/package-lock.json', 'reel/check.mjs',
                     'reel/render.mjs', 'reel/audio.mjs', 'reel/src/index.jsx', 'resources/fonts/font.ttf',
                     'resources/armies/army.webp', 'public/build/manifest.json']:
            (self.root / name).write_text('{}')
        self.env_file = self.root / '.env'
        self.env_file.write_text('APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=existing-key\n')
        (self.root / 'storage/app/retained.txt').write_text('retained user data')
        self.log = self.root / 'calls.jsonl'
        self.env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'CALL_LOG': str(self.log)}
        self.original_env = self.env_file.read_bytes()

    def run_deploy(self, **env):
        return subprocess.run(['bash', str(APP / 'deploy-production.sh')], cwd=self.root,
                              env={**self.env, **env}, capture_output=True, text=True)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def assert_success(self, result):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_deploy_preserves_state_and_orders_server_steps(self):
        self.assert_success(self.run_deploy())
        calls = self.calls()
        self.assertLess(calls.index(['php', 'artisan', 'config:clear']), calls.index(['php', 'artisan', 'migrate', '--force']))
        self.assertLess(calls.index(['php', 'artisan', 'migrate', '--force']), calls.index(['php', 'artisan', 'config:cache']))
        self.assertLess(calls.index(['php', 'artisan', 'view:cache']), calls.index(['php', 'artisan', 'queue:restart']))
        self.assertFalse(any('key:generate' in call for call in calls))
        self.assertFalse(any('optimize:clear' in call for call in calls))
        self.assertEqual(self.env_file.read_bytes(), self.original_env)
        self.assertEqual((self.root / 'storage/app/retained.txt').read_text(), 'retained user data')
        self.assertTrue((self.root / 'public/generated/reels').is_dir())
        self.assertTrue((self.root / 'storage/app/story-cards').is_dir())
        self.assert_success(self.run_deploy())
        self.assertEqual(self.calls().count(['php', 'artisan', 'storage:link']), 1)

    def test_install_failure_never_runs_migrations(self):
        command = 'composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress'
        self.assertEqual(self.run_deploy(FAIL_COMMAND=command).returncode, 23)
        self.assertNotIn(['php', 'artisan', 'migrate', '--force'], self.calls())

    def test_migration_failure_never_caches_or_restarts_workers(self):
        self.assertEqual(self.run_deploy(FAIL_COMMAND='php artisan migrate --force').returncode, 23)
        self.assertNotIn(['php', 'artisan', 'config:cache'], self.calls())
        self.assertNotIn(['php', 'artisan', 'queue:restart'], self.calls())
        self.assertNotIn(['php', 'artisan', 'up'], self.calls())

    def test_storage_link_failure_is_not_silenced(self):
        self.assertEqual(self.run_deploy(FAIL_COMMAND='php artisan storage:link').returncode, 23)
        self.assertNotIn(['php', 'artisan', 'config:cache'], self.calls())

    def test_missing_env_is_never_created_or_overwritten(self):
        self.env_file.unlink()
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse(self.env_file.exists())
        self.assertEqual(self.calls(), [])

    def test_reel_install_and_smoke_are_repeatable_and_react_to_changes(self):
        self.assert_success(self.run_deploy(FAKE_RENDERER='server'))
        self.assert_success(self.run_deploy(FAKE_RENDERER='server'))
        self.assertEqual(sum(call[0] == 'npm' for call in self.calls()), 1)
        self.assertEqual(self.calls().count(['node', 'check.mjs']), 1)
        (self.root / 'reel/src/index.jsx').write_text('new composition')
        self.assert_success(self.run_deploy(FAKE_RENDERER='server'))
        self.assertEqual(self.calls().count(['node', 'check.mjs']), 2)
        self.assertEqual(sum(call[0] == 'npm' for call in self.calls()), 1)
        (self.root / 'reel/package-lock.json').write_text('changed lock')
        self.assert_success(self.run_deploy(FAKE_RENDERER='server'))
        self.assertEqual(sum(call[0] == 'npm' for call in self.calls()), 2)

    def test_failed_reel_check_does_not_record_success(self):
        result = self.run_deploy(FAKE_RENDERER='server', FAIL_COMMAND='node check.mjs')
        self.assertEqual(result.returncode, 23)
        self.assertFalse((self.root / 'storage/app/reel-lock.sha').exists())
        self.assertFalse((self.root / 'storage/app/reel-check.sha').exists())
        self.assertNotIn(['php', 'artisan', 'migrate', '--force'], self.calls())

    def ssh_env(self):
        return {**self.env, 'CPANEL_SSH_HOST': 'host.example', 'CPANEL_SSH_USER': 'cpanel',
                'CPANEL_SSH_KEY': 'fixture only', 'CPANEL_SSH_KNOWN_HOSTS': 'host.example ssh-ed25519 fixture',
                'CPANEL_APP_ROOT': '/home/cpanel/matchday-africa', 'GITHUB_SHA': 'a' * 40}

    def run_ssh(self, mode, env):
        return subprocess.run(['bash', str(REPO / 'scripts/ci/deploy-ssh.sh'), mode],
                              cwd=REPO, env=env, capture_output=True, text=True)

    def test_missing_ssh_access_fails_before_transport(self):
        self.assertNotEqual(self.run_ssh('preflight', self.env).returncode, 0)
        self.assertEqual(self.calls(), [])

    def test_ssh_requires_pinned_host_keys_and_cleans_up_credentials(self):
        self.assert_success(self.run_ssh('preflight', self.ssh_env()))
        calls = self.calls()
        self.assertEqual(len(calls), 2)
        for call in calls:
            self.assertIn('StrictHostKeyChecking=yes', call)
            self.assertIn('BatchMode=yes', call)
            self.assertFalse(Path(call[call.index('-i') + 1]).exists())
        self.assert_success(self.run_ssh('deploy', self.ssh_env()))

    def test_ssh_rejects_shell_syntax_in_application_path(self):
        env = self.ssh_env()
        env['CPANEL_APP_ROOT'] = '/home/cpanel/app; touch /tmp/unwanted'
        self.assertNotEqual(self.run_ssh('deploy', env).returncode, 0)
        self.assertEqual(self.calls(), [])

    @unittest.skipUnless(REAL_PHP, 'PHP is required')
    def test_real_dotenv_validation_uses_parsed_flags_and_preserves_key(self):
        (self.root / 'vendor').symlink_to(APP / 'vendor')
        self.env_file.write_text('APP_ENV="production"\nAPP_DEBUG="false"\nAPP_KEY="existing-key"\nREEL_RENDERER=off\n')
        def validate():
            return subprocess.run([REAL_PHP, str(APP / 'scripts/deployment-settings.php'), 'validate', str(self.root)],
                                  capture_output=True, text=True)
        self.assert_success(validate())
        self.env_file.write_text('APP_ENV=production\nAPP_DEBUG="true"\nAPP_KEY=existing-key\n')
        self.assertNotEqual(validate().returncode, 0)
        self.env_file.write_text('APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=\n')
        result = validate()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('never rotate', result.stderr)


class SmokeTests(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('smoke_production', APP / 'scripts/smoke-production.py')
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.sha = 'a' * 40
        self.pages = {'/deploy-version.txt': self.sha, '/up': 'ok', '/': '<html>/build/assets/app.js Matchday</html>',
                      '/matches': '<html>Matches <a href="/matches/123">fixture</a></html>',
                      '/api/matchday-pulse': json.dumps({'html': '<p>pulse</p>', 'has_live': False}),
                      '/matches/123': '<html>Match room</html>',
                      '/matches/123/live': json.dumps({'score': {'home': 1, 'away': 0}, 'html': '<p>live</p>'})}

    def run_smoke(self):
        def open_response(request, timeout):
            path = self.module.urllib.parse.urlsplit(request.full_url).path
            response = io.BytesIO(self.pages[path].encode())
            response.status = 200
            response.url = request.full_url
            return response
        with patch.object(self.module.urllib.request, 'urlopen', open_response), patch.dict(os.environ, {'SMOKE_MATCH_ID': ''}):
            self.module.smoke('https://matchday.africa', self.sha)

    def test_matching_release_pages_and_live_api_pass(self):
        self.run_smoke()

    def test_old_release_fails(self):
        self.pages['/deploy-version.txt'] = 'b' * 40
        with self.assertRaisesRegex(RuntimeError, 'uploaded release'):
            self.run_smoke()

    def test_broken_pulse_payload_fails(self):
        self.pages['/api/matchday-pulse'] = '{}'
        with self.assertRaisesRegex(RuntimeError, 'unexpected payload'):
            self.run_smoke()


if __name__ == '__main__':
    unittest.main(verbosity=2)
