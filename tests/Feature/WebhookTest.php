<?php
namespace Safepay\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Safepay\Jobs\ProcessWebhook;
use Safepay\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function signedPost(array $body, ?string $secret = 'test_webhook_secret')
    {
        $payload = json_encode($body);
        $t = time();
        $sig = hash_hmac('sha256', "$t.$payload", $secret);

        return $this->call('POST', '/webhook/fedapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-FEDAPAY-SIGNATURE' => "t=$t,s=$sig",
        ], $payload);
    }

    /** @test */
    public function it_returns_403_on_invalid_signature(): void
    {
        $this->postJson('/webhook/fedapay', [], ['X-FEDAPAY-SIGNATURE' => 'invalid_signature'])
            ->assertStatus(403);
    }

    /** @test */
    public function it_rejects_everything_when_no_secret_is_configured(): void
    {
        Queue::fake();
        config(['safepay.webhook_secret' => '']);

        $this->postJson('/webhook/fedapay', [
            'name' => 'transaction.approved',
            'entity' => ['id' => 'fedapay_tx_123'],
        ])->assertStatus(500);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_dispatches_job_on_valid_approved_transaction_and_ignores_request_prestataire(): void
    {
        Queue::fake();

        $this->signedPost([
            'name' => 'transaction.approved',
            'entity' => ['id' => 'fedapay_tx_123'],
            'prestataire_id' => 'attacker_uuid',
        ])->assertStatus(200);

        Queue::assertPushed(ProcessWebhook::class, fn ($job) => $job->transactionId === 'fedapay_tx_123' && $job->prestataireId === null);
    }

    /** @test */
    public function it_acknowledges_unhandled_events_without_dispatching(): void
    {
        Queue::fake();

        $this->signedPost(['name' => 'customer.created', 'entity' => ['id' => 1]])->assertStatus(200);

        Queue::assertNothingPushed();
    }
}
