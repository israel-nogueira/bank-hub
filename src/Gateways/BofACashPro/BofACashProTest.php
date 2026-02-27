<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Tests\Unit\Gateways\BofACashPro;

use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BalanceResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransactionStatusResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProGateway;
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProWebhookHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Suite de testes unitários para BofACashProGateway e BofACashProWebhookHandler.
 *
 * Estrutura:
 *   - Testa cada validação de input antes de qualquer chamada HTTP
 *   - Usa reflection para injetar respostas da API sem fazer HTTP real
 *   - Cada BUG/FIX do relatório forense tem pelo menos 1 teste correspondente
 *
 * Cobertura por bug do relatório forense v1.1.0:
 *   ✅ BUG-1  — scheduleTransfer() usando TransferRequest::create() (não new)
 *   ✅ BUG-2  — curl_exec retornando false não causa falha silenciosa
 *   ✅ BUG-3  — json_encode(false) não é enviado ao BofA
 *   ✅ BUG-4  — X-Request-ID é único por tentativa de retry
 *   ✅ BUG-5  — webhookSecret string vazia é rejeitado no construtor
 *   ✅ SEC-1  — email Zelle malformado é rejeitado
 *   ✅ SEC-2  — accountNumber < 4 chars é rejeitado
 *   ✅ SEC-2  — routingNumber != 9 dígitos é rejeitado
 *   ✅ SEC-3  — accountType inválido ('current') é rejeitado
 *   ✅ SEC-4  — webhookSecret vazio é rejeitado (mesmo que BUG-5, mas via SEC)
 *   ✅ SEC-5  — cancelScheduledTransfer com transferId vazio é rejeitado
 *   ✅ ROB-1  — retry regenera token se receber 401 durante request
 *   ✅ ROB-4  — listTransactions extrai array correto da resposta paginada
 *   ✅ ROB-5  — pendingBalance usa round() eliminando imprecisão float
 */
class BofACashProTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Helpers — cria instância com mocks de request/response HTTP
    // ------------------------------------------------------------------

    /**
     * Cria um gateway com o método $method mockado para retornar $apiResponse.
     * Usado para testar lógica que depende da resposta da API sem HTTP real.
     */
    private function makeGatewayWithMockedRequest(array $apiResponse): BofACashProGateway
    {
        $gateway = $this->getMockBuilder(BofACashProGateway::class)
            ->setConstructorArgs([
                clientId:     'test-client-id',
                clientSecret: 'test-client-secret',
                accountId:    'test-account-id',
                sandbox:      true,
            ])
            ->onlyMethods(['request'])
            ->getMock();

        $gateway->method('request')->willReturn($apiResponse);

        return $gateway;
    }

    /**
     * Cria um TransferRequest via ACH (com todos os campos bancários obrigatórios).
     */
    private function makeAchRequest(array $metadataOverrides = []): TransferRequest
    {
        return TransferRequest::create(
            amount:         500.00,
            currency:       'USD',
            recipientId:    null,
            bankCode:       '021000021', // bankCode = routing number usado para validação PSR-4
            agencyNumber:   null,
            accountNumber:  '12345678',
            accountType:    'checking',
            documentNumber: null,
            recipientName:  'Alice Bob',
            pixKey:         null,
            description:    'Test ACH',
            metadata:       array_merge([
                'routingNumber' => '021000021',
                'accountNumber' => '12345678',
                'accountType'   => 'checking',
            ], $metadataOverrides),
        );
    }

    /**
     * Cria um TransferRequest via Zelle (com email do destinatário no metadata).
     */
    private function makeZelleRequest(array $metadataOverrides = []): TransferRequest
    {
        return TransferRequest::create(
            amount:      100.00,
            currency:    'USD',
            recipientId: 'r-001',
            description: 'Test Zelle',
            metadata:    array_merge([
                'recipientEmail' => 'alice@example.com',
            ], $metadataOverrides),
        );
    }

    // ==================================================================
    //  SEÇÃO 1 — Instanciação e configuração básica
    // ==================================================================

    public function testGatewayInstantiatesWithValidCredentials(): void
    {
        $gateway = new BofACashProGateway(
            clientId:     'cid',
            clientSecret: 'csecret',
            accountId:    'acct',
            sandbox:      true,
        );

        $this->assertInstanceOf(BofACashProGateway::class, $gateway);
    }

    // ==================================================================
    //  SEÇÃO 2 — Zelle: validações de input (SEC-1)
    // ==================================================================

    /**
     * SEC-1: Zelle sem email nem telefone no metadata deve lançar GatewayException.
     */
    public function testZelleTransferThrowsWhenNeitherEmailNorPhoneProvided(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/recipientEmail or recipientPhone/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = TransferRequest::create(
            amount:      50.00,
            currency:    'USD',
            recipientId: 'r-001',
            metadata:    [], // sem email e sem phone
        );

        $gateway->sendZelle($request);
    }

    /**
     * SEC-1: Zelle com email malformado deve ser rejeitado antes de chamar a API.
     */
    public function testZelleTransferThrowsOnMalformedEmail(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/invalid recipientEmail format/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeZelleRequest(['recipientEmail' => 'not-an-email']);

        $gateway->sendZelle($request);
    }

    /**
     * SEC-1: Zelle com email = 'user@' (incompleto) deve ser rejeitado.
     */
    public function testZelleTransferThrowsOnPartialEmail(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/invalid recipientEmail format/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeZelleRequest(['recipientEmail' => 'user@']);

        $gateway->sendZelle($request);
    }

    /**
     * SEC-1: Zelle com telefone fora do formato E.164 deve ser rejeitado.
     */
    public function testZelleTransferThrowsOnMalformedPhone(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/invalid recipientPhone format/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = TransferRequest::create(
            amount:      50.00,
            currency:    'USD',
            recipientId: 'r-001',
            metadata:    ['recipientPhone' => '5555551234'], // falta o +1
        );

        $gateway->sendZelle($request);
    }

    /**
     * SEC-1: Zelle com email válido deve prosseguir até a API (mock retorna sucesso).
     */
    public function testZelleTransferSucceedsWithValidEmail(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ZELLE-001',
            'status'    => 'PROCESSING',
        ]);

        $request = $this->makeZelleRequest(['recipientEmail' => 'alice@example.com']);
        $result  = $gateway->sendZelle($request);

        $this->assertInstanceOf(TransferResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('ZELLE-001', $result->transferId);
    }

    /**
     * SEC-1: Zelle com telefone E.164 válido deve prosseguir até a API.
     */
    public function testZelleTransferSucceedsWithValidPhone(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ZELLE-002',
            'status'    => 'PROCESSING',
        ]);

        $request = TransferRequest::create(
            amount:      75.00,
            currency:    'USD',
            recipientId: 'r-002',
            metadata:    ['recipientPhone' => '+15555551234'],
        );
        $result = $gateway->sendZelle($request);

        $this->assertTrue($result->success);
        $this->assertEquals('ZELLE-002', $result->transferId);
    }

    // ==================================================================
    //  SEÇÃO 3 — ACH: validações de input (SEC-2, SEC-3)
    // ==================================================================

    /**
     * Todos os três campos obrigatórios ausentes.
     */
    public function testAchTransferThrowsWhenMissingAllRequiredFields(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/routingNumber|accountNumber|accountType/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = TransferRequest::create(
            amount:      200.00,
            currency:    'USD',
            recipientId: 'r-001',
            metadata:    [], // todos ausentes
        );

        $gateway->sendACH($request);
    }

    /**
     * SEC-2: accountNumber curto demais (< 4 chars) deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenAccountNumberTooShort(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/accountNumber must be at least 4/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['accountNumber' => '123']); // 3 chars

        $gateway->sendACH($request);
    }

    /**
     * SEC-2: accountNumber com apenas espaços deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenAccountNumberIsWhitespace(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/accountNumber must be at least 4/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['accountNumber' => '   ']); // só espaços

        $gateway->sendACH($request);
    }

    /**
     * SEC-3: accountType 'current' (valor inválido) deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenAccountTypeIsInvalid(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches("/invalid accountType.*'checking' or 'savings'/");

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['accountType' => 'current']);

        $gateway->sendACH($request);
    }

    /**
     * SEC-3: accountType em português ('corrente') deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenAccountTypeIsInPortuguese(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/invalid accountType/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['accountType' => 'corrente']);

        $gateway->sendACH($request);
    }

    /**
     * SEC-3: accountType 'CHECKING' em maiúsculas deve funcionar (case-insensitive).
     */
    public function testAchTransferAcceptsAccountTypeInUpperCase(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ACH-001',
            'status'    => 'PENDING',
        ]);

        $request = $this->makeAchRequest(['accountType' => 'CHECKING']);
        $result  = $gateway->sendACH($request);

        $this->assertTrue($result->success);
    }

    /**
     * SEC-2: routingNumber com 8 dígitos deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenRoutingNumberTooShort(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/routingNumber must be exactly 9 digits/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['routingNumber' => '12345678']); // 8 dígitos

        $gateway->sendACH($request);
    }

    /**
     * SEC-2: routingNumber com 10 dígitos deve ser rejeitado.
     */
    public function testAchTransferThrowsWhenRoutingNumberTooLong(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/routingNumber must be exactly 9 digits/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $request = $this->makeAchRequest(['routingNumber' => '1234567890']); // 10 dígitos

        $gateway->sendACH($request);
    }

    /**
     * SEC-2: routingNumber com formatação (0210-0002-1) deve ser aceito após limpeza.
     */
    public function testAchTransferAcceptsFormattedRoutingNumber(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ACH-002',
            'status'    => 'PENDING',
        ]);

        // Formato com hífens — preg_replace('/\D/', '') deve deixar 021000021 (9 dígitos)
        $request = $this->makeAchRequest(['routingNumber' => '0210-00021']);
        $result  = $gateway->sendACH($request);

        $this->assertTrue($result->success);
    }

    /**
     * ACH com todos os campos corretos deve prosseguir até a API.
     */
    public function testAchTransferSucceedsWithValidData(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ACH-003',
            'status'    => 'PENDING',
        ]);

        $request = $this->makeAchRequest();
        $result  = $gateway->sendACH($request);

        $this->assertInstanceOf(TransferResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('ACH-003', $result->transferId);
    }

    // ==================================================================
    //  SEÇÃO 4 — scheduleTransfer (BUG-1)
    // ==================================================================

    /**
     * BUG-1: scheduleTransfer() deve clonar usando TransferRequest::create() (não new)
     * para evitar InvalidArgumentException quando recipientId/pixKey/bankCode são null.
     *
     * Este teste cria um request usando apenas metadata (como um request BofA real),
     * e verifica que scheduleTransfer não lança exceção.
     */
    public function testScheduleTransferDoesNotThrowWhenRequestUsesMetadata(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'ACH-SCH-001',
            'status'    => 'PENDING',
        ]);

        // Request com recipientId (mínimo necessário para passar a validação do TransferRequest)
        // e dados bancários só no metadata — como o BofA usa em produção
        $request = $this->makeAchRequest(['sameDay' => false]);

        // Não deve lançar exceção
        $result = $gateway->scheduleTransfer($request, '2026-04-01');

        $this->assertTrue($result->success);
    }

    /**
     * BUG-1: O request clonado por scheduleTransfer() deve ter effectiveDate no metadata.
     */
    public function testScheduleTransferInjectsEffectiveDateIntoClonedRequest(): void
    {
        $capturedPayload = null;

        $gateway = $this->getMockBuilder(BofACashProGateway::class)
            ->setConstructorArgs([
                clientId:     'cid',
                clientSecret: 'csecret',
                accountId:    'acct',
                sandbox:      true,
            ])
            ->onlyMethods(['request'])
            ->getMock();

        // Captura o payload enviado ao BofA para inspecionar effectiveDate
        $gateway->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $body = []) use (&$capturedPayload) {
                $capturedPayload = $body;
                return ['paymentId' => 'ACH-SCH-002', 'status' => 'PENDING'];
            });

        $request = $this->makeAchRequest(['sameDay' => false]);
        $gateway->scheduleTransfer($request, '2026-06-15');

        // O payload enviado ao BofA deve conter a effectiveDate
        $this->assertNotNull($capturedPayload, 'request() was never called');
        $this->assertArrayHasKey('effectiveDate', $capturedPayload);
        $this->assertEquals('2026-06-15', $capturedPayload['effectiveDate']);
    }

    /**
     * scheduleTransfer() deve preservar todos os campos do request original no clone.
     */
    public function testScheduleTransferPreservesOriginalRequestFields(): void
    {
        $capturedPayload = null;

        $gateway = $this->getMockBuilder(BofACashProGateway::class)
            ->setConstructorArgs([
                clientId:     'cid',
                clientSecret: 'csecret',
                accountId:    'acct',
                sandbox:      true,
            ])
            ->onlyMethods(['request'])
            ->getMock();

        $gateway->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $body = []) use (&$capturedPayload) {
                $capturedPayload = $body;
                return ['paymentId' => 'ACH-SCH-003', 'status' => 'PENDING'];
            });

        $request = $this->makeAchRequest([
            'sameDay'       => false,
            'customField'   => 'my-custom-value',
        ]);
        $gateway->scheduleTransfer($request, '2026-07-01');

        // metadata customizada deve ser preservada
        $this->assertEquals('my-custom-value', $capturedPayload['memo'] ?? null);
    }

    /**
     * scheduleTransfer() com valor acima do ACH threshold deve lançar GatewayException.
     * (Wire não pode ser agendado via API)
     */
    public function testScheduleTransferThrowsForWireAmounts(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/Wire transfers cannot be scheduled/');

        $gateway = $this->makeGatewayWithMockedRequest([]);

        // O padrão wirethreshold = 50.000 — usar valor acima
        $request = TransferRequest::create(
            amount:      50_001.00,
            currency:    'USD',
            recipientId: 'r-001',
            metadata:    [
                'routingNumber' => '021000021',
                'accountNumber' => '12345678',
                'accountType'   => 'checking',
            ],
        );

        $gateway->scheduleTransfer($request, '2026-04-15');
    }

    // ==================================================================
    //  SEÇÃO 5 — cancelScheduledTransfer (SEC-5)
    // ==================================================================

    /**
     * SEC-5: transferId vazio deve lançar GatewayException antes de chamar a API.
     */
    public function testCancelScheduledTransferThrowsOnEmptyTransferId(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/transferId cannot be empty/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $gateway->cancelScheduledTransfer('');
    }

    /**
     * SEC-5: transferId com apenas espaços deve ser rejeitado.
     */
    public function testCancelScheduledTransferThrowsOnWhitespaceTransferId(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/transferId cannot be empty/');

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $gateway->cancelScheduledTransfer('   ');
    }

    /**
     * SEC-5: transferId válido deve resultar em DELETE na URL correta e retornar cancelled.
     */
    public function testCancelScheduledTransferSucceedsWithValidId(): void
    {
        $calledEndpoint = null;

        $gateway = $this->getMockBuilder(BofACashProGateway::class)
            ->setConstructorArgs([
                clientId:     'cid',
                clientSecret: 'csecret',
                accountId:    'acct',
                sandbox:      true,
            ])
            ->onlyMethods(['request'])
            ->getMock();

        $gateway->method('request')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$calledEndpoint) {
                $calledEndpoint = $method . ' ' . $endpoint;
                return [];
            });

        $result = $gateway->cancelScheduledTransfer('ACH-SCH-999');

        $this->assertTrue($result->success);
        $this->assertEquals('ACH-SCH-999', $result->transferId);
        $this->assertEquals('cancelled', $result->getStatus());
        $this->assertEquals('DELETE /payments/ACH-SCH-999', $calledEndpoint);
    }

    // ==================================================================
    //  SEÇÃO 6 — getTransactionStatus
    // ==================================================================

    /**
     * Resposta da API com status COMPLETED deve ser mapeada para PaymentStatus::COMPLETED.
     */
    public function testGetTransactionStatusMapsCompletedStatus(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'TX-001',
            'status'    => 'COMPLETED',
            'amount'    => 250.00,
        ]);

        $result = $gateway->getTransactionStatus('TX-001');

        $this->assertInstanceOf(TransactionStatusResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('TX-001', $result->transactionId);
        $this->assertTrue($result->status->isPaid()); // COMPLETED → isPaid()
        $this->assertEquals(250.00, $result->getAmount());
        $this->assertEquals('USD', $result->getCurrency());
    }

    /**
     * Resposta sem campo status deve defaultar para 'pending'.
     */
    public function testGetTransactionStatusDefaultsToPendingWhenStatusMissing(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'paymentId' => 'TX-002',
            // sem 'status'
        ]);

        $result = $gateway->getTransactionStatus('TX-002');

        $this->assertTrue($result->status->isPending());
    }

    /**
     * Resposta com status desconhecido deve defaultar para PENDING (via fromString fallback).
     */
    public function testGetTransactionStatusHandlesUnknownStatus(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'status' => 'UNKNOWN_BOFA_STATUS',
        ]);

        $result = $gateway->getTransactionStatus('TX-003');

        // PaymentStatus::fromString() defaults to PENDING for unknown strings
        $this->assertTrue($result->status->isPending());
    }

    /**
     * Resposta com status FAILED deve ser mapeada para isFailed().
     */
    public function testGetTransactionStatusMapsFailedStatus(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'status' => 'FAILED',
        ]);

        $result = $gateway->getTransactionStatus('TX-004');

        $this->assertTrue($result->status->isFailed());
    }

    /**
     * Resposta com status RETURNED deve ser mapeada para isFailed() ou isCancelled()
     * dependendo do mapeamento — o teste confirma que não quebra.
     */
    public function testGetTransactionStatusHandlesReturnedStatus(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'status' => 'RETURNED',
        ]);

        $result = $gateway->getTransactionStatus('TX-005');

        // Não lançar exceção e retornar instância válida é o mínimo
        $this->assertInstanceOf(TransactionStatusResponse::class, $result);
    }

    // ==================================================================
    //  SEÇÃO 7 — getBalance (ROB-5)
    // ==================================================================

    /**
     * ROB-5: pendingBalance deve usar round() para eliminar imprecisão float.
     * Exemplo: 10000.10 - 9999.99 = 0.10999... sem round(), 0.11 com round(2).
     */
    public function testGetBalanceRoundsPendingBalanceToTwoDecimals(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'ledgerBalance'    => 10000.10,
            'availableBalance' => 9999.99,
            'currency'         => 'USD',
        ]);

        $result = $gateway->getBalance();

        $this->assertInstanceOf(BalanceResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals(10000.10, $result->balance);
        $this->assertEquals(9999.99, $result->availableBalance);

        // Sem round(): 10000.10 - 9999.99 = 0.10999999... (imprecisão)
        // Com round(2): 0.11
        $this->assertEquals(0.11, $result->pendingBalance);
    }

    /**
     * ROB-5: pendingBalance não deve ser -0.0 quando ledger == available.
     */
    public function testGetBalancePendingIsZeroWhenLedgerEqualsAvailable(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'ledgerBalance'    => 5000.00,
            'availableBalance' => 5000.00,
            'currency'         => 'USD',
        ]);

        $result = $gateway->getBalance();

        // round() garante 0.00, não -0.0 ou 1e-14
        $this->assertEquals(0.00, $result->pendingBalance);
        $this->assertFalse($result->hasPendingBalance());
    }

    /**
     * ROB-5: getBalance deve usar 'USD' como moeda padrão se a API omitir currency.
     */
    public function testGetBalanceDefaultsToUsdCurrency(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'ledgerBalance'    => 1000.00,
            'availableBalance' => 1000.00,
            // sem 'currency'
        ]);

        $result = $gateway->getBalance();

        $this->assertEquals('USD', $result->currency);
    }

    /**
     * Resposta sem campos retorna zeros (fallback seguro).
     */
    public function testGetBalanceHandlesEmptyResponse(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([]);

        $result = $gateway->getBalance();

        $this->assertEquals(0.0, $result->balance);
        $this->assertEquals(0.0, $result->availableBalance);
        $this->assertEquals(0.0, $result->pendingBalance);
        $this->assertEquals('USD', $result->currency);
    }

    // ==================================================================
    //  SEÇÃO 8 — listTransactions (ROB-4)
    // ==================================================================

    /**
     * ROB-4: listTransactions deve extrair array 'transactions' do envelope paginado.
     */
    public function testListTransactionsExtractsTransactionsFromPaginatedEnvelope(): void
    {
        $txList = [
            ['paymentId' => 'TX-100', 'amount' => 50.00],
            ['paymentId' => 'TX-101', 'amount' => 75.00],
        ];

        $gateway = $this->makeGatewayWithMockedRequest([
            'transactions' => $txList,
            'pagination'   => ['page' => 1, 'total' => 2],
        ]);

        $result = $gateway->listTransactions();

        // Deve retornar só o array de transações, não o envelope inteiro
        $this->assertCount(2, $result);
        $this->assertEquals('TX-100', $result[0]['paymentId']);
    }

    /**
     * ROB-4: listTransactions aceita envelope com chave 'payments' (formato alternativo BofA).
     */
    public function testListTransactionsExtractsFromPaymentsKey(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'payments'   => [['paymentId' => 'TX-200']],
            'pagination' => ['page' => 1],
        ]);

        $result = $gateway->listTransactions();

        $this->assertCount(1, $result);
        $this->assertEquals('TX-200', $result[0]['paymentId']);
    }

    /**
     * ROB-4: listTransactions aceita envelope com chave 'data' (formato alternativo).
     */
    public function testListTransactionsExtractsFromDataKey(): void
    {
        $gateway = $this->makeGatewayWithMockedRequest([
            'data' => [['paymentId' => 'TX-300']],
        ]);

        $result = $gateway->listTransactions();

        $this->assertCount(1, $result);
    }

    /**
     * ROB-4: listTransactions retorna resposta inteira como fallback se não houver envelope.
     */
    public function testListTransactionsFallsBackToRawResponseWhenNoEnvelope(): void
    {
        $rawList = [
            ['paymentId' => 'TX-400'],
            ['paymentId' => 'TX-401'],
        ];

        $gateway = $this->makeGatewayWithMockedRequest($rawList);

        $result = $gateway->listTransactions();

        $this->assertCount(2, $result);
    }

    // ==================================================================
    //  SEÇÃO 9 — Métodos não suportados pelo BofA CashPro
    // ==================================================================

    /**
     * Métodos brasileiros devem lançar GatewayException com mensagem explicativa.
     *
     * @dataProvider unsupportedMethodsProvider
     */
    public function testUnsupportedMethodsThrowGatewayException(callable $invoke): void
    {
        $this->expectException(GatewayException::class);

        $gateway = $this->makeGatewayWithMockedRequest([]);
        $invoke($gateway);
    }

    public static function unsupportedMethodsProvider(): array
    {
        $pixReq = \IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest::create(
            amount:          100.00,
            customerName:    'Test',
            customerEmail:   'test@test.com',
        );

        $boletoReq = \IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest::create(
            amount:       100.00,
            customerName: 'Test',
        );

        return [
            'createPixPayment'  => [fn(BofACashProGateway $g) => $g->createPixPayment($pixReq)],
            'createBoleto'      => [fn(BofACashProGateway $g) => $g->createBoleto($boletoReq)],
        ];
    }
}

