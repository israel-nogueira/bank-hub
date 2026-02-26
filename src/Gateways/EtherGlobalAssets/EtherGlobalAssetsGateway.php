<?php

namespace IsraelNogueira\PaymentHub\Gateways\EtherGlobalAssets;

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

class EtherGlobalAssetsGateway implements PaymentGatewayInterface
{
	private const BASE_URL = 'https://api.etherglobalassets.com.br';
    private const TOKEN_EXPIRATION = 3600; // 1 hora
    
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    // ==================== AUTENTICAÇÃO ====================
    
    private function authenticate(): void
    {
        $response = $this->request('POST', '/auth/authenticate', [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
        ], false);

		if (!isset($response['access_token'])) {
			throw new GatewayException('Failed to authenticate with Ether Global Assets');
		}
		$this->accessToken = $response['access_token'];
		$this->tokenExpiresAt = time() + ($response['expires_in'] ?? self::TOKEN_EXPIRATION);

    }

    private function getToken(): string
    {
        if (!$this->accessToken || time() >= ($this->tokenExpiresAt ?? 0)) {
            $this->authenticate();
        }

        return $this->accessToken;
    }

    private function request(string $method, string $endpoint, array $data = [], bool $requiresAuth = true): array
    {
        $url = self::BASE_URL . $endpoint;
        
        $headers = ['Content-Type: application/json'];
        
        if ($requiresAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->getToken();
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new GatewayException('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new GatewayException(
                $decoded['message'] ?? 'Request failed',
                $httpCode,
                null,
                ['response' => $decoded]
            );
        }

        return $decoded ?? [];
    }

    // ==================== PIX ====================
    
    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        $amountInCents = (int) round($request->money->amount() * 100);
        
        $response = $this->request('POST', '/pix/deposit', [
            'amount' => $amountInCents,
        ]);

