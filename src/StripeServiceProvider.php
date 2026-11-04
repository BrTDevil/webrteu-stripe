<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use WeBRTeu\Payments\PaymentManager;
use WeBRTeu\Stripe\Commands\WebhookCreateCommand;
use WeBRTeu\Stripe\Commands\WebhookListCommand;

class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stripe.php', 'stripe');

        $this->app->singleton(StripeClient::class);
        $this->app->singleton(StripeGateway::class);

        $this->callAfterResolving(PaymentManager::class, function (PaymentManager $payments): void {
            $payments->extend('stripe', fn (Container $app): StripeGateway => $app->make(StripeGateway::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/stripe.php' => $this->app->configPath('stripe.php')], 'stripe-config');

        $this->commands([WebhookCreateCommand::class, WebhookListCommand::class]);
    }
}
