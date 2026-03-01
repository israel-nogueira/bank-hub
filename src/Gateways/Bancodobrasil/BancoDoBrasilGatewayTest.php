<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Tests\Gateways\BancoDoBrasil;

use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway;
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilWebhookHandler;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BoletoResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================
 *  Testes Unitários — Banco do Brasil Gateway
 * ============================================================
 *
 *  Cobertura:
 *    ✅ Construtor — validações de certificado em produção
 *    ✅ cancelBoleto — retorna PaymentResponse (consistência com outros gateways)
 *    ✅ getStatement — paginação (indice + quantidade)
 *    ✅ transfer — roteamento PIX vs TED
 *    ✅ BancoDoBrasilWebhookHandler — token, eventos, idempotência
 *
 *  ESTRATÉGIA DE MOCK
 *  ------------------
 *  O BancoDoBrasilGateway usa cURL nativo internamente.
 *  Para testar sem rede, usamos um stub anônimo que sobrescreve
 *  o método `request()` via herança (padrão usado no BofACashProTest).
 *
 * @covers \IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway
 * @covers \IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilWebhookHandler
 */
class BancoDoBrasilGatewayTest extends TestCase
{
    // ──────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Cria um gateway em sandbox sem certificado (válido para testes).
     */
    private function makeGatewaySandbox(): BancoDoBrasilGateway
    {
        return new BancoDoBrasilGateway(
            clientId:        'test-client-id',
            clientSecret:    'test-client-secret',
            developerAppKey: 'gw-dev-app-key',
            pixKey:          'teste@bb.com.br',
            convenio:        3128557,
            carteira:        17,
            variacaoCarteira: 35,
            agencia:         '0001',
            conta:           '123456',
            sandbox:         true,
        );
    }

    /**
     * Cria um webhook handler com token configurado.
     */
    private function makeWebhookHandler(string $token = 'token-seguro-123'): BancoDoBrasilWebhookHandler
    {
        return new BancoDoBrasilWebhookHandler(webhookToken: $token);
    }

    /**
     * Gera payload JSON de PIX recebido conforme formato real do BB.
     */
    private function makePixPayload(array $override = []): string
    {
        return json_encode([
            'pix' => [
                array_merge([
                    'endToEndId'  => 'E00038166202501141052152649956',
                    'txid'        => 'abc123def456ghi789',
                    'valor'       => '150.00',
                    'horario'     => '2025-01-14T10:52:15.649Z',
                    'pagador'     => ['nome' => 'João Silva', 'cpf' => '12345678900'],
                    'infoPagador' => 'Pagamento pedido 1234',
                ], $override),
            ],
        ]);
    }

    /**
     * Gera payload JSON de boleto conforme formato real do BB.
     */
    private function makeBoletoPayload(string $situacao = 'LIQUIDADA'): string
    {
        return json_encode([
            'cobrancas' => [[
                'nossoNumero'    => '00031285570000100',
                'situacao'       => $situacao,
                'valor'          => ['original' => 299.90],
                'dataPagamento'  => '2025-01-15',
                'convenio'       => 3128557,
            ]],
        ]);
    }

