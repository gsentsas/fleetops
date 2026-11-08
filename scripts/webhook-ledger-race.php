#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * GST FleetOps — multi-process race bench for WebhookReplayLedger.
 *
 * Drives the REAL ledger class and the REAL Laravel migration from eight (by
 * default) separate PHP OS processes released together by a file barrier,
 * against a database this script creates and destroys itself:
 *
 *   --driver=sqlite  a private temporary SQLite file (WAL + busy_timeout).
 *   --driver=mysql   schemas named gst_ledger_tmp_<random> on a LOCAL server
 *                    (unix socket, 127.0.0.1, ::1 or localhost only). No other
 *                    schema is read or written; both are dropped at the end.
 *
 * Usage:
 *   php scripts/webhook-ledger-race.php --driver=sqlite [--autoload=PATH] [--workers=8]
 *   php scripts/webhook-ledger-race.php --driver=mysql --mysql-socket=PATH [--mysql-user=root]
 *   php scripts/webhook-ledger-race.php --driver=mysql --mysql-host=127.0.0.1 --mysql-port=3306
 *   --only=schema,race,fencing,collision,isolation,stale,failclosed,txn_race,...
 *
 * A MySQL password, if any, is read from GST_LEDGER_MYSQL_PASSWORD and never
 * printed. Composer autoload is taken from --autoload, GST_AUTOLOAD,
 * server/vendor/autoload.php or server_vendor/autoload.php, in that order.
 *
 * Output is KEY=VALUE lines. Exit code 0 only when every invariant held.
 * No token, payload, digest or SQL error message is ever printed: database
 * errors are reported by SQLSTATE/driver code only (Laravel QueryException
 * messages embed bindings, which include claim tokens).
 *
 * This is laboratory evidence for the ledger + SQL backend. It is not an HTTP
 * test, not a test of the production database, and not a production GO.
 */

use Fleetbase\FleetOps\Support\Telematics\WebhookReplayLedger;

const GST_RECEIPTS  = 'telematic_webhook_receipts';
const GST_EFFECTS   = 'gst_bench_effects';
const GST_MIGRATION = __DIR__ . '/../server/migrations/2026_11_08_000001_create_telematic_webhook_receipts_table.php';
const GST_SCHEMA_RE = '/\Agst_ledger_tmp_[0-9a-f]{12}(_empty)?\z/';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');

$gstOpts = getopt('', ['driver:', 'autoload:', 'workers:', 'mysql-socket:', 'mysql-host:', 'mysql-port:', 'mysql-user:', 'worker:', 'spec:', 'only:']);

if (isset($gstOpts['worker'])) {
    exit(gst_worker((int) $gstOpts['worker'], (string) ($gstOpts['spec'] ?? '')));
}

exit(gst_orchestrate($gstOpts));

// ---------------------------------------------------------------------------
// Bootstrapping
// ---------------------------------------------------------------------------

function gst_autoload_path(array $opts): string
{
    $root       = dirname(__DIR__);
    $candidates = array_filter([
        $opts['autoload'] ?? null,
        getenv('GST_AUTOLOAD') ?: null,
        $root . '/server/vendor/autoload.php',
        $root . '/server_vendor/autoload.php',
    ]);
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && is_file($candidate)) {
            return (string) realpath($candidate);
        }
    }

    throw new RuntimeException('AUTOLOAD_NOT_FOUND');
}

function gst_boot(string $autoload, array $connection): Illuminate\Database\Capsule\Manager
{
    static $loaded = false;
    if (!$loaded) {
        $loader = require $autoload;
        if (!$loader instanceof Composer\Autoload\ClassLoader) {
            throw new RuntimeException('AUTOLOAD_NOT_COMPOSER');
        }
        // The product source under test always wins over any vendored copy.
        $loader->addPsr4('Fleetbase\\FleetOps\\', dirname(__DIR__) . '/server/src/', true);
        $loaded = true;
    }

    $app = new Illuminate\Container\Container();
    Illuminate\Container\Container::setInstance($app);
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    // Same wiring as Laravel's DatabaseServiceProvider, so afterCommit() works.
    $app->instance('db.transactions', new Illuminate\Database\DatabaseTransactionsManager());

    $capsule = new Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection($connection, 'default');
    $capsule->getDatabaseManager()->setDefaultConnection('default');
    $capsule->setAsGlobal();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());

    $pdo = $capsule->getConnection()->getPdo();
    if (($connection['driver'] ?? '') === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout=' . (int) ($connection['gst_busy_timeout_ms'] ?? 15000));
    }

    return $capsule;
}

function gst_sqlite_config(string $file, int $busyMs = 15000): array
{
    return ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'gst_busy_timeout_ms' => $busyMs];
}

/** Mirrors Fleetbase core-api config/database.connections.php 'mysql' (strict, utf8mb4_unicode_ci). */
function gst_mysql_config(array $server, string $database, ?string $password = null): array
{
    return [
        'driver'      => 'mysql',
        'unix_socket' => $server['socket'] ?? '',
        'host'        => $server['host'] ?? '127.0.0.1',
        'port'        => (string) ($server['port'] ?? '3306'),
        'database'    => $database,
        'username'    => $server['user'],
        'password'    => $password ?? $server['password'],
        'charset'     => 'utf8mb4',
        'collation'   => 'utf8mb4_unicode_ci',
        'prefix'      => '',
        'strict'      => true,
        'engine'      => null,
        'options'     => [PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5],
    ];
}

function gst_classify(Throwable $e): array
{
    $message = $e->getMessage();
    $class   = (new ReflectionClass($e))->getShortName();
    if (preg_match('/\A(WEBHOOK|GST_BENCH)_[A-Z_]+\z/', $message)) {
        return ['category' => $message, 'class' => $class];
    }
    $db = $e;
    while ($db !== null && !($db instanceof PDOException || $db instanceof Illuminate\Database\QueryException)) {
        $db = $db->getPrevious();
    }
    if ($db !== null || str_contains($class, 'Database')) {
        $info = $db !== null && is_array($db->errorInfo ?? null) ? $db->errorInfo : [];

        return ['category' => 'DB_ERROR', 'class' => $class, 'sqlstate' => (string) ($info[0] ?? ''), 'driver_code' => (string) ($info[1] ?? '')];
    }

    return ['category' => 'OTHER', 'class' => $class];
}

