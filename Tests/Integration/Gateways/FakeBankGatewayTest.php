<?php

namespace IsraelNogueira\PaymentHub\Tests\Integration\Gateways;

use IsraelNogueira\PaymentHub\Tests\Integration\GatewayTestCase;
use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\Gateways\FakeBank\FakeBankGateway;

/**
 * Testes do FakeBankGateway usando a classe base
 */
class FakeBankGatewayTest extends GatewayTestCase
{
    protected function getGateway(): PaymentGatewayInterface
    {
        return new FakeBankGateway();
    }

    /**
     * FakeBank suporta TODOS os métodos
     */
    protected function getSupportedMethods(): array
    {
        return [
            'pix',
            'credit_card',
            'debit_card',
            'boleto',
            'subscription',
            'refund',
            'customer',
            'payment_link',
            'balance',
            'transaction_status',
            'webhooks',
        ];
    }
}
