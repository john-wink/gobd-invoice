<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tests;

use JohnWink\GobdInvoice\GobdInvoiceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
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
        $migrations = [
            'create_gobd_documents_table',
            'create_gobd_document_lines_table',
            'create_gobd_number_sequences_table',
            'create_gobd_audit_log_table',
        ];

        foreach ($migrations as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration.'.php.stub')->up();
        }
    }
}
