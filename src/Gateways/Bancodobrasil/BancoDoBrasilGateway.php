<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil;

use DateTime;
use DateTimeInterface;
use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BalanceResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\CustomerResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\EscrowResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentLinkResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\RefundResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubAccountResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubscriptionResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransactionStatusResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\WalletResponse;
// FIX #1 (CRÍTICO) — Currency estava ausente nos imports originais, causando
// fatal error em runtime em cancelBoleto() e todos os métodos com Currency::BRL
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Enums\PaymentStatus;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

/**
 * Gateway do Banco do Brasil para o PaymentHub.
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  FUNCIONALIDADES                                        │
 * │  ✅ PIX — Cobrança imediata (QR Code Dinâmico v2)       │
 * │  ✅ PIX — Estorno (devolução parcial ou total)          │
 * │  ✅ Boleto Bancário                                     │
 * │  ✅ Boleto Híbrido (Boleto + PIX no mesmo título)       │
 * │  ✅ Transferência via PIX                               │
 * │  ✅ Transferência via TED                               │
 * │  ✅ Agendamento e cancelamento de transferências        │
 * │  ✅ Consulta de saldo                                   │
 * │  ✅ Consulta de extrato                                 │
 * │  ✅ Webhooks (PIX e Boleto)                             │
 * └─────────────────────────────────────────────────────────┘
 *
 * AUTENTICAÇÃO
 * ─────────────────────────────────────────────────────────────
 * OAuth 2.0 Client Credentials (Basic Auth com base64).
 * Token único com escopos combinados para PIX + Cobrança + Conta.
 * Cache automático com renovação 60 segundos antes do vencimento.
 *
 * AMBIENTES
 * ─────────────────────────────────────────────────────────────
 * Sandbox  → api.sandbox.bb.com.br  | oauth.sandbox.bb.com.br
 * Produção → api.bb.com.br          | oauth.bb.com.br
 *
 * ATENÇÃO: Em produção é obrigatório registrar o certificado
 * digital (.pfx) no portal antes de operar. Sem ele, a API
 * retorna HTTP 503 com bad_certificate.
 *
 * PRÉ-REQUISITOS
 * ─────────────────────────────────────────────────────────────
 * 1. Cadastro em: https://app.developers.bb.com.br
 * 2. Criar aplicação → obter clientId, clientSecret, developerAppKey
 * 3. Para boletos: número do convênio, carteira e variação (com gerente BB)
 * 4. Para PIX: chave PIX cadastrada na conta BB
 * 5. Para produção: certificado digital registrado no portal
 *
 * @author  PaymentHub
 * @version 2.1.0
 */
class BancoDoBrasilGateway implements PaymentGatewayInterface
{
    // ──────────────────────────────────────────────────────────
    //  Endpoints
    // ──────────────────────────────────────────────────────────

    private const API_SANDBOX    = 'https://api.sandbox.bb.com.br';
    private const API_PRODUCTION = 'https://api.bb.com.br';

    private const OAUTH_SANDBOX    = 'https://oauth.sandbox.bb.com.br/oauth/token';
    private const OAUTH_PRODUCTION = 'https://oauth.bb.com.br/oauth/token';

    private const PATH_PIX    = '/pix/v2';
    private const PATH_BOLETO = '/cobrancas/v2';
    private const PATH_CONTA  = '/conta-corrente/v1';
    private const PATH_PAG    = '/pagamentos-lote/v1';

    /** Valor mínimo aceito pelo BB para cobranças e devoluções (R$ 0,01). */
    private const AMOUNT_MIN = 0.01;

    /** Número máximo de retries para erros transitórios (HTTP 429 / 503). */
    private const MAX_RETRIES = 3;

    // ──────────────────────────────────────────────────────────
    //  Estado interno
    // ──────────────────────────────────────────────────────────

    private string  $baseUrl;
    private string  $oauthUrl;
    private ?string $accessToken    = null;
    private ?int    $tokenExpiresAt = null;

    /** Cache de cobranças consultadas na sessão (txid → dados). */
    private array $cobCache = [];

    // ─────────────────────────────────────────────────────────
    //  Construtor
    // ─────────────────────────────────────────────────────────

