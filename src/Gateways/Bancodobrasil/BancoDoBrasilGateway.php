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
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BalanceResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BoletoResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\CustomerResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\EscrowResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentLinkResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\RefundResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubscriptionResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\WebhookResponse;
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
 * 5. Produção: registrar certificado no portal (obrigatório desde jun/2024)
 *
 * @see     https://developers.bb.com.br
 * @author  PaymentHub
 * @version 1.1.0
 */
final class BancoDoBrasilGateway implements PaymentGatewayInterface
{
    // ─────────────────────────────────────────────────────────
    //  Endpoints por ambiente
    // ─────────────────────────────────────────────────────────

    private const API_SANDBOX    = 'https://api.sandbox.bb.com.br';
    private const API_PRODUCTION = 'https://api.bb.com.br';

    private const OAUTH_SANDBOX    = 'https://oauth.sandbox.bb.com.br/oauth/token';
    private const OAUTH_PRODUCTION = 'https://oauth.bb.com.br/oauth/token';

    // Prefixos de versão confirmados na documentação oficial (2024–2025):
    //   PIX    → /pix-bb/v2  (v1 encerra 31/03/2026)
    //   Boleto → /cobrancas/v2
    //   Conta  → /conta-corrente/v1
    //   Pagamentos (TED/PIX avulso) → /pagamentos/v1
    private const PATH_PIX    = '/pix-bb/v2';
    private const PATH_BOLETO = '/cobrancas/v2';
    private const PATH_CONTA  = '/conta-corrente/v1';
    private const PATH_PAGTOS = '/pagamentos/v1';

    // Escopos OAuth solicitados em conjunto para um único token
    private const OAUTH_SCOPES = [
        'cob.write',
        'cob.read',
        'pix.write',
        'pix.read',
        'cobrancas.boletos-info.read',
        'cobrancas.boletos.read',
        'cobrancas.boletos.write',
    ];

    // Constantes para tipos de documento nos payloads do BB
    private const DOC_TIPO_CPF  = 1;
    private const DOC_TIPO_CNPJ = 2;

    // Política de retry para erros transitórios (429, 503, timeouts)
    // Espera: 1 s → 2 s → 4 s (backoff exponencial)
    private const RETRY_MAX      = 3;
    private const RETRY_BASE_MS  = 1_000_000; // 1 s em microssegundos

    // Valor mínimo aceito pelo BB (R$ 0,01)
    private const AMOUNT_MIN = 0.01;

    // ─────────────────────────────────────────────────────────
    //  Estado interno
    // ─────────────────────────────────────────────────────────

    private string  $baseUrl;
    private string  $oauthUrl;
    private ?string $accessToken    = null;
    private ?int    $tokenExpiresAt = null;

