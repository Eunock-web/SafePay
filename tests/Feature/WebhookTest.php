<?php
namespace Safepay\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Safepay\Jobs\ProcessWebhook;
use Safepay\Tests\TestCase;

class WebhookTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_403_on_invalid_signature(): void
    {
        $response = $this->postJson('/webhook/fedapay', [], [
            'X-FEDAPAY-SIGNATURE' => 'invalid_signature'
        ]);

        $response->assertStatus(403);
    }

    /**
     * @test
     */
    public function it_dispatches_job_on_valid_approved_transaction(): void
    {
        // On intercepte la Queue pour ne pas vraiment exécuter le Job
        Queue::fake();

        // Test bypasses signature validation by removing the secret
        config(['safepay.webhook_secret' => '']);

        $response = $this->postJson('/webhook/fedapay', [
            'name' => 'transaction.approved',
            'entity' => ['id' => 'fedapay_tx_123'],
            'prestataire_id' => 'prestataire_uuid'
        ], [
            'X-FEDAPAY-SIGNATURE' => 'valid_signature'
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWebhook::class);
    }
}
