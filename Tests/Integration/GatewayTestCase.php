<?php

namespace IsraelNogueira\PaymentHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

/**
 * Classe base abstrata para testes de Gateways
 * 
 * Todos os gateways devem herdar desta classe e implementar o método getGateway()
 */
abstract class GatewayTestCase extends TestCase
{
    protected PaymentGatewayInterface $gateway;
    
    /**
     * Método abstrato que cada gateway deve implementar
     * para retornar sua instância configurada
     */
    abstract protected function getGateway(): PaymentGatewayInterface;
    
    /**
     * Retorna lista de métodos que o gateway suporta
     * Por padrão, assume que todos são suportados
     * Sobrescreva em casos específicos
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
    
    /**
     * Verifica se um método é suportado pelo gateway
     */
    protected function isMethodSupported(string $method): bool
    {
        return in_array($method, $this->getSupportedMethods(), true);
    }

    protected function setUp(): void
    {
        $this->gateway = $this->getGateway();
    }

    // ==================== TESTES PIX ====================
    
    public function testCreatePixPayment(): void
    {
        if (!$this->isMethodSupported('pix')) {
            $this->markTestSkipped('Gateway não suporta PIX');
        }

        $request = PixPaymentRequest::create(
            amount: 100.50,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            customerDocument: '12345678909',
            description: 'Test PIX Payment'
        );

        $response = $this->gateway->createPixPayment($request);

        $this->assertTrue($response->success, 'PIX payment creation should succeed');
        $this->assertNotEmpty($response->transactionId, 'Transaction ID should not be empty');
        $this->assertGreaterThan(0, $response->amount, 'Amount should be greater than 0');
    }

    public function testGetPixQrCode(): void
    {
        if (!$this->isMethodSupported('pix')) {
            $this->markTestSkipped('Gateway não suporta PIX');
        }

        $request = PixPaymentRequest::create(
            amount: 100.50,
            customerEmail: 'test@example.com'
        );

        $payment = $this->gateway->createPixPayment($request);
        $qrCode = $this->gateway->getPixQrCode($payment->transactionId);

        $this->assertNotEmpty($qrCode, 'QR Code should not be empty');
    }

    public function testGetPixCopyPaste(): void
    {
        if (!$this->isMethodSupported('pix')) {
            $this->markTestSkipped('Gateway não suporta PIX');
        }

        $request = PixPaymentRequest::create(
            amount: 100.50,
            customerEmail: 'test@example.com'
        );

        $payment = $this->gateway->createPixPayment($request);
        $copyPaste = $this->gateway->getPixCopyPaste($payment->transactionId);

        $this->assertNotEmpty($copyPaste, 'Copy/Paste code should not be empty');
    }

    // ==================== TESTES CARTÃO DE CRÉDITO ====================
    
    public function testCreateCreditCardPayment(): void
    {
        if (!$this->isMethodSupported('credit_card')) {
            $this->markTestSkipped('Gateway não suporta Cartão de Crédito');
        }

        $request = CreditCardPaymentRequest::create(
            amount: 250.00,
            cardNumber: '4111111111111111',
            cardHolderName: 'TEST USER',
            cardExpiryMonth: '12',
            cardExpiryYear: '2028',
            cardCvv: '123',
            customerEmail: 'test@example.com',
            installments: 1
        );

        $response = $this->gateway->createCreditCardPayment($request);

        $this->assertTrue($response->success, 'Credit card payment should succeed');
        $this->assertNotEmpty($response->transactionId, 'Transaction ID should not be empty');
    }

    public function testTokenizeCard(): void
    {
        if (!$this->isMethodSupported('credit_card')) {
            $this->markTestSkipped('Gateway não suporta Cartão de Crédito');
        }

        $cardData = [
            'number' => '4111111111111111',
            'holder_name' => 'TEST USER',
            'expiry_month' => '12',
            'expiry_year' => '2028',
            'cvv' => '123'
        ];

        $token = $this->gateway->tokenizeCard($cardData);

        $this->assertNotEmpty($token, 'Card token should not be empty');
    }

    // ==================== TESTES CARTÃO DE DÉBITO ====================
    
    public function testCreateDebitCardPayment(): void
    {
        if (!$this->isMethodSupported('debit_card')) {
            $this->markTestSkipped('Gateway não suporta Cartão de Débito');
        }

        $request = DebitCardPaymentRequest::create(
            amount: 150.00,
            cardNumber: '4111111111111111',
            cardHolderName: 'TEST USER',
            cardExpiryMonth: '12',
            cardExpiryYear: '2028',
            cardCvv: '123',
            customerEmail: 'test@example.com'
        );

        $response = $this->gateway->createDebitCardPayment($request);

        $this->assertTrue($response->success, 'Debit card payment should succeed');
        $this->assertNotEmpty($response->transactionId, 'Transaction ID should not be empty');
    }

    // ==================== TESTES BOLETO ====================
    
    public function testCreateBoleto(): void
    {
        if (!$this->isMethodSupported('boleto')) {
            $this->markTestSkipped('Gateway não suporta Boleto');
        }

        $request = BoletoPaymentRequest::create(
            amount: 500.00,
            customerName: 'Test User',
            customerDocument: '12345678909',
            customerEmail: 'test@example.com',
            dueDate: (new \DateTime('+7 days'))->format('Y-m-d')
        );

        $response = $this->gateway->createBoleto($request);

        $this->assertTrue($response->success, 'Boleto creation should succeed');
        $this->assertNotEmpty($response->transactionId, 'Transaction ID should not be empty');
    }

