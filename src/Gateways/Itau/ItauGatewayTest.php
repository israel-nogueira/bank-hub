<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Tests\Integration\Gateways;

use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Enums\PaymentStatus;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
use IsraelNogueira\PaymentHub\Gateways\Itau\ItauGateway;
use IsraelNogueira\PaymentHub\Tests\Integration\GatewayTestCase;
use DateTime;

/**
 * Testes de integração do ItauGateway.
 *
 * Execução contra sandbox:
 *   ITAU_CLIENT_ID=xxx ITAU_CLIENT_SECRET=yyy \
 *   ITAU_PIX_KEY=empresa@itau.com.br ITAU_CONVENIO=12345 \
 *   ./vendor/bin/phpunit --filter ItauGatewayTest
 *
 * Sem variáveis de ambiente, os testes são pulados automaticamente.
 */
class ItauGatewayTest extends GatewayTestCase
{
    // ─────────────────────────────────────────────────────────
    //  Bootstrap
    // ─────────────────────────────────────────────────────────

    protected function getGateway(): PaymentGatewayInterface
    {
        $clientId     = getenv('ITAU_CLIENT_ID')     ?: '';
        $clientSecret = getenv('ITAU_CLIENT_SECRET') ?: '';
        $pixKey       = getenv('ITAU_PIX_KEY')       ?: 'sandbox@itau.com.br';
        $convenio     = getenv('ITAU_CONVENIO')      ?: '';

        if (empty($clientId) || empty($clientSecret)) {
            $this->markTestSkipped(
                'Credenciais Itaú não configuradas. '
                . 'Defina ITAU_CLIENT_ID e ITAU_CLIENT_SECRET para executar estes testes.'
            );
        }

        return new ItauGateway(
            clientId:     $clientId,
            clientSecret: $clientSecret,
            sandbox:      true,
            pixKey:       $pixKey,
            convenio:     $convenio ?: null,
        );
    }

