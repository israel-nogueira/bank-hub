<?php

namespace IsraelNogueira\PaymentHub\Gateways\NuBank;

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
use IsraelNogueira\PaymentHub\Enums\PaymentStatus;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

/**
 * NuBank Gateway - Open Finance / BaaS
 *
 * Integração com a API NuBank via Open Finance / NuBaaS.
 *
 * PRÉ-REQUISITOS
 * --------------
 * - Conta NuBaaS ou parceria via Open Finance aprovada
 * - Client ID e Client Secret obtidos via developers.nubank.com.br
 * - Certificate (.p12 ou .pem) para autenticação mTLS em produção
 *
 * ONBOARDING
 * ----------
 * 1. Acesse: https://developers.nubank.com.br
 * 2. Cadastre sua empresa e aguarde aprovação
 * 3. Receba as credenciais OAuth2 e o certificado mTLS
 *
 * REFERÊNCIAS
 * -----------
 * @see https://developers.nubank.com.br
 *
 * @author  PaymentHub
 * @version 1.0.0
 */
class NuBankGateway implements PaymentGatewayInterface
{
    // ----------------------------------------------------------
    //  URLs base por ambiente
    // ----------------------------------------------------------

    /** URL de produção NuBaaS / Open Finance */
    private const PRODUCTION_URL = 'https://api.nubank.com.br';

    /** URL de sandbox para testes (ambiente de homologação NuBank) */
    private const SANDBOX_URL = 'https://sandbox.nubank.com.br';

    /** Endpoint OAuth2 — produção */
    private const OAUTH_URL = 'https://api.nubank.com.br/oauth/token';

    /** Endpoint OAuth2 — sandbox */
    private const OAUTH_SANDBOX_URL = 'https://sandbox.nubank.com.br/oauth/token';

    // ----------------------------------------------------------
    //  Estado interno
    // ----------------------------------------------------------