    public function testGetBoletoUrl(): void
    {
        if (!$this->isMethodSupported('boleto')) {
            $this->markTestSkipped('Gateway não suporta Boleto');
        }

        $request = BoletoPaymentRequest::create(
            amount: 500.00,
            customerName: 'Test User',
            customerDocument: '12345678909',
            customerEmail: 'test@example.com',
            dueDate: (new \DateTime('+7 days'))->format('Y-m-d')
        );

        $payment = $this->gateway->createBoleto($request);
        $url = $this->gateway->getBoletoUrl($payment->transactionId);

        $this->assertNotEmpty($url, 'Boleto URL should not be empty');
        $this->assertStringContainsString('http', $url, 'Boleto URL should be a valid HTTP URL');
    }

    // ==================== TESTES ASSINATURAS ====================
    
    public function testCreateSubscription(): void
    {
        if (!$this->isMethodSupported('subscription')) {
            $this->markTestSkipped('Gateway não suporta Assinaturas');
        }

        $request = SubscriptionRequest::create(
            amount: 99.90,
            interval: 'monthly',
            customerEmail: 'test@example.com',
            cardToken: 'tok_test_123'
        );

        $response = $this->gateway->createSubscription($request);

        $this->assertTrue($response->success, 'Subscription creation should succeed');
        $this->assertNotEmpty($response->subscriptionId, 'Subscription ID should not be empty');
    }

    public function testCancelSubscription(): void
    {
        if (!$this->isMethodSupported('subscription')) {
            $this->markTestSkipped('Gateway não suporta Assinaturas');
        }

        $request = SubscriptionRequest::create(
            amount: 99.90,
            interval: 'monthly',
            customerEmail: 'test@example.com',
            cardToken: 'tok_test_123'
        );

        $createResponse = $this->gateway->createSubscription($request);
        $cancelResponse = $this->gateway->cancelSubscription($createResponse->subscriptionId);

        $this->assertTrue($cancelResponse->success, 'Subscription cancellation should succeed');
    }

    // ==================== TESTES REFUND ====================
    
    public function testRefund(): void
    {
        if (!$this->isMethodSupported('refund')) {
            $this->markTestSkipped('Gateway não suporta Refund');
        }

        // Criar pagamento primeiro
        $pixRequest = PixPaymentRequest::create(
            amount: 100.00,
            customerEmail: 'test@example.com'
        );

        $payment = $this->gateway->createPixPayment($pixRequest);

        // Fazer refund
        $refundRequest = RefundRequest::create(
            transactionId: $payment->transactionId,
            amount: 100.00,
            reason: 'Test refund'
        );

        $refund = $this->gateway->refund($refundRequest);

        $this->assertTrue($refund->success, 'Refund should succeed');
        $this->assertNotEmpty($refund->refundId, 'Refund ID should not be empty');
    }

    // ==================== TESTES CUSTOMER ====================
    
    public function testCreateCustomer(): void
    {
        if (!$this->isMethodSupported('customer')) {
            $this->markTestSkipped('Gateway não suporta Customer');
        }

        $request = CustomerRequest::create(
            name: 'Test User',
            email: 'test@example.com',
            taxId: '12345678909',
            phone: '11999999999'
        );

        $response = $this->gateway->createCustomer($request);

        $this->assertTrue($response->success, 'Customer creation should succeed');
        $this->assertNotEmpty($response->customerId, 'Customer ID should not be empty');
    }

    // ==================== TESTES PAYMENT LINK ====================
    
    public function testCreatePaymentLink(): void
    {
        if (!$this->isMethodSupported('payment_link')) {
            $this->markTestSkipped('Gateway não suporta Payment Link');
        }

        $request = PaymentLinkRequest::create(
            amount: 100.00,
            description: 'Test Payment Link'
        );

        $response = $this->gateway->createPaymentLink($request);

        $this->assertTrue($response->success, 'Payment link creation should succeed');
        $this->assertNotEmpty($response->linkId, 'Link ID should not be empty');
        $this->assertNotEmpty($response->url, 'Link URL should not be empty');
    }

    // ==================== TESTES GERAIS ====================
    
    public function testGetTransactionStatus(): void
    {
        if (!$this->isMethodSupported('transaction_status')) {
            $this->markTestSkipped('Gateway não suporta Transaction Status');
        }

        $request = PixPaymentRequest::create(
            amount: 100.00,
            customerEmail: 'test@example.com'
        );

        $payment = $this->gateway->createPixPayment($request);
        $status = $this->gateway->getTransactionStatus($payment->transactionId);

        $this->assertTrue($status->success, 'Get transaction status should succeed');
        $this->assertEquals($payment->transactionId, $status->transactionId, 'Transaction IDs should match');
    }

    public function testGetBalance(): void
    {
        if (!$this->isMethodSupported('balance')) {
            $this->markTestSkipped('Gateway não suporta Balance');
        }

        $balance = $this->gateway->getBalance();

        $this->assertTrue($balance->success, 'Get balance should succeed');
        $this->assertGreaterThanOrEqual(0, $balance->available, 'Balance should be greater than or equal to 0');
    }

    public function testRegisterWebhook(): void
    {
        if (!$this->isMethodSupported('webhooks')) {
            $this->markTestSkipped('Gateway não suporta Webhooks');
        }

        $webhooks = $this->gateway->registerWebhook(
            'https://example.com/webhook',
            ['payment.approved', 'payment.refunded']
        );

        $this->assertIsArray($webhooks, 'Webhooks should be an array');
        $this->assertNotEmpty($webhooks, 'Webhooks array should not be empty');
    }
}
