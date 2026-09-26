<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tests;

/**
 * The multi-tenant configuration a host such as craftplan-next runs: every
 * package table carries a `team_id`, numbers are unique per team and all keys
 * are UUIDv7. The migrations read this configuration, so it is set before
 * they run.
 */
abstract class TenantTestCase extends TestCase
{
    public const string TENANT_COLUMN = 'team_id';

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gobd-invoice.tenancy.column', self::TENANT_COLUMN);
        $app['config']->set('gobd-invoice.database.key_type', 'uuid');
    }
}
