<?php

namespace Fleetbase\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;

/**
 * An in-memory SQLite database holding the webhook replay ledger's
 * telematic_webhook_receipts table, bound as the 'db' service for every
 * connection name (Fleetbase models default to the 'mysql' connection), with
 * a transactions manager so DB::afterCommit() behaves as in the application.
 *
 * NOT a MySQL test: scripts/webhook-ledger-race.php runs the multi-process
 * ledger proofs against SQLite and MySQL.
 */
final class WebhookReceiptDatabase
{
    public static function boot(): SQLiteConnection
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'));
        $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
        $resolver->setDefaultConnection('mysql');
        EloquentModel::setConnectionResolver($resolver);

        self::bind($connection);
        self::migrate($connection);

        return $connection;
    }

    /** Bind $connection as the 'db' service; every connection name resolves to it. */
    public static function bind(Connection $connection): void
    {
        $manager = new DatabaseTransactionsManager();
        $connection->setTransactionManager($manager);
        app()->instance('db.transactions', $manager);
        app()->instance('db', new class($connection) {
            public function __construct(private Connection $connection)
            {
            }

            public function connection($name = null): Connection
            {
                return $this->connection;
            }

            public function __call($method, $arguments)
            {
                return $this->connection->{$method}(...$arguments);
            }
        });
        Facade::clearResolvedInstance('db');
    }

    /** Create the receipts table with the real migration. */
    public static function migrate(Connection $connection): void
    {
        app()->instance('db.schema', $connection->getSchemaBuilder());
        Facade::clearResolvedInstance('db.schema');
        (require __DIR__ . '/../../migrations/2026_11_08_000001_create_telematic_webhook_receipts_table.php')->up();
    }

    /** @return list<string> receipt statuses, oldest first */
    public static function statuses(Connection $connection): array
    {
        return $connection->table('telematic_webhook_receipts')->orderBy('created_at')->pluck('status')->all();
    }
}
