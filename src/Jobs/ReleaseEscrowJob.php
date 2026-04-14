<?php
namespace Safepay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Safepay\Services\EscrowService;

class ReleaseEscrowJob implements ShouldQueue{
    use Queueable;
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(public string $transactionId)
    {
        
    }

    public function handle(){
        app(EscrowService::class)->release($this->transactionId);
    }
}