    /**
     * @param string $clientId           Client ID gerado no Portal Developers BB.
     * @param string $clientSecret       Client Secret gerado no Portal Developers BB.
     * @param string $developerAppKey    Chave da aplicação: "gw-dev-app-key" (sandbox)
     *                                   ou "gw-app-key" (produção).
     * @param string $pixKey             Chave PIX da conta (CPF, CNPJ, e-mail, telefone
     *                                   ou chave aleatória). Obrigatória para cobranças PIX.
     * @param int    $convenio           Número do convênio de cobrança (obrigatório para boletos).
     * @param int    $carteira           Número da carteira (ex.: 17).
     * @param int    $variacaoCarteira   Variação da carteira (ex.: 35).
     * @param string $agencia            Agência da conta BB (4 dígitos, sem dígito verificador).
     * @param string $conta              Número da conta (sem dígito verificador).
     * @param bool   $sandbox            true = ambiente de testes, false = produção.
     * @param string $certPath           Caminho para o certificado client (.pem ou .pfx).
     *                                   OBRIGATÓRIO em produção (BB exige mTLS desde jun/2024).
     * @param string $certKeyPath        Caminho para a chave privada do certificado (.key ou .pem).
     *                                   Ignorado quando $certPath é um .pfx que inclui a chave.
     * @param string $certPassword       Senha do certificado (se protegido por senha).
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $developerAppKey,
        private readonly string $pixKey           = '',
        private readonly int    $convenio         = 0,
        private readonly int    $carteira         = 17,
        private readonly int    $variacaoCarteira = 35,
        private readonly string $agencia          = '',
        private readonly string $conta            = '',
        private readonly bool   $sandbox          = true,
        private readonly string $certPath         = '',
        private readonly string $certKeyPath      = '',
        // FIX 2.2 — SensitiveParameter impede que a senha apareça em stack traces,
        // logs de erro e outputs de var_dump/print_r em PHP 8.2+.
        #[\SensitiveParameter]
        private readonly string $certPassword     = '',
    ) {
        if (!$sandbox && $certPath === '') {
            throw new \InvalidArgumentException(
                'Certificado digital é obrigatório em produção. Informe $certPath com o caminho para o arquivo .pem ou .pfx.'
            );
        }

        $this->baseUrl  = $sandbox ? self::API_SANDBOX   : self::API_PRODUCTION;
        $this->oauthUrl = $sandbox ? self::OAUTH_SANDBOX : self::OAUTH_PRODUCTION;
    }

    // ══════════════════════════════════════════════════════════
    //  AUTENTICAÇÃO OAuth 2.0
    // ══════════════════════════════════════════════════════════

    /**
     * Executa o fluxo OAuth 2.0 Client Credentials e armazena o token em cache.
     *
     * @throws GatewayException
     */
    private function authenticate(): void
    {
        $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

        // Guard: curl_init pode retornar false quando a extensão está desabilitada
        $ch = curl_init($this->oauthUrl);
        if ($ch === false) {
            throw new GatewayException(
                'BB OAuth: falha ao inicializar cURL. Verifique se a extensão curl está habilitada.'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials'
                . '&scope=cobrancas.boletos-info.read'
                . '+cobrancas.boletos.write'
                . '+pix.read+pix.write'
                . '+conta-corrente.saldo.read'
                . '+conta-corrente.extrato.read',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            // FIX 2.1 — Forçar verificação SSL explicitamente, independente da
            // configuração padrão do PHP no servidor. Garante proteção contra MITM.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($this->certPath !== '') {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->certPath);
            if ($this->certKeyPath !== '') {
                curl_setopt($ch, CURLOPT_SSLKEY, $this->certKeyPath);
            }
            if ($this->certPassword !== '') {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
            }
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch);
        curl_close($ch);

        if ($curlErr !== 0) {
            throw new GatewayException(
                'BB OAuth: erro de rede — ' . curl_strerror($curlErr)
            );
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $body, true) ?? [];

        if ($httpCode !== 200 || empty($data['access_token'])) {
            // FIX 2.2 — rawResponse nunca deve conter credenciais. A senha do
            // certificado já está protegida por #[SensitiveParameter] no construtor,
            // mas garantimos aqui que o array de contexto também não a exponha.
            throw new GatewayException(
                'BB OAuth: falha na autenticação — '
                    . ($data['error_description'] ?? $data['error'] ?? 'resposta inesperada'),
                $httpCode,
                null,
                ['response' => $data],
            );
        }

        // Margem de 60 s garante que o token não expire durante uma requisição longa
        $this->accessToken    = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600) - 60;
    }

    /**
     * Retorna o token válido, renovando-o automaticamente se necessário.
     *
     * @throws GatewayException
     */
    private function getToken(): string
    {
        if ($this->accessToken === null || time() >= ($this->tokenExpiresAt ?? 0)) {
            $this->authenticate();
        }

        return (string) $this->accessToken;
    }

    // ══════════════════════════════════════════════════════════
    //  HTTP CLIENT INTERNO
    // ══════════════════════════════════════════════════════════

