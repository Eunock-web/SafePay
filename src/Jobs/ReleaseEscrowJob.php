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

    // Never retried blindly: release() is guarded by the transaction status, and a failed payout needs a human look.
    public int $tries = 1;

    public function __construct(public string $transactionId)
    {
        
    }

    public function handle(){
        app(EscrowService::class)->release($this->transactionId);
    }
}