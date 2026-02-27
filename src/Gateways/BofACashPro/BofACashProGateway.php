<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Gateways\BofACashPro;

use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransactionStatusResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubscriptionResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\RefundResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubAccountResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\WalletResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\EscrowResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentLinkResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\CustomerResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BalanceResponse;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

/**
 * ============================================================
 *  Bank of America — CashPro API Gateway
 * ============================================================
 *
 *  Integração com a plataforma CashPro do Bank of America para
 *  operações bancárias corporativas nos EUA via API REST.
 *
 *  MÉTODOS DE TRANSFERÊNCIA SUPORTADOS
 *  ------------------------------------
 *  | Método | Velocidade   | Limite típico | Reversível |
 *  |--------|-------------|----------------|------------|
 *  | Zelle  | Instantâneo | Negociado BofA | NÃO        |
 *  | ACH    | Same-day/3d | Sem limite real| Sim (antes liquidação) |
 *  | Wire   | Mesmo dia   | Sem limite real| NÃO        |
 *
 *  ROTEAMENTO AUTOMÁTICO (método transfer())
 *  -----------------------------------------
 *  O método transfer() roteia automaticamente com base no valor:
 *    - Até  $3.500  → Zelle  (instantâneo, custo zero)
 *    - Até $50.000  → ACH    (same-day, custo mínimo)
 *    - Acima disso  → Wire   (mesmo dia, taxa fixa)
 *
 *  Os limiares podem ser customizados via construtor.
 *
 *  PRÉ-REQUISITOS
 *  --------------
 *  - Conta corporativa CashPro Online ativa no BofA
 *  - Client ID e Client Secret obtidos via developer.bankofamerica.com
 *  - IPs da aplicação cadastrados no portal (whitelist)
 *  - Licença Money Transmitter (para o modelo de redirecionar fundos)
 *
 *  ONBOARDING
 *  ----------
 *  1. Acesse: https://developer.bankofamerica.com
 *  2. Solicite acesso: GlobalAPIOps@bofa.com
 *  3. Aguarde ~15 dias para receber o Client Secret via Secure Message
 *
 *  REFERÊNCIAS
 *  -----------
 *  @see https://developer.bankofamerica.com
 *  @see BofACashPro_API_Skills.md (documentação de capacidades)
 *
 * @author  PaymentHub
 * @version 1.2.0
 */
class BofACashProGateway implements PaymentGatewayInterface
{
    // ----------------------------------------------------------
    //  URLs base por ambiente
    // ----------------------------------------------------------

    /**
     * URL de produção da CashPro API.
     * Requer credenciais aprovadas pelo BofA.
     */
    private const PRODUCTION_URL = 'https://api.bankofamerica.com/cashpro/v1';

    /**
     * URL de sandbox para desenvolvimento e testes.
     * Não processa transações reais.
     * Disponível após onboarding no Developer Portal.
     */
    private const SANDBOX_URL = 'https://api-sandbox.bankofamerica.com/cashpro/v1';

    /**
     * Endpoint OAuth2 para obtenção de tokens de acesso.
     * Utiliza o fluxo Client Credentials (machine-to-machine).
     */
    private const OAUTH_URL = 'https://api.bankofamerica.com/oauth/token';

    /**
     * Endpoint OAuth2 no ambiente de sandbox.
     */
    private const OAUTH_SANDBOX_URL = 'https://api-sandbox.bankofamerica.com/oauth/token';

    // ----------------------------------------------------------
    //  Limiares de roteamento automático (em USD)
    // ----------------------------------------------------------

    /**
     * Valor máximo (inclusive) para roteamento via Zelle.
     * Acima deste valor, a transferência é roteada para ACH.
     *
     * Zelle: instantâneo, sem custo, mas irreversível.
     * Limite real negociado com o BofA durante onboarding corporativo.
     */
    private const ZELLE_THRESHOLD = 3500.00;

    /**
     * Valor máximo (inclusive) para roteamento via ACH.
     * Acima deste valor, a transferência é roteada para Wire.
     *
     * ACH: same-day ou 1-3 dias úteis, custo mínimo.
     * Reversível antes da liquidação.
     */
    private const ACH_THRESHOLD = 50000.00;

    // ----------------------------------------------------------
    //  Estado interno do gateway
    // ----------------------------------------------------------

    /** URL base resolvida de acordo com o modo sandbox/produção. */
    private string $baseUrl;

    /** URL OAuth resolvida de acordo com o modo sandbox/produção. */
    private string $oauthUrl;

    /** Token de acesso OAuth2 em cache. Renovado automaticamente ao expirar. */
    private ?string $accessToken = null;

    /**
     * Timestamp UNIX em que o token atual expira.
     * Calculado como: time() + expires_in - margem de segurança.
     */
    private ?int $tokenExpiresAt = null;

    // ----------------------------------------------------------
    //  Construtor
    // ----------------------------------------------------------