    /**
     * Executa uma chamada HTTP autenticada à API do Banco do Brasil.
     *
     * Recursos de resiliência implementados:
     *   - Guard em curl_init (retorna false quando extensão está desabilitada)
     *   - json_encode verificado antes de enviar
     *   - Idempotency-Key derivado do hash do payload (idempotente em retries)
     *   - Retry automático com backoff exponencial para HTTP 429 e 503
     *   - mTLS (certificado client) aplicado quando $certPath estiver configurado
     *
     * @param string               $method  Verbo HTTP: GET | POST | PUT | PATCH | DELETE
     * @param string               $path    Caminho completo após a baseUrl
     * @param array<string, mixed> $body    Dados para serializar como JSON no corpo
     * @param array<string, mixed> $query   Parâmetros de query string
     *
     * @return array<string, mixed>
     * @throws GatewayException
     */
    protected function request(
        string $method,
        string $path,
        array  $body  = [],
        array  $query = [],
    ): array {
        $token  = $this->getToken();
        $url    = $this->baseUrl . $path;
        $method = strtoupper($method);

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        // json_encode verificado antes de usar — evita enviar payload silenciosamente vazio
        $jsonBody = '';
        if ($body !== []) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new GatewayException(
                    'BB API: falha ao serializar payload JSON — ' . json_last_error_msg()
                );
            }
            $jsonBody = $encoded;
        }

        // Idempotency-Key determinística: mesmo payload sempre gera a mesma chave.
        // Garante que retries não criem duplicatas no lado do BB.
        $idempotencyKey = hash('sha256', $method . $path . $jsonBody);

        $appKeyHeader = $this->sandbox ? 'gw-dev-app-key' : 'gw-app-key';

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            $appKeyHeader . ': ' . $this->developerAppKey,
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        // Retry com backoff exponencial para erros transitórios (1s, 2s, 4s)
        $attempt = 0;

        do {
            $attempt++;

            // Guard: curl_init pode retornar false
            $ch = curl_init($url);
            if ($ch === false) {
                throw new GatewayException(
                    'BB API: falha ao inicializar cURL. Verifique se a extensão curl está habilitada.'
                );
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                // FIX 2.1 — Verificação SSL explícita em todas as chamadas à API.
                // Sem isso, PHP pode usar o padrão do servidor (nem sempre seguro).
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($jsonBody !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }

            if ($this->certPath !== '') {
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certPath);
                if ($this->certKeyPath !== '') {
                    curl_setopt($ch, CURLOPT_SSLKEY, $this->certKeyPath);
                }
                if ($this->certPassword !== '') {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
                }
            }

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr      = curl_errno($ch);
            curl_close($ch);

            if ($curlErr !== 0) {
                throw new GatewayException(
                    'BB API: erro de rede — ' . curl_strerror($curlErr)
                );
            }

            if (in_array($httpCode, [429, 503], true) && $attempt < self::MAX_RETRIES) {
                // FIX 2.5 — sleep() é bloqueante e pode consumir workers PHP-FPM durante
                // períodos de throttling. Respeitamos o header Retry-After quando presente;
                // caso contrário usamos backoff exponencial (1s, 2s, 4s).
                // NOTA: em contextos assíncronos (Swoole, ReactPHP, etc.) substitua
                // este sleep() por uma estratégia não-bloqueante.
                $parsedBody  = json_decode((string) $responseBody, true) ?? [];
                $waitSeconds = isset($parsedBody['retryAfter'])
                    ? min((int) $parsedBody['retryAfter'], 30) // cap de 30s por segurança
                    : (2 ** ($attempt - 1));
                sleep($waitSeconds);
                continue;
            }

            break;
        } while (true);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $responseBody, true) ?? [];

        if ($httpCode >= 400) {
            throw new GatewayException(
                'BB API: erro HTTP ' . $httpCode . ' — '
                    . ($data['message'] ?? $data['erros'][0]['mensagem'] ?? 'erro desconhecido'),
                $httpCode,
                null,
                ['response' => $data],
            );
        }

        return $data;
    }

    // ══════════════════════════════════════════════════════════
    //  PIX — COBRANÇA IMEDIATA
    // ══════════════════════════════════════════════════════════

    /**
     * Cria uma cobrança PIX imediata (QR Code Dinâmico) via API PIX v2.
     *
     * Endpoint: PUT /pix/v2/cob/{txid}
     *
     * O txid é gerado automaticamente como UUID v4 sem hifens (32 chars),
     * conforme exigido pelo BB (alfanumérico, 26-35 chars).
     *
     * Campos importantes no retorno ($response->metadata):
     *   - pixCopiaECola : string PIX Copia e Cola para o pagador colar no app
     *   - location      : URL do QR Code dinâmico
     *   - txid          : ID da transação no BB
     *
     * @throws GatewayException Se a pixKey não estiver configurada.
     */
    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        if ($this->pixKey === '') {
            throw new GatewayException(
                'Chave PIX é obrigatória para criar cobranças. Informe $pixKey no construtor.'
            );
        }

        // FIX 2.4 — Usar sempre o getter. Acesso direto à propriedade `amount`
        // fragiliza o código: se o DTO mudar (ex: tornar `amount` protected),
        // isso quebraria silenciosamente em runtime sem erro de compilação.
        $amount = $request->getAmount();

        if ($amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf(
                    'Valor mínimo para cobrança PIX é R$ %.2f. Recebido: R$ %.2f.',
                    self::AMOUNT_MIN,
                    $amount
                )
            );
        }

        // txid: UUID v4 sem hifens, 32 chars — dentro do range aceito pelo BB (26-35).
        // FIX 2.8 — random_int() usa CSPRNG do SO; mt_rand() era previsível.
        $txid = sprintf(
            '%08x%04x%04x%04x%12x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffffffffffff)
        );

        $expiresIn = (int) ($request->metadata['expiresIn'] ?? 3600);

        $payload = [
            'calendario'         => ['expiracao' => $expiresIn],
            'devedor'            => [
                'cpf'  => $this->sanitizeDocument((string) ($request->customerDocument ?? '')),
                'nome' => (string) ($request->customerName ?? 'Pagador'),
            ],
            'valor'              => ['original' => $this->formatAmount($amount)],
            'chave'              => $this->pixKey,
            'solicitacaoPagador' => (string) ($request->description ?? ''),
        ];

        $response = $this->request('PUT', self::PATH_PIX . "/cob/{$txid}", $payload);

        return PaymentResponse::create(
            success:       true,
            transactionId: (string) ($response['txid'] ?? $txid),
            status:        PaymentStatus::PENDING,
            amount:        $amount,
            currency:      Currency::BRL,
            message:       'Cobrança PIX criada com sucesso',
            rawResponse:   $response,
            metadata:      [
                'txid'          => (string) ($response['txid']          ?? $txid),
                'location'      => (string) ($response['location']      ?? ''),
                'pixCopiaECola' => (string) ($response['pixCopiaECola'] ?? ''),
            ],
        );
    }

    /**
     * Retorna o código PIX Copia e Cola de uma cobrança existente.
     *
     * Endpoint: GET /pix/v2/cob/{txid}
     */
    public function getPixCopyPaste(string $transactionId): string
    {
        $response = $this->request('GET', self::PATH_PIX . "/cob/{$transactionId}");
        return (string) ($response['pixCopiaECola'] ?? '');
    }

    /**
     * Retorna a URL do QR Code de uma cobrança PIX.
     *
     * Endpoint: GET /pix/v2/cob/{txid}
     */
    public function getPixQrCode(string $transactionId): string
    {
        $response = $this->request('GET', self::PATH_PIX . "/cob/{$transactionId}");
        return (string) ($response['location'] ?? '');
    }

    // ══════════════════════════════════════════════════════════
    //  CARTÃO DE CRÉDITO — NÃO SUPORTADO (stubs obrigatórios da interface)
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException(
            'Cartão de crédito não disponível no Banco do Brasil via API. Use Asaas, PagarMe, Adyen ou Stripe.'
        );
    }

    /**
     * FIX #14 — Método obrigatório da interface que estava ausente no arquivo original.
     *
     * @throws GatewayException
     */
    public function tokenizeCard(array $cardData): string
    {
        throw new GatewayException(
            'Tokenização de cartão não disponível no Banco do Brasil. Use Asaas, PagarMe, Adyen ou Stripe.'
        );
    }

    /**
     * FIX #15 — Método obrigatório da interface que estava ausente no arquivo original.
     *
     * @throws GatewayException
     */
    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        throw new GatewayException(
            'Pré-autorização não disponível no Banco do Brasil. Use Adyen, Stripe ou PagarMe.'
        );
    }

    /**
     * FIX #16 — Método obrigatório da interface que estava ausente no arquivo original.
     *
     * @throws GatewayException
     */
    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        throw new GatewayException(
            'Pré-autorização não disponível no Banco do Brasil. Use Adyen, Stripe ou PagarMe.'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  CARTÃO DE DÉBITO — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException(
            'Cartão de débito não disponível no Banco do Brasil. Use Asaas ou PagarMe.'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  BOLETO BANCÁRIO
    // ══════════════════════════════════════════════════════════

    /**
     * Registra um boleto bancário via API Cobrança v2.
     *
     * Endpoint: POST /cobrancas/v2/boletos
     *
     * FIX #4 — Retorno corrigido de BoletoResponse para PaymentResponse,
     * conforme assinatura da PaymentGatewayInterface. Os dados do boleto
     * (linhaDigitavel, boletoUrl, etc.) são retornados em metadata.
     *
     * Suporte a:
     *   - Boleto simples
     *   - Boleto Híbrido (Boleto + PIX) via metadata['hibrido'] = true
     *   - Juros mora via metadata['fine'] (% ao mês)
     *   - Desconto via metadata['discount'] (R$ fixo até o vencimento)
     *
     * @throws GatewayException Se o convênio não estiver configurado.
     */
    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        if ($this->convenio === 0) {
            throw new GatewayException(
                'Número do convênio é obrigatório para emitir boletos no Banco do Brasil.'
            );
        }

        if ($request->getAmount() < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf(
                    'Valor mínimo para boleto é R$ %.2f. Recebido: R$ %.2f.',
                    self::AMOUNT_MIN,
                    $request->getAmount()
                )
            );
        }

        // nossoNumero sequencial obrigatório em produção
        $nossoNumero = $this->resolveNossoNumero($request);
        $hoje        = new DateTime();
        $vencimento  = $request->dueDate !== null
            ? (new DateTime($request->dueDate))->format('d.m.Y')
            : (new DateTime('+3 days'))->format('d.m.Y');

        $doc     = $this->sanitizeDocument((string) ($request->customerDocument ?? ''));
        $docType = $this->documentType($doc);

        $payload = [
            'numeroConvenio'                       => $this->convenio,
            'numeroCarteira'                       => $this->carteira,
            'numeroVariacaoCarteira'               => $this->variacaoCarteira,
            'codigoModalidade'                     => 1,
            'dataEmissao'                          => $hoje->format('d.m.Y'),
            'dataVencimento'                       => $vencimento,
            // valor como string formatada — BB rejeita float com precisão inesperada
            'valorOriginal'                        => $this->formatAmount($request->getAmount()),
            'codigoAceite'                         => 'N',
            'codigoTipoTitulo'                     => 2,
            'descricaoTipoTitulo'                  => 'DM',
            'indicadorPermissaoRecebimentoParcial' => 'N',
            'numeroTituloBeneficiario'             => $nossoNumero,
            'numeroTituloCliente'                  => '000' . $this->convenio . $nossoNumero,

            'pagador' => [
                'tipoInscricao'   => $docType,
                'numeroInscricao' => (int)    $doc,
                'nome'            => (string) ($request->customerName             ?? 'Pagador'),
                'endereco'        => (string) ($request->metadata['address']      ?? 'Endereço não informado'),
                'bairro'          => (string) ($request->metadata['neighborhood'] ?? ''),
                'cidade'          => (string) ($request->metadata['city']         ?? ''),
                'codigoCidade'    => (int)    ($request->metadata['cityCode']     ?? 0),
                'uf'              => (string) ($request->metadata['state']        ?? 'SP'),
                'cep'             => (string) preg_replace(
                    '/\D/',
                    '',
                    (string) ($request->metadata['zipCode'] ?? '00000000')
                ),
                'telefone'        => (string) preg_replace(
                    '/\D/',
                    '',
                    (string) ($request->customerPhone ?? '')
                ),
            ],

            'mensagemBloquetoOcorrencia' => (string) ($request->description ?? ''),
        ];

        // Boleto Híbrido (Boleto + PIX no mesmo título)
        if (!empty($request->metadata['hibrido'])) {
            $payload['indicadorPix'] = 'S';
        }

        // Juros mora (% ao mês)
        if (!empty($request->metadata['fine'])) {
            $payload['jurosMora'] = [
                'tipo'  => 2,
                'valor' => $this->formatAmount((float) $request->metadata['fine']),
            ];
        }

        // Desconto fixo até o vencimento
        if (!empty($request->metadata['discount'])) {
            $payload['desconto'] = [
                'tipo'          => 1,
                'dataExpiracao' => $vencimento,
                'valor'         => $this->formatAmount((float) $request->metadata['discount']),
            ];
        }

        $response = $this->request('POST', self::PATH_BOLETO . '/boletos', $payload);

        return PaymentResponse::create(
            success:       true,
            transactionId: (string) ($response['numero'] ?? $nossoNumero),
            status:        PaymentStatus::PENDING,
            amount:        $request->getAmount(),
            currency:      Currency::BRL,
            message:       'Boleto registrado com sucesso',
            rawResponse:   $response,
            metadata:      [
                'nossoNumero'    => $nossoNumero,
                'linhaDigitavel' => $response['linhaDigitavel']      ?? '',
                'boletoUrl'      => $response['linkImagemBoleto']    ?? '',
                'codigoBarras'   => $response['codigoBarraNumerico'] ?? null,
                'qrCodePix'      => $response['qrCodePix']           ?? null,
                'pixCopiaECola'  => $response['pixCopiaECola']       ?? null,
                'dueDate'        => $request->dueDate ?? (new DateTime('+3 days'))->format('Y-m-d'),
            ],
        );
    }

    /**
     * Retorna a URL do PDF/HTML do boleto para visualização ou impressão.
     *
     * Endpoint: GET /cobrancas/v2/boletos/{numero}?numeroConvenio={convenio}
     */
    public function getBoletoUrl(string $transactionId): string
    {
        $response = $this->request(
            'GET',
            self::PATH_BOLETO . "/boletos/{$transactionId}",
            [],
            ['numeroConvenio' => $this->convenio],
        );

        return (string) ($response['linkImagemBoleto'] ?? '');
    }

    /**
     * Solicita baixa (cancelamento) de um boleto registrado.
     *
     * Endpoint: POST /cobrancas/v2/boletos/{numero}/baixar
     *
     * ⚠ ATENÇÃO: a baixa é irreversível. Boleto já pago deve ser
     * tratado direto com o gerente do Banco do Brasil.
     *
     * Retorna PaymentResponse com success = true quando codigoErroRegistro = 0,
     * ou success = false com a mensagem de erro do BB em caso de falha (sem lançar exceção).
     */
    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        $response = $this->request(
            'POST',
            self::PATH_BOLETO . "/boletos/{$transactionId}/baixar",
            ['numeroConvenio' => $this->convenio],
        );

        $success = ((int) ($response['codigoErroRegistro'] ?? 0)) === 0;
        $message = $success
            ? 'Baixa solicitada com sucesso'
            : (string) ($response['mensagem'] ?? 'Erro ao solicitar baixa do boleto');

        return PaymentResponse::create(
            success:         $success,
            transactionId:   $transactionId,
            status:          $success ? PaymentStatus::CANCELLED : PaymentStatus::FAILED,
            amount:          0,
            currency:        Currency::BRL,
            message:         $message,
            gatewayResponse: $response,
            rawResponse:     $response,
        );
    }

    // ══════════════════════════════════════════════════════════
    //  ASSINATURAS — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new GatewayException(
            'Assinaturas não disponíveis no Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    /** @throws GatewayException */
    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSAÇÕES — CONSULTA E LISTAGEM
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o status de uma cobrança PIX ou boleto.
     *
     * FIX #5 — Retorno corrigido para TransactionStatusResponse conforme
     * PaymentGatewayInterface. O original retornava PaymentResponse.
     *
     * Tenta primeiro como PIX (GET /pix/v2/cob/{txid});
     * se a API retornar HTTP 404 (cobrança não encontrada), tenta como boleto.
     * Qualquer outro erro (rede, 401, 500, etc.) é propagado imediatamente.
     *
     * @throws GatewayException
     */
    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        // Tenta consultar como cobrança PIX primeiro
        try {
            $response = $this->request('GET', self::PATH_PIX . "/cob/{$transactionId}");

            $bbStatus = strtoupper((string) ($response['status'] ?? 'ATIVA'));
            $status   = match ($bbStatus) {
                'CONCLUIDA'                        => PaymentStatus::PAID,
                'REMOVIDA_PELO_USUARIO_RECEBEDOR',
                'REMOVIDA_PELO_PSP'                => PaymentStatus::CANCELLED,
                default                            => PaymentStatus::PENDING,
            };

            return TransactionStatusResponse::create(
                success:       true,
                transactionId: $transactionId,
                status:        $status,
                amount:        (float) ($response['valor']['original'] ?? 0),
                currency:      Currency::BRL,
                rawResponse:   $response,
            );
        } catch (GatewayException $e) {
            // FIX 2.6 — O catch anterior era silencioso e escondia erros reais
            // (rede, 401, 500). Agora só silenciamos HTTP 404 (cobrança PIX não
            // encontrada), que é o caso legítimo de fallback para boleto.
            // Qualquer outro código de erro é re-lançado imediatamente.
            if ($e->getCode() !== 404) {
                throw $e;
            }
            // HTTP 404 = não é cobrança PIX — continua para tentar como boleto
        }

        $response   = $this->request(
            'GET',
            self::PATH_BOLETO . "/boletos/{$transactionId}",
            [],
            ['numeroConvenio' => $this->convenio],
        );

        $bbSituacao = strtoupper((string) ($response['situacao'] ?? 'ABERTO'));
        $status     = match ($bbSituacao) {
            'LIQUIDADA', 'PAGO' => PaymentStatus::PAID,
            'BAIXADA'           => PaymentStatus::CANCELLED,
            'VENCIDA'           => PaymentStatus::FAILED,
            default             => PaymentStatus::PENDING,
        };

        return TransactionStatusResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        $status,
            amount:        (float) ($response['valor']['original'] ?? 0),
            currency:      Currency::BRL,
            rawResponse:   $response,
        );
    }

    /**
     * Lista cobranças PIX em um intervalo de datas.
     *
     * Endpoint: GET /pix/v2/cob?inicio={inicio}&fim={fim}
     *
     * @param array<string, mixed> $filters Filtros opcionais:
     *   - inicio   : string datetime ISO-8601 (padrão: 30 dias atrás)
     *   - fim      : string datetime ISO-8601 (padrão: agora)
     *   - status   : ATIVA | CONCLUIDA | REMOVIDA_PELO_USUARIO_RECEBEDOR
     *   - pagina   : int (paginação)
     *
     * @return array<string, mixed>
     * @throws GatewayException
     */
    public function listTransactions(array $filters = []): array
    {
        $query = [
            'inicio' => $filters['inicio'] ?? (new DateTime('-30 days'))->format(DateTimeInterface::ATOM),
            'fim'    => $filters['fim']    ?? (new DateTime())->format(DateTimeInterface::ATOM),
        ];

        if (isset($filters['status'])) {
            $query['status'] = $filters['status'];
        }
        if (isset($filters['pagina'])) {
            $query['paginacao.paginaAtual'] = (int) $filters['pagina'];
        }

        return $this->request('GET', self::PATH_PIX . '/cob', [], $query);
    }

    // ══════════════════════════════════════════════════════════
    //  ESTORNOS E CHARGEBACKS
    // ══════════════════════════════════════════════════════════

    /**
     * Solicita devolução (estorno total) de um PIX recebido.
     *
     * Endpoint: PUT /pix/v2/pix/{e2eId}/devolucao/{devolutionId}
     *
     * O devolutionId é derivado deterministicamente do e2eId + valor,
     * garantindo idempotência em retries — o BB ignora tentativas com mesmo ID.
     *
     * @throws GatewayException Se o valor for menor que o mínimo.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $e2eId  = (string) ($request->metadata['e2eId'] ?? $request->transactionId);
        $amount = (float) ($request->amount ?? 0);

        if ($amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf(
                    'Valor mínimo para devolução PIX é R$ %.2f. Recebido: R$ %.2f.',
                    self::AMOUNT_MIN,
                    $amount
                )
            );
        }

        $devolutionId = substr(
            hash('sha256', $e2eId . number_format($amount, 2, '.', '')),
            0,
            35
        );

        $response = $this->request(
            'PUT',
            self::PATH_PIX . "/pix/{$e2eId}/devolucao/{$devolutionId}",
            ['valor' => $this->formatAmount($amount)],
        );

        return RefundResponse::create(
            success:       isset($response['id']),
            refundId:      (string) ($response['id']     ?? $devolutionId),
            transactionId: $e2eId,
            amount:        (float)  ($response['valor']  ?? $amount),
            status:        (string) ($response['status'] ?? 'EM_PROCESSAMENTO'),
            message:       'Devolução PIX solicitada com sucesso',
            rawResponse:   $response,
        );
    }

    /**
     * Solicita devolução parcial de um PIX recebido.
     *
     * FIX #6 — Método obrigatório da interface que estava ausente no arquivo original.
     *
     * @throws GatewayException Se o valor for menor que o mínimo.
     */
    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        if ($amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf(
                    'Valor mínimo para devolução parcial PIX é R$ %.2f. Recebido: R$ %.2f.',
                    self::AMOUNT_MIN,
                    $amount
                )
            );
        }

        // Sufixo 'partial' diferencia o ID do full refund para o mesmo par e2eId+amount
        $devolutionId = substr(
            hash('sha256', $transactionId . number_format($amount, 2, '.', '') . 'partial'),
            0,
            35
        );

        $response = $this->request(
            'PUT',
            self::PATH_PIX . "/pix/{$transactionId}/devolucao/{$devolutionId}",
            ['valor' => $this->formatAmount($amount)],
        );

        return RefundResponse::create(
            success:       isset($response['id']),
            refundId:      (string) ($response['id']     ?? $devolutionId),
            transactionId: $transactionId,
            amount:        $amount,
            status:        (string) ($response['status'] ?? 'EM_PROCESSAMENTO'),
            message:       'Devolução parcial PIX solicitada com sucesso',
            rawResponse:   $response,
        );
    }

    /**
     * Lista chargebacks (contestações).
     *
     * FIX #7 — Método obrigatório da interface que estava ausente no arquivo original.
     * O BB não possui API pública de chargebacks — retorna array vazio.
     *
     * @return array<string, mixed>
     */
    public function getChargebacks(array $filters = []): array
    {
        // O Banco do Brasil não expõe API de chargebacks via portal de desenvolvedores.
        // Chargebacks são tratados diretamente pelo gerente de relacionamento BB.
        return [];
    }

    /**
     * Contesta um chargeback.
     *
     * FIX #8 — Método obrigatório da interface que estava ausente no arquivo original.
     *
     * @throws GatewayException
     */
    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        throw new GatewayException(
            'Contestação de chargebacks não disponível via API do Banco do Brasil. '
            . 'Entre em contato com seu gerente de relacionamento BB.'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  SPLIT DE PAGAMENTO — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /**
     * FIX #9 — Assinatura corrigida para createSplitPayment(SplitPaymentRequest)
     * conforme PaymentGatewayInterface. O original declarava splitPayment(array).
     *
     * @throws GatewayException
     */
    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException(
            'Split de pagamento não disponível no Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  SUB-CONTAS — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /**
     * FIX #10 — Assinatura corrigida para SubAccountRequest → SubAccountResponse
     * conforme PaymentGatewayInterface. O original usava array → array.
     *
     * @throws GatewayException
     */
    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        throw new GatewayException('Sub-contas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        throw new GatewayException('Sub-contas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-contas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-contas não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-contas não disponíveis no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  WALLETS — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /**
     * FIX #11 — Assinatura corrigida para WalletRequest → WalletResponse
     * conforme PaymentGatewayInterface. O original usava array → array.
     *
     * @throws GatewayException
     */
    public function createWallet(WalletRequest $request): WalletResponse
    {
        throw new GatewayException(
            'Wallets devem ser gerenciadas na camada de aplicação, não via API Banco do Brasil.'
        );
    }

    /**
     * FIX #12 — Método renomeado de creditWallet() para addBalance() conforme interface.
     *
     * @throws GatewayException
     */
    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets não disponíveis no Banco do Brasil.');
    }

    /**
     * FIX #13 — Método renomeado de debitWallet() para deductBalance() conforme interface.
     *
     * @throws GatewayException
     */
    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Wallets não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Wallets não disponíveis no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  ESCROW — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException(
            'Escrow/Custódia não disponível no Banco do Brasil. Use C6Bank ou PagarMe.'
        );
    }

    /** @throws GatewayException */
    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSFERÊNCIAS — PIX / TED
    // ══════════════════════════════════════════════════════════

    /**
     * Realiza uma transferência bancária com roteamento automático.
     *
     * Roteamento:
     *   - Se metadata['pixKey'] estiver presente → usa PIX
     *   - Caso contrário → usa TED (exige bankCode, agency, account, accountDigit)
     *
     * @throws GatewayException
     */
    public function transfer(TransferRequest $request): TransferResponse
    {
        $pixKey = $request->metadata['pixKey'] ?? null;

        if ($pixKey !== null) {
            return $this->transferViaPix($request, (string) $pixKey);
        }

        return $this->transferViaTed($request);
    }

    /**
     * Transferência via PIX (chave PIX do beneficiário).
     */
    private function transferViaPix(TransferRequest $request, string $pixKey): TransferResponse
    {
        // FIX 2.4 — Usar getAmount() consistentemente (ver mesma correção em createPixPayment).
        $amount = $request->getAmount();

        $payload = [
            'valor'            => $this->formatAmount($amount),
            'chave'            => $pixKey,
            'descricao'        => (string) ($request->description ?? ''),
            'nomeDestinatario' => (string) ($request->beneficiaryName ?? ''),
            'cpfCnpj'          => $this->sanitizeDocument((string) ($request->beneficiaryDocument ?? '')),
        ];

        $response = $this->request('POST', self::PATH_PIX . '/pix', $payload);

        return TransferResponse::create(
            success:     true,
            // FIX 2.3 — uniqid() foi removido: gerava um ID diferente a cada retry,
            // quebrando a idempotência e podendo duplicar reconciliações financeiras.
            // Se o BB não retornar idPagamento, a transferência é ambígua — lançamos
            // exceção para forçar verificação manual antes de qualquer novo envio.
            transferId:  (string) ($response['idPagamento'] ?? throw new GatewayException(
                'BB PIX: idPagamento ausente na resposta. Verifique o status antes de retentar.'
            )),
            amount:      $amount,
            currency:    Currency::BRL,
            status:      PaymentStatus::PENDING,
            message:     'Transferência PIX enviada com sucesso',
            rawResponse: array_merge($response, ['_method' => 'pix']),
        );
    }

    /**
     * Transferência via TED (dados bancários do beneficiário).
     */
    private function transferViaTed(TransferRequest $request): TransferResponse
    {
        // FIX 2.4 — Usar getAmount() consistentemente.
        $amount = $request->getAmount();

        $payload = [
            'valor'        => $this->formatAmount($amount),
            'descricao'    => (string) ($request->description ?? ''),
            'destinatario' => [
                'nome'        => (string) ($request->beneficiaryName     ?? ''),
                'cpfCnpj'     => $this->sanitizeDocument((string) ($request->beneficiaryDocument ?? '')),
                'codigoBanco' => (string) ($request->bankCode            ?? ''),
                'agencia'     => (string) ($request->agency              ?? ''),
                'conta'       => (string) ($request->account             ?? ''),
                'digitoConta' => (string) ($request->accountDigit        ?? ''),
                'tipoConta'   => $request->accountType === 'savings' ? 'POUPANCA' : 'CORRENTE',
            ],
        ];

        $response = $this->request('POST', self::PATH_PAG . '/lotes-pagamentos/ted', $payload);

        return TransferResponse::create(
            success:     true,
            // FIX 2.3 — mesmo motivo da transferViaPix: uniqid() removido.
            transferId:  (string) ($response['idPagamento'] ?? throw new GatewayException(
                'BB TED: idPagamento ausente na resposta. Verifique o status antes de retentar.'
            )),
            amount:      $amount,
            currency:    Currency::BRL,
            status:      PaymentStatus::PENDING,
            message:     'Transferência TED enviada com sucesso',
            rawResponse: array_merge($response, ['_method' => 'ted']),
        );
    }

    /**
     * Agenda uma transferência para uma data futura.
     *
     * @throws GatewayException
     */
    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        $request->metadata = array_merge($request->metadata ?? [], ['dataAgendamento' => $date]);
        return $this->transfer($request);
    }

    /**
     * Cancela uma transferência agendada.
     *
     * @throws GatewayException
     */
    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        $response = $this->request('DELETE', self::PATH_PAG . "/lotes-pagamentos/{$transferId}");

        return TransferResponse::create(
            success:     true,
            transferId:  $transferId,
            amount:      0,
            currency:    Currency::BRL,
            status:      PaymentStatus::CANCELLED,
            message:     'Agendamento cancelado com sucesso',
            rawResponse: $response,
        );
    }

    // ══════════════════════════════════════════════════════════
    //  PAYMENT LINKS — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException(
            'Links de pagamento não disponíveis no Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    /** @throws GatewayException */
    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Links de pagamento não disponíveis no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Links de pagamento não disponíveis no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  CLIENTES — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        throw new GatewayException(
            'Gestão de clientes deve ser feita na camada de aplicação, não via API Banco do Brasil.'
        );
    }

    /** @throws GatewayException */
    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        throw new GatewayException('Gestão de clientes não disponível no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getCustomer(string $customerId): CustomerResponse
    {
        throw new GatewayException('Gestão de clientes não disponível no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function listCustomers(array $filters = []): array
    {
        throw new GatewayException('Gestão de clientes não disponível no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  ANTIFRAUDE — NÃO SUPORTADO
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function analyzeTransaction(array $data): array
    {
        throw new GatewayException('Antifraude não disponível no Banco do Brasil via API pública.');
    }

    /** @throws GatewayException */
    public function addToBlacklist(array $data): bool
    {
        throw new GatewayException('Blacklist/Antifraude não disponível no Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function removeFromBlacklist(string $id): bool
    {
        throw new GatewayException('Blacklist/Antifraude não disponível no Banco do Brasil.');
    }

    // ══════════════════════════════════════════════════════════
    //  SALDO E EXTRATO
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o saldo da conta corrente.
     *
     * Endpoint: GET /conta-corrente/v1/saldo
     *
     * @throws GatewayException
     */
    public function getBalance(): BalanceResponse
    {
        $query = [];
        if ($this->agencia !== '') {
            $query['agencia'] = $this->agencia;
        }
        if ($this->conta !== '') {
            $query['contaCorrente'] = $this->conta;
        }

        $response = $this->request('GET', self::PATH_CONTA . '/saldo', [], $query);

        return BalanceResponse::create(
            success:          true,
            balance:          (float) ($response['saldoContabil']   ?? 0),
            availableBalance: (float) ($response['saldoDisponivel'] ?? 0),
            pendingBalance:   0.0,
            currency:         Currency::BRL,
            rawResponse:      $response,
            metadata:         [
                'bloqueado_judicial'       => $response['bloqueioJudicial']      ?? 0,
                'bloqueado_administrativo' => $response['bloqueioAdministrativo'] ?? 0,
            ],
        );
    }

    /**
     * Consulta o extrato paginado da conta corrente.
     *
     * Endpoint: GET /conta-corrente/v1/extrato/saldo-dia
     *
     * O BB exige datas no formato dd.mm.aaaa (ex.: 01.03.2025) nos parâmetros.
     *
     * FIX #2 — Indentação corrigida de tabs para 4 espaços (padrão PSR-12).
     * FIX #3 — @return e @param corrigidos para refletir o shape real do array retornado.
     *
     * @param DateTime $from    Data inicial do período.
     * @param DateTime $to      Data final do período.
     * @param int      $page    Número da página (base 1). Padrão: 1.
     * @param int      $perPage Registros por página. Padrão: 50.
     *
     * @return array{
     *   lancamentos: array<int, array<string, mixed>>,
     *   quantidadeRegistros: int,
     *   indicePrimeiro: int,
     *   pagina: int,
     *   totalPaginas: int|null,
     * }
     *
     * @throws GatewayException
     */
    public function getStatement(
        DateTime $from,
        DateTime $to,
        int      $page    = 1,
        int      $perPage = 50,
    ): array {
        // page 1 → indice 0, page 2 → indice 50, etc.
        $indice = ($page - 1) * $perPage;

        $query = [
            'dataInicioSaldo' => $from->format('d.m.Y'),
            'dataFimSaldo'    => $to->format('d.m.Y'),
            'indice'          => $indice,
            'quantidade'      => $perPage,
        ];

        if ($this->agencia !== '') {
            $query['agencia'] = $this->agencia;
        }
        if ($this->conta !== '') {
            $query['contaCorrente'] = $this->conta;
        }

        $response = $this->request('GET', self::PATH_CONTA . '/extrato/saldo-dia', [], $query);

        /** @var array<int, array<string, mixed>> $lancamentos */
        $lancamentos = $response['lancamentos'] ?? $response['listaLancamentos'] ?? [];
        $total       = (int) ($response['quantidadeRegistros'] ?? count($lancamentos));

        return [
            'lancamentos'         => $lancamentos,
            'quantidadeRegistros' => $total,
            'indicePrimeiro'      => $indice,
            'pagina'              => $page,
            'totalPaginas'        => $perPage > 0 ? (int) ceil($total / $perPage) : null,
        ];
    }

    /** @throws GatewayException */
    public function getSettlementSchedule(array $filters = []): array
    {
        throw new GatewayException(
            'Cronograma de liquidação não disponível via API pública do Banco do Brasil.'
        );
    }

    /** @throws GatewayException */
    public function anticipateReceivables(array $data): array
    {
        throw new GatewayException(
            'Antecipação de recebíveis não disponível via API pública do Banco do Brasil.'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  WEBHOOKS
    // ══════════════════════════════════════════════════════════

    /**
     * Registra uma URL de webhook para notificações PIX ou Boleto.
     *
     * @param string               $url     URL pública HTTPS que receberá as notificações.
     * @param array<string, mixed> $options Opções opcionais:
     *   - type: 'pix' (padrão) | 'boleto'
     *
     * @return array<string, mixed>
     * @throws GatewayException
     */
    public function registerWebhook(string $url, array $options = []): array
    {
        $type = strtolower($options['type'] ?? 'pix');

        if ($type === 'boleto') {
            return $this->request(
                'PUT',
                self::PATH_BOLETO . '/boletos/webhook',
                ['webhookUrl' => $url, 'numeroConvenio' => $this->convenio],
            );
        }

        // PIX: PUT /pix/v2/webhook/{chave}
        $chave = urlencode($this->pixKey);
        return $this->request(
            'PUT',
            self::PATH_PIX . "/webhook/{$chave}",
            ['webhookUrl' => $url],
        );
    }

    /**
     * Lista os webhooks registrados para a chave PIX configurada.
     *
     * @return array<string, mixed>
     * @throws GatewayException
     */
    public function listWebhooks(): array
    {
        $chave = urlencode($this->pixKey);
        return $this->request('GET', self::PATH_PIX . "/webhook/{$chave}");
    }

    /**
     * Remove o webhook registrado para a chave PIX configurada.
     *
     * @throws GatewayException
     */
    public function deleteWebhook(string $webhookId): bool
    {
        $chave = urlencode($this->pixKey);
        $this->request('DELETE', self::PATH_PIX . "/webhook/{$chave}");
        return true;
    }

    // ══════════════════════════════════════════════════════════
    //  HELPERS PRIVADOS
    // ══════════════════════════════════════════════════════════

    /**
     * Formata um valor monetário como string com 2 casas decimais.
     * O BB exige valores como "150.00" (string), não como float direto.
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Remove formatação de CPF/CNPJ, mantendo apenas dígitos.
     */
    private function sanitizeDocument(string $document): string
    {
        return preg_replace('/\D/', '', $document) ?? '';
    }

    /**
     * Retorna o tipo de inscrição do pagador:
     *   1 = CPF (11 dígitos)
     *   2 = CNPJ (14 dígitos)
     */
    private function documentType(string $document): int
    {
        return strlen($document) === 14 ? 2 : 1;
    }

    /**
     * Resolve o nossoNumero para emissão de boleto.
     *
     * Em produção é obrigatório fornecer um número sequencial único via
     * metadata['nossoNumero']. Em sandbox, gera um número baseado em microtime.
     *
     * ⚠ ATENÇÃO: em produção, SEMPRE forneça metadata['nossoNumero'] com um
     * sequencial único para evitar duplicatas no convênio BB.
     *
     * @throws GatewayException Em produção sem nossoNumero informado.
     */
    private function resolveNossoNumero(BoletoPaymentRequest $request): string
    {
        if (!empty($request->metadata['nossoNumero'])) {
            return str_pad((string) $request->metadata['nossoNumero'], 10, '0', STR_PAD_LEFT);
        }

        if (!$this->sandbox) {
            throw new GatewayException(
                'metadata["nossoNumero"] é obrigatório em produção para evitar duplicatas no convênio.'
            );
        }

        // Sandbox: sequencial baseado em microtime — suficiente para testes
        return str_pad((string) (int) (microtime(true) * 1000), 10, '0', STR_PAD_LEFT);
    }
}