    /**
     * Métodos suportados pelo ItauGateway.
     * Cartão, assinatura, split, wallet, escrow e links NÃO são suportados.
     */
    protected function getSupportedMethods(): array
    {
        return [
            'pix',
            'boleto',
            'refund',
            'transfer',
            'balance',
            'transaction_status',
            'webhooks',
            'customer',
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  PIX
    // ─────────────────────────────────────────────────────────

    public function testCreatePixPaymentReturnsValidResponse(): void
    {
        $request = new PixPaymentRequest(
            amount:           100.00,
            currency:         'BRL',
            customerName:     'João da Silva',
            customerDocument: '12345678909',
            customerEmail:    'joao@example.com',
            description:      'Teste de cobrança PIX',
        );

        $response = $this->gateway->createPixPayment($request);

        $this->assertTrue($response->success, 'PIX deve ser criado com sucesso');
        $this->assertNotEmpty($response->transactionId, 'transactionId não pode ser vazio');
        $this->assertEquals(PaymentStatus::PENDING, $response->status, 'Status inicial deve ser PENDING');
        $this->assertEquals(100.00, $response->money->amount(), 'Valor deve ser 100.00');
        $this->assertArrayHasKey('txid', $response->metadata, 'Metadata deve conter txid');
    }

    public function testCreatePixPaymentWithMinimumAmount(): void
    {
        $request = new PixPaymentRequest(
            amount:           0.01,
            currency:         'BRL',
            customerName:     'Teste Mínimo',
            customerDocument: '12345678909',
            description:      'Valor mínimo',
        );

        $response = $this->gateway->createPixPayment($request);

        $this->assertTrue($response->success);
        $this->assertEquals(0.01, $response->money->amount());
    }

    public function testCreatePixPaymentBelowMinimumThrowsException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/valor mínimo/i');

        $this->gateway->createPixPayment(new PixPaymentRequest(
            amount:           0.00,
            currency:         'BRL',
            customerName:     'Test',
            customerDocument: '12345678909',
        ));
    }

    public function testCreatePixPaymentWithoutPixKeyThrowsException(): void
    {
        $gateway = new ItauGateway(
            clientId:     getenv('ITAU_CLIENT_ID')     ?: 'test',
            clientSecret: getenv('ITAU_CLIENT_SECRET') ?: 'test',
            sandbox:      true,
            pixKey:       null,
        );

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/pixKey não configurada/i');

        $gateway->createPixPayment(new PixPaymentRequest(
            amount:           10.00,
            currency:         'BRL',
            customerName:     'Test',
            customerDocument: '12345678909',
        ));
    }

    public function testGetPixQrCodeReturnsNonEmptyString(): void
    {
        $pix = $this->gateway->createPixPayment(new PixPaymentRequest(
            amount:           50.00,
            currency:         'BRL',
            customerName:     'QR Code Test',
            customerDocument: '12345678909',
        ));

        $qrCode = $this->gateway->getPixQrCode($pix->transactionId);

        $this->assertNotEmpty($qrCode, 'QR Code não pode ser vazio');
        $this->assertIsString($qrCode, 'QR Code deve ser string');
    }

    public function testGetPixCopyPasteReturnsNonEmptyString(): void
    {
        $pix = $this->gateway->createPixPayment(new PixPaymentRequest(
            amount:           75.00,
            currency:         'BRL',
            customerName:     'Copy Paste Test',
            customerDocument: '12345678909',
        ));

        $copyPaste = $this->gateway->getPixCopyPaste($pix->transactionId);

        $this->assertNotEmpty($copyPaste, 'Copia e Cola não pode ser vazio');
        $this->assertStringStartsWith('00020', $copyPaste, 'EMV Copia e Cola deve iniciar com 00020');
    }

    // ─────────────────────────────────────────────────────────
    //  BOLETO
    // ─────────────────────────────────────────────────────────

    public function testCreateBoletoReturnsValidResponse(): void
    {
        if (empty(getenv('ITAU_CONVENIO'))) {
            $this->markTestSkipped('ITAU_CONVENIO não configurado. Necessário para testes de boleto.');
        }

        $request = new BoletoPaymentRequest(
            amount:           200.00,
            currency:         'BRL',
            customerName:     'Ana Costa',
            customerDocument: '12345678909',
            customerEmail:    'ana@example.com',
            dueDate:          new DateTime('+5 days'),
            description:      'Teste de boleto',
            metadata: [
                'endereco' => 'Rua Teste, 123',
                'bairro'   => 'Centro',
                'cidade'   => 'São Paulo',
                'uf'       => 'SP',
                'cep'      => '01310100',
            ],
        );

        $response = $this->gateway->createBoleto($request);

        $this->assertTrue($response->success, 'Boleto deve ser criado com sucesso');
        $this->assertNotEmpty($response->transactionId, 'nossoNumero não pode ser vazio');
        $this->assertEquals(PaymentStatus::PENDING, $response->status);
        $this->assertEquals(200.00, $response->money->amount());
        $this->assertArrayHasKey('nossoNumero',    $response->metadata);
        $this->assertArrayHasKey('linhaDigitavel', $response->metadata);
        $this->assertArrayHasKey('codigoBarras',   $response->metadata);
    }

    public function testCreateBoletoWithoutConvenioThrowsException(): void
    {
        $gateway = new ItauGateway(
            clientId:     getenv('ITAU_CLIENT_ID')     ?: 'test',
            clientSecret: getenv('ITAU_CLIENT_SECRET') ?: 'test',
            sandbox:      true,
            convenio:     null,
        );

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/convenio não configurado/i');

        $gateway->createBoleto(new BoletoPaymentRequest(
            amount:           100.00,
            currency:         'BRL',
            customerName:     'Test',
            customerDocument: '12345678909',
            dueDate:          new DateTime('+3 days'),
        ));
    }

    // ─────────────────────────────────────────────────────────
    //  ESTORNO (REFUND)
    // ─────────────────────────────────────────────────────────

    public function testRefundCreatesDevolution(): void
    {
        $request = RefundRequest::create(
            transactionId: 'E00341934302501151234567890123',
            amount:        50.00,
            reason:        'Teste de estorno',
            metadata:      ['e2eId' => 'E00341934302501151234567890123'],
        );

        $response = $this->gateway->refund($request);

        $this->assertTrue($response->success, 'Estorno deve ser criado com sucesso');
        $this->assertNotEmpty($response->refundId, 'refundId não pode ser vazio');
        $this->assertContains($response->status->value, ['processing', 'refunded', 'failed']);
        $this->assertEquals(50.00, $response->money->amount());
    }

    public function testPartialRefund(): void
    {
        $response = $this->gateway->partialRefund(
            transactionId: 'E00341934302501151234567890456',
            amount:        25.00
        );

        $this->assertTrue($response->success);
        $this->assertEquals(25.00, $response->money->amount());
    }

    public function testRefundBelowMinimumThrowsException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/valor mínimo/i');

        $this->gateway->refund(RefundRequest::create(
            transactionId: 'E00341934302501151234567890789',
            amount:        0.00,
            reason:        'Teste',
        ));
    }

    // ─────────────────────────────────────────────────────────
    //  TRANSFERÊNCIAS
    // ─────────────────────────────────────────────────────────

