#!/usr/bin/env python3
"""Disposable real HTTP app with identity-empty restored schema and Redis. No active writes."""
import gzip
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
WORK = Path(sys.argv[1]).resolve()
WORK.mkdir(mode=0o700, exist_ok=True)
os.chmod(WORK, 0o700)
PREFIX = 'gst-r78-' + secrets.token_hex(4)
NET = PREFIX + '-internal'
NAMES = {k: PREFIX + '-' + k for k in ['mysql', 'redis', 'api', 'api2']}
IMAGE = 'gst-rc1-api:8e01d0b-r50-lab'
EXPECTED = 'sha256:11292d2398f686fa3b5ba1f61dedc2ec6923dbff8ef9fbe077dc45b6ad571300'
ARCHIVE = Path('/opt/gst-rc1-staging/qa-rollback/s98-r29-current/qa-three-schemas.sql.gz')
CREATED = []
RESULT = {'prefix': PREFIX, 'checks': []}


def cmd(*args, data=None, ok=True, timeout=180):
    r = subprocess.run(args, input=data, capture_output=True, text=True, timeout=timeout)
    if ok and r.returncode:
        raise RuntimeError('command_failed:' + args[0] + ':exit=' + str(r.returncode))
    return r


def docker(*args, **kwargs):
    return cmd('docker', *args, **kwargs)


def inspect(name):
    return json.loads(docker('inspect', name).stdout)[0]


def protected():
    result = {}
    for project in ['gstfleet', 'gst-rc1-qa']:
        for cid in docker('ps', '-q', '--filter', 'label=com.docker.compose.project=' + project).stdout.split():
            c = inspect(cid)
            result[c['Name']] = {'id': c['Id'], 'image': c['Image'], 'started': c['State']['StartedAt'], 'restarts': c['RestartCount'], 'memory_limit': c['HostConfig']['Memory'], 'ports': c['HostConfig']['PortBindings']}
    return result