    /**
     * Instancia o gateway CashPro do Bank of America.
     *
     * @param string $clientId      Client ID obtido no Developer Portal do BofA.
     * @param string $clientSecret  Client Secret recebido via Secure Message (~15 dias após onboarding).
     * @param string $accountId     ID da conta BofA corporativa que receberá/enviará os pagamentos.
     * @param bool   $sandbox       true = ambiente de sandbox (testes), false = produção.
     * @param float  $zelleThreshold  Limite máximo em USD para usar Zelle (padrão: $3.500).
     * @param float  $achThreshold    Limite máximo em USD para usar ACH (padrão: $50.000).
     *
     * Exemplo:
     *   $gateway = new BofACashProGateway(
     *       clientId:     'sua-client-id',
     *       clientSecret: 'seu-client-secret',
     *       accountId:    '123456789',
     *       sandbox:      false,
     *   );
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $accountId,
        private readonly bool   $sandbox = false,
        private readonly float  $zelleThreshold = self::ZELLE_THRESHOLD,
        private readonly float  $achThreshold   = self::ACH_THRESHOLD,
    ) {
        $this->baseUrl  = $sandbox ? self::SANDBOX_URL  : self::PRODUCTION_URL;
        $this->oauthUrl = $sandbox ? self::OAUTH_SANDBOX_URL : self::OAUTH_URL;
    }

    // ==========================================================
    //  AUTENTICAÇÃO OAUTH2
    // ==========================================================

    /**
     * Realiza autenticação via OAuth2 Client Credentials.
     *
     * A CashPro API utiliza o fluxo machine-to-machine padrão:
     *   POST /oauth/token
     *   grant_type=client_credentials
     *   client_id + client_secret
     *
     * O token retornado é armazenado em cache internamente.
     * Uma margem de 60 segundos é subtraída do expires_in para
     * evitar que um token prestes a expirar seja usado em chamadas longas.
     *
     * @throws GatewayException Se a autenticação falhar (credenciais inválidas, IP não cadastrado, etc.)
     */
    private function authenticate(): void
    {
        $ch = curl_init($this->oauthUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new GatewayException('BofA OAuth: cURL error — ' . curl_strerror($curlErr));
        }

        $data = json_decode($body, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new GatewayException(
                'BofA OAuth: authentication failed — ' . ($data['error_description'] ?? $data['error'] ?? 'unknown'),
                $httpCode,
                null,
                ['response' => $data]
            );
        }

        // Cache do token com margem de 60 segundos para evitar uso de token expirado
        $this->accessToken    = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600) - 60;
    }

    /**
     * Retorna o token de acesso vigente, renovando-o automaticamente se necessário.
     *
     * @return string Bearer token válido.
     * @throws GatewayException Se a renovação do token falhar.
     */
    private function getToken(): string
    {
        if (!$this->accessToken || time() >= ($this->tokenExpiresAt ?? 0)) {
            $this->authenticate();
        }

        return $this->accessToken;
    }

    // ==========================================================
    //  HTTP CLIENT INTERNO
    // ==========================================================

    /**
     * Executa uma chamada HTTP autenticada à CashPro API.
     *
     * Utiliza cURL nativo (sem dependências externas), seguindo o
     * padrão dos demais gateways do PaymentHub.
     *
     * FIX-11 (ROB1): Em caso de 401 Unauthorized (token expirado no meio de um request),
     * força renovação do token e tenta uma única vez. Isso cobre o race condition onde
     * o token expira entre getToken() e o momento em que o servidor BofA processa o request.
     *
     * Estratégia de retry para outros erros transitórios (429, 503): não implementada
     * aqui para manter a classe focada. Recomenda-se um decorator/middleware de retry
     * na camada de orquestração (PaymentHub).
     *
     * @param string $method    Verbo HTTP: GET, POST, PUT, DELETE.
     * @param string $endpoint  Caminho relativo à baseUrl (ex: '/payments').
     * @param array  $body      Payload JSON do request (para POST/PUT).
     * @param array  $query     Parâmetros de query string (para GET).
     * @param bool   $_isRetry  Uso interno — previne loop infinito de renovação.
     *
     * @return array Resposta decodificada como array associativo.
     *
     * @throws GatewayException Para erros HTTP (4xx, 5xx) ou falhas de rede.
     */
    private function request(
        string $method,
        string $endpoint,
        array  $body     = [],
        array  $query    = [],
        bool   $_isRetry = false,
    ): array {
        $url = $this->baseUrl . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // FIX-3 (BUG3): json_encode pode retornar false para payloads não-serializáveis.
        // Verificar antes de usar.
        $jsonBody = null;
        if (!empty($body)) {
            $jsonBody = json_encode($body);
            if ($jsonBody === false) {
                throw new GatewayException(
                    'BofA API: failed to encode request body — ' . json_last_error_msg(),
                    0,
                    null,
                    ['endpoint' => $endpoint]
                );
            }
        }

        $ch = curl_init($url);

        // FIX-4 (BUG4): X-Request-ID é gerado DENTRO do bloco de execução (não antes)
        // para garantir que cada tentativa gere um novo ID único.
        // O BofA usa este ID para idempotência e pode rejeitar duplicatas.
        $requestId = $this->generateRequestId();

        $headers = [
            'Authorization: Bearer ' . $this->getToken(),
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Request-ID: ' . $requestId,
        ];

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        match ($method) {
            'POST'   => $curlOpts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $jsonBody],
            'PUT'    => $curlOpts += [CURLOPT_CUSTOMREQUEST => 'PUT',    CURLOPT_POSTFIELDS => $jsonBody],
            'DELETE' => $curlOpts += [CURLOPT_CUSTOMREQUEST => 'DELETE'],
            default  => null,
        };

        curl_setopt_array($ch, $curlOpts);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr      = curl_errno($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new GatewayException('BofA API: cURL error — ' . curl_strerror($curlErr));
        }

        // FIX-2 (BUG2): curl_exec pode retornar false em caso de falha parcial de rede
        // onde curl_errno() retorna 0. Verificar explicitamente antes de json_decode.
        if ($responseBody === false) {
            throw new GatewayException(
                'BofA API: empty response from server (possible network error)',
                0,
                null,
                ['endpoint' => $endpoint, 'httpCode' => $httpCode]
            );
        }

        $decoded = json_decode($responseBody, true) ?? [];

        if ($httpCode >= 400) {
            // FIX-11 (ROB1): 401 pode significar que o token expirou enquanto o request
            // estava em trânsito (race condition entre o cache e o servidor BofA).
            // Força renovação e tenta uma única vez. Se falhar novamente, lança exceção.
            if ($httpCode === 401 && !$_isRetry) {
                $this->accessToken    = null; // Invalida cache de token
                $this->tokenExpiresAt = null;
                return $this->request($method, $endpoint, $body, $query, _isRetry: true);
            }

            throw new GatewayException(
                'BofA API error: ' . ($decoded['message'] ?? $decoded['error'] ?? 'unknown'),
                $httpCode,
                null,
                ['endpoint' => $endpoint, 'response' => $decoded]
            );
        }

        return $decoded;
    }

    // ==========================================================
    //  TRANSFERÊNCIAS — NÚCLEO DO GATEWAY
    // ==========================================================

    /**
     * Roteador inteligente de transferências.
     *
     * Seleciona automaticamente o método de pagamento mais adequado
     * com base no valor da transferência:
     *
     *   ≤ $zelleThreshold  →  sendZelle()   (instantâneo, custo zero)
     *   ≤ $achThreshold    →  sendACH()     (same-day, custo mínimo)
     *   > $achThreshold    →  sendWire()    (mesmo dia, taxa fixa ~$25-$35)
     *
     * CAMPOS NECESSÁRIOS no TransferRequest por método:
     * --------------------------------------------------
     *  Zelle: recipientEmail OU recipientPhone (via metadata)
     *  ACH:   routingNumber + accountNumber + accountType + recipientName (via metadata)
     *  Wire:  routingNumber + accountNumber + recipientName + bankName (via metadata)
     *
     * EXEMPLO DE USO:
     * ---------------
     *   // Saque de $500 — vai via Zelle automaticamente
     *   $request = TransferRequest::create(
     *       amount: 500.00,
     *       recipientName: 'John Doe',
     *       description: 'Saque #12345',
     *       metadata: [
     *           'recipientEmail' => 'john@example.com',
     *           'memo'           => 'Withdrawal REF-12345',
     *       ]
     *   );
     *
     *   // Saque de $15.000 — vai via ACH automaticamente
     *   $request = TransferRequest::create(
     *       amount: 15000.00,
     *       recipientName: 'Jane Doe',
     *       description: 'Saque #67890',
     *       metadata: [
     *           'routingNumber'  => '021000021',
     *           'accountNumber'  => '123456789',
     *           'accountType'    => 'checking',
     *           'memo'           => 'Withdrawal REF-67890',
     *       ]
     *   );
     *
     * @param TransferRequest $request DTO de transferência do PaymentHub.
     * @return TransferResponse Resultado da transferência com ID, status e método usado.
     *
     * @throws GatewayException Se o método roteado não tiver os dados necessários.
     * @throws GatewayException Se a API do BofA retornar erro.
     */
    public function transfer(TransferRequest $request): TransferResponse
    {
        $amount = $request->getAmount();

        return match (true) {
            $amount <= $this->zelleThreshold => $this->sendZelle($request),
            $amount <= $this->achThreshold   => $this->sendACH($request),
            default                          => $this->sendWire($request),
        };
    }

    // ----------------------------------------------------------
    //  Zelle
    // ----------------------------------------------------------

    /**
     * Envia uma transferência instantânea via Zelle.
     *
     * SOBRE O ZELLE
     * -------------
     * - Instantâneo: fundos disponíveis em segundos para o destinatário.
     * - Opera 24/7/365, incluindo fins de semana e feriados.
     * - Custo: zero para contas corporativas CashPro.
     * - IRREVERSÍVEL após o envio — valide os dados antes de chamar.
     * - Destinatário precisa ter Zelle ativo no banco dele.
     * - Identificação: usa e-mail OU telefone (não ambos).
     *
     * CAMPOS OBRIGATÓRIOS via $request->metadata:
     *   'recipientEmail' string  E-mail cadastrado no Zelle pelo destinatário.
     *   'recipientPhone' string  Telefone no formato E.164 (ex: +15555551234).
     *                           Forneça email OU phone — pelo menos um.
     *
     * CAMPOS OPCIONAIS via $request->metadata:
     *   'memo'  string  Mensagem visível para o destinatário (até 140 chars).
     *                   IMPORTANTE: Use este campo para identificar o cliente
     *                   dentro da sua plataforma (ex: "REF-USER-7890").
     *
     * LIMITAÇÕES
     * ----------
     * - Limite por transação: negociado diretamente com o BofA no onboarding.
     * - Destinatário sem Zelle ativo receberá um convite por e-mail/SMS do Zelle.
     *   O dinheiro fica retido por 14 dias; se não aceito, é devolvido.
     *
     * @param TransferRequest $request DTO com amount (USD) e metadata com email/phone.
     * @return TransferResponse Com transferId, status 'processing' e method='zelle'.
     *
     * @throws GatewayException Se nem email nem telefone forem informados no metadata.
     * @throws GatewayException Se a API retornar erro (destinatário inválido, limite excedido, etc.).
     */
    public function sendZelle(TransferRequest $request): TransferResponse
    {
        $meta  = $request->metadata ?? [];
        $email = $meta['recipientEmail'] ?? null;
        $phone = $meta['recipientPhone'] ?? null;

        if (!$email && !$phone) {
            throw new GatewayException(
                'Zelle transfer requires recipientEmail or recipientPhone in metadata.'
            );
        }

        // FIX-6 (SEC1): Validar formato do email para evitar envio para endereço malformado.
        // filter_var com FILTER_VALIDATE_EMAIL rejeita strings malformadas antes de chamar a API.
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GatewayException(
                "Zelle transfer: invalid recipientEmail format '{$email}'. " .
                'Must be a valid email address (e.g. user@example.com).'
            );
        }

        // Validação básica de telefone E.164 (+15555551234)
        if ($phone !== null && !preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            throw new GatewayException(
                "Zelle transfer: invalid recipientPhone format '{$phone}'. " .
                'Must be E.164 format (e.g. +15555551234).'
            );
        }

        $payload = array_filter([
            'paymentType'       => 'ZELLE',
            'amount'            => $request->getAmount(),
            'currency'          => 'USD',
            'debitAccountId'    => $this->accountId,
            'recipientEmail'    => $email,
            'recipientPhone'    => $phone,
            'memo'              => $meta['memo'] ?? $request->description,
            // clientReferenceId garante idempotência — o BofA rejeita duplicatas com o mesmo ID
            'clientReferenceId' => $this->generateRequestId(),
        ]);

        $response = $this->request('POST', '/payments/zelle', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  $response['paymentId'] ?? $response['id'],
            amount:      $request->getAmount(),
            status:      strtolower($response['status'] ?? 'processing'),
            currency:    'USD',
            message:     'Zelle transfer initiated successfully',
            rawResponse: array_merge($response, ['_method' => 'zelle']),
        );
    }

    // ----------------------------------------------------------
    //  ACH (Automated Clearing House)
    // ----------------------------------------------------------

    /**
     * Envia uma transferência via ACH (Automated Clearing House).
     *
     * SOBRE O ACH
     * -----------
     * - Padrão de transferência bancária nos EUA.
     * - Same-Day ACH: liquidação no mesmo dia útil (se enviado antes do cutoff).
     * - ACH Standard: liquidação em 1-3 dias úteis.
     * - Custo: inferior ao Wire, geralmente centavos por transação.
     * - REVERSÍVEL: pode ser cancelado antes da liquidação.
     * - Sem limite prático de valor para contas corporativas.
     * - Requer dados bancários completos do destinatário (routing + account).
     *
     * CAMPOS OBRIGATÓRIOS via $request->metadata:
     *   'routingNumber'  string  Routing number do banco do destinatário (9 dígitos).
     *   'accountNumber'  string  Número da conta bancária do destinatário.
     *   'accountType'    string  Tipo da conta: 'checking' ou 'savings'.
     *
     * CAMPOS OPCIONAIS via $request->metadata:
     *   'sameDay'        bool    true = Same-Day ACH (padrão: true para maior velocidade).
     *   'effectiveDate'  string  Data de liquidação no formato YYYY-MM-DD.
     *                            Ignorado se sameDay = true.
     *   'memo'           string  Descrição da transferência (aparece no extrato).
     *   'companyEntryDesc' string  Identificador do remetente (até 10 chars) exibido no extrato do destinatário.
     *
     * SOBRE O CUTOFF SAME-DAY ACH
     * ----------------------------
     * O BofA tem dois cutoffs diários para Same-Day ACH (horário Eastern):
     *   - 10h30 ET → liquidação às 13h ET
     *   - 14h45 ET → liquidação às 17h ET
     * Envios após 14h45 ET são processados no próximo dia útil como Standard ACH.
     *
     * @param TransferRequest $request DTO com amount (USD) e metadata com dados bancários.
     * @return TransferResponse Com transferId, status e method='ach'.
     *
     * @throws GatewayException Se routingNumber, accountNumber ou accountType não forem informados.
     * @throws GatewayException Se a API retornar erro (routing inválido, conta encerrada, etc.).
     */
    public function sendACH(TransferRequest $request): TransferResponse
    {
        $meta = $request->metadata ?? [];

        foreach (['routingNumber', 'accountNumber', 'accountType'] as $required) {
            if (empty($meta[$required])) {
                throw new GatewayException(
                    "ACH transfer requires '{$required}' in metadata."
                );
            }
        }

        // FIX-7 (SEC2): accountNumber com comprimento mínimo de 4 dígitos.
        // Strings vazias, "0", " " passariam o empty() check mas são inválidas.
        $accountNumber = trim((string) $meta['accountNumber']);
        if (strlen($accountNumber) < 4) {
            throw new GatewayException(
                'ACH transfer: accountNumber must be at least 4 characters.'
            );
        }

        // FIX-7 (SEC3): accountType deve ser 'checking' ou 'savings' (case-insensitive).
        // Qualquer outro valor como "current", "poupanca" seria enviado ao BofA e resultaria
        // em erro genérico difícil de debugar.
        $accountType = strtolower(trim((string) $meta['accountType']));
        if (!in_array($accountType, ['checking', 'savings'], true)) {
            throw new GatewayException(
                "ACH transfer: invalid accountType '{$meta['accountType']}'. " .
                "Must be 'checking' or 'savings'."
            );
        }

        // FIX-7 (SEC2): Validação básica de routing number (9 dígitos)
        $routingNumber = preg_replace('/\D/', '', (string) $meta['routingNumber']);
        if (strlen($routingNumber) !== 9) {
            throw new GatewayException(
                "ACH transfer: routingNumber must be exactly 9 digits. Got: '{$meta['routingNumber']}'"
            );
        }

        $sameDay = $meta['sameDay'] ?? true;

        $payload = array_filter([
            'paymentType'          => $sameDay ? 'ACH_SAME_DAY' : 'ACH_STANDARD',
            'amount'               => $request->getAmount(),
            'currency'             => 'USD',
            'debitAccountId'       => $this->accountId,
            'recipientName'        => $request->recipientName,
            'recipientRouting'     => $routingNumber,
            'recipientAccount'     => $accountNumber,
            'recipientAccountType' => strtoupper($accountType), // CHECKING | SAVINGS
            'effectiveDate'        => !$sameDay ? ($meta['effectiveDate'] ?? null) : null,
            'memo'                 => $meta['memo'] ?? $request->description,
            'companyEntryDesc'     => substr($meta['companyEntryDesc'] ?? 'PAYMENTHUB', 0, 10),
            'clientReferenceId'    => $this->generateRequestId(),
        ]);

        $response = $this->request('POST', '/payments/ach', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  $response['paymentId'] ?? $response['id'],
            amount:      $request->getAmount(),
            status:      strtolower($response['status'] ?? 'processing'),
            currency:    'USD',
            message:     ($sameDay ? 'Same-Day' : 'Standard') . ' ACH transfer initiated',
            rawResponse: array_merge($response, ['_method' => 'ach', '_sameDay' => $sameDay]),
        );
    }

    // ----------------------------------------------------------
    //  Wire Transfer (Fedwire)
    // ----------------------------------------------------------

    /**
     * Envia uma transferência de alto valor via Wire (Fedwire).
     *
     * SOBRE O WIRE
     * ------------
     * - Fedwire: rede de liquidação bruta em tempo real do Federal Reserve (EUA).
     * - Liquidação garantida no mesmo dia útil se enviado antes do cutoff.
     * - IRREVERSÍVEL após o envio — solicitar devolução depende da cooperação do banco receptor.
     * - Sem limite prático de valor.
     * - Custo: taxa fixa por transação (~$25-$35 para domestic Wire).
     * - Destinatário não precisa de app ou cadastro específico — apenas conta bancária.
     * - Ideal para valores acima de $50.000.
     *
     * CAMPOS OBRIGATÓRIOS via $request->metadata:
     *   'routingNumber'   string  ABA routing number do banco receptor (9 dígitos).
     *   'accountNumber'   string  Número da conta bancária do destinatário.
     *   'bankName'        string  Nome do banco receptor.
     *
     * CAMPOS OPCIONAIS via $request->metadata:
     *   'bankAddress'     string  Endereço do banco receptor (exigido por alguns bancos).
     *   'memo'            string  OBI (Originator to Beneficiary Information) — até 140 chars.
     *                             Aparece no extrato do destinatário. Use para identificar o cliente.
     *
     * CUTOFF FEDWIRE
     * --------------
     * O Fedwire opera das 21h ET (domingo) às 18h30 ET (sexta).
     * BofA geralmente tem cutoff interno às 17h ET.
     * Wires recebidos após o cutoff são processados no próximo dia útil.
     *
     * WIRE INTERNACIONAL (SWIFT)
     * --------------------------
     * Para pagamentos internacionais, use o método sendSwiftWire() (não implementado nesta versão).
     * Requer: SWIFT/BIC code, IBAN ou account number, e potencialmente um banco intermediário.
     *
     * @param TransferRequest $request DTO com amount (USD) e metadata com dados bancários.
     * @return TransferResponse Com transferId, status e method='wire'.
     *
     * @throws GatewayException Se routingNumber, accountNumber ou bankName não forem informados.
     * @throws GatewayException Se a API retornar erro (routing inválido, cutoff ultrapassado, etc.).
     */
    public function sendWire(TransferRequest $request): TransferResponse
    {
        $meta = $request->metadata ?? [];

        foreach (['routingNumber', 'accountNumber', 'bankName'] as $required) {
            if (empty($meta[$required])) {
                throw new GatewayException(
                    "Wire transfer requires '{$required}' in metadata."
                );
            }
        }

        $payload = array_filter([
            'paymentType'       => 'WIRE',
            'amount'            => $request->getAmount(),
            'currency'          => 'USD',
            'debitAccountId'    => $this->accountId,
            'beneficiaryName'   => $request->recipientName,
            'beneficiaryRouting'=> $meta['routingNumber'],
            'beneficiaryAccount'=> $meta['accountNumber'],
            'beneficiaryBank'   => $meta['bankName'],
            'beneficiaryBankAddress' => $meta['bankAddress'] ?? null,
            // OBI: mensagem que aparece no extrato do destinatário (Originator to Beneficiary Info)
            'obi'               => substr($meta['memo'] ?? $request->description ?? '', 0, 140),
            'clientReferenceId' => $this->generateRequestId(),
        ]);

        $response = $this->request('POST', '/payments/wire', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  $response['paymentId'] ?? $response['id'],
            amount:      $request->getAmount(),
            status:      strtolower($response['status'] ?? 'processing'),
            currency:    'USD',
            message:     'Wire transfer initiated successfully',
            rawResponse: array_merge($response, ['_method' => 'wire']),
        );
    }

    // ----------------------------------------------------------
    //  Agendamento e Cancelamento (ACH)
    // ----------------------------------------------------------

    /**
     * Agenda uma transferência para uma data futura.
     *
     * SUPORTE POR MÉTODO
     * ------------------
     * - ACH Standard: suporta agendamento. O campo effectiveDate define a data de liquidação.
     * - Zelle: NÃO suporta agendamento — é sempre instantâneo.
     * - Wire: NÃO suporta agendamento via API — use o CashPro Online para agendamentos manuais.
     *
     * O método determina automaticamente o tipo de transferência pelo valor e
     * injeta a effectiveDate no metadata antes de chamar sendACH().
     *
     * @param TransferRequest $request DTO de transferência.
     * @param string          $date    Data de liquidação no formato YYYY-MM-DD.
     * @return TransferResponse Com status 'scheduled'.
     *
     * @throws GatewayException Se o valor exceder o threshold do ACH (Wire não suporta agendamento).
     * @throws GatewayException Se a data for inválida ou no passado.
     */
    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        $amount = $request->getAmount();

        if ($amount > $this->achThreshold) {
            throw new GatewayException(
                'Wire transfers cannot be scheduled via API. Use ACH for scheduled transfers or CashPro Online for Wire.'
            );
        }

        // FIX-1 (BUG1): TransferRequest::__construct() exige recipientId||pixKey||bankCode.
        // O BofA usa apenas metadata para dados bancários, então todos esses campos
        // podem ser null no request original. Ao clonar com `new TransferRequest()`,
        // o construtor lançaria InvalidArgumentException.
        //
        // Solução: injetar effectiveDate/sameDay diretamente no metadata do request original
        // via TransferRequest::create() que bypassa a validação por receber os mesmos campos
        // que o request original já passou.
        $scheduledMetadata = array_merge($request->metadata ?? [], [
            'sameDay'       => false,
            'effectiveDate' => $date,
        ]);

        // Recria usando o factory method passando todos os campos do request original.
        // O factory method usa os mesmos campos que o request original já validou,
        // garantindo que a validação do construtor passe exatamente como na criação original.
        $scheduledRequest = TransferRequest::create(
            amount:         $request->getAmount(),
            currency:       $request->getCurrency(),
            recipientId:    $request->recipientId,
            bankCode:       $request->bankCode,
            agencyNumber:   $request->agencyNumber,
            accountNumber:  $request->accountNumber,
            accountType:    $request->accountType,
            documentNumber: $request->documentNumber,
            recipientName:  $request->recipientName,
            pixKey:         $request->pixKey,
            description:    $request->description,
            metadata:       $scheduledMetadata,
        );

        return $this->sendACH($scheduledRequest);
    }

    /**
     * Cancela uma transferência ACH agendada ainda não liquidada.
     *
     * JANELA DE CANCELAMENTO
     * ----------------------
     * - ACH Same-Day: janela muito curta (minutos). Use apenas se acabou de enviar.
     * - ACH Standard: pode ser cancelado até o início do processamento na data de liquidação.
     * - Zelle/Wire: IRREVERSÍVEIS — esta chamada lançará exceção nesses casos.
     *
     * O BofA retorna erro se o pagamento já foi liquidado ou está em processamento final.
     *
     * @param string $transferId  ID retornado no campo transferId do TransferResponse original.
     * @return TransferResponse Com status 'cancelled'.
     *
     * @throws GatewayException Se o pagamento já foi liquidado ou não pode ser cancelado.
     * @throws GatewayException Se o transferId não existir.
     */
    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        // FIX-8 (SEC5): String vazia resultaria em DELETE /payments/ — poderia deletar
        // recurso errado ou retornar 404/400 genérico difícil de debugar.
        if (trim($transferId) === '') {
            throw new GatewayException(
                'cancelScheduledTransfer: transferId cannot be empty.'
            );
        }

        $response = $this->request('DELETE', "/payments/{$transferId}");

        return TransferResponse::create(
            success:     true,
            transferId:  $transferId,
            amount:      null,
            status:      'cancelled',
            currency:    'USD',
            message:     'Transfer cancelled successfully',
            rawResponse: $response,
        );
    }

    // ==========================================================
    //  CONSULTAS
    // ==========================================================

    /**
     * Consulta o status atual de uma transação.
     *
     * POSSÍVEIS STATUS RETORNADOS PELO BOFA
     * --------------------------------------
     *   PENDING     → Recebido, aguardando processamento.
     *   PROCESSING  → Em processamento pela rede (Zelle/Fedwire).
     *   COMPLETED   → Liquidado com sucesso.
     *   FAILED      → Falhou (destinatário inválido, limite excedido, etc.).
     *   CANCELLED   → Cancelado antes da liquidação.
     *   RETURNED    → ACH devolvido pelo banco receptor (conta encerrada, etc.).
     *
     * @param string $transactionId  Payment ID retornado no TransferResponse ou PaymentResponse.
     * @return TransactionStatusResponse Com status, timestamps e dados do pagamento.
     *
     * @throws GatewayException Se o transactionId não existir.
     */
    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        $response = $this->request('GET', "/payments/{$transactionId}");

        return TransactionStatusResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        strtolower($response['status'] ?? 'pending'),
            amount:        isset($response['amount']) ? (float) $response['amount'] : null,
            currency:      'USD',
            rawResponse:   $response,
        );
    }

    /**
     * Lista transações da conta com filtros opcionais.
     *
     * FILTROS SUPORTADOS (via $filters)
     * -----------------------------------
     *   'startDate'    string  Data inicial no formato YYYY-MM-DD.
     *   'endDate'      string  Data final no formato YYYY-MM-DD.
     *   'paymentType'  string  Filtrar por tipo: ZELLE, ACH_SAME_DAY, ACH_STANDARD, WIRE.
     *   'status'       string  Filtrar por status: PENDING, COMPLETED, FAILED, etc.
     *   'minAmount'    float   Valor mínimo da transação.
     *   'maxAmount'    float   Valor máximo da transação.
     *   'page'         int     Número da página (paginação, padrão: 1).
     *   'pageSize'     int     Itens por página (padrão: 50, máximo: 500).
     *
     * USO PRINCIPAL NA FINTECH
     * ------------------------
     * Listar transações é o mecanismo de fallback para reconciliação quando
     * um webhook não foi recebido. Comparar com registros internos para
     * identificar Zelles recebidos não processados.
     *
     * @param array $filters Filtros opcionais conforme documentado acima.
     * @return array Lista de transações. Cada item segue o formato da CashPro API.
     *
     * @throws GatewayException Se os filtros de data forem inválidos.
     */
    public function listTransactions(array $filters = []): array
    {
        $query = array_filter(array_merge([
            'accountId' => $this->accountId,
        ], $filters));

        $response = $this->request('GET', '/accounts/transactions', query: $query);

        // FIX-9 (ROB4): A CashPro API retorna uma resposta paginada com envelope.
        // Retornar $response inteiro exporia metadados de paginação como se fossem
        // transações. Extrair o array de transações com fallback para o payload inteiro
        // se a estrutura não tiver o envelope esperado.
        return $response['transactions'] ?? $response['payments'] ?? $response['data'] ?? $response;
    }

    /**
     * Consulta o saldo disponível e contábil da conta corporativa.
     *
     * TIPOS DE SALDO
     * --------------
     *   availableBalance → Saldo disponível para uso imediato (após retenções e saques pendentes).
     *   ledgerBalance    → Saldo contábil total (pode incluir depósitos ainda não liberados).
     *   openingBalance   → Saldo no início do dia útil corrente.
     *
     * FREQUÊNCIA RECOMENDADA
     * ----------------------
     * Não consulte saldo em loop — use Push Notifications para monitoramento em tempo real.
     * Consulta pontual antes de autorizar saques grandes é uma boa prática.
     *
     * @return BalanceResponse Com availableBalance, ledgerBalance e currency (USD).
     *
     * @throws GatewayException Se a conta não for encontrada ou o token não tiver permissão.
     */
    public function getBalance(): BalanceResponse
    {
        $response = $this->request('GET', "/accounts/{$this->accountId}/balances");

        return new BalanceResponse(
            success:          true,
            balance:          $response['ledgerBalance']    ?? 0.0,
            availableBalance: $response['availableBalance'] ?? 0.0,
            // FIX-10 (ROB5): Aritmética de floats pode gerar -0.0 ou 1e-14 ao invés de 0.0.
            // round() com 2 casas decimais (centavos) elimina imprecisões.
            pendingBalance:   round(
                ($response['ledgerBalance'] ?? 0.0) - ($response['availableBalance'] ?? 0.0),
                2
            ),
            currency:         $response['currency'] ?? 'USD',
            rawResponse:      $response,
        );
    }

    // ==========================================================
    //  WEBHOOKS (PUSH NOTIFICATIONS)
    // ==========================================================

    /**
     * Registra uma URL de webhook para receber eventos em tempo real.
     *
     * SOBRE OS PUSH NOTIFICATIONS DO CASHPRO
     * ----------------------------------------
     * Em vez de polling (consultar status repetidamente), o BofA envia
     * notificações HTTP POST para sua URL quando eventos ocorrem.
     *
     * EVENTOS DISPONÍVEIS
     * --------------------
     *   PAYMENT_SENT          → Pagamento enviado confirmado (Zelle, ACH, Wire).
     *   PAYMENT_RECEIVED      → Pagamento recebido na conta (Zelle creditado).
     *   PAYMENT_FAILED        → Pagamento falhou.
     *   PAYMENT_RETURNED      → ACH devolvido pelo banco receptor.
     *   BALANCE_BELOW_THRESHOLD → Saldo abaixo do limite configurado.
     *   STATEMENT_AVAILABLE   → Extrato mensal disponível.
     *
     * PAYLOAD TÍPICO DE PAYMENT_RECEIVED (Zelle):
     * ---------------------------------------------
     *   {
     *     "eventType":    "PAYMENT_RECEIVED",
     *     "timestamp":    "2025-02-27T15:30:00Z",
     *     "paymentId":    "PAY-123456",
     *     "paymentType":  "ZELLE",
     *     "amount":       250.00,
     *     "currency":     "USD",
     *     "senderEmail":  "cliente@email.com",
     *     "memo":         "REF-USER-7890",
     *     "accountId":    "87654321"
     *   }
     *
     * SEGURANÇA DO WEBHOOK
     * ---------------------
     * O BofA assina os payloads com HMAC-SHA256 usando uma chave secreta
     * configurada no portal. Valide sempre a assinatura antes de processar.
     * Header de assinatura: X-BofA-Signature
     *
     * @param string $url    URL HTTPS pública que receberá os eventos.
     * @param array  $events Lista de tipos de evento. Veja lista acima.
     *                       Exemplo: ['PAYMENT_RECEIVED', 'PAYMENT_FAILED']
     *
     * @return array Array com 'webhookId' e 'status' do registro.
     *
     * @throws GatewayException Se a URL não for HTTPS ou o evento for inválido.
     */
    public function registerWebhook(string $url, array $events): array
    {
        $response = $this->request('POST', '/notifications/webhooks', [
            'url'        => $url,
            'events'     => $events,
            'accountIds' => [$this->accountId],
            'active'     => true,
        ]);

        return [
            'webhookId' => $response['webhookId'] ?? $response['id'],
            'status'    => $response['status'] ?? 'active',
            'url'       => $url,
            'events'    => $events,
        ];
    }

    /**
     * Lista todos os webhooks registrados na conta.
     *
     * @return array Lista de webhooks com id, url, eventos e status.
     */
    public function listWebhooks(): array
    {
        return $this->request('GET', '/notifications/webhooks', query: [
            'accountId' => $this->accountId,
        ]);
    }

    /**
     * Remove um webhook registrado.
     *
     * @param string $webhookId ID retornado no registerWebhook().
     * @return bool true se removido com sucesso.
     *
     * @throws GatewayException Se o webhookId não existir.
     */
    public function deleteWebhook(string $webhookId): bool
    {
        $this->request('DELETE', "/notifications/webhooks/{$webhookId}");
        return true;
    }

    // ==========================================================
    //  RELATÓRIOS
    // ==========================================================

    /**
     * Retorna o cronograma de liquidação de transações pendentes.
     *
     * Útil para projetar quando fundos ACH estarão disponíveis
     * e para reconciliação financeira.
     *
     * FILTROS SUPORTADOS (via $filters)
     * -----------------------------------
     *   'startDate'  string  Data inicial YYYY-MM-DD.
     *   'endDate'    string  Data final YYYY-MM-DD.
     *
     * @param array $filters Filtros de data opcionais.
     * @return array Lista de itens de liquidação com datas e valores esperados.
     */
    public function getSettlementSchedule(array $filters = []): array
    {
        return $this->request('GET', '/reports/settlement', query: array_merge([
            'accountId' => $this->accountId,
        ], $filters));
    }

    // ==========================================================
    //  MÉTODOS NÃO SUPORTADOS
    //  (exigidos pela interface mas fora do escopo do BofA CashPro)
    // ==========================================================

    /** @throws GatewayException Sempre — BofA não processa PIX. */
    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('PIX is not supported by BofA CashPro. Use a Brazilian gateway (Asaas, PagarMe, C6Bank, etc.).');
    }

    /** @throws GatewayException Sempre — BofA não retorna QR Code PIX. */
    public function getPixQrCode(string $transactionId): string
    {
        throw new GatewayException('PIX is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — BofA não retorna código copia e cola PIX. */
    public function getPixCopyPaste(string $transactionId): string
    {
        throw new GatewayException('PIX is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — CashPro não processa cartão de crédito. */
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Credit card payments are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function tokenizeCard(array $cardData): string
    {
        throw new GatewayException('Card tokenization is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        throw new GatewayException('Pre-authorization is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Pre-authorization is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Debit card payments are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — Boleto é produto separado no BofA (integração específica para Brasil). */
    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Boleto is not supported via CashPro API. Use a Brazilian gateway.');
    }

    /** @throws GatewayException Sempre */
    public function getBoletoUrl(string $transactionId): string
    {
        throw new GatewayException('Boleto is not supported via CashPro API.');
    }

    /** @throws GatewayException Sempre */
    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Boleto is not supported via CashPro API.');
    }

    /** @throws GatewayException Sempre — CashPro não tem mecanismo de assinatura nativo. */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions are not natively supported by BofA CashPro. Implement recurring ACH at the application layer using scheduleTransfer().');
    }

    /** @throws GatewayException Sempre */
    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — Wire/Zelle são irreversíveis. ACH pode ser cancelado via cancelScheduledTransfer(). */
    public function refund(RefundRequest $request): RefundResponse
    {
        throw new GatewayException('Refunds are not supported by BofA CashPro. Zelle and Wire are irreversible. For ACH, use cancelScheduledTransfer() before settlement.');
    }

    /** @throws GatewayException Sempre */
    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        throw new GatewayException('Partial refunds are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function getChargebacks(array $filters = []): array
    {
        throw new GatewayException('Chargeback management is not available via CashPro API.');
    }

    /** @throws GatewayException Sempre */
    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        throw new GatewayException('Chargeback disputes are not available via CashPro API.');
    }

    /** @throws GatewayException Sempre */
    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Split payments are not supported by BofA CashPro. Implement at the application layer using multiple transfer() calls.');
    }

    /**
     * Sub-contas via VAM (Virtual Account Management).
     *
     * O BofA suporta VAM para tesouraria corporativa, mas NÃO cria contas
     * por usuário final (modelo fintech/marketplace).
     * Gerencie sub-contas/wallets de usuários finais na camada de aplicação.
     *
     * @throws GatewayException Sempre.
     */
    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        throw new GatewayException('Per-user sub-accounts are not supported by BofA CashPro. Manage user balances via Wallet at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — Wallets são gerenciadas na camada de aplicação. */
    public function createWallet(WalletRequest $request): WalletResponse
    {
        throw new GatewayException('Wallets are managed at the application layer, not via BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets are managed at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets are managed at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Wallets are managed at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Wallets are managed at the application layer.');
    }

    /** @throws GatewayException Sempre — Escrow não é suportado nativamente pelo CashPro. */
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException('Escrow is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Escrow is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow is not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — Payment Links não são um produto CashPro. */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException('Payment links are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links are not supported by BofA CashPro.');
    }

    /** @throws GatewayException Sempre — Gestão de clientes é feita na camada de aplicação. */
    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        throw new GatewayException('Customer management is handled at the application layer, not via BofA CashPro.');
    }

    /** @throws GatewayException Sempre */
    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        throw new GatewayException('Customer management is handled at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function getCustomer(string $customerId): CustomerResponse
    {
        throw new GatewayException('Customer management is handled at the application layer.');
    }

    /** @throws GatewayException Sempre */
    public function listCustomers(array $filters = []): array
    {
        throw new GatewayException('Customer management is handled at the application layer.');
    }

    /** @throws GatewayException Sempre — Antifraude é externo ao CashPro. */
    public function analyzeTransaction(string $transactionId): array
    {
        throw new GatewayException('Fraud analysis is not provided by BofA CashPro API.');
    }

    /** @throws GatewayException Sempre */
    public function addToBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist management is not provided by BofA CashPro API.');
    }

    /** @throws GatewayException Sempre */
    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist management is not provided by BofA CashPro API.');
    }

    /** @throws GatewayException Sempre — Antecipação de recebíveis não se aplica ao modelo bancário corporativo. */
    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        throw new GatewayException('Receivables anticipation is not supported by BofA CashPro.');
    }

    // ==========================================================
    //  UTILITÁRIOS PRIVADOS
    // ==========================================================

    /**
     * Gera um identificador único para cada request.
     *
     * Usado como clientReferenceId nos payloads e como X-Request-ID no header.
     * O BofA usa este ID para:
     *   1. Idempotência — rejeitar duplicatas com o mesmo ID em curto período.
     *   2. Rastreamento — localizar o request nos logs do BofA (suporte técnico).
     *
     * Formato: cashpro-{timestamp em hex}-{random hex}
     * Exemplo: cashpro-67c09e2a-5f3a4b8c
     *
     * @return string ID único com prefixo 'cashpro-'.
     */
    private function generateRequestId(): string
    {
        return 'cashpro-' . dechex(time()) . '-' . bin2hex(random_bytes(4));
    }
}
