<?php
namespace Safepay;

use Illuminate\Support\ServiceProvider;
use Safepay\Services\EscrowService;
use Safepay\Services\FedapayService;

class SafePayServiceProvider extends ServiceProvider{
    public function register():void{
        $this->app->singleton(EscrowService::class, function ($app){
            $fedapayservice = new FedapayService();
            return new EscrowService($fedapayservice);
        });

        $this->mergeConfigFrom(__DIR__.'/../config/safepay.php', 'safepay');
    }

    public function boot():void{
        $this->publishes([
            __DIR__.'/../config/safepay.php' => config_path('safepay.php')
        ], 'safepay-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}