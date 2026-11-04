<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\TestCase as Orchestra;
use WeBRTeu\Payments\PaymentsServiceProvider;
use WeBRTeu\Stripe\StripeServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // URL-urile generate (notifyUrl, BACKREF...) folosesc app.url, nu host-ul cererii de test.
        URL::forceRootUrl('https://shop.example');
        URL::forceScheme('https');
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PaymentsServiceProvider::class, StripeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.url', 'https://shop.example');
        $app['config']->set('stripe.secret', env('STRIPE_SANDBOX_SECRET', 'sk_test_123'));
        $app['config']->set('stripe.webhook_secrets', ['whsec_old', 'whsec_new']);
    }

    protected function defineDatabaseMigrations(): void
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../vendor/webrteu/payments/database/migrations/create_payments_tables.php.stub';
        $migration->up();
    }
}
