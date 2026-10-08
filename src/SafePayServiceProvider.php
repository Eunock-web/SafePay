<?php
namespace Safepay;

use Illuminate\Support\ServiceProvider;
use Safepay\Services\EscrowService;
use Safepay\Services\FedapayService;

class SafePayServiceProvider extends ServiceProvider{
    public function register():void{
        $this->app->singleton(EscrowService::class, function ($app){
            return new EscrowService($app->make(FedapayService::class));
        });

        $this->mergeConfigFrom(__DIR__.'/../config/safepay.php', 'safepay');
    }

    public function boot():void{
        $this->publishes([
            __DIR__.'/../config/safepay.php' => config_path('safepay.php')
        ], 'safepay-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations')
        ], 'safepay-migrations');

        // Évite la double exécution si l'utilisateur a publié les migrations.
        if (empty(glob(database_path('migrations/*_create_transactions_table.php')))) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/safepay.php');
    }
}