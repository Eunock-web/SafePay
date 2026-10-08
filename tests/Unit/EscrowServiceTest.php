<?php
namespace Safepay\Tests\Unit;

use Safepay\Models\Transaction;
use Safepay\Models\TransactionLog;
use Safepay\Services\EscrowService;
use Safepay\Services\FedapayService;
use Safepay\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Safepay\Jobs\ReleaseEscrowJob;
use Mockery;

class EscrowServiceTest extends TestCase
{
    protected EscrowService $escrowService;
    protected $fedapayMock;

    protected function setUp(): void
    {
        parent::setUp();

        // On crée un faux FedapayService qui ne contacte pas vraiment FedaPay
        $this->fedapayMock = Mockery::mock(FedapayService::class);

        $this->escrowService = new EscrowService($this->fedapayMock);

        $user = new \Illuminate\Foundation\Auth\User();
        $user->id = 'client_uuid';
        $this->actingAs($user);
    }

    /**
     * @test
     */
    public function it_creates_transaction_and_log_on_successful_payment(): void
    {
        // On dit au mock ce qu'il doit retourner
        $fakeTxData = (object) [
            'id' => 'fedapay_tx_123',
            'status' => 'approved',
            'amount' => 5000,
            'mode' => 'mobile_money',
            'description' => 'Test payment',
            'custom_metadata' => []
        ];

        $this
            ->fedapayMock
            ->shouldReceive('verifyCollect')
            ->once()
            ->with('fedapay_tx_123')
            ->andReturn(['success' => true, 'data' => $fakeTxData]);

        $result = $this->escrowService->handleTransaction('fedapay_tx_123', 'prestataire_uuid');

        // Vérifications
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => 'fedapay_tx_123',
            'status' => 'escrow_lock',
            'amount' => 5000
        ]);
        $this->assertDatabaseHas('transaction_logs', [
            'transaction_id' => 'fedapay_tx_123',
            'status' => 'approved'
        ]);
    }

    /**
     * @test
     */
    public function it_creates_canceled_transaction_on_failed_payment(): void
    {
        $fakeTxData = (object) [
            'id' => 'fedapay_tx_456',
            'status' => 'failed',
            'amount' => 5000,
            'mode' => 'mobile_money',
            'description' => 'Failed payment',
            'custom_metadata' => []
        ];

        $this
            ->fedapayMock
            ->shouldReceive('verifyCollect')
            ->once()
            ->andReturn(['success' => false, 'data' => $fakeTxData]);

        $result = $this->escrowService->handleTransaction('fedapay_tx_456', 'prestataire_uuid');
        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => 'fedapay_tx_456',
            'status' => 'failed'
        ]);
    }

    /**
     * @test
     */
    public function it_rejects_release_if_transaction_not_in_escrow(): void
    {
        // On crée une transaction déjà libérée
        Transaction::create([
            'transaction_id' => 'fedapay_tx_789',
            'client_id' => 'client_uuid',
            'prestataire_id' => 'prestataire_uuid',
            'amount' => 5000,
            'currency' => 'XOF',
            'status' => 'released'
        ]);

        $result = $this->escrowService->release('fedapay_tx_789');

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction is not active in escrow', $result['message']);
    }

    /**
     * @test
     */
    public function it_does_not_put_a_released_transaction_back_in_escrow_on_replay(): void
    {
        Transaction::create([
            'transaction_id' => 'fedapay_tx_900',
            'client_id' => 'client_uuid',
            'prestataire_id' => 'prestataire_uuid',
            'amount' => 5000,
            'currency' => 'XOF',
            'status' => 'released'
        ]);

        $this->fedapayMock->shouldReceive('verifyCollect')->andReturn(['success' => true, 'data' => (object) [
            'id' => 'fedapay_tx_900', 'status' => 'approved', 'amount' => 5000, 'custom_metadata' => []
        ]]);

        $this->escrowService->handleTransaction('fedapay_tx_900', 'prestataire_uuid');

        $this->assertDatabaseHas('transactions', ['transaction_id' => 'fedapay_tx_900', 'status' => 'released']);
        $this->assertDatabaseCount('transaction_logs', 0);
    }

    /**
     * @test
     */
    public function it_reads_client_and_prestataire_from_metadata_without_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->fedapayMock->shouldReceive('verifyCollect')->andReturn(['success' => true, 'data' => (object) [
            'id' => 'fedapay_tx_901', 'status' => 'approved', 'amount' => 100,
            'custom_metadata' => (object) ['client_id' => 'c1', 'prestataire_id' => 'p1']
        ]]);

        $result = $this->escrowService->handleTransaction('fedapay_tx_901');

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'fedapay_tx_901', 'client_id' => 'c1', 'prestataire_id' => 'p1']);
    }

    /**
     * @test
     */
    public function it_flags_verification_outage_as_retryable(): void
    {
        $this->fedapayMock->shouldReceive('verifyCollect')->andReturn(['success' => false, 'error' => 'down']);

        $result = $this->escrowService->handleTransaction('fedapay_tx_902', 'p1');

        $this->assertTrue($result['retryable']);
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * @test
     */
    public function it_rejects_release_if_transaction_not_found(): void
    {
        $result = $this->escrowService->release('nonexistent_tx');

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction not found', $result['message']);
    }

    private function fakeApprovedPayment(string $id): void
    {
        $this->fedapayMock->shouldReceive('verifyCollect')->andReturn(['success' => true, 'data' => (object) [
            'id' => $id, 'status' => 'approved', 'amount' => 5000, 'custom_metadata' => []
        ]]);
    }

    /** @test */
    public function it_schedules_auto_release_after_the_escrow_delay(): void
    {
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'safepay.escrow_delay' => 48]);
        $this->fakeApprovedPayment('fedapay_tx_950');

        $this->escrowService->handleTransaction('fedapay_tx_950', 'p1');
        // A replayed webhook must not schedule a second release.
        $this->escrowService->handleTransaction('fedapay_tx_950', 'p1');

        Queue::assertPushed(ReleaseEscrowJob::class, 1);
        Queue::assertPushed(ReleaseEscrowJob::class, fn ($job) => $job->transactionId === 'fedapay_tx_950' && $job->delay !== null);
    }

    /** @test */
    public function it_does_not_schedule_auto_release_on_sync_queue_or_zero_delay(): void
    {
        Queue::fake();
        $this->fakeApprovedPayment('fedapay_tx_951');
        $this->escrowService->handleTransaction('fedapay_tx_951', 'p1'); // sync driver

        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'safepay.escrow_delay' => 0]);
        $this->fakeApprovedPayment('fedapay_tx_952');
        $this->escrowService->handleTransaction('fedapay_tx_952', 'p1');

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_computes_commission_from_config(): void
    {
        config(['safepay.commission' => 2.5]);
        $this->assertSame(125, $this->escrowService->calculateCommission(5000.0));
        config(['safepay.commission' => 0]);
        $this->assertSame(0, $this->escrowService->calculateCommission(5000.0));
    }

    /** @test */
    public function it_pays_out_the_amount_minus_commission(): void
    {
        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('firstname');
            $t->string('lastname');
            $t->string('email');
        });
        config(['auth.providers.users.model' => SafepayTestUser::class, 'safepay.commission' => 2.5]);
        SafepayTestUser::create(['id' => 'p1', 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c']);
        Transaction::create(['transaction_id' => 'tx_pay', 'client_id' => 'c1', 'prestataire_id' => 'p1',
            'amount' => 5000, 'currency' => 'XOF', 'status' => 'escrow_lock']);

        $payout = Mockery::mock();
        $payout->id = 77;
        $payout->shouldReceive('sendNow')->once();
        $this->fedapayMock->shouldReceive('payout')->once()
            ->with(Mockery::on(fn ($d) => $d['amount'] === 4875))
            ->andReturn(['success' => true, 'data' => $payout]);

        $result = $this->escrowService->release('tx_pay');

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'tx_pay', 'status' => 'released', 'commission' => 125, 'payout_id' => '77']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

class SafepayTestUser extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}
