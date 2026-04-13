<?php
    return [
        'secret_key' => env('FEDAPAY_SECRET_KEY'),
        'public_key' => env('FEDAPAY_PUBLIC_KEY'),
        'webhook_secret' => env('FEDAPAY_WEBHOOK_KEY'),
        'environment' => env('FEDAPAY_ENVIRONMENT', 'sandbox'),
        'escrow_delay' => env('ESCROW_DELAY', 48),
        'commission' => env('ESCROW_COMMISSION', 2.5)
    ];