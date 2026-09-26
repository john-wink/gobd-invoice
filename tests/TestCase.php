<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tests;

use JohnWink\GobdInvoice\GobdInvoiceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The package migrations in the order a host runs them.
     *
     * @var list<string>
     */
    public const array MIGRATIONS = [
        'create_gobd_documents_table',
        'create_gobd_document_lines_table',
        'create_gobd_number_sequences_table',
        'create_gobd_audit_log_table',
    ];

    public static function usesPostgres(): bool
    {
        return getenv('GOBD_TEST_DB_DRIVER') === 'pgsql';
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            GobdInvoiceServiceProvider::class,
        ];
    }

    /**
     * The suite runs on in-memory SQLite by default. GOBD_TEST_DB_DRIVER=pgsql
     * runs the very same suite against a real PostgreSQL server, which is the
     * only place the row locks and the database guards actually take effect.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::usesPostgres() ? [
            'driver' => 'pgsql',
            'host' => (string) getenv('GOBD_TEST_DB_HOST'),
            'port' => (string) getenv('GOBD_TEST_DB_PORT'),
            'database' => (string) getenv('GOBD_TEST_DB_DATABASE'),
            'username' => (string) getenv('GOBD_TEST_DB_USERNAME'),
            'password' => (string) getenv('GOBD_TEST_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ] : [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Lifecycle/mechanics tests use minimal fixtures; the §14 content gate is
        // exercised explicitly in ContentValidationTest (which re-enables it). The
        // shipped config default is true (fail-closed).
        $app['config']->set('gobd-invoice.content_validation', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (self::usesPostgres()) {
            $this->dropPackageTables();
        }

        foreach (self::MIGRATIONS as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration.'.php.stub')->up();
        }
    }

    /**
     * A PostgreSQL database outlives the test, and the concurrency tests need
     * committed rows that other connections can see, so every test starts from
     * freshly created tables instead of a wrapping transaction.
     */
    private function dropPackageTables(): void
    {
        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration.'.php.stub')->down();
        }
    }
}