def sql(query):
    return docker('exec', NAMES['mysql'], 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names -e "$1"', 'sh', query).stdout.strip()


def launch(key, image, options, tail):
    CREATED.append(NAMES[key])
    return docker('run', '-d', '--name', NAMES[key], '--label', 'gst.scope=s98-r78', '--network', NET, *options, image, *tail)


before = protected()
RESULT['protected_before'] = before
net = False
try:
    assert len(before) == 12
    assert docker('image', 'inspect', IMAGE, '--format', '{{.Id}}').stdout.strip() == EXPECTED
    assert hashlib.sha256(ARCHIVE.read_bytes()).hexdigest() == '2340e4e102ba15e782c49b6e324c961c179a80f5f6dc0dda53b23680d7159916'
    env = {
        'MYSQL_ROOT_PASSWORD': secrets.token_hex(24), 'MYSQL_ROOT_HOST': '%',
        'APP_ENV': 'lab', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
        'APP_URL': 'https://api.lab.test', 'CONSOLE_HOST': 'https://console.lab.test',
        'DB_CONNECTION': 'mysql', 'DB_HOST': NAMES['mysql'], 'DB_DATABASE': 'gst_rc1_qa', 'DB_USERNAME': 'root',
        'REDIS_HOST': NAMES['redis'], 'REDIS_PORT': '6379', 'REDIS_CLIENT': 'phpredis',
        'CACHE_STORE': 'redis', 'CACHE_DRIVER': 'redis', 'RESPONSE_CACHE_DRIVER': 'array',
        'SESSION_DRIVER': 'redis', 'SESSION_SECURE_COOKIE': 'true', 'SESSION_SAME_SITE': 'lax',
        'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array', 'TELESCOPE_ENABLED': 'false', 'LOG_CHANNEL': 'stderr',
        'VIEW_COMPILED_PATH': '/tmp/views', 'BCRYPT_ROUNDS': '4',
        'R78_SECOND_API': NAMES['api2'],
        'R78_FIRST_API': NAMES['api'],
    }
    env['DB_PASSWORD'] = env['MYSQL_ROOT_PASSWORD']
    envfile = WORK / 'lab.env'
    envfile.write_text(''.join(k + '=' + v + '\n' for k, v in env.items()))
    envfile.chmod(0o600)
    docker('network', 'create', '--internal', '--label', 'gst.scope=s98-r78', NET)
    net = True
    launch('mysql', 'mysql:8.0-oracle', ['--memory', '1200m', '--memory-swap', '1200m', '--cpus', '1', '--tmpfs', '/var/lib/mysql:rw,size=768m', '--env-file', str(envfile)], ['--skip-log-bin', '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--innodb-redo-log-capacity=64M'])
    for _ in range(90):
        r = docker('logs', NAMES['mysql'], ok=False)
        if 'MySQL init process done. Ready for start up.' in r.stdout + r.stderr:
            try:
                if sql('SELECT 1') == '1': break
            except RuntimeError: pass
        time.sleep(1)
    else: raise RuntimeError('mysql_readiness')
    with gzip.open(ARCHIVE, 'rt') as f:
        docker('exec', '-i', NAMES['mysql'], 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot', data=f.read())
    RESULT['migrations_before'] = int(sql('SELECT COUNT(*) FROM gst_rc1_qa.migrations'))
    RESULT['identity_counts'] = {t: int(sql('SELECT COUNT(*) FROM gst_rc1_qa.' + t)) for t in ['users', 'companies', 'drivers', 'api_credentials', 'personal_access_tokens']}
    assert not any(RESULT['identity_counts'].values())
    launch('redis', 'redis:7.4-alpine', ['--memory', '96m', '--memory-swap', '96m', '--cpus', '.25'], ['redis-server', '--save', '', '--appendonly', 'no'])
    common = ['--env-file', str(envfile), '--read-only', '--memory', '768m', '--memory-swap', '768m', '--cpus', '1', '--pids-limit', '128',
        '--tmpfs', '/tmp:rw,size=96m', '--tmpfs', '/fleetbase/api/storage:rw,size=64m', '--tmpfs', '/fleetbase/api/bootstrap/cache:rw,size=16m',
        '--mount', 'type=bind,src=' + str(ROOT) + ',dst=/fleetbase/api/vendor/fleetbase/fleetops-api,readonly',
        '--mount', 'type=bind,src=' + str(ROOT) + ',dst=/lab/fork,readonly',
        '--mount', 'type=bind,src=' + str(WORK.parent / 'AuthController.php') + ',dst=/fleetbase/api/vendor/fleetbase/core-api/src/Http/Controllers/Internal/v1/AuthController.php,readonly',
        '--mount', 'type=bind,src=' + str(WORK.parent / 'fleetbase.php') + ',dst=/fleetbase/api/vendor/fleetbase/core-api/config/fleetbase.php,readonly', '--entrypoint', 'sh']
    setup = 'mkdir -p /tmp/views /fleetbase/api/storage/framework/cache/data /fleetbase/api/storage/framework/sessions /fleetbase/api/storage/logs; cd /fleetbase/api; '
    migrate = PREFIX + '-migrate'; CREATED.append(migrate)
    r = docker('run', '--rm', '--name', migrate, '--label', 'gst.scope=s98-r78', '--network', NET, *common, IMAGE, '-c', setup + 'php artisan migrate --force --no-interaction', ok=False)
    (WORK / 'migration-private.log').write_text(r.stdout + r.stderr)
    assert r.returncode == 0, 'migration_failed_private_log'
    RESULT['migrations_after'] = int(sql('SELECT COUNT(*) FROM gst_rc1_qa.migrations'))
    assert RESULT['migrations_after'] == 398
    launch('api', IMAGE, common, ['-c', setup + 'exec php -S 0.0.0.0:8000 -t public /lab/fork/security-tests/r78-router.php'])
    launch('api2', IMAGE, common, ['-c', setup + 'exec php -S 0.0.0.0:8000 -t public /lab/fork/security-tests/r78-router.php'])
    for _ in range(45):
        r = docker('exec', NAMES['api'], 'curl', '-sS', '--max-time', '2', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1:8000/', ok=False)
        RESULT['readiness_last_status'] = r.stdout
        if r.stdout == '200': break
        time.sleep(1)
    else: raise RuntimeError('http_readiness')
    r = docker('exec', NAMES['api'], 'php', '/lab/fork/security-tests/r78-fixtures.php', ok=False)
    (WORK / 'fixtures-private.log').write_text(r.stdout + r.stderr)
    assert r.returncode == 0, 'fixtures_failed_private_log'
    r = docker('exec', NAMES['api'], 'php', '/lab/fork/security-tests/r78-admin-http.php', ok=False)
    (WORK / 'http-checks.log').write_text(r.stdout)
    RESULT['http_exit'] = r.returncode
    checkdata = docker('exec', NAMES['api'], 'cat', '/tmp/r78-result.json', ok=False)
    RESULT['checks'] = json.loads(checkdata.stdout) if checkdata.stdout else []
    sensitive = docker('exec', NAMES['api'], 'cat', '/tmp/r78-sensitive.json', ok=False)
    secrets_to_check = json.loads(sensitive.stdout) if sensitive.stdout else []
    captured = ''
    for key in ['api', 'api2']:
        logs = docker('logs', NAMES[key], ok=False)
        captured += logs.stdout + logs.stderr
    RESULT['sensitive_values_absent_from_runtime_logs'] = all(value not in captured for value in secrets_to_check if isinstance(value, str) and value)
    print(r.stdout, flush=True)
    RESULT['states'] = {k: {f: inspect(n)['State'][f] for f in ['Running', 'OOMKilled', 'ExitCode']} for k, n in NAMES.items()}
    RESULT['no_published_ports'] = all(not inspect(n)['HostConfig']['PortBindings'] for n in NAMES.values())
    RESULT['network_internal'] = json.loads(docker('network', 'inspect', NET).stdout)[0]['Internal']
    assert r.returncode == 0, 'http_cases_failed'
    assert RESULT['sensitive_values_absent_from_runtime_logs'], 'sensitive_runtime_log_leak'
except Exception as e:
    RESULT['error'] = str(e)
    raise
finally:
    if NAMES['api'] in CREATED:
        diagnostic = docker('logs', NAMES['api'], ok=False)
        (WORK / 'api-private.log').write_text(diagnostic.stdout + diagnostic.stderr)
    for name in reversed(CREATED): docker('rm', '-f', name, ok=False)
    if net: docker('network', 'rm', NET, ok=False)
    (WORK / 'lab.env').unlink(missing_ok=True)
    RESULT['cleanup_containers'] = not docker('ps', '-aq', '--filter', 'name=' + PREFIX).stdout.strip()
    RESULT['cleanup_network'] = docker('network', 'inspect', NET, ok=False).returncode != 0
    RESULT['protected_unchanged'] = protected() == before
    (WORK / 'result.json').write_text(json.dumps(RESULT, indent=2) + '\n')
    print(json.dumps({k: v for k, v in RESULT.items() if k not in ['checks', 'protected_before']}), flush=True)