// ---------------------------------------------------------------------------
// Worker process
// ---------------------------------------------------------------------------

function gst_crash(): never
{
    // A real crash: no PHP shutdown, no destructor, no client-issued
    // COMMIT/ROLLBACK. Only the database server decides the transaction's fate.
    posix_kill(getmypid(), SIGKILL);
    sleep(30);
    exit(99);
}

function gst_worker(int $index, string $specFile): int
{
    $spec = json_decode((string) @file_get_contents($specFile), true);
    $task = is_array($spec) ? ($spec['tasks'][$index] ?? null) : null;
    if (!is_array($task)) {
        echo json_encode(['worker' => $index, 'ok' => false, 'category' => 'BENCH_SPEC']) . "\n";

        return 2;
    }

    $out = ['worker' => $index];
    try {
        $connection = $task['connection'] ?? $spec['connection'];
        $password   = getenv('GST_LEDGER_MYSQL_PASSWORD');
        if (($connection['password'] ?? null) === '@env') {
            $connection['password'] = is_string($password) ? $password : '';
        }
        $capsule = gst_boot($spec['autoload'], $connection);
        $db      = $capsule->getConnection();
        foreach ($task['pre'] ?? [] as $statement) {
            $db->statement($statement);
        }
        $ledger = new WebhookReplayLedger();
    } catch (Throwable $e) {
        // A backend unreachable at boot is itself a fail-closed outcome.
        echo json_encode($out + ['ok' => false, 'phase' => 'boot'] + gst_classify($e)) . "\n";
        gst_barrier($spec, $index, true);

        return 0;
    }

    gst_barrier($spec, $index, false);

    try {
        $action = $task['action'];
        if ($action === 'claim') {
            $r = $ledger->claim($task['scope'], $task['body'], $task['key']);
            $out += ['ok' => true, 'decision' => $r['decision'], 'scope_hash' => $r['scope_hash'], 'receipt_key' => $r['receipt_key'], 'has_token' => is_string($r['token'])];
        } elseif ($action === 'complete') {
            $out += ['ok' => true, 'bool' => $ledger->complete($task['scope_hash'], $task['receipt_key'], $task['token'])];
        } elseif ($action === 'release') {
            $out += ['ok' => true, 'bool' => $ledger->releaseWithoutEffects($task['scope_hash'], $task['receipt_key'], $task['token'])];
        } elseif ($action === 'manual_txn') {
            $crashAt = $task['crash_at'] ?? null;
            $db->beginTransaction();
            $r = $ledger->claim($task['scope'], $task['body'], $task['key']);
            if ($r['decision'] !== 'ACQUIRED') {
                $db->commit();
                $out += ['ok' => true, 'decision' => $r['decision']];
            } else {
                if ($crashAt === 'after_claim') {
                    gst_crash();
                }
                $db->table(GST_EFFECTS)->insert(['tag' => $task['tag'], 'created_at' => gmdate('Y-m-d H:i:s')]);
                if ($crashAt === 'after_effect') {
                    gst_crash();
                }
                $completed = $ledger->complete($r['scope_hash'], $r['receipt_key'], $r['token']);
                if ($crashAt === 'after_complete') {
                    gst_crash();
                }
                $db->commit();
                if ($crashAt === 'after_commit') {
                    gst_crash();
                }
                $out += ['ok' => true, 'decision' => $completed ? 'PROCESSED' : 'COMPLETE_REFUSED'];
            }
        } elseif ($action === 'atomic') {
            $r = $ledger->processAtomically($task['scope'], $task['body'], $task['key'], function () use ($db, $task) {
                $db->table(GST_EFFECTS)->insert(['tag' => $task['tag'], 'created_at' => gmdate('Y-m-d H:i:s')]);
                if (($task['crash'] ?? null) === 'first_acquirer_after_effect' && @mkdir($task['crash_flag'], 0700)) {
                    gst_crash();
                }
                if (!empty($task['fail'])) {
                    throw new RuntimeException('GST_BENCH_BUSINESS_FAILURE');
                }
                if (!empty($task['hold_ms'])) {
                    usleep((int) $task['hold_ms'] * 1000);
                }

                return 'effects-written';
            }, (int) ($task['attempts'] ?? 3));
            $out += ['ok' => true, 'decision' => $r['decision'], 'scope_hash' => $r['scope_hash'], 'receipt_key' => $r['receipt_key']];
        } else {
            $out += ['ok' => false, 'category' => 'BENCH_UNKNOWN_ACTION'];
        }
    } catch (Throwable $e) {
        $out += ['ok' => false, 'phase' => 'action'] + gst_classify($e);
    }

    echo json_encode($out) . "\n";

    return 0;
}

function gst_barrier(array $spec, int $index, bool $skipWait): void
{
    file_put_contents($spec['ready_dir'] . '/ready-' . $index, '1', LOCK_EX);
    if ($skipWait) {
        return;
    }
    $deadline = microtime(true) + 60;
    while (!is_file($spec['go_file'])) {
        if (microtime(true) > $deadline) {
            echo json_encode(['worker' => $index, 'ok' => false, 'category' => 'BENCH_BARRIER_TIMEOUT']) . "\n";
            exit(3);
        }
        usleep(500);
    }
}

// ---------------------------------------------------------------------------
// Orchestrator
// ---------------------------------------------------------------------------

final class GstBench
{
    public array $failures = [];
    public int $races      = 0;

    public function __construct(
        public string $driver,
        public string $autoload,
        public string $work,
        public array $connection,
        public Illuminate\Database\Capsule\Manager $capsule,
        public int $workers,
        public array $extra = [],
    ) {
    }

    public function line(string $key, string|int $value): void
    {
        echo $key . '=' . $value . "\n";
    }

    public function check(string $key, bool $ok, string $detail = ''): bool
    {
        $this->line($key, $ok ? 'PASS' : 'FAIL' . ($detail !== '' ? '_' . $detail : ''));
        if (!$ok) {
            $this->failures[] = $key;
        }

        return $ok;
    }