        return PaymentResponse::create(
            success: true,
            transactionId: $response['uuid'] ?? $response['id'],
            status: strtolower($response['status'] ?? 'pending'),
            amount: $request->money->amount(),
            currency: $request->money->currency()->value,
            message: 'PIX payment created successfully',
            rawResponse: $response,
            metadata: [
                'qr_code_id' => $response['qrCodeId'] ?? null,
                'pix_key' => $response['pixKey'] ?? null,
                'expire_at' => $response['expireAt'] ?? null,
            ]
        );
    }

    public function getPixQrCode(string $transactionId): string
    {
        throw new GatewayException('getPixQrCode: Use getPixCopyPaste - QR Code is returned in createPixPayment response');
    }

    public function getPixCopyPaste(string $transactionId): string
    {
        throw new GatewayException('getPixCopyPaste: PIX key is returned in createPixPayment metadata');
    }

    // ==================== CARTÃO DE CRÉDITO ====================
    
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Credit card payments not supported by Ether Global Assets');
    }

    public function tokenizeCard(array $cardData): string
    {
        throw new GatewayException('Card tokenization not supported by Ether Global Assets');
    }

    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        throw new GatewayException('Pre-authorization not supported by Ether Global Assets');
    }

    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Pre-authorization not supported by Ether Global Assets');
    }

    // ==================== CARTÃO DE DÉBITO ====================
    
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Debit card payments not supported by Ether Global Assets');
    }

    // ==================== BOLETO ====================
    
    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Boleto not supported by Ether Global Assets');
    }

    public function getBoletoUrl(string $transactionId): string
    {
        throw new GatewayException('Boleto not supported by Ether Global Assets');
    }

    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Boleto not supported by Ether Global Assets');
    }

    // ==================== ASSINATURAS ====================
    
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions not supported by Ether Global Assets');
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions not supported by Ether Global Assets');
    }

    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions not supported by Ether Global Assets');
    }

    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions not supported by Ether Global Assets');
    }

    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        throw new GatewayException('Subscriptions not supported by Ether Global Assets');
    }

    // ==================== TRANSAÇÕES ====================
    
    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        $response = $this->request('GET', "/transactions/{$transactionId}");
        
        $transaction = $response['transaction'] ?? [];
        $financial = $response['financial'] ?? [];
        
        $amount = isset($financial['netAmount']) ? ($financial['netAmount'] / 100) : null;
        
        return TransactionStatusResponse::create(
            success: true,
            transactionId: $transaction['id'] ?? $transactionId,
            status: strtolower($transaction['status'] ?? 'unknown'),
            amount: $amount,
            currency: $transaction['currency'] ?? 'BRL',
            rawResponse: $response
        );
    }

    public function listTransactions(array $filters = []): array
    {
        $endpoint = '/transactions';
        
        // Construir query params
        $queryParams = [];
        
        if (isset($filters['page'])) {
            $queryParams[] = 'page=' . (int)$filters['page'];
        }
        
        if (isset($filters['limit'])) {
            $queryParams[] = 'limit=' . (int)$filters['limit'];
        }
        
        if (isset($filters['type'])) {
            $queryParams[] = 'type=' . urlencode($filters['type']);
        }
        
        if (isset($filters['status'])) {
            $queryParams[] = 'status=' . urlencode($filters['status']);
        }
        
        if (!empty($queryParams)) {
            $endpoint .= '?' . implode('&', $queryParams);
        }
        
        $response = $this->request('GET', $endpoint);
        
        return [
            'transactions' => $response['data'] ?? [],
            'pagination' => $response['pagination'] ?? [],
            'filters' => $response['filters'] ?? [],
        ];
    }

    // ==================== ESTORNOS ====================
    
    public function refund(RefundRequest $request): RefundResponse
    {
        throw new GatewayException('Refunds not supported by Ether Global Assets');
    }

    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        throw new GatewayException('Refunds not supported by Ether Global Assets');
    }

    public function getChargebacks(array $filters = []): array
    {
        throw new GatewayException('Chargebacks not supported by Ether Global Assets');
    }

    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        throw new GatewayException('Chargebacks not supported by Ether Global Assets');
    }

    // ==================== SPLIT ====================
    
    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Split payments not supported by Ether Global Assets');
    }

    // ==================== SUB-CONTAS ====================
    
    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts not supported by Ether Global Assets');
    }

    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts not supported by Ether Global Assets');
    }

    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts not supported by Ether Global Assets');
    }

    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts not supported by Ether Global Assets');
    }

    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Sub-accounts not supported by Ether Global Assets');
    }

    // ==================== WALLETS ====================
    
    public function createWallet(WalletRequest $request): WalletResponse
    {
        throw new GatewayException('Wallets not supported by Ether Global Assets');
    }

    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets not supported by Ether Global Assets');
    }

    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallets not supported by Ether Global Assets');
    }

    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Wallets not supported by Ether Global Assets');
    }

    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Wallets not supported by Ether Global Assets');
    }

    // ==================== ESCROW ====================
    
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException('Escrow not supported by Ether Global Assets');
    }

    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow not supported by Ether Global Assets');
    }

    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Escrow not supported by Ether Global Assets');
    }

    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Escrow not supported by Ether Global Assets');
    }

    // ==================== TRANSFERÊNCIAS ====================
    
    public function transfer(TransferRequest $request): TransferResponse
    {
        $amountInCents = (int) round($request->money->amount() * 100);
        
        $response = $this->request('POST', '/pix/withdraw/pix-key', [
            'amount' => $amountInCents,
            'pixKey' => $request->pixKey,
            'pixKeyType' => $request->metadata['pixKeyType'] ?? 'EMAIL',
            'description' => $request->description,
        ]);

        return TransferResponse::create(
            success: true,
            transferId: $response['transactionId'] ?? $response['pixId'],
            amount: $request->money->amount(),
            status: strtolower($response['status'] ?? 'processing'),
            currency: $request->money->currency()->value,
            message: 'PIX transfer initiated',
            rawResponse: $response
        );
    }

    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        throw new GatewayException('Scheduled transfers not supported by Ether Global Assets');
    }

    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        throw new GatewayException('Scheduled transfers not supported by Ether Global Assets');
    }

    // ==================== LINK DE PAGAMENTO ====================
    
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException('Payment links not supported by Ether Global Assets');
    }

    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links not supported by Ether Global Assets');
    }

    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links not supported by Ether Global Assets');
    }

    // ==================== CLIENTES ====================
    
    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        throw new GatewayException('Customer management not supported by Ether Global Assets');
    }

    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        throw new GatewayException('Customer management not supported by Ether Global Assets');
    }

    public function getCustomer(string $customerId): CustomerResponse
    {
        throw new GatewayException('Customer management not supported by Ether Global Assets');
    }

    public function listCustomers(array $filters = []): array
    {
        throw new GatewayException('Customer management not supported by Ether Global Assets');
    }

    // ==================== ANTIFRAUDE ====================
    
    public function analyzeTransaction(string $transactionId): array
    {
        throw new GatewayException('Anti-fraud not supported by Ether Global Assets');
    }

    public function addToBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist not supported by Ether Global Assets');
    }

    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist not supported by Ether Global Assets');
    }

    // ==================== WEBHOOKS ====================
    
    public function registerWebhook(string $url, array $events): array
    {
        throw new GatewayException('Webhook registration must be done via Ether Global Assets dashboard');
    }

    public function listWebhooks(): array
    {
        throw new GatewayException('Webhook management must be done via Ether Global Assets dashboard');
    }

    public function deleteWebhook(string $webhookId): bool
    {
        throw new GatewayException('Webhook management must be done via Ether Global Assets dashboard');
    }

    // ==================== SALDO ====================
    
    public function getBalance(): BalanceResponse
    {
        $response = $this->request('GET', '/account-balance');
        
        $balanceInReais = ($response['balance'] ?? 0) / 100;

        return new BalanceResponse(
            success: true,
            balance: $balanceInReais,
            availableBalance: $balanceInReais,
            pendingBalance: 0.0,
            currency: 'BRL',
            rawResponse: $response
        );
    }

    public function getSettlementSchedule(array $filters = []): array
    {
        throw new GatewayException('Settlement schedule not supported by Ether Global Assets');
    }

    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        throw new GatewayException('Receivables anticipation not supported by Ether Global Assets');
    }
}