    public function testTransferViaPixByKey(): void
    {
        $request = new TransferRequest(
            amount:        10.00,
            recipientName: 'Destinatário Teste',
            description:   'Teste de transferência PIX',
            metadata: [
                'pixKey' => 'destinatario@teste.com',
            ],
        );

        $response = $this->gateway->transfer($request);

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->transferId);
        $this->assertEquals(PaymentStatus::PENDING, $response->status);
        $this->assertEquals(10.00, $response->money->amount());
    }

    public function testTransferViaTed(): void
    {
        $request = new TransferRequest(
            amount:        50.00,
            recipientName: 'Empresa Teste',
            description:   'Teste de TED',
            metadata: [
                'method'            => 'ted',
                'recipientDocument' => '12345678000190',
                'bankCode'          => '237',
                'agency'            => '0001',
                'account'           => '123456-7',
                'accountType'       => 'corrente',
            ],
        );

        $response = $this->gateway->transfer($request);

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->transferId);
        $this->assertEquals(PaymentStatus::PENDING, $response->status);
        $this->assertEquals(50.00, $response->money->amount());
    }

    public function testScheduleAndCancelTransfer(): void
    {
        $request = new TransferRequest(
            amount:        100.00,
            recipientName: 'Agendamento Teste',
            description:   'Transferência agendada',
            metadata: [
                'recipientDocument' => '12345678000190',
                'bankCode'          => '341',
                'agency'            => '1234',
                'account'           => '56789-0',
                'accountType'       => 'corrente',
            ],
        );

        $scheduled = $this->gateway->scheduleTransfer($request, '2025-12-31');

        $this->assertTrue($scheduled->success);
        $this->assertNotEmpty($scheduled->transferId);
        $this->assertEquals(PaymentStatus::PENDING, $scheduled->status);
        $this->assertEquals(100.00, $scheduled->money->amount());

        $cancelled = $this->gateway->cancelScheduledTransfer($scheduled->transferId);

        $this->assertTrue($cancelled->success);
        $this->assertEquals(PaymentStatus::CANCELLED, $cancelled->status);
    }

    // ─────────────────────────────────────────────────────────
    //  SALDO E EXTRATO
    // ─────────────────────────────────────────────────────────

    public function testGetBalanceReturnsValidResponse(): void
    {
        $balance = $this->gateway->getBalance();

        $this->assertTrue($balance->success);
        $this->assertIsFloat($balance->balance);
        $this->assertIsFloat($balance->availableBalance);
        $this->assertIsFloat($balance->pendingBalance);
        $this->assertEquals('BRL', $balance->currency);
        $this->assertGreaterThanOrEqual(0, $balance->balance, 'Saldo não pode ser negativo em sandbox');
    }

    public function testGetSettlementScheduleReturnsArray(): void
    {
        $lancamentos = $this->gateway->getSettlementSchedule([
            'dataInicio' => (new DateTime('-7 days'))->format('Y-m-d'),
            'dataFim'    => (new DateTime())->format('Y-m-d'),
        ]);

        $this->assertIsArray($lancamentos);
    }

    // ─────────────────────────────────────────────────────────
    //  TRANSACTION STATUS
    // ─────────────────────────────────────────────────────────

    public function testGetTransactionStatusForPixCobranca(): void
    {
        $pix = $this->gateway->createPixPayment(new PixPaymentRequest(
            amount:           30.00,
            currency:         'BRL',
            customerName:     'Status Test',
            customerDocument: '12345678909',
        ));

        $status = $this->gateway->getTransactionStatus($pix->transactionId);

        $this->assertTrue($status->success);
        $this->assertEquals($pix->transactionId, $status->transactionId);
        $this->assertContains($status->status, [
            PaymentStatus::PENDING,
            PaymentStatus::PAID,
            PaymentStatus::CANCELLED,
        ]);
        $this->assertEquals(30.00, $status->money->amount());
    }

    // ─────────────────────────────────────────────────────────
    //  LIST TRANSACTIONS
    // ─────────────────────────────────────────────────────────

    public function testListTransactionsReturnsArray(): void
    {
        $list = $this->gateway->listTransactions([
            'inicio' => (new DateTime('-30 days'))->format('Y-m-d\TH:i:s\Z'),
            'fim'    => (new DateTime())->format('Y-m-d\TH:i:s\Z'),
        ]);

        $this->assertIsArray($list);
    }

    // ─────────────────────────────────────────────────────────
    //  WEBHOOKS
    // ─────────────────────────────────────────────────────────

    public function testRegisterWebhookReturnsWebhookData(): void
    {
        $webhook = $this->gateway->registerWebhook(
            'https://webhook.site/test-itau',
            ['pix.recebido', 'pix.devolucao']
        );

        $this->assertArrayHasKey('webhookId', $webhook);
        $this->assertArrayHasKey('url',       $webhook);
        $this->assertArrayHasKey('chave',     $webhook);
        $this->assertNotEmpty($webhook['webhookId']);
        $this->assertEquals('https://webhook.site/test-itau', $webhook['url']);
    }

    public function testRegisterWebhookWithoutPixKeyThrowsException(): void
    {
        $gateway = new ItauGateway(
            clientId:     getenv('ITAU_CLIENT_ID')     ?: 'test',
            clientSecret: getenv('ITAU_CLIENT_SECRET') ?: 'test',
            sandbox:      true,
            pixKey:       null,
        );

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/pixKey não configurada/i');

        $gateway->registerWebhook('https://test.com', ['pix.recebido']);
    }

    public function testListWebhooksReturnsArray(): void
    {
        $webhooks = $this->gateway->listWebhooks();

        $this->assertIsArray($webhooks);
    }

    public function testDeleteWebhookReturnsTrue(): void
    {
        $result = $this->gateway->deleteWebhook('webhook_id_qualquer');

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────
    //  CLIENTES
    // ─────────────────────────────────────────────────────────

    public function testCreateCustomerReturnsValidResponse(): void
    {
        $request = new CustomerRequest(
            name:  'Teste da Silva',
            taxId: '12345678909',
            email: 'teste@example.com',
            phone: '11987654321',
        );

        $customer = $this->gateway->createCustomer($request);

        $this->assertTrue($customer->success);
        $this->assertNotEmpty($customer->customerId);
        $this->assertEquals('Teste da Silva', $customer->name);
        $this->assertEquals('teste@example.com', $customer->email);
        $this->assertEquals('active', $customer->status);
    }

    public function testUpdateCustomerReturnsUpdatedData(): void
    {
        $customer = $this->gateway->createCustomer(new CustomerRequest(
            name:  'Cliente Update Test',
            taxId: '98765432100',
            email: 'update@example.com',
        ));

        $updated = $this->gateway->updateCustomer($customer->customerId, [
            'email' => 'atualizado@example.com',
        ]);

        $this->assertTrue($updated->success);
        $this->assertEquals($customer->customerId, $updated->customerId);
    }

    public function testGetCustomerReturnsCorrectData(): void
    {
        $customer = $this->gateway->createCustomer(new CustomerRequest(
            name:  'Get Customer Test',
            taxId: '11122233344',
            email: 'get@example.com',
        ));

        $found = $this->gateway->getCustomer($customer->customerId);

        $this->assertTrue($found->success);
        $this->assertEquals($customer->customerId, $found->customerId);
    }

    public function testListCustomersReturnsArray(): void
    {
        $customers = $this->gateway->listCustomers();

        $this->assertIsArray($customers);
    }

    // ─────────────────────────────────────────────────────────
    //  MÉTODOS NÃO SUPORTADOS
    // ─────────────────────────────────────────────────────────

    public function testUnsupportedCreditCardThrowsGatewayException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/cartão de crédito/i');

        $this->gateway->createCreditCardPayment(CreditCardPaymentRequest::create(
            amount:         100.00,
            cardNumber:     '4111111111111111',
            cardHolderName: 'Test',
            cardExpiryMonth: '12',
            cardExpiryYear:  '2026',
            cardCvv:         '123',
        ));
    }

    public function testUnsupportedSubscriptionThrowsGatewayException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/assinaturas/i');

        $this->gateway->createSubscription(SubscriptionRequest::create(
            amount:        29.90,
            interval:      'monthly',
            customerEmail: 'test@test.com',
            cardToken:     'tok_123',
        ));
    }

    public function testUnsupportedSplitPaymentThrowsGatewayException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/split/i');

        $this->gateway->createSplitPayment(SplitPaymentRequest::create(
            amount:        100.00,
            splits:        [],
            paymentMethod: 'pix',
        ));
    }

    public function testUnsupportedEscrowThrowsGatewayException(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/escrow/i');

        $this->gateway->holdInEscrow(EscrowRequest::create(
            amount:   100.00,
            currency: Currency::BRL,
        ));
    }

    public function testUnsupportedTokenizeCardThrowsGatewayException(): void
    {
        $this->expectException(GatewayException::class);

        $this->gateway->tokenizeCard(['number' => '4111111111111111']);
    }
}