    /** Cache de cobranças PIX já consultadas: txid → response array. */
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
        private readonly string $certPassword     = '',
    ) {
        if (!$sandbox && $certPath === '') {
            throw new \InvalidArgumentException(
                'Certificado digital é obrigatório em produção. Informe $certPath com o caminho para o arquivo .pem ou .pfx.'
            );
        }

        $this->baseUrl  = $sandbox ? self::API_SANDBOX    : self::API_PRODUCTION;
        $this->oauthUrl = $sandbox ? self::OAUTH_SANDBOX  : self::OAUTH_PRODUCTION;
    }

    // ══════════════════════════════════════════════════════════
    //  AUTENTICAÇÃO OAuth2 — Client Credentials
    // ══════════════════════════════════════════════════════════

    /**
     * Autentica via OAuth2 Client Credentials e armazena o token em cache.
     *
     * O BB exige Basic Auth com base64(clientId:clientSecret) no header,
     * mais grant_type=client_credentials e os escopos no body.
     * O token gerado é reutilizado até 60 segundos antes do vencimento.
     *
     * @throws GatewayException Se a autenticação falhar.
     */
    private function authenticate(): void
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

        $ch = curl_init($this->oauthUrl);

        if ($ch === false) {
            throw new GatewayException('BB OAuth: falha ao inicializar cURL. Verifique se a extensão está habilitada.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'client_credentials',
                'scope'      => implode(' ', self::OAUTH_SCOPES),
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $credentials,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // mTLS: obrigatório em produção, ignorado em sandbox
        if ($this->certPath !== '') {
            $opts[CURLOPT_SSLCERT] = $this->certPath;
            if ($this->certKeyPath !== '') {
                $opts[CURLOPT_SSLKEY] = $this->certKeyPath;
            }
            if ($this->certPassword !== '') {
                $opts[CURLOPT_SSLCERTPASSWD] = $this->certPassword;
            }
        } elseif ($this->sandbox) {
            // Sandbox BB pode usar certificado autoassinado
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
        }

        curl_setopt_array($ch, $opts);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch);
        curl_close($ch);

        if ($curlErr !== 0) {
            throw new GatewayException('BB OAuth: erro cURL — ' . curl_strerror($curlErr));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $body, true) ?? [];

        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new GatewayException(
                'BB OAuth: falha na autenticação — ' . ($data['error_description'] ?? $data['error'] ?? 'resposta inesperada'),
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
    private function request(
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

        // FIX #4 — json_encode verificado antes de usar
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

        // FIX #9 — Idempotency-Key determinística: mesmo payload sempre gera a mesma chave.
        // Garante que retries não criem duplicatas no lado do BB.
        $idempotencyKey = hash('sha256', $method . $path . $jsonBody);

        $appKeyHeader = $this->sandbox ? 'gw-dev-app-key' : 'gw-app-key';

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            $appKeyHeader . ': ' . $this->developerAppKey,
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        // FIX #11 — Retry com backoff exponencial para erros transitórios
        $attempt = 0;

        do {
            $attempt++;

            // FIX #3 — Guard: curl_init pode retornar false
            $ch = curl_init($url);
            if ($ch === false) {
                throw new GatewayException(
                    'BB API: falha ao inicializar cURL. Verifique se a extensão curl está habilitada.'
                );
            }

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];

            // FIX #2 — mTLS: aplica certificado client quando configurado
            if ($this->certPath !== '') {
                $opts[CURLOPT_SSLCERT] = $this->certPath;
                if ($this->certKeyPath !== '') {
                    $opts[CURLOPT_SSLKEY] = $this->certKeyPath;
                }
                if ($this->certPassword !== '') {
                    $opts[CURLOPT_SSLCERTPASSWD] = $this->certPassword;
                }
            } elseif ($this->sandbox) {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
            }

            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $jsonBody;
            } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($jsonBody !== '') {
                    $opts[CURLOPT_POSTFIELDS] = $jsonBody;
                }
            }

            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_errno($ch);
            curl_close($ch);

            if ($curlErr !== 0) {
                // Erros de rede são transitórios — vale retry
                if ($attempt < self::RETRY_MAX) {
                    usleep(self::RETRY_BASE_MS * (2 ** ($attempt - 1)));
                    continue;
                }
                throw new GatewayException('BB API: erro cURL — ' . curl_strerror($curlErr));
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response, true) ?? [];

            // HTTP 429 (rate limit) e 503 (indisponível) são transitórios
            $isTransient = in_array($httpCode, [429, 503], true);

            if ($isTransient && $attempt < self::RETRY_MAX) {
                // Respeita Retry-After se o BB enviar, senão usa backoff padrão
                $retryAfterMs = isset($decoded['retryAfter'])
                    ? (int) $decoded['retryAfter'] * 1_000_000
                    : self::RETRY_BASE_MS * (2 ** ($attempt - 1));

                usleep($retryAfterMs);
                continue;
            }

            if ($httpCode >= 400) {
                $message = $decoded['mensagem']
                    ?? $decoded['message']
                    ?? ($decoded['erros'][0]['mensagem']  ?? null)
                    ?? ($decoded['errors'][0]['message']  ?? null)
                    ?? "Requisição falhou com HTTP {$httpCode}";

                throw new GatewayException((string) $message, $httpCode, null, ['response' => $decoded]);
            }

            return $decoded;

        } while ($attempt < self::RETRY_MAX);

        // Nunca alcançado, mas satisfaz o analisador estático
        throw new GatewayException('BB API: número máximo de tentativas atingido.');
    }

    // ══════════════════════════════════════════════════════════
    //  HELPERS PRIVADOS
    // ══════════════════════════════════════════════════════════

    /**
     * Gera um txId aleatório para cobranças PIX.
     * O BACEN exige entre 26 e 35 caracteres alfanuméricos (a-z, A-Z, 0-9).
     */
    private function generateTxId(): string
    {
        $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = random_int(26, 35);
        $txId   = '';

        for ($i = 0; $i < $length; $i++) {
            $txId .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $txId;
    }

    /**
     * Resolve o nosso número para um boleto.
     *
     * ⚠ ATENÇÃO — CRÍTICO PARA PRODUÇÃO:
     * O BB rejeita duplicatas de nosso número dentro do mesmo convênio.
     * O caller DEVE fornecer um número sequencial único via metadata['nossoNumero'].
     * Use um sequence do banco de dados ou tabela de controle para isso.
     *
     * Apenas em sandbox/desenvolvimento, um número aleatório é gerado como fallback
     * para facilitar testes sem precisar de controle de sequência.
     *
     * @throws GatewayException Em produção, se metadata['nossoNumero'] não for informado.
     */
    private function resolveNossoNumero(BoletoPaymentRequest $request): string
    {
        if (isset($request->metadata['nossoNumero'])) {
            return str_pad((string) $request->metadata['nossoNumero'], 10, '0', STR_PAD_LEFT);
        }

        if (!$this->sandbox) {
            throw new GatewayException(
                'metadata["nossoNumero"] é obrigatório em produção. ' .
                'Use um número sequencial único por convênio (ex.: auto-increment do banco de dados).'
            );
        }

        // Fallback apenas em sandbox — não use em produção
        return str_pad((string) random_int(1, 9_999_999_999), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Remove todos os caracteres não numéricos de um CPF ou CNPJ.
     */
    private function sanitizeDocument(string $document): string
    {
        return preg_replace('/\D/', '', $document) ?? '';
    }

    /**
     * Retorna 1 para CPF ou 2 para CNPJ (formato exigido pelo BB em boletos e TED).
     */
    private function documentType(string $sanitizedDocument): int
    {
        return strlen($sanitizedDocument) === 11 ? self::DOC_TIPO_CPF : self::DOC_TIPO_CNPJ;
    }

    /**
     * Retorna 'cpf' ou 'cnpj' como string (formato exigido pelo BB em PIX).
     */
    private function documentTypeString(string $sanitizedDocument): string
    {
        return strlen($sanitizedDocument) === 11 ? 'cpf' : 'cnpj';
    }

    /**
     * Formata um float como string monetária com 2 casas decimais.
     * A API do BB exige este formato (ex.: "100.50") nos campos de valor.
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Converte os status da API PIX do BB para o enum PaymentStatus do PaymentHub.
     *
     * Status documentados pelo BACEN/BB:
     *   ATIVA                            → aguardando pagamento
     *   CONCLUIDA                        → paga com sucesso
     *   REMOVIDA_PELO_USUARIO_RECEBEDOR  → cancelada pelo beneficiário
     *   REMOVIDA_PELO_PSP                → cancelada pelo banco/PSP
     */
    private function mapPixStatus(string $bbStatus): PaymentStatus
    {
        return match (strtoupper($bbStatus)) {
            'CONCLUIDA'                           => PaymentStatus::APPROVED,
            'REMOVIDA_PELO_USUARIO_RECEBEDOR',
            'REMOVIDA_PELO_PSP'                   => PaymentStatus::CANCELLED,
            default                               => PaymentStatus::PENDING,
        };
    }

    /**
     * Converte os status de boleto da API Cobrança do BB para PaymentStatus.
     */
    private function mapBoletoStatus(string $bbStatus): PaymentStatus
    {
        return match (strtoupper($bbStatus)) {
            'LIQUIDADO', 'PAGO'    => PaymentStatus::APPROVED,
            'VENCIDO'              => PaymentStatus::EXPIRED,
            'BAIXADO', 'CANCELADO' => PaymentStatus::CANCELLED,
            default                => PaymentStatus::PENDING,
        };
    }

    // ══════════════════════════════════════════════════════════
    //  PIX
    // ══════════════════════════════════════════════════════════

    /**
     * Cria uma cobrança PIX imediata (QR Code Dinâmico) via API PIX v2.
     *
     * Endpoint: PUT /pix-bb/v2/cob/{txid}
     *
     * O BB usa PUT com txid definido pelo cliente, diferente de outros bancos
     * que usam POST com txid gerado pelo servidor. Isso permite idempotência:
     * reenviar a mesma requisição com o mesmo txid não cria duplicata.
     *
     * Campos de $request:
     *   amount            (float)  — valor em reais
     *   pixKey            (string) — chave PIX; se vazio, usa $this->pixKey
     *   description       (string) — mensagem para o pagador (solicitacaoPagador)
     *   customerName      (string) — nome do devedor (opcional, mas recomendado)
     *   customerDocument  (string) — CPF ou CNPJ do devedor (opcional)
     *   metadata['expiresIn'] (int) — segundos até expirar (padrão: 86400 = 24 h)
     *
     * @throws GatewayException Se a chave PIX não estiver configurada.
     */
    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        $pixKey = $request->pixKey ?? $this->pixKey;

        if ($pixKey === '') {
            throw new GatewayException(
                'Chave PIX é obrigatória. Informe via $request->pixKey ou no construtor do gateway.'
            );
        }

        // FIX #7 — Valor mínimo
        if ($request->getAmount() < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf('Valor mínimo para cobrança PIX é R$ %.2f. Recebido: R$ %.2f.', self::AMOUNT_MIN, $request->getAmount())
            );
        }

        $txId    = $this->generateTxId();
        $expires = (int) ($request->metadata['expiresIn'] ?? 86400);

        $payload = [
            'calendario' => [
                'expiracao' => $expires,
            ],
            'valor' => [
                'original'            => $this->formatAmount($request->getAmount()),
                'modalidadeAlteracao' => 0, // 0 = pagador não pode alterar o valor
            ],
            'chave'              => $pixKey,
            'solicitacaoPagador' => $request->description ?? 'Pagamento via PIX',
        ];

        // Devedor é opcional; quando informado, nome e documento são obrigatórios juntos
        if ($request->customerName !== null && $request->getCustomerDocument() !== null) {
            $doc     = $this->sanitizeDocument($request->getCustomerDocument());
            $docType = $this->documentTypeString($doc);

            $payload['devedor'] = [
                $docType => $doc,
                'nome'   => $request->customerName,
            ];
        }

        $response      = $this->request('PUT', self::PATH_PIX . "/cob/{$txId}", $payload);
        $txIdRetornado = (string) ($response['txid'] ?? $txId);

        return PaymentResponse::create(
            success:       isset($response['txid']),
            transactionId: $txIdRetornado,
            status:        $this->mapPixStatus((string) ($response['status'] ?? 'ATIVA')),
            amount:        $request->getAmount(),
            currency:      'BRL',
            message:       'Cobrança PIX criada com sucesso',
            rawResponse:   $response,
            metadata:      [
                'txid'          => $txIdRetornado,
                'pixCopiaECola' => $response['pixCopiaECola'] ?? null,
                'qrCode'        => $response['qrCode']        ?? null,
                'location'      => $response['location']      ?? null,
                'revisao'       => (int) ($response['revisao'] ?? 0),
                'status'        => $response['status']        ?? 'ATIVA',
            ],
        );
    }

    /**
     * Busca os dados de uma cobrança PIX com cache em memória.
     *
     * Evita chamadas duplicadas à API quando getPixQrCode() e getPixCopyPaste()
     * são chamados em sequência para o mesmo txid na mesma requisição HTTP.
     *
     * @return array<string, mixed>
     */
    private function fetchCob(string $txid): array
    {
        if (!isset($this->cobCache[$txid])) {
            $this->cobCache[$txid] = $this->request('GET', self::PATH_PIX . "/cob/{$txid}");
        }

        return $this->cobCache[$txid];
    }

    /**
     * Retorna o QR Code (string EMVCo) de uma cobrança PIX pelo txid.
     *
     * Endpoint: GET /pix-bb/v2/cob/{txid}
     *
     * Prioriza 'qrCode' (EMV completo); fallback para 'pixCopiaECola'.
     * Resultado cacheado em memória — chamadas repetidas não geram nova requisição.
     */
    public function getPixQrCode(string $transactionId): string
    {
        $response = $this->fetchCob($transactionId);

        return (string) ($response['qrCode'] ?? $response['pixCopiaECola'] ?? '');
    }

    /**
     * Retorna o código PIX Copia e Cola de uma cobrança.
     *
     * Endpoint: GET /pix-bb/v2/cob/{txid}
     * Resultado cacheado em memória — chamadas repetidas não geram nova requisição.
     */
    public function getPixCopyPaste(string $transactionId): string
    {
        $response = $this->fetchCob($transactionId);

        return (string) ($response['pixCopiaECola'] ?? '');
    }

    /**
     * Solicita a devolução (estorno) de um PIX recebido.
     *
     * Endpoint: PUT /pix-bb/v2/pix/{e2eId}/devolucao/{id}
     *
     * O {e2eId} é o EndToEndId da transação original, disponível no webhook
     * de confirmação de pagamento (campo 'endToEndId').
     *
     * O {id} é gerado por nós: identificador único da devolução (máx. 35 chars).
     * Usando o mesmo {id} em nova tentativa torna a operação idempotente.
     *
     * @param RefundRequest $request
     *   metadata['e2eId'] — EndToEndId da transação original (preferencial)
     *   transactionId     — usado como fallback se e2eId não informado
     *   amount            — valor a devolver (parcial ou total)
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $e2eId  = (string) ($request->metadata['e2eId'] ?? $request->transactionId);
        $amount = (float) ($request->amount ?? 0);

        if ($amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf('Valor mínimo para devolução PIX é R$ %.2f. Recebido: R$ %.2f.', self::AMOUNT_MIN, $amount)
            );
        }

        // FIX #10 — ID determinístico: mesmos e2eId + amount sempre geram o mesmo devolutionId.
        // Garante idempotência em retries — o BB ignora segunda tentativa com mesmo ID.
        $devolutionId = substr(hash('sha256', $e2eId . number_format($amount, 2, '.', '')), 0, 35);

        $payload = [
            'valor' => $this->formatAmount((float) ($request->amount ?? 0)),
        ];

        $response = $this->request(
            'PUT',
            self::PATH_PIX . "/pix/{$e2eId}/devolucao/{$devolutionId}",
            $payload,
        );

        return RefundResponse::create(
            success:       isset($response['id']),
            refundId:      (string) ($response['id']     ?? $devolutionId),
            transactionId: $e2eId,
            amount:        (float)  ($response['valor']  ?? $request->amount ?? 0),
            status:        (string) ($response['status'] ?? 'EM_PROCESSAMENTO'),
            message:       'Devolução PIX solicitada com sucesso',
            rawResponse:   $response,
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
     * Dados do convênio (configurados no construtor):
     *   $convenio, $carteira, $variacaoCarteira
     *
     * Suporte a:
     *   - Boleto simples
     *   - Boleto Híbrido (Boleto + PIX) via metadata['hibrido'] = true
     *   - Juros mora via metadata['fine'] (% ao mês)
     *   - Desconto via metadata['discount'] (R$ fixo até o vencimento)
     *
     * Campos de endereço via $request->metadata:
     *   address, neighborhood, city, cityCode (IBGE int), state, zipCode
     *
     * @throws GatewayException Se o convênio não estiver configurado.
     */
    public function createBoleto(BoletoPaymentRequest $request): BoletoResponse
    {
        if ($this->convenio === 0) {
            throw new GatewayException(
                'Número do convênio é obrigatório para emitir boletos no Banco do Brasil.'
            );
        }

        // FIX #7 — Valor mínimo
        if ($request->getAmount() < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf('Valor mínimo para boleto é R$ %.2f. Recebido: R$ %.2f.', self::AMOUNT_MIN, $request->getAmount())
            );
        }

        // FIX #1 — nossoNumero sequencial obrigatório em produção
        $nossoNumero = $this->resolveNossoNumero($request);
        $hoje        = new DateTime();
        $vencimento  = $request->dueDate !== null
            ? (new DateTime($request->dueDate))->format('d.m.Y')
            : (new DateTime('+3 days'))->format('d.m.Y');

        $doc     = $this->sanitizeDocument((string) ($request->customerDocument ?? ''));
        $docType = $this->documentType($doc);

        $payload = [
            'numeroConvenio'         => $this->convenio,
            'numeroCarteira'         => $this->carteira,
            'numeroVariacaoCarteira' => $this->variacaoCarteira,
            'codigoModalidade'       => 1,
            'dataEmissao'            => $hoje->format('d.m.Y'),
            'dataVencimento'         => $vencimento,

            // FIX #5 — valor como string formatada, não float direto
            'valorOriginal'          => $this->formatAmount($request->getAmount()),

            'codigoAceite'           => 'N',
            'codigoTipoTitulo'       => 2,
            'descricaoTipoTitulo'    => 'DM',
            'indicadorPermissaoRecebimentoParcial' => 'N',
            'numeroTituloBeneficiario' => $nossoNumero,
            'numeroTituloCliente'      => '000' . $this->convenio . $nossoNumero,

            'pagador' => [
                'tipoInscricao'   => $docType,
                'numeroInscricao' => (int)    $doc,
                'nome'            => (string) ($request->customerName             ?? 'Pagador'),
                'endereco'        => (string) ($request->metadata['address']      ?? 'Endereço não informado'),
                'bairro'          => (string) ($request->metadata['neighborhood'] ?? ''),
                'cidade'          => (string) ($request->metadata['city']         ?? ''),
                'codigoCidade'    => (int)    ($request->metadata['cityCode']     ?? 0),
                'uf'              => (string) ($request->metadata['state']        ?? 'SP'),
                'cep'             => (string) preg_replace('/\D/', '', (string) ($request->metadata['zipCode'] ?? '00000000')),
                'telefone'        => (string) preg_replace('/\D/', '', (string) ($request->customerPhone      ?? '')),
            ],

            'mensagemBloquetoOcorrencia' => (string) ($request->description ?? ''),
        ];

        // Juros mora (opcional) — tipo 2 = percentual mensal
        if (!empty($request->metadata['fine'])) {
            $payload['jurosMora'] = [
                'tipo'  => 2,
                'valor' => (float) $request->metadata['fine'],
            ];
        }

        // Desconto (opcional) — tipo 1 = valor fixo até a data de vencimento
        if (!empty($request->metadata['discount'])) {
            $payload['desconto'] = [
                'tipo'          => 1,
                'dataExpiracao' => $vencimento,
                'valor'         => (float) $request->metadata['discount'],
            ];
        }

        // Boleto Híbrido: ativa QR Code PIX embutido no mesmo documento
        $isHibrido = (bool) ($request->metadata['hibrido'] ?? false);
        if ($isHibrido) {
            $payload['indicadorPix'] = 'S';
        }

        $response      = $this->request('POST', self::PATH_BOLETO . '/boletos', $payload);
        $boletoId      = (string) ($response['numero']              ?? $nossoNumero);
        $boletoUrl     = (string) ($response['linkImagemBoleto']    ?? '');
        $barCode       = (string) ($response['codigoBarraNumerico'] ?? '');
        $linhaDigitavel= (string) ($response['linhaDigitavel']      ?? '');

        return BoletoResponse::create(
            success:        isset($response['numero']),
            boletoId:       $boletoId,
            boletoUrl:      $boletoUrl,
            barCode:        $barCode,
            linhaDigitavel: $linhaDigitavel,
            dueDate:        $vencimento,
            amount:         $request->getAmount(),
            status:         PaymentStatus::PENDING,
            message:        $isHibrido
                                ? 'Boleto Híbrido (Boleto + PIX) registrado com sucesso'
                                : 'Boleto registrado com sucesso',
            rawResponse:    $response,
            metadata:       [
                'nossoNumero'    => $nossoNumero,
                'numeroConvenio' => $this->convenio,
                'hibrido'        => $isHibrido,
                'urlPix'         => $response['urlPix']        ?? null,
                'qrCodePix'      => $response['qrCodePix']     ?? null,
                'pixCopiaECola'  => $response['pixCopiaECola'] ?? null,
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
	 * ou success = false com a mensagem de erro do BB em caso de falha.
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
    //  TRANSFERÊNCIAS — PIX / TED
    // ══════════════════════════════════════════════════════════

    /**
     * Realiza uma transferência bancária com roteamento automático.
     *
     * Regra de roteamento:
     *   metadata['pixKey'] informado → PIX (instantâneo)
     *   metadata['pixKey'] ausente   → TED (mesmo dia útil)
     *
     * Campos de $request:
     *   amount              — valor em reais
     *   beneficiaryName     — nome do destinatário
     *   beneficiaryDocument — CPF ou CNPJ do destinatário
     *   description         — finalidade/descrição
     *   metadata['pixKey']  — chave PIX (para roteamento via PIX)
     *   bankCode            — código COMPE do banco destino (para TED)
     *   agency              — agência do banco destino (para TED)
     *   account             — número da conta destino (para TED)
     *   accountDigit        — dígito verificador da conta (para TED)
     *   accountType         — 'checking' | 'savings' (para TED)
     */
    public function transfer(TransferRequest $request): TransferResponse
    {
        $pixKey = (string) ($request->metadata['pixKey'] ?? '');

        return $pixKey !== ''
            ? $this->sendViaPix($request, $pixKey)
            : $this->sendViaTed($request);
    }

    /**
     * Envia um PIX avulso para outra conta via chave PIX.
     *
     * Endpoint: POST /pagamentos/v1/pix
     */
    private function sendViaPix(TransferRequest $request, string $pixKey): TransferResponse
    {
        $doc     = $this->sanitizeDocument((string) ($request->beneficiaryDocument ?? ''));
        $docType = $this->documentTypeString($doc);

        $payload = [
            'valor'        => $this->formatAmount($request->amount),
            'chave'        => $pixKey,
            'descricao'    => $request->description ?? 'Transferência via PIX',
            'destinatario' => [
                $docType => $doc,
                'nome'   => (string) ($request->beneficiaryName ?? ''),
            ],
        ];

        $response = $this->request('POST', self::PATH_PAGTOS . '/pix', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['idPagamento'] ?? $response['id'] ?? uniqid('PIX-BB-', true)),
            amount:      $request->amount,
            status:      strtolower((string) ($response['status'] ?? 'processing')),
            currency:    'BRL',
            message:     'Transferência via PIX iniciada com sucesso',
            rawResponse: array_merge($response, ['_method' => 'pix']),
        );
    }

    /**
     * Envia uma TED (Transferência Eletrônica Disponível).
     *
     * Endpoint: POST /pagamentos/v1/ted
     */
    private function sendViaTed(TransferRequest $request): TransferResponse
    {
        $doc       = $this->sanitizeDocument((string) ($request->beneficiaryDocument ?? ''));
        $docType   = $this->documentType($doc);
        $tipoConta = ($request->accountType ?? 'checking') === 'savings' ? 'POUPANCA' : 'CORRENTE';

        $payload = [
            'valor'        => $this->formatAmount($request->amount),
            'descricao'    => $request->description ?? 'Transferência TED',
            'contaDestino' => [
                'banco'            => (string) ($request->bankCode     ?? '001'),
                'agencia'          => (string) ($request->agency       ?? ''),
                'conta'            => (string) ($request->account      ?? ''),
                'digitoConta'      => (string) ($request->accountDigit ?? ''),
                'tipoContaDestino' => $tipoConta,
            ],
            'beneficiario' => [
                'tipoInscricao'   => $docType,
                'numeroInscricao' => $doc,
                'nome'            => (string) ($request->beneficiaryName ?? ''),
            ],
        ];

        $response = $this->request('POST', self::PATH_PAGTOS . '/ted', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['idPagamento'] ?? $response['id'] ?? uniqid('TED-BB-', true)),
            amount:      $request->amount,
            status:      strtolower((string) ($response['status'] ?? 'processing')),
            currency:    'BRL',
            message:     'Transferência TED iniciada com sucesso',
            rawResponse: array_merge($response, ['_method' => 'ted']),
        );
    }

    /**
     * Agenda uma transferência (PIX ou TED) para uma data futura.
     *
     * O campo 'dataAgendamento' instrui o BB a processar na data informada.
     * O roteamento PIX vs TED segue a mesma lógica do método transfer().
     *
     * @param TransferRequest $request Dados da transferência.
     * @param string          $date    Data alvo no formato YYYY-MM-DD.
     */
    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        $pixKey   = (string) ($request->metadata['pixKey'] ?? '');
        $endpoint = $pixKey !== '' ? '/pix' : '/ted';
        $doc      = $this->sanitizeDocument((string) ($request->beneficiaryDocument ?? ''));

        $payload = [
            'valor'           => $this->formatAmount($request->amount),
            'dataAgendamento' => $date,
            'descricao'       => $request->description ?? 'Transferência agendada',
        ];

        if ($pixKey !== '') {
            $docType = $this->documentTypeString($doc);
            $payload['chave']        = $pixKey;
            $payload['destinatario'] = [
                $docType => $doc,
                'nome'   => (string) ($request->beneficiaryName ?? ''),
            ];
        } else {
            $tipoConta = ($request->accountType ?? 'checking') === 'savings' ? 'POUPANCA' : 'CORRENTE';
            $payload['contaDestino'] = [
                'banco'            => (string) ($request->bankCode     ?? '001'),
                'agencia'          => (string) ($request->agency       ?? ''),
                'conta'            => (string) ($request->account      ?? ''),
                'digitoConta'      => (string) ($request->accountDigit ?? ''),
                'tipoContaDestino' => $tipoConta,
            ];
            $payload['beneficiario'] = [
                'tipoInscricao'   => $this->documentType($doc),
                'numeroInscricao' => $doc,
                'nome'            => (string) ($request->beneficiaryName ?? ''),
            ];
        }

        $response = $this->request('POST', self::PATH_PAGTOS . $endpoint, $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['idPagamento'] ?? uniqid('SCHED-BB-', true)),
            amount:      $request->amount,
            status:      'scheduled',
            currency:    'BRL',
            message:     "Transferência agendada para {$date}",
            rawResponse: $response,
        );
    }

    /**
     * Cancela uma transferência agendada que ainda não foi processada.
     *
     * Endpoint: DELETE /pagamentos/v1/agendamentos/{id}
     */
    public function cancelScheduledTransfer(string $transferId): bool
    {
        $this->request('DELETE', self::PATH_PAGTOS . "/agendamentos/{$transferId}");

        return true;
    }

    // ══════════════════════════════════════════════════════════
    //  SALDO E EXTRATO
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o saldo da conta corrente.
     *
     * Endpoint: GET /conta-corrente/v1/saldo
     *
     * O BB retorna campos separados por tipo de bloqueio:
     *   bloqueadoChequeEspecial, bloqueadoJudicial, bloqueadoAdministrativo.
     * O campo 'disponivel' já desconta todos os bloqueios.
     */
    public function getBalance(): BalanceResponse
    {
        // FIX #8 — Passa agência e conta quando configurados, suportando multi-conta
        $query = [];
        if ($this->agencia !== '') {
            $query['agencia'] = $this->agencia;
        }
        if ($this->conta !== '') {
            $query['contaCorrente'] = $this->conta;
        }

        $response = $this->request('GET', self::PATH_CONTA . '/saldo', [], $query);

        $available = (float) ($response['disponivel']                 ?? 0.0);
        $blocked   = (float) ($response['bloqueadoChequeEspecial']    ?? 0.0)
                   + (float) ($response['bloqueadoJudicial']          ?? 0.0)
                   + (float) ($response['bloqueadoAdministrativo']    ?? 0.0);

        return BalanceResponse::create(
            success:          true,
            availableBalance: $available,
            totalBalance:     $available + $blocked,
            currency:         'BRL',
            rawResponse:      $response,
            metadata:         [
                'saldo_disponivel'          => $available,
                'bloqueado_cheque_especial' => (float) ($response['bloqueadoChequeEspecial']  ?? 0.0),
                'bloqueado_judicial'        => (float) ($response['bloqueadoJudicial']        ?? 0.0),
                'bloqueado_administrativo'  => (float) ($response['bloqueadoAdministrativo']  ?? 0.0),
                'limite_contrato'           => (float) ($response['limiteContrato']           ?? 0.0),
                'utilizacao_limite'         => (float) ($response['utilizacaoLimite']         ?? 0.0),
            ],
        );
    }

    /**
     * Consulta o extrato (lançamentos a crédito e débito) da conta corrente.
     *
     * Endpoint: GET /conta-corrente/v1/extrato/saldo-dia
     *
     * O BB exige datas no formato dd.mm.aaaa (ex.: 01.03.2025) nos parâmetros.
     *
     * @return array<int, array<string, mixed>> Lista de lançamentos do período.
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

    // ══════════════════════════════════════════════════════════
    //  CONSULTAS
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o status de uma cobrança PIX ou boleto.
     *
     * PIX    → GET /pix-bb/v2/cob/{txid}
     * Boleto → GET /cobrancas/v2/boletos/{numero}?numeroConvenio=...
     *
     * @param string $transactionId txid (PIX) ou número do título (boleto)
     * @param string $type          'pix' (padrão) | 'boleto'
     */
    public function getTransactionStatus(string $transactionId, string $type = 'pix'): PaymentResponse
    {
        if ($type === 'boleto') {
            $response = $this->request(
                'GET',
                self::PATH_BOLETO . "/boletos/{$transactionId}",
                [],
                ['numeroConvenio' => $this->convenio],
            );

            return PaymentResponse::create(
                success:       true,
                transactionId: $transactionId,
                status:        $this->mapBoletoStatus((string) ($response['situacao']      ?? '')),
                amount:        (float)  ($response['valorOriginal'] ?? 0.0),
                currency:      'BRL',
                message:       'Boleto consultado com sucesso',
                rawResponse:   $response,
            );
        }

        // PIX (padrão)
        $response = $this->request('GET', self::PATH_PIX . "/cob/{$transactionId}");

        return PaymentResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        $this->mapPixStatus((string) ($response['status']          ?? 'ATIVA')),
            amount:        (float)  ($response['valor']['original'] ?? 0.0),
            currency:      'BRL',
            message:       'Cobrança PIX consultada com sucesso',
            rawResponse:   $response,
        );
    }

    /**
     * Lista cobranças PIX com filtros opcionais.
     *
     * Endpoint: GET /pix-bb/v2/cob
     *
     * Filtros disponíveis (BACEN/BB):
     *   inicio         — RFC3339 (obrigatório, default: -30 dias)
     *   fim            — RFC3339 (obrigatório, default: agora)
     *   status         — ATIVA | CONCLUIDA | REMOVIDA_PELO_USUARIO_RECEBEDOR | REMOVIDA_PELO_PSP
     *   cpf / cnpj     — documento do devedor
     *   paginaAtual    — número da página (default: 0)
     *   itensPorPagina — itens por página (máx. 100)
     *
     * @param  array<string, mixed>             $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(array $filters = []): array
    {
        $query = array_merge(
            [
                'inicio' => (new DateTime('-30 days'))->format(DateTimeInterface::RFC3339),
                'fim'    => (new DateTime())->format(DateTimeInterface::RFC3339),
            ],
            $filters,
        );

        $response = $this->request('GET', self::PATH_PIX . '/cob', [], $query);

        /** @var array<int, array<string, mixed>> $cobranças */
        $cobranças = $response['cobs'] ?? $response['cobranças'] ?? [];

        return $cobranças;
    }

    // ══════════════════════════════════════════════════════════
    //  WEBHOOKS
    // ══════════════════════════════════════════════════════════

    /**
     * Registra URLs de webhook para receber notificações do BB.
     *
     * O BB possui endpoints distintos para cada produto:
     *
     *   PIX    → PUT  /pix-bb/v2/webhook/{chave}
     *             Notifica recebimentos PIX na chave configurada.
     *
     *   Boleto → POST /cobrancas/v2/webhooks
     *             Notifica liquidações de boletos do convênio.
     *
     * A URL deve ser HTTPS e acessível publicamente.
     * Em sandbox o BB envia notificações simuladas.
     *
     * @param string   $url    URL pública (HTTPS) que receberá os POSTs.
     * @param string[] $events Eventos desejados: ['pix', 'boleto']. Vazio = ambos.
     */
    public function registerWebhook(string $url, array $events = []): WebhookResponse
    {
        $registerAll = $events === [];
        $results     = [];

        if ($registerAll || in_array('pix', $events, true)) {
            if ($this->pixKey === '') {
                throw new GatewayException('Chave PIX não configurada. Impossível registrar webhook PIX.');
            }

            $results['pix'] = $this->request(
                'PUT',
                self::PATH_PIX . "/webhook/{$this->pixKey}",
                ['webhookUrl' => $url],
            );
        }

        if (($registerAll || in_array('boleto', $events, true)) && $this->convenio !== 0) {
            $results['boleto'] = $this->request(
                'POST',
                self::PATH_BOLETO . '/webhooks',
                [
                    'numeroConvenio' => $this->convenio,
                    'urlWebhook'     => $url,
                ],
            );
        }

        return WebhookResponse::create(
            success:     true,
            webhookId:   uniqid('BB-WH-', true),
            url:         $url,
            events:      $events === [] ? ['pix', 'boleto'] : $events,
            rawResponse: $results,
        );
    }

    /**
     * Consulta o webhook PIX registrado para a chave configurada.
     *
     * Endpoint: GET /pix-bb/v2/webhook/{chave}
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        if ($this->pixKey === '') {
            return [];
        }

        $response = $this->request('GET', self::PATH_PIX . "/webhook/{$this->pixKey}");

        return [$response];
    }

    /**
     * Remove o webhook PIX da chave configurada.
     *
     * Endpoint: DELETE /pix-bb/v2/webhook/{chave}
     *
     * @throws GatewayException Se a chave PIX não estiver configurada.
     */
    public function deleteWebhook(string $webhookId): bool
    {
        if ($this->pixKey === '') {
            throw new GatewayException('Chave PIX não configurada. Impossível remover webhook.');
        }

        $this->request('DELETE', self::PATH_PIX . "/webhook/{$this->pixKey}");

        return true;
    }

    // ══════════════════════════════════════════════════════════
    //  MÉTODOS NÃO SUPORTADOS PELO BANCO DO BRASIL
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException(
            'Cartão de crédito não disponível no gateway Banco do Brasil. Use Asaas, PagarMe, Adyen ou Stripe.'
        );
    }

    /** @throws GatewayException */
    public function tokenizeCard(array $cardData): string
    {
        throw new GatewayException('Tokenização de cartão não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        throw new GatewayException('Pré-autorização não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Pré-autorização não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException(
            'Cartão de débito não disponível no gateway Banco do Brasil. Use Asaas ou PagarMe.'
        );
    }

    /** @throws GatewayException */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new GatewayException(
            'Assinaturas recorrentes não disponíveis no gateway Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    /** @throws GatewayException */
    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        throw new GatewayException('Assinaturas não disponíveis no gateway Banco do Brasil.');
    }

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
        throw new GatewayException('Gestão de clientes não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getCustomer(string $customerId): CustomerResponse
    {
        throw new GatewayException('Gestão de clientes não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function listCustomers(array $filters = []): array
    {
        throw new GatewayException('Gestão de clientes não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException(
            'Links de pagamento não disponíveis no gateway Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    /** @throws GatewayException */
    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Links de pagamento não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Links de pagamento não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function createSubAccount(array $data): array
    {
        throw new GatewayException('Sub-contas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function updateSubAccount(string $subAccountId, array $data): array
    {
        throw new GatewayException('Sub-contas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getSubAccount(string $subAccountId): array
    {
        throw new GatewayException('Sub-contas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function activateSubAccount(string $subAccountId): array
    {
        throw new GatewayException('Sub-contas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function deactivateSubAccount(string $subAccountId): array
    {
        throw new GatewayException('Sub-contas não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function createWallet(array $data): array
    {
        throw new GatewayException(
            'Wallets devem ser gerenciadas na camada de aplicação, não via API Banco do Brasil.'
        );
    }

    /** @throws GatewayException */
    public function creditWallet(string $walletId, float $amount): array
    {
        throw new GatewayException('Wallets não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function debitWallet(string $walletId, float $amount): array
    {
        throw new GatewayException('Wallets não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Wallets não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Wallets não disponíveis no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException(
            'Escrow/Custódia não disponível no gateway Banco do Brasil. Use C6Bank ou PagarMe.'
        );
    }

    /** @throws GatewayException */
    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function splitPayment(array $data): array
    {
        throw new GatewayException(
            'Split de pagamento não disponível no gateway Banco do Brasil. Use Asaas, PagarMe ou C6Bank.'
        );
    }

    /** @throws GatewayException */
    public function analyzeTransaction(array $data): array
    {
        throw new GatewayException('Antifraude não disponível no gateway Banco do Brasil via API pública.');
    }

    /** @throws GatewayException */
    public function addToBlacklist(array $data): bool
    {
        throw new GatewayException('Blacklist/Antifraude não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function removeFromBlacklist(string $id): bool
    {
        throw new GatewayException('Blacklist/Antifraude não disponível no gateway Banco do Brasil.');
    }

    /** @throws GatewayException */
    public function getAnticipationSchedule(): array
    {
        throw new GatewayException('Antecipação de recebíveis não disponível via API pública do Banco do Brasil.');
    }
}