<?php
namespace Safepay\Tests\Unit;

use Safepay\Tests\TestCase;
use Safepay\Services\FedapayService;
use InvalidArgumentException;

class FedapayServiceTest extends TestCase
{
    /** @test */
    public function it_throws_exception_when_secret_key_is_missing(): void
    {
        // On vide la config pour simuler un .env incomplet
        config(['safepay.secret_key' => null]);

        $this->expectException(InvalidArgumentException::class);

        new FedapayService();
    }

    /** @test */
    public function it_throws_exception_when_environment_is_missing(): void
    {
        config(['safepay.environment' => null]);

        $this->expectException(InvalidArgumentException::class);

        new FedapayService();
    }
}