    /**
     * Gera payload JSON de PIX com devolução.
     */
    private function makePixDevolucaoPayload(): string
    {
        return json_encode([
            'pix' => [[
                'endToEndId' => 'E00038166202501141052152649956',
                'txid'       => 'abc123def456ghi789',
                'valor'      => '150.00',
                'horario'    => '2025-01-14T10:52:15Z',
                'devolucoes' => [[
                    'id'     => 'DEV001',
                    'valor'  => '50.00',
                    'status' => 'DEVOLVIDO',
                    'horario' => ['liquidacao' => '2025-01-14T12:00:00Z'],
                ]],
            ]],
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 1 — Construtor
    // ══════════════════════════════════════════════════════════

    /**
     * Produção sem certificado deve lançar InvalidArgumentException.
     */
    public function testConstructorThrowsWhenProductionWithoutCertificate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Certificado digital é obrigatório/');

        new BancoDoBrasilGateway(
            clientId:        'id',
            clientSecret:    'secret',
            developerAppKey: 'gw-app-key',
            sandbox:         false, // ← produção sem certPath
        );
    }

    /**
     * Sandbox sem certificado deve ser instanciado normalmente.
     */
    public function testConstructorSandboxWithoutCertificateIsValid(): void
    {
        $gateway = $this->makeGatewaySandbox();
        $this->assertInstanceOf(BancoDoBrasilGateway::class, $gateway);
    }

    /**
     * Produção com certificado deve ser instanciado normalmente.
     */
    public function testConstructorProductionWithCertificateIsValid(): void
    {
        $gateway = new BancoDoBrasilGateway(
            clientId:        'id',
            clientSecret:    'secret',
            developerAppKey: 'gw-app-key',
            sandbox:         false,
            certPath:        '/etc/ssl/bb/cert.pem',  // caminho configurado
        );
        $this->assertInstanceOf(BancoDoBrasilGateway::class, $gateway);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 2 — cancelBoleto → deve retornar PaymentResponse
    // ══════════════════════════════════════════════════════════

    /**
     * cancelBoleto deve retornar PaymentResponse, não bool.
     * Consistência com outros gateways (C6Bank, PagSeguro, etc.).
     */
    public function testCancelBoletoReturnsPaymentResponse(): void
    {
        // Usamos um stub anônimo para mockar o request HTTP interno
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                // Simula resposta de sucesso da baixa de boleto do BB
                return ['codigoErroRegistro' => 0, 'mensagem' => 'Baixa realizada com sucesso'];
            }
        };

        $result = $gateway->cancelBoleto('00031285570000100');

        $this->assertInstanceOf(PaymentResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('cancelled', $result->status->value);
        $this->assertSame('00031285570000100', $result->transactionId);
    }

    /**
     * Quando codigoErroRegistro !== 0, cancelBoleto ainda deve retornar
     * PaymentResponse com success = false (sem lançar exceção genérica).
     */
    public function testCancelBoletoReturnsFailureResponseOnBBError(): void
    {
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                return ['codigoErroRegistro' => 10, 'mensagem' => 'Boleto já liquidado'];
            }
        };

        $result = $gateway->cancelBoleto('00031285570000100');

        $this->assertInstanceOf(PaymentResponse::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('liquidado', strtolower($result->message));
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 3 — getStatement com paginação
    // ══════════════════════════════════════════════════════════

    /**
     * getStatement deve passar indice e quantidade para a API quando fornecidos.
     */
    public function testGetStatementPassesPaginationParams(): void
    {
        $capturedQuery = null;

        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
            agencia:         '0001',
            conta:           '123456',
        ) extends BancoDoBrasilGateway {
            public ?array $lastQuery = null;

            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                $this->lastQuery = $query;
                return [
                    'lancamentos' => [
                        ['data' => '15.01.2025', 'valor' => 100.0, 'creditoDebito' => 'C', 'descricao' => 'PIX recebido'],
                        ['data' => '16.01.2025', 'valor' => 50.0,  'creditoDebito' => 'D', 'descricao' => 'TED enviado'],
                    ],
                    'quantidadeRegistros' => 2,
                    'indicePrimeiro'      => 0,
                ];
            }
        };

        $result = $gateway->getStatement(
            new \DateTime('2025-01-01'),
            new \DateTime('2025-01-31'),
            page: 2,
            perPage: 25,
        );

        // Verifica que a paginação foi passada nos query params
        $this->assertArrayHasKey('indice',     $gateway->lastQuery);
        $this->assertArrayHasKey('quantidade', $gateway->lastQuery);
        $this->assertSame(25, $gateway->lastQuery['indice']);    // page 2, 25 por página → offset 25
        $this->assertSame(25, $gateway->lastQuery['quantidade']);

        // Verifica que os lançamentos são retornados
        $this->assertCount(2, $result['lancamentos']);
        $this->assertSame(2, $result['quantidadeRegistros']);
    }