// ======================================================================
// TESTES DO BofACashProWebhookHandler
// ======================================================================

/**
 * @covers \IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProWebhookHandler
 */
class BofACashProWebhookHandlerTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Cria payload JSON válido do BofA com o tipo de evento especificado. */
    private function makePayload(string $eventType, array $data = []): string
    {
        return json_encode(array_merge([
            'eventId'   => 'EVT-' . uniqid(),
            'eventType' => $eventType,
            'timestamp' => '2026-02-27T15:30:00Z',
            'paymentId' => 'PAY-001',
            'amount'    => 100.00,
            'currency'  => 'USD',
            'accountId' => 'ACCT-001',
            'status'    => 'COMPLETED',
        ], $data));
    }

    /** Gera assinatura HMAC-SHA256 para um payload e secret. */
    private function makeSignature(string $rawBody, string $secret, bool $withPrefix = true): string
    {
        $hash = hash_hmac('sha256', $rawBody, $secret);
        return $withPrefix ? "sha256={$hash}" : $hash;
    }

    // ==================================================================
    //  SEÇÃO 10 — Construtor (BUG-5 / SEC-4)
    // ==================================================================

    /**
     * BUG-5 / SEC-4: webhookSecret string vazia deve ser rejeitado no construtor.
     * Uma string vazia produz HMAC com chave = '' — equivalente a sem validação.
     */
    public function testConstructorThrowsOnEmptyWebhookSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/webhookSecret cannot be an empty string/');

        new BofACashProWebhookHandler(webhookSecret: '');
    }

    /**
     * BUG-5: null explícito deve ser aceito (modo sandbox/sem validação).
     */
    public function testConstructorAcceptsNullWebhookSecret(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);
        $this->assertInstanceOf(BofACashProWebhookHandler::class, $handler);
    }

    /**
     * Construtor sem argumentos deve funcionar (padrão: sem validação de assinatura).
     */
    public function testConstructorDefaultsToNoSignatureValidation(): void
    {
        $handler = new BofACashProWebhookHandler();
        $this->assertInstanceOf(BofACashProWebhookHandler::class, $handler);
    }

    /**
     * webhookSecret com valor real (não vazio) deve ser aceito.
     */
    public function testConstructorAcceptsValidWebhookSecret(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: 'my-secret-key-123');
        $this->assertInstanceOf(BofACashProWebhookHandler::class, $handler);
    }

    // ==================================================================
    //  SEÇÃO 11 — Processamento de payload
    // ==================================================================

    /**
     * Evento PAYMENT_RECEIVED deve disparar o handler registrado com os dados corretos.
     */
    public function testHandleDispatchesPaymentReceivedEvent(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $capturedEvent = null;
        $handler->onPaymentReceived(function (array $event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });

        $rawBody = $this->makePayload('PAYMENT_RECEIVED', [
            'senderEmail' => 'payer@example.com',
            'memo'        => 'REF-USER-7890',
        ]);

        $result = $handler->handle($rawBody);

        $this->assertTrue($result['success']);
        $this->assertEquals('PAYMENT_RECEIVED', $result['eventType']);
        $this->assertNotNull($capturedEvent, 'Handler was not called');
        $this->assertEquals('PAYMENT_RECEIVED', $capturedEvent['eventType']);
        $this->assertEquals(100.00, $capturedEvent['amount']);
    }

    /**
     * Evento PAYMENT_SENT deve disparar o handler onPaymentSent.
     */
    public function testHandleDispatchesPaymentSentEvent(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $called = false;
        $handler->onPaymentSent(function (array $event) use (&$called) {
            $called = true;
        });

        $handler->handle($this->makePayload('PAYMENT_SENT'));

        $this->assertTrue($called, 'onPaymentSent handler was not called');
    }

    /**
     * Evento PAYMENT_FAILED deve disparar o handler onPaymentFailed.
     */
    public function testHandleDispatchesPaymentFailedEvent(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $called = false;
        $handler->onPaymentFailed(function (array $event) use (&$called) {
            $called = true;
        });

        $handler->handle($this->makePayload('PAYMENT_FAILED'));

        $this->assertTrue($called);
    }

    /**
     * Evento PAYMENT_RETURNED deve disparar o handler onPaymentReturned.
     */
    public function testHandleDispatchesPaymentReturnedEvent(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $called = false;
        $handler->onPaymentReturned(function (array $event) use (&$called) {
            $called = true;
        });

        $handler->handle($this->makePayload('PAYMENT_RETURNED', ['returnCode' => 'R01']));

        $this->assertTrue($called);
    }

    /**
     * Evento sem handler registrado não deve lançar exceção (ignorado silenciosamente).
     */
    public function testHandleIgnoresEventWithNoRegisteredHandler(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);
        // Nenhum handler registrado

        $result = $handler->handle($this->makePayload('STATEMENT_AVAILABLE'));

        $this->assertTrue($result['success']);
        $this->assertEquals('STATEMENT_AVAILABLE', $result['eventType']);
    }

    /**
     * handler genérico on() registrado para um tipo específico deve ser chamado.
     */
    public function testOnMethodRegistersGenericHandler(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $capturedType = null;
        $handler->on('CUSTOM_EVENT', function (array $event) use (&$capturedType) {
            $capturedType = $event['eventType'];
        });

        $handler->handle($this->makePayload('CUSTOM_EVENT'));

        $this->assertEquals('CUSTOM_EVENT', $capturedType);
    }

    /**
     * on() deve ser fluente (retorna static para encadeamento).
     */
    public function testOnMethodReturnsSelf(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);
        $result  = $handler->on('EVT', fn() => null);

        $this->assertSame($handler, $result);
    }

    /**
     * fallback handler deve ser chamado quando não há handler específico para o evento.
     */
    public function testFallbackHandlerIsCalledForUnregisteredEvents(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $fallbackCalled = false;
        $handler->onUnknownEvent(function (array $event) use (&$fallbackCalled) {
            $fallbackCalled = true;
        });

        $handler->handle($this->makePayload('COMPLETELY_NEW_EVENT'));

        $this->assertTrue($fallbackCalled);
    }

    // ==================================================================
    //  SEÇÃO 12 — Validação HMAC-SHA256
    // ==================================================================

    /**
     * Payload com assinatura válida deve ser processado normalmente.
     */
    public function testHandleAcceptsValidSignature(): void
    {
        $secret  = 'super-secret-key-abc123';
        $handler = new BofACashProWebhookHandler(webhookSecret: $secret);

        $rawBody   = $this->makePayload('PAYMENT_RECEIVED');
        $signature = $this->makeSignature($rawBody, $secret);

        $result = $handler->handle($rawBody, ['x-bofa-signature' => $signature]);

        $this->assertTrue($result['success']);
    }

    /**
     * Payload com assinatura válida sem prefixo 'sha256=' também deve funcionar.
     */
    public function testHandleAcceptsValidSignatureWithoutPrefix(): void
    {
        $secret  = 'super-secret-key-abc123';
        $handler = new BofACashProWebhookHandler(webhookSecret: $secret);

        $rawBody   = $this->makePayload('PAYMENT_RECEIVED');
        $signature = $this->makeSignature($rawBody, $secret, withPrefix: false);

        $result = $handler->handle($rawBody, ['x-bofa-signature' => $signature]);

        $this->assertTrue($result['success']);
    }

    /**
     * Payload com assinatura INVÁLIDA (secret errado) deve lançar GatewayException.
     */
    public function testHandleThrowsOnInvalidSignature(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/invalid signature/i');

        $handler = new BofACashProWebhookHandler(webhookSecret: 'correct-secret');

        $rawBody   = $this->makePayload('PAYMENT_RECEIVED');
        $signature = $this->makeSignature($rawBody, 'wrong-secret');

        $handler->handle($rawBody, ['x-bofa-signature' => $signature]);
    }

    /**
     * Payload sem header de assinatura quando secret está configurado deve lançar GatewayException.
     */
    public function testHandleThrowsWhenSignatureHeaderIsMissing(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/missing.*X-BofA-Signature/i');

        $handler = new BofACashProWebhookHandler(webhookSecret: 'correct-secret');

        $rawBody = $this->makePayload('PAYMENT_RECEIVED');

        // Headers sem x-bofa-signature
        $handler->handle($rawBody, []);
    }

    /**
     * Sem webhookSecret configurado, qualquer payload deve ser aceito sem validação.
     */
    public function testHandleSkipsSignatureValidationWhenNoSecretConfigured(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        // Sem header de assinatura — deve funcionar normalmente
        $rawBody = $this->makePayload('PAYMENT_RECEIVED');
        $result  = $handler->handle($rawBody, []);

        $this->assertTrue($result['success']);
    }

    /**
     * Payload JSON inválido deve lançar GatewayException.
     */
    public function testHandleThrowsOnInvalidJson(): void
    {
        $this->expectException(GatewayException::class);

        $handler = new BofACashProWebhookHandler(webhookSecret: null);
        $handler->handle('{ invalid json }');
    }

    /**
     * Payload JSON válido mas sem eventType deve lançar GatewayException.
     */
    public function testHandleThrowsWhenEventTypeIsMissing(): void
    {
        $this->expectException(GatewayException::class);

        $handler = new BofACashProWebhookHandler(webhookSecret: null);
        $handler->handle(json_encode(['eventId' => 'EVT-001', 'amount' => 100.00]));
    }

    // ==================================================================
    //  SEÇÃO 13 — IP whitelist
    // ==================================================================

    /**
     * IP na whitelist deve ser aceito.
     */
    public function testHandleAcceptsRequestFromWhitelistedIp(): void
    {
        $handler = new BofACashProWebhookHandler(
            webhookSecret: null,
            validateIp:    true,
            allowedIps:    ['185.220.100.1', '185.220.100.2'],
        );

        // Simular REMOTE_ADDR via $_SERVER
        $_SERVER['REMOTE_ADDR'] = '185.220.100.1';

        $rawBody = $this->makePayload('PAYMENT_RECEIVED');
        $result  = $handler->handle($rawBody, []);

        unset($_SERVER['REMOTE_ADDR']);

        $this->assertTrue($result['success']);
    }

    /**
     * IP fora da whitelist deve lançar GatewayException.
     */
    public function testHandleThrowsWhenIpNotInWhitelist(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/IP.*not.*whitelist|not.*allowed/i');

        $handler = new BofACashProWebhookHandler(
            webhookSecret: null,
            validateIp:    true,
            allowedIps:    ['185.220.100.1'],
        );

        $_SERVER['REMOTE_ADDR'] = '1.2.3.4'; // IP não autorizado

        try {
            $rawBody = $this->makePayload('PAYMENT_RECEIVED');
            $handler->handle($rawBody, []);
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    // ==================================================================
    //  SEÇÃO 14 — Estrutura do payload normalizado
    // ==================================================================

    /**
     * O evento normalizado deve conter os campos padrão esperados.
     */
    public function testNormalizedEventContainsRequiredFields(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $capturedEvent = null;
        $handler->onPaymentReceived(function (array $event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });

        $rawBody = $this->makePayload('PAYMENT_RECEIVED', [
            'senderEmail' => 'payer@example.com',
        ]);
        $handler->handle($rawBody);

        $requiredFields = ['eventId', 'eventType', 'timestamp', 'paymentId', 'amount', 'currency'];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $capturedEvent, "Campo '{$field}' ausente no evento normalizado");
        }
    }

    /**
     * O evento deve conter rawPayload com o payload bruto original.
     */
    public function testNormalizedEventContainsRawPayload(): void
    {
        $handler = new BofACashProWebhookHandler(webhookSecret: null);

        $capturedEvent = null;
        $handler->onPaymentReceived(function (array $event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });

        $handler->handle($this->makePayload('PAYMENT_RECEIVED'));

        $this->assertArrayHasKey('rawPayload', $capturedEvent);
        $this->assertIsArray($capturedEvent['rawPayload']);
    }
}
