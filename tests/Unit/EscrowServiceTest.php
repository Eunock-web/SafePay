<?php
namespace Safepay\Tests\Unit;

use Safepay\Models\Transaction;
use Safepay\Models\TransactionLog;
use Safepay\Services\EscrowService;
use Safepay\Services\FedapayService;
use Safepay\Tests\TestCase;
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
    public function it_rejects_release_if_transaction_not_found(): void
    {
        $result = $this->escrowService->release('nonexistent_tx');

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction not found', $result['message']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