    public function db(): Illuminate\Database\Connection
    {
        return $this->capsule->getConnection();
    }

    public function ledger(): WebhookReplayLedger
    {
        return new WebhookReplayLedger();
    }

    public function reset(): void
    {
        $migration = require GST_MIGRATION;
        $migration->down();
        $migration->up();
        $schema = $this->db()->getSchemaBuilder();
        $schema->dropIfExists(GST_EFFECTS);
        $schema->create(GST_EFFECTS, function (Illuminate\Database\Schema\Blueprint $t): void {
            $t->increments('id');
            $t->string('tag', 64);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function receipts(): Illuminate\Database\Query\Builder
    {
        return $this->db()->table(GST_RECEIPTS);
    }

    public function effects(string $tag): int
    {
        return $this->db()->table(GST_EFFECTS)->where('tag', $tag)->count();
    }

    /** @return array<int,array<string,mixed>> results indexed by worker */
    public function race(string $name, array $tasks, int $timeout = 120): array
    {
        $this->races++;
        $dir = $this->work . '/race-' . $this->races;
        mkdir($dir . '/ready', 0700, true);
        $spec = [
            'autoload'   => $this->autoload,
            'connection' => $this->connection,
            'ready_dir'  => $dir . '/ready',
            'go_file'    => $dir . '/go',
            'tasks'      => array_values($tasks),
        ];
        $specFile = $dir . '/spec.json';
        file_put_contents($specFile, json_encode($spec), LOCK_EX);
        chmod($specFile, 0600);

        $env = array_filter([
            'PATH'                      => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR'                    => $dir,
            'HOME'                      => $dir,
            'GST_LEDGER_MYSQL_PASSWORD' => getenv('GST_LEDGER_MYSQL_PASSWORD') ?: null,
        ], fn ($v) => $v !== null);

        $procs = [];
        foreach (array_keys($spec['tasks']) as $i) {
            $cmd       = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'memory_limit=512M', __FILE__, '--worker=' . $i, '--spec=' . $specFile];
            $procs[$i] = [
                'proc' => proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/out-$i", 'w'], 2 => ['file', "$dir/err-$i", 'w']], $pipes, $dir, $env),
                'done' => false, 'exit' => null, 'signal' => null,
            ];
        }

        $count    = count($procs);
        $deadline = microtime(true) + 60;
        while (count(glob($dir . '/ready/ready-*') ?: []) < $count) {
            if (microtime(true) > $deadline) {
                $this->line('RACE_' . strtoupper($name) . '_READY_BARRIER', 'TIMEOUT');
                break;
            }
            usleep(2000);
        }
        file_put_contents($dir . '/go', 'go');

        $deadline = microtime(true) + $timeout;
        do {
            $running = 0;
            foreach ($procs as $i => $p) {
                if ($p['done']) {
                    continue;
                }
                $status = proc_get_status($p['proc']);
                if ($status['running']) {
                    $running++;
                    continue;
                }
                $procs[$i]['done']   = true;
                $procs[$i]['exit']   = $status['exitcode'];
                $procs[$i]['signal'] = $status['signaled'] ? $status['termsig'] : null;
            }
            if ($running > 0) {
                if (microtime(true) > $deadline) {
                    foreach ($procs as $i => $p) {
                        if (!$p['done']) {
                            proc_terminate($p['proc'], SIGKILL);
                            $procs[$i]['done']   = true;
                            $procs[$i]['signal'] = 'TIMEOUT';
                        }
                    }
                    break;
                }
                usleep(2000);
            }
        } while ($running > 0);

        $results = [];
        foreach ($procs as $i => $p) {
            proc_close($p['proc']);
            $parsed = null;
            foreach (array_reverse(file("$dir/out-$i", FILE_IGNORE_NEW_LINES) ?: []) as $lineText) {
                $decoded = json_decode($lineText, true);
                if (is_array($decoded) && ($decoded['worker'] ?? null) === $i) {
                    $parsed = $decoded;
                    break;
                }
            }
            $results[$i] = ($parsed ?? ['ok' => false, 'category' => 'NO_RESULT']) + ['exit' => $p['exit'], 'signal' => $p['signal']];
        }
        gst_rmtree($dir);

        return $results;
    }

    public static function tally(array $results, string $field = 'decision'): array
    {
        $tally = [];
        foreach ($results as $r) {
            if ($r['signal'] !== null) {
                $label = 'KILLED';
            } elseif (!empty($r['ok'])) {
                $value = $r[$field] ?? 'NONE';
                $label = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value;
            } else {
                $label = (string) ($r['category'] ?? 'ERROR');
            }
            $tally[$label] = ($tally[$label] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }

    public static function fmt(array $tally): string
    {
        $parts = [];
        foreach ($tally as $k => $v) {
            $parts[] = $k . ':' . $v;
        }

        return $parts === [] ? 'NONE' : implode(',', $parts);
    }
}

function gst_rmtree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            gst_rmtree($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
}

function gst_scope(array $overrides = []): array
{
    // Key ORDER is part of the ledger contract, and endpoint must be one of
    // the two allowed values (provider|custom). The P0-F7D1 harness sent
    // 'telemetrics:handle', which the ledger rightly rejects as
    // WEBHOOK_ENDPOINT_INVALID (reported by the harness as INVALID_SCOPE).
    return array_replace([
        'company_uuid'     => 'e4f55013-3cc6-4401-bd0b-b7a672053101',
        'provider'         => 'flespi',
        'integration_uuid' => 'e4f55013-3cc6-4401-bd0b-b7a672053102',
        'endpoint'         => 'provider',
    ], $overrides);
}

function gst_tasks(int $n, callable $make): array
{
    $tasks = [];
    for ($i = 0; $i < $n; $i++) {
        $tasks[] = $make($i);
    }

    return $tasks;
}

function gst_orchestrate(array $opts): int
{
    $driver  = $opts['driver'] ?? '';
    $workers = (int) ($opts['workers'] ?? 8);
    $only    = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;
    echo "=== GST FLEETOPS WEBHOOK LEDGER RACE BENCH ===\n";
    if (!in_array($driver, ['sqlite', 'mysql'], true) || $workers < 4 || $workers > 64 || $workers % 4 !== 0) {
        fwrite(STDERR, "usage: --driver=sqlite|mysql [--workers=MULTIPLE_OF_4 (4..64)]\n");

        return 2;
    }
    if (!function_exists('posix_kill') || !function_exists('proc_open')) {
        fwrite(STDERR, "posix and proc_open are required\n");

        return 2;
    }

    $autoload = gst_autoload_path($opts);
    $work     = sys_get_temp_dir() . '/gst-ledger-race-' . bin2hex(random_bytes(6));
    mkdir($work, 0700);
    $cleanup = [];
    $server  = null;
    $bench   = null;
    $rc      = 1;

    try {
        if ($driver === 'sqlite') {
            $file = $work . '/receipts.sqlite';
            touch($file);
            chmod($file, 0600);
            $connection = gst_sqlite_config($file);
            $capsule    = gst_boot($autoload, $connection);
            $capsule->getConnection()->getPdo()->exec('PRAGMA journal_mode=WAL');
            $empty = $work . '/empty.sqlite';
            touch($empty);
            $extra = [
                'empty_connection'       => gst_sqlite_config($empty),
                'unreachable_connection' => gst_sqlite_config($work . '/missing-dir/none.sqlite'),
                'readonly_pre'           => ['PRAGMA query_only = 1'],
                'short_lock_connection'  => gst_sqlite_config($file, 300),
                'short_lock_pre'         => [],
            ];
        } else {
            $server = [
                'socket'   => $opts['mysql-socket'] ?? '',
                'host'     => $opts['mysql-host'] ?? '127.0.0.1',
                'port'     => $opts['mysql-port'] ?? '3306',
                'user'     => $opts['mysql-user'] ?? 'root',
                'password' => getenv('GST_LEDGER_MYSQL_PASSWORD') ?: '',
            ];
            if ($server['socket'] === '' && !in_array($server['host'], ['127.0.0.1', '::1', 'localhost'], true)) {
                throw new RuntimeException('MYSQL_HOST_NOT_LOCAL_REFUSED');
            }
            $name = 'gst_ledger_tmp_' . bin2hex(random_bytes(6));
            if (!preg_match(GST_SCHEMA_RE, $name) || !preg_match(GST_SCHEMA_RE, $name . '_empty')) {
                throw new RuntimeException('MYSQL_SCHEMA_NAME_GUARD');
            }
            $adb = gst_boot($autoload, gst_mysql_config($server, ''))->getConnection();
            foreach ([$name, $name . '_empty'] as $schemaName) {
                if ((int) $adb->selectOne('SELECT COUNT(*) AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$schemaName])->c !== 0) {
                    throw new RuntimeException('MYSQL_SCHEMA_ALREADY_EXISTS_REFUSED');
                }
                $adb->statement('CREATE DATABASE `' . $schemaName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $cleanup[] = $schemaName;
            }
            $vars = $adb->selectOne('SELECT VERSION() AS v, @@GLOBAL.sql_mode AS m, @@GLOBAL.transaction_isolation AS i, @@GLOBAL.time_zone AS tz, @@GLOBAL.innodb_lock_wait_timeout AS lw');
            echo 'MYSQL_SERVER_VERSION=' . $vars->v . "\n";
            echo 'MYSQL_GLOBAL_ISOLATION=' . $vars->i . "\n";
            echo 'MYSQL_GLOBAL_TIME_ZONE=' . $vars->tz . "\n";
            echo 'MYSQL_GLOBAL_LOCK_WAIT_TIMEOUT=' . $vars->lw . "\n";
            echo 'MYSQL_GLOBAL_SQL_MODE=' . $vars->m . "\n";
            $marker     = $server['password'] !== '' ? '@env' : '';
            $connection = gst_mysql_config($server, $name, $marker);
            $capsule    = gst_boot($autoload, gst_mysql_config($server, $name));
            $extra      = [
                'empty_connection'       => gst_mysql_config($server, $name . '_empty', $marker),
                'unreachable_connection' => gst_mysql_config(['socket' => $work . '/no-such-mysql.sock', 'host' => 'localhost', 'port' => 1, 'user' => $server['user'], 'password' => ''], $name),
                'readonly_pre'           => ['SET SESSION TRANSACTION READ ONLY'],
                'short_lock_connection'  => $connection,
                'short_lock_pre'         => ['SET SESSION innodb_lock_wait_timeout = 1'],
            ];
        }

        echo 'DRIVER=' . strtoupper($driver) . "\n";
        echo 'PHP_VERSION=' . PHP_VERSION . "\n";
        echo 'LARAVEL_VERSION=' . Illuminate\Foundation\Application::VERSION . "\n";
        echo 'WORKERS=' . $workers . "\n";
        $bench = new GstBench($driver, $autoload, $work, $connection, $capsule, $workers, $extra);
        gst_run_scenarios($bench, $only);
        $rc = $bench->failures === [] ? 0 : 1;
    } catch (Throwable $e) {
        $c = gst_classify($e);
        echo 'BENCH_STOP=' . ($c['category'] === 'OTHER' ? $c['class'] . ':' . $e->getMessage() : $c['category'] . '_' . ($c['sqlstate'] ?? '') . '_' . ($c['driver_code'] ?? '')) . "\n";
        $rc = 2;
    } finally {
        if ($cleanup !== [] && $server !== null) {
            try {
                $adb = gst_boot($autoload, gst_mysql_config($server, ''))->getConnection();
                foreach ($cleanup as $schemaName) {
                    if (preg_match(GST_SCHEMA_RE, $schemaName)) {
                        $adb->statement('DROP DATABASE IF EXISTS `' . $schemaName . '`');
                    }
                }
                echo 'TEMP_MYSQL_SCHEMAS_DROPPED=' . count($cleanup) . "\n";
            } catch (Throwable) {
                echo "TEMP_MYSQL_SCHEMAS_DROPPED=FAILED_MANUAL_CLEANUP_REQUIRED\n";
                $rc = 2;
            }
        }
        gst_rmtree($work);
        echo 'TEMP_WORKDIR_REMOVED=' . (is_dir($work) ? 'NO' : 'YES') . "\n";
    }

    if ($bench !== null) {
        echo 'FAILED_CHECKS=' . ($bench->failures === [] ? 'NONE' : implode(',', $bench->failures)) . "\n";
    }
    echo 'LEDGER_RACE_BENCH_' . strtoupper($driver) . '=' . ($rc === 0 ? 'PASS' : 'RED') . "\n";

    return $rc;
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

function gst_run_scenarios(GstBench $b, ?array $only): void
{
    $want = fn (string $id) => $only === null || in_array($id, $only, true);
    $n    = $b->workers;
    $b->reset();

    if ($want('schema')) {
        [$ok, $detail] = gst_schema_check($b);
        $b->check('SQL_UNIQUE_CONSTRAINT', $ok, $detail);
    }

    // A1: same scope + key + body. A2: no idempotency header (body-bound).
    if ($want('race')) {
        $r = $b->race('same_key', gst_tasks($n, fn () => ['action' => 'claim', 'scope' => gst_scope(), 'body' => '{"event":"race","sequence":1}', 'key' => 'race-key-1']));
        $t = GstBench::tally($r);
        $b->line('PHP_CONCURRENT_PROCESS_COUNT', count($r));
        $b->line('PHP_CONCURRENT_RESULTS', GstBench::fmt($t));
        $b->line('PHP_CONCURRENT_CLAIMS_ACQUIRED', $t['ACQUIRED'] ?? 0);
        $b->line('PHP_CONCURRENT_CLAIMS_IN_PROGRESS', $t['IN_PROGRESS'] ?? 0);
        $rows = $b->receipts()->count();
        $b->line('SQL_RECEIPT_ROWS', $rows);
        $tokens = count(array_filter($r, fn ($x) => !empty($x['has_token'])));
        $b->check('PHP_SHARED_SQL_CONCURRENCY', ($t['ACQUIRED'] ?? 0) === 1 && ($t['IN_PROGRESS'] ?? 0) === $n - 1 && $rows === 1
            && $tokens === 1 && $b->receipts()->value('status') === 'processing');

        $r = $b->race('body_bound', gst_tasks($n, fn () => ['action' => 'claim', 'scope' => gst_scope(), 'body' => '{"event":"no-header"}', 'key' => null]));
        $t = GstBench::tally($r);
        $b->line('NO_HEADER_RACE_RESULTS', GstBench::fmt($t));
        $b->check('NO_HEADER_BODY_BOUND_RACE', ($t['ACQUIRED'] ?? 0) === 1 && ($t['IN_PROGRESS'] ?? 0) === $n - 1 && $b->receipts()->count() === 2);
    }

    // A3: owner token fencing (incl. concurrent completion). A4: duplicate after success.
    if ($want('fencing')) {
        $ledger = $b->ledger();
        $claim  = $ledger->claim(gst_scope(), 'fence-body', 'fence-key');
        $forged = bin2hex(random_bytes(32));
        $pre    = $claim['decision'] === 'ACQUIRED'
            && $ledger->complete($claim['scope_hash'], $claim['receipt_key'], $forged) === false
            && $ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $forged) === false;
        // The same token in another letter case must never be accepted:
        // MySQL utf8mb4_unicode_ci compares case-insensitively, SQLite does not.
        try {
            $upper = $ledger->complete($claim['scope_hash'], $claim['receipt_key'], strtoupper($claim['token']));
        } catch (InvalidArgumentException) {
            $upper = false;
        }
        $b->check('TOKEN_CASE_FENCING', $upper === false
            && $b->receipts()->where('receipt_key', $claim['receipt_key'])->value('status') === 'processing');
        $half = intdiv($n, 2);
        $r    = $b->race('complete', gst_tasks($n, fn ($i) => ['action' => 'complete', 'scope_hash' => $claim['scope_hash'], 'receipt_key' => $claim['receipt_key'], 'token' => $i < $half ? $claim['token'] : $forged]));
        $ownerTrue  = count(array_filter(array_slice($r, 0, $half), fn ($x) => ($x['ok'] ?? false) && $x['bool'] === true));
        $forgedTrue = count(array_filter(array_slice($r, $half), fn ($x) => ($x['ok'] ?? false) && $x['bool'] === true));
        $errors     = count(array_filter($r, fn ($x) => empty($x['ok'])));
        $b->line('CONCURRENT_COMPLETE_OWNER_TRUE', $ownerTrue);
        $b->line('CONCURRENT_COMPLETE_FORGED_TRUE', $forgedTrue);
        $status = $b->receipts()->where('receipt_key', $claim['receipt_key'])->value('status');
        $b->check('OWNER_TOKEN_FENCING', $pre && $ownerTrue === 1 && $forgedTrue === 0 && $errors === 0 && $status === 'processed'
            && $ledger->releaseWithoutEffects($claim['scope_hash'], $claim['receipt_key'], $claim['token']) === false);

        $before = $b->receipts()->count();
        $r      = $b->race('dup_after_success', gst_tasks($n, fn () => ['action' => 'claim', 'scope' => gst_scope(), 'body' => 'fence-body', 'key' => 'fence-key']));
        $t      = GstBench::tally($r);
        $b->line('DUPLICATE_AFTER_SUCCESS_RESULTS', GstBench::fmt($t));
        $b->check('DUPLICATE_AFTER_SUCCESS', ($t['DUPLICATE_PROCESSED'] ?? 0) === $n && $b->receipts()->count() === $before);
    }

    // A5: one key, two different bodies, raced.
    if ($want('collision')) {
        $bodies = ['{"body":"A"}', '{"body":"B"}'];
        $r      = $b->race('payload_collision', gst_tasks($n, fn ($i) => ['action' => 'claim', 'scope' => gst_scope(), 'body' => $bodies[$i % 2], 'key' => 'collide-key']));
        $t      = GstBench::tally($r);
        $b->line('PAYLOAD_COLLISION_RESULTS', GstBench::fmt($t));
        $winner = array_keys(array_filter($r, fn ($x) => ($x['decision'] ?? null) === 'ACQUIRED'));
        $ok     = count($winner) === 1;
        if ($ok) {
            $winnerBody = $bodies[$winner[0] % 2];
            foreach ($r as $i => $x) {
                if ($i !== $winner[0]) {
                    $ok = $ok && ($x['decision'] ?? null) === ($bodies[$i % 2] === $winnerBody ? 'IN_PROGRESS' : 'PAYLOAD_CONFLICT');
                }
            }
            $stored = $b->receipts()->where('receipt_key', $r[$winner[0]]['receipt_key'])->get();
            $ok     = $ok && count($stored) === 1 && hash_equals((string) $stored[0]->payload_hash, hash('sha256', $winnerBody));
        }
        $b->check('PAYLOAD_COLLISION_REJECTED', $ok && ($t['PAYLOAD_CONFLICT'] ?? 0) === intdiv($n, 2));
    }

    // A6: tenant / provider / integration isolation under concurrency.
    if ($want('isolation')) {
        $variants = [
            'base'        => [],
            'tenant'      => ['company_uuid' => 'e4f55013-3cc6-4401-bd0b-b7a672053201'],
            'provider'    => ['provider' => 'samsara'],
            'integration' => ['integration_uuid' => 'e4f55013-3cc6-4401-bd0b-b7a672053202'],
        ];
        $names  = array_keys($variants);
        $r      = $b->race('isolation', gst_tasks($n, fn ($i) => ['action' => 'claim', 'scope' => gst_scope($variants[$names[$i % 4]]), 'body' => 'iso-body', 'key' => 'iso-key']));
        $groups = [];
        foreach ($r as $i => $x) {
            $groups[$names[$i % 4]][] = $x;
        }
        $hashes = array_unique(array_map(fn ($x) => (string) ($x['scope_hash'] ?? ''), $r));
        $b->line('ISOLATION_RESULTS', GstBench::fmt(GstBench::tally($r)));
        foreach (['tenant' => 'TENANT_ISOLATION', 'provider' => 'PROVIDER_ISOLATION', 'integration' => 'INTEGRATION_ISOLATION'] as $g => $key) {
            $t = GstBench::tally($groups[$g]);
            $b->check($key, ($t['ACQUIRED'] ?? 0) === 1 && ($t['IN_PROGRESS'] ?? 0) === count($groups[$g]) - 1 && count($hashes) === 4);
        }
        $b->check('ENDPOINT_ISOLATION', $b->ledger()->claim(gst_scope(['endpoint' => 'custom']), 'iso-body', 'iso-key')['decision'] === 'ACQUIRED');
    }

    // A7: an expired lease is reported, never stolen.
    if ($want('stale')) {
        $claim = $b->ledger()->claim(gst_scope(), 'stale-body', 'stale-key');
        $b->receipts()->where('receipt_key', $claim['receipt_key'])->update(['lease_expires_at' => '2000-01-01 00:00:00']);
        $tokenBefore = (string) $b->receipts()->where('receipt_key', $claim['receipt_key'])->value('claim_token');
        $r           = $b->race('stale', gst_tasks($n, fn () => ['action' => 'claim', 'scope' => gst_scope(), 'body' => 'stale-body', 'key' => 'stale-key']));
        $t           = GstBench::tally($r);
        $b->line('STALE_RACE_RESULTS', GstBench::fmt($t));
        $row = $b->receipts()->where('receipt_key', $claim['receipt_key'])->first();
        $b->check('STALE_LEASE_NOT_STOLEN', ($t['STALE_INDETERMINATE'] ?? 0) === $n && $row->status === 'processing'
            && hash_equals($tokenBefore, (string) $row->claim_token));
    }

    // A8: backend failures fail closed (exception, never a decision).
    if ($want('failclosed')) {
        $cases = [
            'missing_table' => ['connection' => $b->extra['empty_connection']],
            'unreachable'   => ['connection' => $b->extra['unreachable_connection']],
            'read_only'     => ['pre' => $b->extra['readonly_pre']],
        ];
        $allClosed = true;
        foreach ($cases as $label => $override) {
            $r      = $b->race('fail_' . $label, [array_merge(['action' => 'claim', 'scope' => gst_scope(), 'body' => 'fail-' . $label, 'key' => 'fail-' . $label], $override)]);
            $x      = $r[0];
            $closed = empty($x['ok']) && ($x['category'] ?? '') === 'DB_ERROR';
            $b->line('FAILCLOSED_' . strtoupper($label), $closed ? 'EXCEPTION_' . ($x['sqlstate'] ?? '') . '_' . ($x['driver_code'] ?? '') : 'UNEXPECTED_' . ($x['decision'] ?? $x['category'] ?? 'NONE'));
            $allClosed = $allClosed && $closed;
        }
        // An INSERT the backend silently drops for a reason OTHER than the
        // unique key must never be read as a duplicate or as a success.
        // (Empty table first: MySQL validates a new CHECK against existing rows.)
        $b->reset();
        if ($b->driver === 'sqlite') {
            $b->db()->statement('CREATE TRIGGER gst_poison BEFORE INSERT ON ' . GST_RECEIPTS . ' BEGIN SELECT RAISE(IGNORE); END');
        } else {
            $b->db()->statement('ALTER TABLE ' . GST_RECEIPTS . " ADD CONSTRAINT gst_poison CHECK (status <> 'processing')");
        }
        $r      = $b->race('fail_ignored_insert', [['action' => 'claim', 'scope' => gst_scope(), 'body' => 'poison', 'key' => 'poison-key']]);
        $closed = ($r[0]['category'] ?? '') === 'WEBHOOK_CLAIM_INDETERMINATE_DB_WRITE' && $b->receipts()->where('payload_hash', hash('sha256', 'poison'))->count() === 0;
        $b->line('FAILCLOSED_SILENTLY_IGNORED_INSERT', $closed ? 'INDETERMINATE_EXCEPTION' : 'UNEXPECTED_' . ($r[0]['decision'] ?? $r[0]['category'] ?? 'NONE'));
        $b->check('BACKEND_FAILURE_FAIL_CLOSED', $allClosed && $closed);
        $b->reset();
    }

    // A9: a caller whose transaction already holds a consistent-read snapshot
    // (MySQL REPEATABLE READ) must still see a claim committed by a peer after
    // that snapshot, instead of misreading it as an indeterminate write.
    if ($want('snapshot')) {
        $b->reset();
        $db = $b->db();
        $db->beginTransaction();
        $db->table(GST_RECEIPTS)->count();
        $peer = $b->race('snapshot_peer', [['action' => 'claim', 'scope' => gst_scope(), 'body' => 'snap', 'key' => 'snap']]);
        try {
            $label = $b->ledger()->claim(gst_scope(), 'snap', 'snap')['decision'];
        } catch (Throwable $e) {
            $label = gst_classify($e)['category'];
        }
        $db->rollBack();
        $b->line('SNAPSHOT_CLAIM_RESULT', $label);
        // SQLite cannot upgrade a stale WAL read snapshot to a writer: it fails
        // closed (SQLITE_BUSY_SNAPSHOT), which is acceptable there.
        $b->check('SNAPSHOT_SEES_COMMITTED_CLAIM', ($peer[0]['decision'] ?? null) === 'ACQUIRED'
            && ($label === 'IN_PROGRESS' || ($b->driver === 'sqlite' && $label === 'DB_ERROR')));
    }

    if (!method_exists(WebhookReplayLedger::class, 'processAtomically')) {
        $b->line('TRANSACTIONAL_SCENARIOS', 'SKIPPED_PROCESS_ATOMICALLY_NOT_IMPLEMENTED');

        return;
    }
    gst_run_transactional($b, $want);
}

function gst_run_transactional(GstBench $b, callable $want): void
{
    $n = $b->workers;

    // B1: claim + effects + completion in ONE transaction, raced.
    if ($want('txn_race')) {
        $b->reset();
        $r = $b->race('atomic_race', gst_tasks($n, fn () => ['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'atomic-body', 'key' => 'atomic-key', 'tag' => 'atomic', 'hold_ms' => 150]));
        $t = GstBench::tally($r);
        $b->line('TXN_RACE_RESULTS', GstBench::fmt($t));
        $b->line('TXN_RACE_EFFECT_ROWS', $b->effects('atomic'));
        $b->check('TXN_CONCURRENT_EXACTLY_ONE_EFFECT', ($t['PROCESSED'] ?? 0) === 1 && ($t['DUPLICATE_PROCESSED'] ?? 0) === $n - 1
            && $b->effects('atomic') === 1 && $b->receipts()->count() === 1 && $b->receipts()->value('status') === 'processed');
    }

    // B2: SIGKILL at each point before COMMIT -> nothing persists; retry recovers once.
    if ($want('txn_crash')) {
        foreach (['after_claim', 'after_effect', 'after_complete'] as $point) {
            $b->reset();
            $tag       = 'crash-' . $point;
            $r         = $b->race('crash_' . $point, [['action' => 'manual_txn', 'scope' => gst_scope(), 'body' => $tag, 'key' => $tag, 'tag' => $tag, 'crash_at' => $point]]);
            $killed    = ($r[0]['signal'] ?? null) === SIGKILL;
            $clean     = $b->receipts()->count() === 0 && $b->effects($tag) === 0;
            $retry     = $b->race('retry_' . $point, [['action' => 'atomic', 'scope' => gst_scope(), 'body' => $tag, 'key' => $tag, 'tag' => $tag]]);
            $recovered = ($retry[0]['decision'] ?? null) === 'PROCESSED' && $b->effects($tag) === 1;
            $b->check('CRASH_' . strtoupper($point) . '_ROLLED_BACK_AND_RECOVERED', $killed && $clean && $recovered,
                ($killed ? '' : 'NOT_KILLED') . ($clean ? '' : 'RESIDUE') . ($recovered ? '' : 'NO_RECOVERY'));
        }

        // B3: crash after COMMIT, before the HTTP response -> retries are duplicates.
        $b->reset();
        $tag       = 'crash-after-commit';
        $r         = $b->race('crash_after_commit', [['action' => 'manual_txn', 'scope' => gst_scope(), 'body' => $tag, 'key' => $tag, 'tag' => $tag, 'crash_at' => 'after_commit']]);
        $committed = ($r[0]['signal'] ?? null) === SIGKILL && $b->effects($tag) === 1 && $b->receipts()->value('status') === 'processed';
        $retry     = $b->race('retry_after_commit', gst_tasks($n, fn () => ['action' => 'atomic', 'scope' => gst_scope(), 'body' => $tag, 'key' => $tag, 'tag' => $tag]));
        $t         = GstBench::tally($retry);
        $b->line('CRASH_AFTER_COMMIT_RETRY_RESULTS', GstBench::fmt($t));
        $b->check('CRASH_AFTER_COMMIT_NO_REPROCESSING', $committed && ($t['DUPLICATE_PROCESSED'] ?? 0) === $n && $b->effects($tag) === 1);
    }

    // B4: the winner crashes while the others wait on its lock.
    if ($want('txn_crash_contention')) {
        $b->reset();
        $flag   = $b->work . '/crash-flag';
        $r      = $b->race('crash_contention', gst_tasks($n, fn () => ['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'contended', 'key' => 'contended', 'tag' => 'contended', 'crash' => 'first_acquirer_after_effect', 'crash_flag' => $flag, 'hold_ms' => 100, 'attempts' => 5]));
        $t      = GstBench::tally($r);
        $errors = $t['DB_ERROR'] ?? 0;
        $b->line('CRASH_UNDER_CONTENTION_RESULTS', GstBench::fmt($t));
        $b->line('CRASH_UNDER_CONTENTION_EFFECT_ROWS', $b->effects('contended'));
        $b->line('CRASH_UNDER_CONTENTION_RETRYABLE_DB_ERRORS', $errors);
        $b->check('CRASH_UNDER_CONTENTION_EXACTLY_ONE_EFFECT', ($t['KILLED'] ?? 0) === 1 && ($t['PROCESSED'] ?? 0) === 1
            && ($t['DUPLICATE_PROCESSED'] ?? 0) + $errors === $n - 2 && $b->effects('contended') === 1
            && $b->receipts()->count() === 1 && $b->receipts()->value('status') === 'processed');
    }

    // B5: a business exception rolls back receipt AND effects; retry succeeds once.
    if ($want('txn_business_failure')) {
        $b->reset();
        $r          = $b->race('business_failure', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'bf', 'key' => 'bf', 'tag' => 'bf', 'fail' => true]]);
        $rolledBack = ($r[0]['category'] ?? null) === 'GST_BENCH_BUSINESS_FAILURE' && $b->receipts()->count() === 0 && $b->effects('bf') === 0;
        $retry      = $b->race('business_retry', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'bf', 'key' => 'bf', 'tag' => 'bf']]);
        $b->check('TXN_ROLLBACK_ON_BUSINESS_FAILURE', $rolledBack && ($retry[0]['decision'] ?? null) === 'PROCESSED' && $b->effects('bf') === 1);
    }

    // B6: a committed 'processing' row (non-transactional caller) blocks effects.
    if ($want('txn_legacy_processing')) {
        $b->reset();
        $claim    = $b->ledger()->claim(gst_scope(), 'legacy', 'legacy');
        $r        = $b->race('legacy_inflight', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'legacy', 'key' => 'legacy', 'tag' => 'legacy']]);
        $inflight = ($r[0]['decision'] ?? null) === 'IN_PROGRESS' && $b->effects('legacy') === 0;
        $b->receipts()->where('receipt_key', $claim['receipt_key'])->update(['lease_expires_at' => '2000-01-01 00:00:00']);
        $r     = $b->race('legacy_stale', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'legacy', 'key' => 'legacy', 'tag' => 'legacy']]);
        $stale = ($r[0]['decision'] ?? null) === 'STALE_INDETERMINATE' && $b->effects('legacy') === 0;
        $b->check('TXN_NO_EFFECTS_WHILE_UNCERTAIN', $inflight && $stale);
    }

    // B7: SQL lock timeout fails closed, then a retry after release succeeds.
    if ($want('txn_timeout')) {
        $b->reset();
        $db = $b->db();
        $db->beginTransaction();
        $held = $b->ledger()->claim(gst_scope(), 'locked', 'locked');
        $r    = $b->race('lock_timeout', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'locked', 'key' => 'locked', 'tag' => 'locked', 'attempts' => 2,
            'connection' => $b->extra['short_lock_connection'], 'pre' => $b->extra['short_lock_pre']]]);
        $db->rollBack();
        $timedOut = $held['decision'] === 'ACQUIRED' && ($r[0]['category'] ?? null) === 'DB_ERROR' && $b->effects('locked') === 0;
        $b->line('SQL_TIMEOUT_ERROR', ($r[0]['sqlstate'] ?? '') . '_' . ($r[0]['driver_code'] ?? ''));
        $retry = $b->race('lock_retry', [['action' => 'atomic', 'scope' => gst_scope(), 'body' => 'locked', 'key' => 'locked', 'tag' => 'locked']]);
        $b->check('SQL_TIMEOUT_FAIL_CLOSED_THEN_RETRY', $timedOut && ($retry[0]['decision'] ?? null) === 'PROCESSED' && $b->effects('locked') === 1);
    }
}

function gst_schema_check(GstBench $b): array
{
    $db = $b->db();
    if ($b->driver === 'sqlite') {
        $kind = null;
        foreach ($db->select('PRAGMA index_list("' . GST_RECEIPTS . '")') as $index) {
            if ((int) $index->unique !== 1 || (int) ($index->partial ?? 0) !== 0) {
                continue;
            }
            $cols = array_map(fn ($c) => $c->name, $db->select('PRAGMA index_info("' . str_replace('"', '""', $index->name) . '")'));
            if ($cols === ['scope_hash', 'receipt_key']) {
                $kind = $index->origin === 'pk' ? 'PRIMARY_KEY' : 'UNIQUE_INDEX';
            }
        }
        $notNull = true;
        foreach ($db->select('PRAGMA table_info("' . GST_RECEIPTS . '")') as $col) {
            if (in_array($col->name, ['scope_hash', 'receipt_key', 'payload_hash', 'claim_token', 'status', 'lease_expires_at'], true)) {
                $notNull = $notNull && (int) $col->notnull === 1;
            }
        }
        $b->line('SQL_SCHEMA_UNIQUE_PAIR', $kind ?? 'MISSING');

        return [$kind !== null && $notNull, $kind === null ? 'NO_COMPOSITE_UNIQUE' : ($notNull ? '' : 'NULLABLE')];
    }

    $schemaName = $db->getDatabaseName();
    $kind       = null;
    foreach ($db->select('SELECT INDEX_NAME AS n, NON_UNIQUE AS nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE', [$schemaName, GST_RECEIPTS]) as $index) {
        if ((int) $index->nu === 0 && $index->cols === 'scope_hash,receipt_key') {
            $kind = $index->n === 'PRIMARY' ? 'PRIMARY_KEY' : 'UNIQUE_INDEX';
        }
    }
    $engine = (string) $db->selectOne('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schemaName, GST_RECEIPTS])->e;
    $types  = [];
    $ok     = $kind !== null && strtoupper($engine) === 'INNODB';
    foreach ($db->select('SELECT COLUMN_NAME AS c, COLUMN_TYPE AS t, IS_NULLABLE AS nl, COLLATION_NAME AS co FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schemaName, GST_RECEIPTS]) as $col) {
        $types[] = $col->c . ':' . $col->t . ($col->nl === 'YES' ? ':NULL' : '') . ($col->co ? ':' . $col->co : '');
        if (in_array($col->c, ['scope_hash', 'receipt_key', 'payload_hash', 'claim_token'], true)) {
            $ok = $ok && $col->t === 'varchar(64)' && $col->nl === 'NO';
        }
    }
    $b->line('SQL_SCHEMA_UNIQUE_PAIR', $kind ?? 'MISSING');
    $b->line('SQL_ENGINE', $engine);
    $b->line('SQL_COLUMNS', implode(',', $types));

    return [$ok, $kind === null ? 'NO_COMPOSITE_UNIQUE' : ($ok ? '' : 'TYPES_OR_ENGINE')];
}