    private string $baseUrl;
    private string $oauthUrl;
    private ?string $accessToken = null;
    private ?int $tokenExpiry = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool   $sandbox = false,
        /** Caminho para o certificado mTLS (.pem), obrigatório em produção */
        private readonly ?string $certPath = null,
        private readonly ?string $certPassword = null,
    ) {
        if (!$sandbox && $certPath === null) {
            throw new \InvalidArgumentException(
                'NuBank: certPath é obrigatório em produção (mTLS). Forneça o caminho do certificado .pem.'
            );
        }

        $this->baseUrl  = $sandbox ? self::SANDBOX_URL  : self::PRODUCTION_URL;
        $this->oauthUrl = $sandbox ? self::OAUTH_SANDBOX_URL : self::OAUTH_URL;
    }

    // ===========================================================
    //  AUTENTICAÇÃO OAuth2 (Client Credentials)
    // ===========================================================

    /**
     * Obtém (ou renova) o access token OAuth2.
     * O token é armazenado em memória e reutilizado até 60 s antes de expirar.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 60)) {
            return $this->accessToken;
        }

        $ch = curl_init($this->oauthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'payments pix boleto transfers',
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $this->applyCert($ch);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['access_token'])) {
            throw new GatewayException(
                'NuBank: falha na autenticação OAuth2 — ' . ($data['error_description'] ?? 'sem detalhes'),
                $httpCode
            );
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    // ===========================================================
    //  HTTP CLIENT
    // ===========================================================

    /**
     * Executa uma requisição HTTP autenticada à API NuBank.
     *
     * @param string               $method  GET | POST | PUT | PATCH | DELETE
     * @param string               $path    Caminho do endpoint (ex.: /pix/v2/cob/txid)
     * @param array<string, mixed> $body    Payload JSON (para POST/PUT/PATCH)
     * @param array<string, mixed> $query   Query string params
     *
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function request(
        string $method,
        string $path,
        array  $body  = [],
        array  $query = []
    ): array {
        $url = $this->baseUrl . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getAccessToken(),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => strtoupper($method),
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CONNECTTIMEOUT  => 10,
        ]);

        if (!empty($body) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $this->applyCert($ch);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new GatewayException("NuBank: erro cURL — {$curlError}", 0);
        }

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Requisição falhou';
            throw new GatewayException("NuBank: {$msg}", $httpCode, null, ['response' => $decoded]);
        }

        return $decoded;
    }

    /**
     * Aplica o certificado mTLS ao handle cURL (obrigatório em produção).
     */
    private function applyCert(\CurlHandle $ch): void
    {
        if ($this->certPath) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->certPath);
            if ($this->certPassword) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
            }
        }
    }

    // ===========================================================
    //  HELPERS
    // ===========================================================

    /**
     * Gera um txid aleatório para cobranças PIX.
     * Padrão BACEN: [a-zA-Z0-9]{26,35}
     * Usa random_bytes para garantia criptográfica.
     */
    private function generateTxId(): string
    {
        $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max    = strlen($chars) - 1;
        $length = random_int(26, 35);
        $txId   = '';
        for ($i = 0; $i < $length; $i++) {
            $txId .= $chars[random_int(0, $max)];
        }
        return $txId;
    }

    /** Mapeia status da API NuBank para o enum interno PaymentStatus. */
    private function mapStatus(string $nuStatus): PaymentStatus
    {
        return match (strtoupper($nuStatus)) {
            'ATIVA', 'PENDING', 'CREATED'      => PaymentStatus::PENDING,
            'CONCLUIDA', 'PAID', 'APPROVED'    => PaymentStatus::APPROVED,
            'PROCESSING'                        => PaymentStatus::PROCESSING,
            'CANCELLED', 'REMOVIDA'             => PaymentStatus::CANCELLED,
            'EXPIRED'                           => PaymentStatus::EXPIRED,
            'FAILED', 'DENIED'                 => PaymentStatus::FAILED,
            'REFUNDED'                          => PaymentStatus::REFUNDED,
            default                             => PaymentStatus::PENDING,
        };
    }

    // ===========================================================
    //  PIX
    // ===========================================================

    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        if (empty($request->pixKey)) {
            throw new \InvalidArgumentException('NuBank: pixKey é obrigatório para criar cobranças PIX.');
        }

        $txId = $this->generateTxId();

        $data = [
            'calendario'         => ['expiracao' => 172800],  // 48 h
            'valor'              => [
                'original' => number_format($request->getAmount(), 2, '.', ''),
            ],
            'chave'              => $request->pixKey,
            'solicitacaoPagador' => $request->description ?? 'Pagamento via PIX',
        ];

        if ($request->customerName && $request->getCustomerDocument()) {
            $doc     = preg_replace('/\D/', '', $request->getCustomerDocument());
            $docType = strlen($doc) === 11 ? 'cpf' : 'cnpj';
            $data['devedor'] = [$docType => $doc, 'nome' => $request->customerName];
        }

        $response = $this->request('PUT', "/pix/v2/cob/{$txId}", $data);

        return PaymentResponse::create(
            success:       isset($response['txid']),
            transactionId: $response['txid'] ?? $txId,
            status:        $this->mapStatus($response['status'] ?? 'ATIVA'),
            amount:        $request->getAmount(),
            currency:      Currency::BRL,
            gatewayResponse: $response,
            pixCopyPaste:  $response['pixCopiaECola'] ?? null,
            rawResponse:   $response,
        );
    }

    public function getPixQrCode(string $transactionId): string
    {
        $response = $this->request('GET', "/pix/v2/cob/{$transactionId}");
        return $response['qrcode'] ?? $response['imagemQrcode'] ?? '';
    }

    public function getPixCopyPaste(string $transactionId): string
    {
        $response = $this->request('GET', "/pix/v2/cob/{$transactionId}");
        return $response['pixCopiaECola'] ?? '';
    }

    // ===========================================================
    //  CARTÃO DE CRÉDITO
    // ===========================================================

    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount'       => (int) round($request->getAmount() * 100),
            'currency'     => 'BRL',
            'description'  => $request->description ?? 'Pagamento com cartão',
            'capture'      => $request->capture ?? true,
            'installments' => $request->installments ?? 1,
            'customer'     => [
                'name'   => $request->customerName ?? '',
                'email'  => $request->customerEmail ?? '',
                'tax_id' => preg_replace('/\D/', '', $request->getCustomerDocument() ?? ''),
            ],
        ];

        // Token tem prioridade — nunca enviar dados brutos se houver token
        if (!empty($request->cardToken)) {
            $data['card_token'] = $request->cardToken;
        } else {
            $data['card'] = [
                'number'       => preg_replace('/\D/', '', $request->cardNumber ?? ''),
                'holder_name'  => $request->cardHolderName ?? '',
                'expiry_month' => $request->cardExpiryMonth ?? '',
                'expiry_year'  => $request->cardExpiryYear ?? '',
                'cvv'          => $request->cardCvv ?? '',
            ];
        }

        $response = $this->request('POST', '/payments/v1/credit-card', $data);

        return PaymentResponse::create(
            success:       isset($response['id']) && in_array($response['status'] ?? '', ['approved', 'authorized']),
            transactionId: $response['id'] ?? '',
            status:        $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:        $request->getAmount(),
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    public function tokenizeCard(array $cardData): string
    {
        $response = $this->request('POST', '/payments/v1/cards/tokenize', [
            'number'       => preg_replace('/\D/', '', $cardData['number'] ?? ''),
            'holder_name'  => $cardData['holder_name'] ?? '',
            'expiry_month' => $cardData['expiry_month'] ?? '',
            'expiry_year'  => $cardData['expiry_year'] ?? '',
            'cvv'          => $cardData['cvv'] ?? '',
        ]);

        if (!isset($response['token'])) {
            throw new GatewayException('NuBank: tokenização de cartão falhou', 422);
        }

        return $response['token'];
    }

    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        $data = $amount ? ['amount' => (int) round($amount * 100)] : [];

        $response = $this->request('POST', "/payments/v1/credit-card/{$transactionId}/capture", $data);

        return PaymentResponse::create(
            success:       ($response['status'] ?? '') === 'approved',
            transactionId: $transactionId,
            status:        $this->mapStatus($response['status'] ?? 'APPROVED'),
            amount:        $amount ?? 0,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        $response = $this->request('POST', "/payments/v1/credit-card/{$transactionId}/void");

        return PaymentResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        PaymentStatus::CANCELLED,
            amount:        0,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  CARTÃO DE DÉBITO
    // ===========================================================

    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount'      => (int) round($request->getAmount() * 100),
            'currency'    => 'BRL',
            'description' => $request->description ?? 'Pagamento débito',
            'card'        => [
                'number'       => preg_replace('/\D/', '', $request->cardNumber ?? ''),
                'holder_name'  => $request->cardHolderName ?? '',
                'expiry_month' => $request->cardExpiryMonth ?? '',
                'expiry_year'  => $request->cardExpiryYear ?? '',
                'cvv'          => $request->cardCvv ?? '',
            ],
            'customer'    => [
                'name'   => $request->customerName ?? '',
                'email'  => $request->customerEmail ?? '',
                'tax_id' => preg_replace('/\D/', '', $request->getCustomerDocument() ?? ''),
            ],
        ];

        $response = $this->request('POST', '/payments/v1/debit-card', $data);

        return PaymentResponse::create(
            success:       isset($response['id']),
            transactionId: $response['id'] ?? '',
            status:        $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:        $request->getAmount(),
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  BOLETO
    // ===========================================================

    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount'      => (int) round($request->getAmount() * 100),
            'description' => $request->description ?? 'Boleto NuBank',
            'due_date'    => $request->dueDate ?? date('Y-m-d', strtotime('+3 days')),
            'customer'    => [
                'name'   => $request->customerName ?? '',
                'email'  => $request->customerEmail ?? '',
                'tax_id' => preg_replace('/\D/', '', $request->getCustomerDocument() ?? ''),
            ],
        ];

        if (!empty($request->customerAddress)) {
            $data['customer']['address'] = [
                'street'       => $request->customerAddress['street'] ?? '',
                'number'       => $request->customerAddress['number'] ?? '',
                'complement'   => $request->customerAddress['complement'] ?? '',
                'neighborhood' => $request->customerAddress['neighborhood'] ?? '',
                'city'         => $request->customerAddress['city'] ?? '',
                'state'        => $request->customerAddress['state'] ?? '',
                'postal_code'  => preg_replace('/\D/', '', $request->customerAddress['postal_code'] ?? ''),
            ];
        }

        $response = $this->request('POST', '/billing/v1/boletos', $data);

        return PaymentResponse::create(
            success:           isset($response['id']),
            transactionId:     $response['id'] ?? '',
            status:            $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:            $request->getAmount(),
            currency:          Currency::BRL,
            gatewayResponse:   $response,
            boletoUrl:         $response['url'] ?? null,
            boletoBarcode:     $response['barcode'] ?? null,
            boletoDigitableLine: $response['digitable_line'] ?? null,
            rawResponse:       $response,
        );
    }

    public function getBoletoUrl(string $transactionId): string
    {
        $response = $this->request('GET', "/billing/v1/boletos/{$transactionId}");

        if (!isset($response['url'])) {
            throw new GatewayException('NuBank: URL do boleto não encontrada', 404);
        }

        return $response['url'];
    }

    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        $response = $this->request('DELETE', "/billing/v1/boletos/{$transactionId}");

        return PaymentResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        PaymentStatus::CANCELLED,
            amount:        0,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  ASSINATURAS / RECORRÊNCIA
    // ===========================================================

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $data = [
            'amount'      => (int) round($request->amount * 100),
            'interval'    => strtolower($request->interval->value ?? 'monthly'),
            'description' => $request->description ?? 'Assinatura',
            'card_token'  => $request->cardToken ?? '',
            'customer'    => [
                'name'  => $request->customerName ?? '',
                'email' => $request->customerEmail ?? '',
            ],
        ];

        $response = $this->request('POST', '/subscriptions/v1', $data);

        return SubscriptionResponse::create(
            success:        isset($response['id']),
            subscriptionId: $response['id'] ?? '',
            status:         $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:         $request->amount,
            currency:       Currency::BRL,
            rawResponse:    $response,
        );
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        $response = $this->request('DELETE', "/subscriptions/v1/{$subscriptionId}");

        return SubscriptionResponse::create(
            success:        true,
            subscriptionId: $subscriptionId,
            status:         PaymentStatus::CANCELLED,
            amount:         0,
            currency:       Currency::BRL,
            rawResponse:    $response,
        );
    }

    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        $response = $this->request('POST', "/subscriptions/v1/{$subscriptionId}/suspend");

        return SubscriptionResponse::create(
            success:        isset($response['id']),
            subscriptionId: $subscriptionId,
            status:         $this->mapStatus($response['status'] ?? 'CANCELLED'),
            amount:         0,
            currency:       Currency::BRL,
            rawResponse:    $response,
        );
    }

    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        $response = $this->request('POST', "/subscriptions/v1/{$subscriptionId}/reactivate");

        return SubscriptionResponse::create(
            success:        isset($response['id']),
            subscriptionId: $subscriptionId,
            status:         PaymentStatus::APPROVED,
            amount:         0,
            currency:       Currency::BRL,
            rawResponse:    $response,
        );
    }

    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        $response = $this->request('PATCH', "/subscriptions/v1/{$subscriptionId}", $data);

        return SubscriptionResponse::create(
            success:        isset($response['id']),
            subscriptionId: $subscriptionId,
            status:         $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:         ($response['amount'] ?? 0) / 100,
            currency:       Currency::BRL,
            rawResponse:    $response,
        );
    }

    // ===========================================================
    //  TRANSAÇÕES
    // ===========================================================

    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        // Tenta buscar como PIX primeiro, depois como pagamento genérico
        try {
            $response = $this->request('GET', "/pix/v2/cob/{$transactionId}");

            return TransactionStatusResponse::create(
                success:       true,
                transactionId: $transactionId,
                status:        $this->mapStatus($response['status'] ?? 'PENDING'),
                amount:        (float) ($response['valor']['original'] ?? 0),
                currency:      Currency::BRL,
                rawResponse:   $response,
            );
        } catch (GatewayException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        // Fallback: pagamento por cartão ou boleto
        $response = $this->request('GET', "/payments/v1/transactions/{$transactionId}");

        return TransactionStatusResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:        ($response['amount'] ?? 0) / 100,
            currency:      Currency::BRL,
            rawResponse:   $response,
        );
    }

    public function listTransactions(array $filters = []): array
    {
        $query = [
            'start_date' => $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
            'end_date'   => $filters['end_date']   ?? date('Y-m-d'),
        ];

        if (isset($filters['status'])) {
            $query['status'] = $filters['status'];
        }
        if (isset($filters['page'])) {
            $query['page'] = (int) $filters['page'];
        }

        $response = $this->request('GET', '/payments/v1/transactions', [], $query);
        return $response['data'] ?? $response;
    }

    // ===========================================================
    //  ESTORNOS E CHARGEBACKS
    // ===========================================================

    public function refund(RefundRequest $request): RefundResponse
    {
        $data = ['reason' => $request->reason ?? 'Solicitação do cliente'];

        if ($request->amount) {
            $data['amount'] = (int) round($request->amount * 100);
        }

        $response = $this->request('POST', "/payments/v1/transactions/{$request->transactionId}/refund", $data);

        return RefundResponse::create(
            success:       isset($response['id']),
            refundId:      $response['id'] ?? '',
            transactionId: $request->transactionId,
            amount:        ($response['amount'] ?? 0) / 100,
            status:        $this->mapStatus($response['status'] ?? 'REFUNDED')->value,
            rawResponse:   $response,
        );
    }

    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        $response = $this->request('POST', "/payments/v1/transactions/{$transactionId}/refund", [
            'amount' => (int) round($amount * 100),
        ]);

        return RefundResponse::create(
            success:       isset($response['id']),
            refundId:      $response['id'] ?? '',
            transactionId: $transactionId,
            amount:        $amount,
            status:        $this->mapStatus($response['status'] ?? 'REFUNDED')->value,
            rawResponse:   $response,
        );
    }

    public function getChargebacks(array $filters = []): array
    {
        $response = $this->request('GET', '/payments/v1/chargebacks', [], $filters);
        return $response['data'] ?? [];
    }

    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        $response = $this->request('POST', "/payments/v1/chargebacks/{$chargebackId}/dispute", [
            'evidence' => $evidence,
        ]);

        return PaymentResponse::create(
            success:       isset($response['id']),
            transactionId: $response['id'] ?? $chargebackId,
            status:        $this->mapStatus($response['status'] ?? 'PROCESSING'),
            amount:        0,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  SPLIT DE PAGAMENTO
    // ===========================================================

    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        $splits = array_map(fn($r) => [
            'recipient_id' => $r['recipient_id'],
            'amount'       => (int) round($r['amount'] * 100),
        ], $request->recipients ?? []);

        $response = $this->request('POST', '/payments/v1/split', [
            'amount'      => (int) round($request->totalAmount * 100),
            'description' => $request->description ?? 'Split payment',
            'splits'      => $splits,
        ]);

        return PaymentResponse::create(
            success:       isset($response['id']),
            transactionId: $response['id'] ?? '',
            status:        $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:        $request->totalAmount,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  SUB-CONTAS
    // ===========================================================

    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        $response = $this->request('POST', '/accounts/v1/sub-accounts', [
            'name'  => $request->name,
            'email' => $request->email ?? '',
            'tax_id'=> preg_replace('/\D/', '', $request->taxId ?? ''),
        ]);

        return SubAccountResponse::create(
            success:      isset($response['id']),
            subAccountId: $response['id'] ?? '',
            status:       $this->mapStatus($response['status'] ?? 'PENDING'),
            rawResponse:  $response,
        );
    }

    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        $response = $this->request('PATCH', "/accounts/v1/sub-accounts/{$subAccountId}", $data);

        return SubAccountResponse::create(
            success:      isset($response['id']),
            subAccountId: $subAccountId,
            status:       $this->mapStatus($response['status'] ?? 'APPROVED'),
            rawResponse:  $response,
        );
    }

    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        $response = $this->request('GET', "/accounts/v1/sub-accounts/{$subAccountId}");

        return SubAccountResponse::create(
            success:      isset($response['id']),
            subAccountId: $subAccountId,
            status:       $this->mapStatus($response['status'] ?? 'APPROVED'),
            rawResponse:  $response,
        );
    }

    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        $response = $this->request('POST', "/accounts/v1/sub-accounts/{$subAccountId}/activate");

        return SubAccountResponse::create(
            success:      true,
            subAccountId: $subAccountId,
            status:       PaymentStatus::APPROVED,
            rawResponse:  $response,
        );
    }

    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        $response = $this->request('POST', "/accounts/v1/sub-accounts/{$subAccountId}/deactivate");

        return SubAccountResponse::create(
            success:      true,
            subAccountId: $subAccountId,
            status:       PaymentStatus::CANCELLED,
            rawResponse:  $response,
        );
    }

    // ===========================================================
    //  WALLETS
    // ===========================================================

    public function createWallet(WalletRequest $request): WalletResponse
    {
        $response = $this->request('POST', '/wallets/v1', [
            'owner_id'    => $request->ownerId ?? '',
            'description' => $request->description ?? 'Carteira NuBank',
        ]);

        return WalletResponse::create(
            success:    isset($response['id']),
            walletId:   $response['id'] ?? '',
            balance:    ($response['balance'] ?? 0) / 100,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        $response = $this->request('POST', "/wallets/v1/{$walletId}/credit", [
            'amount' => (int) round($amount * 100),
        ]);

        return WalletResponse::create(
            success:    true,
            walletId:   $walletId,
            balance:    ($response['balance'] ?? 0) / 100,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        $response = $this->request('POST', "/wallets/v1/{$walletId}/debit", [
            'amount' => (int) round($amount * 100),
        ]);

        return WalletResponse::create(
            success:    true,
            walletId:   $walletId,
            balance:    ($response['balance'] ?? 0) / 100,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    public function getWalletBalance(string $walletId): BalanceResponse
    {
        $response = $this->request('GET', "/wallets/v1/{$walletId}");

        return BalanceResponse::create(
            success:  true,
            balance:  ($response['balance'] ?? 0) / 100,
            currency: Currency::BRL,
            rawResponse: $response,
        );
    }

    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        $response = $this->request('POST', '/wallets/v1/transfer', [
            'from_wallet_id' => $fromWalletId,
            'to_wallet_id'   => $toWalletId,
            'amount'         => (int) round($amount * 100),
        ]);

        return TransferResponse::create(
            success:    isset($response['id']),
            transferId: $response['id'] ?? '',
            status:     $this->mapStatus($response['status'] ?? 'APPROVED'),
            amount:     $amount,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    // ===========================================================
    //  ESCROW (CUSTÓDIA)
    // ===========================================================

    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        $response = $this->request('POST', '/escrow/v1/hold', [
            'amount'      => (int) round($request->amount * 100),
            'description' => $request->description ?? 'Custódia NuBank',
            'expires_at'  => $request->expiresAt ?? date('Y-m-d\TH:i:s\Z', strtotime('+7 days')),
        ]);

        return EscrowResponse::create(
            success:   isset($response['id']),
            escrowId:  $response['id'] ?? '',
            status:    $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:    $request->amount,
            currency:  Currency::BRL,
            rawResponse: $response,
        );
    }

    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        $response = $this->request('POST', "/escrow/v1/{$escrowId}/release");

        return EscrowResponse::create(
            success:   true,
            escrowId:  $escrowId,
            status:    PaymentStatus::APPROVED,
            amount:    0,
            currency:  Currency::BRL,
            rawResponse: $response,
        );
    }

    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        $response = $this->request('POST', "/escrow/v1/{$escrowId}/partial-release", [
            'amount' => (int) round($amount * 100),
        ]);

        return EscrowResponse::create(
            success:   true,
            escrowId:  $escrowId,
            status:    PaymentStatus::APPROVED,
            amount:    $amount,
            currency:  Currency::BRL,
            rawResponse: $response,
        );
    }

    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        $response = $this->request('DELETE', "/escrow/v1/{$escrowId}");

        return EscrowResponse::create(
            success:   true,
            escrowId:  $escrowId,
            status:    PaymentStatus::CANCELLED,
            amount:    0,
            currency:  Currency::BRL,
            rawResponse: $response,
        );
    }

    // ===========================================================
    //  TRANSFERÊNCIAS
    // ===========================================================

    public function transfer(TransferRequest $request): TransferResponse
    {
        $data = [
            'amount'         => (int) round($request->amount * 100),
            'description'    => $request->description ?? 'Transferência NuBank',
            'recipient_name' => $request->recipientName ?? '',
            'pix_key'        => $request->metadata['pix_key'] ?? null,
        ];

        $response = $this->request('POST', '/transfers/v1', $data);

        return TransferResponse::create(
            success:    isset($response['id']),
            transferId: $response['id'] ?? '',
            status:     $this->mapStatus($response['status'] ?? 'PROCESSING'),
            amount:     $request->amount,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    public function scheduleTransfer(TransferRequest $request, string $scheduledFor): TransferResponse
    {
        $data = [
            'amount'         => (int) round($request->amount * 100),
            'description'    => $request->description ?? 'Transferência agendada',
            'recipient_name' => $request->recipientName ?? '',
            'scheduled_for'  => $scheduledFor,
            'pix_key'        => $request->metadata['pix_key'] ?? null,
        ];

        $response = $this->request('POST', '/transfers/v1/scheduled', $data);

        return TransferResponse::create(
            success:    isset($response['id']),
            transferId: $response['id'] ?? '',
            status:     $this->mapStatus($response['status'] ?? 'PENDING'),
            amount:     $request->amount,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        $response = $this->request('DELETE', "/transfers/v1/scheduled/{$transferId}");

        return TransferResponse::create(
            success:    true,
            transferId: $transferId,
            status:     PaymentStatus::CANCELLED,
            amount:     0,
            currency:   Currency::BRL,
            rawResponse: $response,
        );
    }

    // ===========================================================
    //  SALDO E EXTRATO
    // ===========================================================

    public function getBalance(): BalanceResponse
    {
        $response = $this->request('GET', '/accounts/v1/balance');

        return BalanceResponse::create(
            success:     true,
            balance:     ($response['available'] ?? 0) / 100,
            currency:    Currency::BRL,
            rawResponse: $response,
        );
    }

    public function getStatement(array $filters = []): array
    {
        $query = [
            'start_date' => $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
            'end_date'   => $filters['end_date']   ?? date('Y-m-d'),
        ];

        $response = $this->request('GET', '/accounts/v1/statement', [], $query);
        return $response['entries'] ?? $response['data'] ?? [];
    }

    // ===========================================================
    //  CLIENTES
    // ===========================================================

    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        $response = $this->request('POST', '/customers/v1', [
            'name'   => $request->name ?? '',
            'email'  => $request->email ?? '',
            'tax_id' => preg_replace('/\D/', '', $request->taxId ?? ''),
            'phone'  => preg_replace('/\D/', '', $request->phone ?? ''),
        ]);

        return CustomerResponse::create(
            success:    isset($response['id']),
            customerId: $response['id'] ?? '',
            rawResponse: $response,
        );
    }

    public function getCustomer(string $customerId): CustomerResponse
    {
        $response = $this->request('GET', "/customers/v1/{$customerId}");

        return CustomerResponse::create(
            success:    isset($response['id']),
            customerId: $customerId,
            rawResponse: $response,
        );
    }

    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        $response = $this->request('PATCH', "/customers/v1/{$customerId}", $data);

        return CustomerResponse::create(
            success:    isset($response['id']),
            customerId: $customerId,
            rawResponse: $response,
        );
    }

    public function listCustomers(array $filters = []): array
    {
        $response = $this->request('GET', '/customers/v1', [], $filters);
        return $response['data'] ?? [];
    }

    // ===========================================================
    //  LINKS DE PAGAMENTO
    // ===========================================================

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        $response = $this->request('POST', '/payment-links/v1', [
            'amount'      => (int) round($request->amount * 100),
            'description' => $request->description ?? 'Link de Pagamento',
            'expires_at'  => $request->expiresAt ?? date('Y-m-d\TH:i:s\Z', strtotime('+7 days')),
        ]);

        return PaymentLinkResponse::create(
            success:       isset($response['id']),
            paymentLinkId: $response['id'] ?? '',
            url:           $response['url'] ?? '',
            expiresAt:     $response['expires_at'] ?? '',
            rawResponse:   $response,
        );
    }

    public function expirePaymentLink(string $paymentLinkId): PaymentLinkResponse
    {
        $response = $this->request('POST', "/payment-links/v1/{$paymentLinkId}/expire");

        return PaymentLinkResponse::create(
            success:       true,
            paymentLinkId: $paymentLinkId,
            url:           '',
            expiresAt:     '',
            rawResponse:   $response,
        );
    }

    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        $response = $this->request('GET', "/payment-links/v1/{$linkId}");

        return PaymentLinkResponse::create(
            success:       isset($response['id']),
            paymentLinkId: $response['id'] ?? $linkId,
            url:           $response['url'] ?? '',
            expiresAt:     $response['expires_at'] ?? '',
            rawResponse:   $response,
        );
    }

    // ===========================================================
    //  WEBHOOKS
    // ===========================================================

    public function registerWebhook(string $url, array $events): array
    {
        return $this->request('POST', '/webhooks/v1', [
            'url'    => $url,
            'events' => $events,
        ]);
    }

    public function listWebhooks(): array
    {
        $response = $this->request('GET', '/webhooks/v1');
        return $response['data'] ?? [];
    }

    public function deleteWebhook(string $webhookId): bool
    {
        $this->request('DELETE', "/webhooks/v1/{$webhookId}");
        return true;
    }

    // ===========================================================
    //  ANTIFRAUDE
    // ===========================================================

    /**
     * Consulta análise de risco de uma transação.
     * NuBank retorna score e flags de fraude embutidos na resposta do pagamento.
     */
    public function analyzeTransaction(string $transactionId): array
    {
        $response = $this->request('GET', "/payments/v1/transactions/{$transactionId}");

        return [
            'transaction_id' => $transactionId,
            'risk_score'     => $response['risk_score'] ?? null,
            'risk_level'     => $response['risk_level'] ?? 'unknown',
            'fraud_flags'    => $response['fraud_flags'] ?? [],
            'status'         => $response['status'] ?? null,
            'raw'            => $response,
        ];
    }

    /**
     * Adiciona identificador à blacklist interna NuBank.
     * Tipos aceitos: 'cpf', 'cnpj', 'email', 'ip', 'card_bin'.
     */
    public function addToBlacklist(string $identifier, string $type): bool
    {
        $this->request('POST', '/antifraud/v1/blacklist', [
            'identifier' => $identifier,
            'type'       => $type,
        ]);

        return true;
    }

    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        $this->request('DELETE', '/antifraud/v1/blacklist', [
            'identifier' => $identifier,
            'type'       => $type,
        ]);

        return true;
    }

    // ===========================================================
    //  SALDO E CONCILIAÇÃO
    // ===========================================================

    /**
     * Retorna agenda de liquidação (D+1, D+2...) dos recebíveis.
     */
    public function getSettlementSchedule(array $filters = []): array
    {
        $query = [
            'start_date' => $filters['start_date'] ?? date('Y-m-d'),
            'end_date'   => $filters['end_date']   ?? date('Y-m-d', strtotime('+30 days')),
        ];

        $response = $this->request('GET', '/accounts/v1/settlement-schedule', [], $query);
        return $response['schedule'] ?? $response['data'] ?? [];
    }

    /**
     * Solicita antecipação de recebíveis.
     * NuBank BaaS pode não suportar este endpoint — lança GatewayException com instrução clara.
     */
    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        $response = $this->request('POST', '/accounts/v1/receivables/anticipate', [
            'transaction_ids' => $transactionIds,
        ]);

        return PaymentResponse::create(
            success:       isset($response['id']),
            transactionId: $response['id'] ?? '',
            status:        $this->mapStatus($response['status'] ?? 'PROCESSING'),
            amount:        ($response['amount'] ?? 0) / 100,
            currency:      Currency::BRL,
            gatewayResponse: $response,
            rawResponse:   $response,
        );
    }
}