    /**
     * getStatement sem paginação deve usar valores padrão.
     */
    public function testGetStatementDefaultPagination(): void
    {
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            public ?array $lastQuery = null;

            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                $this->lastQuery = $query;
                return ['lancamentos' => [], 'quantidadeRegistros' => 0];
            }
        };

        $gateway->getStatement(new \DateTime('-30 days'), new \DateTime());

        // Página 1, 50 registros por página → indice = 0
        $this->assertSame(0,  $gateway->lastQuery['indice']);
        $this->assertSame(50, $gateway->lastQuery['quantidade']);
    }

    /**
     * getStatement deve retornar array com 'lancamentos' e metadados de paginação.
     */
    public function testGetStatementReturnStructure(): void
    {
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                return [
                    'lancamentos'         => [['data' => '01.01.2025', 'valor' => 10.0, 'descricao' => 'Teste']],
                    'quantidadeRegistros' => 1,
                    'indicePrimeiro'      => 0,
                ];
            }
        };

        $result = $gateway->getStatement(new \DateTime('-7 days'), new \DateTime());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lancamentos', $result);
        $this->assertArrayHasKey('quantidadeRegistros', $result);
        $this->assertCount(1, $result['lancamentos']);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 4 — Roteamento PIX vs TED
    // ══════════════════════════════════════════════════════════

    /**
     * transfer() com metadata['pixKey'] deve rotear para PIX.
     */
    public function testTransferRoutesToPixWhenPixKeyPresent(): void
    {
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            public string $lastPath = '';

            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                $this->lastPath = $path;
                return ['idPagamento' => 'PAY-PIX-001', 'status' => 'PROCESSANDO'];
            }
        };

        $request = new TransferRequest(
            amount:              200.00,
            beneficiaryName:     'Carlos Mendes',
            beneficiaryDocument: '11122233344',
            description:         'Pagamento fornecedor',
            metadata:            ['pixKey' => 'carlos@email.com'],
        );

        $response = $gateway->transfer($request);

        $this->assertInstanceOf(TransferResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertStringContainsString('pix', strtolower($gateway->lastPath));
        $this->assertStringContainsString('pix', strtolower($response->rawResponse['_method'] ?? ''));
    }

    /**
     * transfer() sem pixKey deve rotear para TED.
     */
    public function testTransferRoutesToTedWhenNoPixKey(): void
    {
        $gateway = new class(
            clientId:        'test-id',
            clientSecret:    'test-secret',
            developerAppKey: 'gw-dev-app-key',
            sandbox:         true,
        ) extends BancoDoBrasilGateway {
            public string $lastPath = '';

            /** @phpstan-ignore-next-line */
            protected function request(string $method, string $path, array $body = [], array $query = []): array
            {
                $this->lastPath = $path;
                return ['idPagamento' => 'PAY-TED-001', 'status' => 'PROCESSANDO'];
            }
        };

        $request = new TransferRequest(
            amount:              1500.00,
            beneficiaryName:     'Empresa XYZ',
            beneficiaryDocument: '12345678000199',
            description:         'Pagamento NF 001',
            bankCode:            '237',
            agency:              '1234',
            account:             '56789',
            accountDigit:        '0',
            accountType:         'checking',
        );

        $response = $gateway->transfer($request);

        $this->assertInstanceOf(TransferResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertStringContainsString('ted', strtolower($gateway->lastPath));
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 5 — BancoDoBrasilWebhookHandler: Construtor
    // ══════════════════════════════════════════════════════════

    /**
     * Token vazio deve lançar InvalidArgumentException.
     */
    public function testWebhookHandlerThrowsOnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/webhookToken não pode ser uma string vazia/');

        new BancoDoBrasilWebhookHandler(webhookToken: '');
    }

    /**
     * Token null deve ser aceito (modo sandbox sem validação).
     */
    public function testWebhookHandlerAcceptsNullToken(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);
        $this->assertInstanceOf(BancoDoBrasilWebhookHandler::class, $handler);
    }

    /**
     * Token válido deve ser aceito normalmente.
     */
    public function testWebhookHandlerAcceptsValidToken(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: 'meu-token-secreto');
        $this->assertInstanceOf(BancoDoBrasilWebhookHandler::class, $handler);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 6 — WebhookHandler: Validação de token
    // ══════════════════════════════════════════════════════════

    /**
     * Webhook com token válido no header x-webhook-token deve ser processado.
     */
    public function testWebhookHandlerAcceptsValidTokenHeader(): void
    {
        $handler = $this->makeWebhookHandler('token-seguro-123');

        $result = $handler->handle(
            rawBody: $this->makePixPayload(),
            headers: ['x-webhook-token' => 'token-seguro-123'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(BancoDoBrasilWebhookHandler::EVENT_PIX_RECEBIDO, $result['eventType']);
    }

    /**
     * Webhook com token válido no header Authorization: Bearer deve ser processado.
     */
    public function testWebhookHandlerAcceptsBearerTokenHeader(): void
    {
        $handler = $this->makeWebhookHandler('token-seguro-123');

        $result = $handler->handle(
            rawBody: $this->makePixPayload(),
            headers: ['Authorization' => 'Bearer token-seguro-123'],
        );

        $this->assertTrue($result['success']);
    }

    /**
     * Webhook com token errado deve lançar GatewayException.
     */
    public function testWebhookHandlerThrowsOnInvalidToken(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/token de segurança inválido/');

        $handler = $this->makeWebhookHandler('token-correto');
        $handler->handle(
            rawBody: $this->makePixPayload(),
            headers: ['x-webhook-token' => 'token-errado'],
        );
    }

    /**
     * Webhook sem header de token deve lançar GatewayException.
     */
    public function testWebhookHandlerThrowsWhenTokenHeaderMissing(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/token de segurança ausente/');

        $handler = $this->makeWebhookHandler('token-seguro-123');
        $handler->handle(
            rawBody: $this->makePixPayload(),
            headers: [], // sem token
        );
    }

    /**
     * Com validateToken = false, ausência de token não deve lançar exceção.
     */
    public function testWebhookHandlerSkipsValidationWhenDisabled(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(
            webhookToken:  'token-seguro-123',
            validateToken: false,
        );

        $result = $handler->handle(
            rawBody: $this->makePixPayload(),
            headers: [], // sem token, mas validação desabilitada
        );

        $this->assertTrue($result['success']);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 7 — WebhookHandler: Eventos PIX
    // ══════════════════════════════════════════════════════════

    /**
     * Evento PIX_RECEBIDO deve disparar o handler correto com dados normalizados.
     */
    public function testWebhookHandlerDispatchesPixRecebido(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $receivedEvent = null;
        $handler->onPixRecebido(function (array $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });

        $handler->handle(rawBody: $this->makePixPayload(), headers: []);

        $this->assertNotNull($receivedEvent);
        $this->assertSame(BancoDoBrasilWebhookHandler::EVENT_PIX_RECEBIDO, $receivedEvent['eventType']);
        $this->assertSame('abc123def456ghi789',            $receivedEvent['txid']);
        $this->assertSame('E00038166202501141052152649956', $receivedEvent['endToEndId']);
        $this->assertSame(150.00,                          $receivedEvent['valor']);
        $this->assertSame('Pagamento pedido 1234',         $receivedEvent['infoPagador']);
    }

    /**
     * Evento PIX com devolucoes deve ser detectado como PIX_DEVOLVIDO.
     */
    public function testWebhookHandlerDispatchesPixDevolvido(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $receivedEvent = null;
        $handler->onPixDevolvido(function (array $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });

        $handler->handle(rawBody: $this->makePixDevolucaoPayload(), headers: []);

        $this->assertNotNull($receivedEvent);
        $this->assertSame(BancoDoBrasilWebhookHandler::EVENT_PIX_DEVOLVIDO, $receivedEvent['eventType']);
        $this->assertSame('DEV001', $receivedEvent['devolucaoId']);
        $this->assertSame(50.00,    $receivedEvent['valor']);
        $this->assertSame('DEVOLVIDO', $receivedEvent['status']);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 8 — WebhookHandler: Eventos Boleto
    // ══════════════════════════════════════════════════════════

    /**
     * Boleto liquidado deve disparar o handler BOLETO_LIQUIDADO.
     */
    public function testWebhookHandlerDispatchesBoletoLiquidado(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $receivedEvent = null;
        $handler->onBoletoLiquidado(function (array $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });

        $handler->handle(rawBody: $this->makeBoletoPayload('LIQUIDADA'), headers: []);

        $this->assertNotNull($receivedEvent);
        $this->assertSame(BancoDoBrasilWebhookHandler::EVENT_BOLETO_LIQUIDADO, $receivedEvent['eventType']);
        $this->assertSame('00031285570000100', $receivedEvent['nossoNumero']);
        $this->assertSame(299.90,              $receivedEvent['valor']);
        $this->assertSame(3128557,             $receivedEvent['convenio']);
    }

    /**
     * Boleto vencido deve disparar o handler BOLETO_VENCIDO.
     */
    public function testWebhookHandlerDispatchesBoletoVencido(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $receivedEvent = null;
        $handler->onBoletoVencido(function (array $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });

        $handler->handle(rawBody: $this->makeBoletoPayload('VENCIDA'), headers: []);

        $this->assertNotNull($receivedEvent);
        $this->assertSame(BancoDoBrasilWebhookHandler::EVENT_BOLETO_VENCIDO, $receivedEvent['eventType']);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 9 — WebhookHandler: Fallback e encadeamento
    // ══════════════════════════════════════════════════════════

    /**
     * Evento desconhecido deve disparar o fallback handler.
     */
    public function testWebhookHandlerCallsFallbackForUnknownEvent(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $fallbackCalled = false;
        $handler->onUnknownEvent(function (array $event) use (&$fallbackCalled) {
            $fallbackCalled = true;
        });

        $unknownPayload = json_encode(['tipo' => 'EVENTO_FUTURO', 'id' => '123']);
        $handler->handle(rawBody: $unknownPayload, headers: []);

        $this->assertTrue($fallbackCalled);
    }

    /**
     * on() deve retornar $this para permitir encadeamento fluente.
     */
    public function testWebhookHandlerOnMethodReturnsSelf(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);
        $result  = $handler->on('EVENTO', fn() => null);

        $this->assertSame($handler, $result);
    }

    /**
     * Payload JSON inválido deve lançar GatewayException.
     */
    public function testWebhookHandlerThrowsOnInvalidJson(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/payload JSON inválido/');

        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);
        $handler->handle(rawBody: 'isso nao e json', headers: []);
    }

    // ══════════════════════════════════════════════════════════
    //  SEÇÃO 10 — WebhookHandler: Idempotência via eventId
    // ══════════════════════════════════════════════════════════

    /**
     * O eventId deve ser o endToEndId do PIX (garantia de unicidade BACEN).
     */
    public function testPixRecebidoEventIdIsEndToEndId(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $result = $handler->handle(rawBody: $this->makePixPayload(), headers: []);

        $this->assertSame('E00038166202501141052152649956', $result['eventId']);
    }

    /**
     * O eventId de boleto deve ser o nossoNumero (identificador único do título).
     */
    public function testBoletoEventIdIsNossoNumero(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $result = $handler->handle(rawBody: $this->makeBoletoPayload(), headers: []);

        $this->assertSame('00031285570000100', $result['eventId']);
    }

    /**
     * O payload original bruto deve estar disponível em rawPayload para auditoria.
     */
    public function testEventContainsRawPayloadForAudit(): void
    {
        $handler = new BancoDoBrasilWebhookHandler(webhookToken: null);

        $receivedEvent = null;
        $handler->onPixRecebido(function (array $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });

        $handler->handle(rawBody: $this->makePixPayload(), headers: []);

        $this->assertArrayHasKey('rawPayload', $receivedEvent);
        $this->assertArrayHasKey('pix', $receivedEvent['rawPayload']);
    }
}
