<?php

namespace IsraelNogueira\PaymentHub\Gateways\PagarMe;

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
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
 
class PagarMeGateway implements PaymentGatewayInterface
{
    private const PRODUCTION_URL = 'https://api.pagar.me/core/v5';
    private const SANDBOX_URL = 'https://api.pagar.me/core/v5';
    
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;
    private bool $sandbox;

    public function __construct(
        string $secretKey,
        string $publicKey = '',
        bool $sandbox = false
    ) {
        // Validar formato da chave secreta
        if (!str_starts_with($secretKey, 'sk_test_') && !str_starts_with($secretKey, 'sk_live_')) {
            throw new GatewayException('Invalid Pagar.me secret key format. Must start with sk_test_ or sk_live_');
        }

        // Validar consistência entre chave e modo
        $isTestKey = str_starts_with($secretKey, 'sk_test_');
        if ($isTestKey !== $sandbox) {
            throw new GatewayException(
                'Secret key mode mismatch: sk_test_ requires sandbox=true, sk_live_ requires sandbox=false'
            );
        }

        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
        $this->sandbox = $sandbox;
        $this->baseUrl = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    // ==================== MÉTODOS PRIVADOS ====================

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new GatewayException('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $decoded['message'] ?? $decoded['errors'][0]['message'] ?? 'Request failed';
            throw new GatewayException(
                $errorMessage,
                $httpCode,
                null,
                ['response' => $decoded]
            );
        }

        return $decoded ?? [];
    }

    private function mapPagarMeStatus(string $status): PaymentStatus
    {
        $statusMap = [
            'paid' => PaymentStatus::PAID,
            'waiting_payment' => PaymentStatus::PENDING,
            'pending' => PaymentStatus::PENDING,
            'processing' => PaymentStatus::PROCESSING,
            'authorized' => PaymentStatus::APPROVED,
            'refused' => PaymentStatus::FAILED,
            'refunded' => PaymentStatus::REFUNDED,
            'canceled' => PaymentStatus::CANCELLED,
            'failed' => PaymentStatus::FAILED,
            'chargedback' => PaymentStatus::REFUNDED,
        ];

        return $statusMap[$status] ?? PaymentStatus::PENDING;
    }

    private function formatAmount(float $amount): int
    {
        // Pagar.me usa centavos
        return (int)($amount * 100);
    }

    private function parseAmount(int $cents): float
    {
        return $cents / 100;
    }

    // ==================== CLIENTES ====================

    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'type' => 'individual',
        ];

        if ($request->documentNumber) {
            $document = preg_replace('/\D/', '', $request->documentNumber);
            $data['document'] = $document;
            $data['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
        }

        if ($request->phone) {
            $phone = preg_replace('/\D/', '', $request->phone);
            $data['phones'] = [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code' => substr($phone, 0, 2),
                    'number' => substr($phone, 2),
                ],
            ];
        }

        if ($request->address) {
            $data['address'] = [
                'line_1' => $request->address['street'] ?? '',
                'line_2' => $request->address['complement'] ?? '',
                'zip_code' => preg_replace('/\D/', '', $request->address['zipcode'] ?? ''),
                'city' => $request->address['city'] ?? '',
                'state' => $request->address['state'] ?? '',
                'country' => 'BR',
            ];
        }

        $response = $this->request('POST', '/customers', $data);

        return new CustomerResponse(
            success: true,
            customerId: $response['id'],
            message: 'Customer created successfully',
            rawResponse: $response
        );
    }

    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        $response = $this->request('PUT', "/customers/{$customerId}", $data);

        return new CustomerResponse(
            success: true,
            customerId: $response['id'],
            message: 'Customer updated successfully',
            rawResponse: $response
        );
    }

    public function getCustomer(string $customerId): CustomerResponse
    {
        $response = $this->request('GET', "/customers/{$customerId}");

        return new CustomerResponse(
            success: true,
            customerId: $response['id'],
            message: null,
            rawResponse: $response
        );
    }

    public function listCustomers(array $filters = []): array
    {
        $response = $this->request('GET', '/customers', $filters);
        return $response['data'] ?? [];
    }

    // ==================== PIX ====================

    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount' => $this->formatAmount($request->money->amount()),
            'payment_method' => 'pix',
            'pix' => [
                'expires_in' => $request->expiresInMinutes ? $request->expiresInMinutes * 60 : 3600,
            ],
        ];

        // Customer data
        if ($request->customerName || $request->customerEmail || $request->customerDocument) {
            $data['customer'] = [
                'name' => $request->customerName ?? 'Cliente',
                'email' => $request->customerEmail?->value() ?? 'cliente@email.com',
                'type' => 'individual',
            ];

            if ($request->customerDocument) {
                $document = preg_replace('/\D/', '', $request->customerDocument->value());
                $data['customer']['document'] = $document;
                $data['customer']['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
            }
        }

        if ($request->metadata) {
            $data['metadata'] = $request->metadata;
        }

        $response = $this->request('POST', '/orders', $data);

        $charge = $response['charges'][0] ?? [];
        $pixData = $charge['last_transaction']['qr_code_url'] ?? null;
        $qrCode = $charge['last_transaction']['qr_code'] ?? null;

        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: $response['status'],
            rawResponse: $response,
            metadata: [
                'qr_code' => $qrCode,
                'qr_code_url' => $pixData,
                'expires_at' => $charge['last_transaction']['expires_at'] ?? null,
            ]
        );
    }

    public function getPixQrCode(string $transactionId): string
    {
        $response = $this->request('GET', "/orders/{$transactionId}");
        $charge = $response['charges'][0] ?? [];
        return $charge['last_transaction']['qr_code'] ?? '';
    }

    public function getPixCopyPaste(string $transactionId): string
    {
        return $this->getPixQrCode($transactionId);
    }

    // ==================== CARTÃO DE CRÉDITO ====================

    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount' => $this->formatAmount($request->money->amount()),
            'payment_method' => 'credit_card',
            'credit_card' => [
                'installments' => $request->installments,
                'statement_descriptor' => 'Pagamento',
                'card' => [
                    'number' => preg_replace('/\D/', '', $request->cardNumber->value()),
                    'holder_name' => $request->cardHolderName,
                    'exp_month' => (int)$request->cardExpiryMonth,
                    'exp_year' => (int)$request->cardExpiryYear,
                    'cvv' => $request->cardCvv,
                ],
            ],
        ];

        // Customer data
        if ($request->customerEmail || $request->customerDocument) {
            $data['customer'] = [
                'name' => $request->customerName ?? 'Cliente',
                'email' => $request->customerEmail?->value() ?? 'cliente@email.com',
                'type' => 'individual',
            ];

            if ($request->customerDocument) {
                $document = preg_replace('/\D/', '', $request->customerDocument);
                $data['customer']['document'] = $document;
                $data['customer']['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
            }
        }

        // Billing address
        if ($request->billingAddress) {
            $data['customer']['address'] = [
                'line_1' => $request->billingAddress['street'] ?? '',
                'line_2' => $request->billingAddress['complement'] ?? '',
                'zip_code' => preg_replace('/\D/', '', $request->billingAddress['zipcode'] ?? ''),
                'city' => $request->billingAddress['city'] ?? '',
                'state' => $request->billingAddress['state'] ?? '',
                'country' => 'BR',
            ];
        }

        // Capture
        if (isset($request->capture)) {
            $data['credit_card']['capture'] = $request->capture;
        }

        if ($request->metadata) {
            $data['metadata'] = $request->metadata;
        }

        $response = $this->request('POST', '/orders', $data);

        $charge = $response['charges'][0] ?? [];
        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: $response['status'],
            rawResponse: $response,
            metadata: [
                'installments' => $request->installments,
                'card_brand' => $charge['last_transaction']['card']['brand'] ?? null,
                'card_last4' => $charge['last_transaction']['card']['last_four_digits'] ?? null,
            ]
        );
    }

    public function tokenizeCard(array $cardData): string
    {
        $data = [
            'type' => 'card',
            'card' => [
                'number' => preg_replace('/\D/', '', $cardData['number']),
                'holder_name' => $cardData['holder_name'],
                'exp_month' => (int)$cardData['expiry_month'],
                'exp_year' => (int)$cardData['expiry_year'],
                'cvv' => $cardData['cvv'],
            ],
        ];

        $response = $this->request('POST', '/tokens', $data);
        return $response['id'];
    }

    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        $data = [];
        
        if ($amount !== null) {
            $data['amount'] = $this->formatAmount($amount);
        }

        $response = $this->request('POST', "/orders/{$transactionId}/capture", $data);

        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: 'Capture successful',
            rawResponse: $response
        );
    }

    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        $response = $this->request('DELETE', "/orders/{$transactionId}");

        return new PaymentResponse(
            success: true,
            transactionId: $transactionId,
            status: PaymentStatus::CANCELLED,
            money: Money::from(0, Currency::BRL),
            message: 'Authorization cancelled',
            rawResponse: $response
        );
    }

    // ==================== CARTÃO DE DÉBITO ====================

    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount' => $this->formatAmount($request->money->amount()),
            'payment_method' => 'debit_card',
            'debit_card' => [
                'card' => [
                    'number' => preg_replace('/\D/', '', $request->cardNumber->value()),
                    'holder_name' => $request->cardHolderName,
                    'exp_month' => (int)$request->cardExpiryMonth,
                    'exp_year' => (int)$request->cardExpiryYear,
                    'cvv' => $request->cardCvv,
                ],
            ],
        ];

        // Customer data
        if ($request->customerEmail || $request->customerDocument) {
            $data['customer'] = [
                'name' => $request->customerName ?? 'Cliente',
                'email' => $request->customerEmail?->value() ?? 'cliente@email.com',
                'type' => 'individual',
            ];

            if ($request->customerDocument) {
                $document = preg_replace('/\D/', '', $request->customerDocument);
                $data['customer']['document'] = $document;
                $data['customer']['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
            }
        }

        $response = $this->request('POST', '/orders', $data);

        $charge = $response['charges'][0] ?? [];
        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: $response['status'],
            rawResponse: $response,
            metadata: [
                'authentication_url' => $charge['last_transaction']['url'] ?? null,
            ]
        );
    }

    // ==================== BOLETO ====================

    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        $data = [
            'amount' => $this->formatAmount($request->money->amount()),
            'payment_method' => 'boleto',
            'boleto' => [
                'instructions' => $request->description ?? 'Pagamento',
                'due_at' => $request->dueDate ? date('Y-m-d', strtotime($request->dueDate)) : date('Y-m-d', strtotime('+3 days')),
            ],
        ];

        // Customer data
        $data['customer'] = [
            'name' => $request->customerName,
            'email' => $request->customerEmail?->value() ?? 'cliente@email.com',
            'type' => 'individual',
        ];

        if ($request->customerDocument) {
            $document = preg_replace('/\D/', '', $request->customerDocument->value());
            $data['customer']['document'] = $document;
            $data['customer']['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
        }

        // Billing address
        if ($request->address) {
            $data['customer']['address'] = [
                'line_1' => $request->address['street'] ?? '',
                'line_2' => $request->address['complement'] ?? '',
                'zip_code' => preg_replace('/\D/', '', $request->address['zipcode'] ?? ''),
                'city' => $request->address['city'] ?? '',
                'state' => $request->address['state'] ?? '',
                'country' => 'BR',
            ];
        }

        if ($request->metadata) {
            $data['metadata'] = $request->metadata;
        }

        $response = $this->request('POST', '/orders', $data);

        $charge = $response['charges'][0] ?? [];
        $boleto = $charge['last_transaction'] ?? [];
        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: $response['status'],
            rawResponse: $response,
            metadata: [
                'barcode' => $boleto['barcode'] ?? null,
                'pdf_url' => $boleto['pdf'] ?? null,
                'line' => $boleto['line'] ?? null,
                'due_at' => $boleto['due_at'] ?? null,
            ]
        );
    }

    public function getBoletoUrl(string $transactionId): string
    {
        $response = $this->request('GET', "/orders/{$transactionId}");
        $charge = $response['charges'][0] ?? [];
        return $charge['last_transaction']['pdf'] ?? '';
    }

    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        return $this->cancelPreAuthorization($transactionId);
    }

    // ==================== ASSINATURAS/RECORRÊNCIA ====================

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $data = [
            'plan_id' => $request->planId ?? null,
            'customer' => [
                'name' => $request->customerName ?? 'Cliente',
                'email' => $request->customerEmail ?? 'cliente@email.com',
            ],
            'payment_method' => 'credit_card',
        ];

        // Card token or card data
        if ($request->cardToken) {
            $data['card_id'] = $request->cardToken;
        }

        // Plano inline
        if (!$request->planId) {
            $intervalMap = [
                'daily' => 'day',
                'weekly' => 'week',
                'monthly' => 'month',
                'yearly' => 'year',
            ];

            $data['plan'] = [
                'name' => $request->description ?? 'Assinatura',
                'amount' => $this->formatAmount($request->money->amount()),
                'interval' => $intervalMap[$request->interval] ?? 'month',
                'interval_count' => 1,
            ];
        }

        if ($request->metadata) {
            $data['metadata'] = $request->metadata;
        }

        $response = $this->request('POST', '/subscriptions', $data);

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $response['id'],
            status: $response['status'],
            message: 'Subscription created successfully',
            rawResponse: $response
        );
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        $response = $this->request('DELETE', "/subscriptions/{$subscriptionId}");

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $subscriptionId,
            status: 'canceled',
            message: 'Subscription cancelled successfully',
            rawResponse: $response
        );
    }

    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscription suspension not directly supported - use cancelSubscription');
    }

    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Subscription reactivation not supported - create new subscription');
    }

    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        $response = $this->request('PUT', "/subscriptions/{$subscriptionId}", $data);

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $response['id'],
            status: $response['status'],
            message: 'Subscription updated successfully',
            rawResponse: $response
        );
    }

    // ==================== TRANSAÇÕES ====================

    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        $response = $this->request('GET', "/orders/{$transactionId}");

        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new TransactionStatusResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            rawResponse: $response
        );
    }

    public function listTransactions(array $filters = []): array
    {
        $response = $this->request('GET', '/orders', $filters);
        return $response['data'] ?? [];
    }

    // ==================== ESTORNOS E CHARGEBACKS ====================

    public function refund(RefundRequest $request): RefundResponse
    {
        $orderId = $request->transactionId;
        $response = $this->request('GET', "/orders/{$orderId}");
        
        $chargeId = $response['charges'][0]['id'] ?? null;
        if (!$chargeId) {
            throw new GatewayException('Charge not found for refund');
        }

        $refundData = [];
        if ($request->metadata) {
            $refundData['metadata'] = $request->metadata;
        }

        $refundResponse = $this->request('POST', "/charges/{$chargeId}/refund", $refundData);

        $money = Money::from($this->parseAmount($refundResponse['amount']), Currency::BRL);

        return new RefundResponse(
            success: true,
            refundId: $refundResponse['id'],
            transactionId: $orderId,
            money: $money,
            status: PaymentStatus::REFUNDED,
            rawResponse: $refundResponse
        );
    }

    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        $response = $this->request('GET', "/orders/{$transactionId}");
        
        $chargeId = $response['charges'][0]['id'] ?? null;
        if (!$chargeId) {
            throw new GatewayException('Charge not found for partial refund');
        }

        $refundData = [
            'amount' => $this->formatAmount($amount),
        ];

        $refundResponse = $this->request('POST', "/charges/{$chargeId}/refund", $refundData);

        $money = Money::from($this->parseAmount($refundResponse['amount']), Currency::BRL);

        return new RefundResponse(
            success: true,
            refundId: $refundResponse['id'],
            transactionId: $transactionId,
            money: $money,
            status: PaymentStatus::REFUNDED,
            rawResponse: $refundResponse
        );
    }

    public function getChargebacks(array $filters = []): array
    {
        // Pagar.me não tem endpoint específico para chargebacks
        // Eles vêm como eventos no webhook
        return [];
    }

    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        throw new GatewayException('Chargeback disputes must be managed via Pagar.me Dashboard');
    }

    // ==================== SPLIT DE PAGAMENTO ====================

    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        $splits = [];
        foreach ($request->splits as $split) {
            $splits[] = [
                'recipient_id' => $split['recipient_id'],
                'amount' => $this->formatAmount($split['amount']),
                'type' => $split['type'] ?? 'flat',
                'options' => [
                    'charge_processing_fee' => $split['charge_processing_fee'] ?? true,
                    'liable' => $split['liable'] ?? true,
                ],
            ];
        }

        $data = [
            'amount' => $this->formatAmount($request->money->amount()),
            'payment_method' => $request->paymentMethod ?? 'credit_card',
            'split' => $splits,
        ];

        // Adicionar dados do método de pagamento
        if ($request->cardToken) {
            $data['card_id'] = $request->cardToken;
        }

        $response = $this->request('POST', '/orders', $data);

        $money = Money::from($this->parseAmount($response['amount']), Currency::BRL);

        return new PaymentResponse(
            success: true,
            transactionId: $response['id'],
            status: $this->mapPagarMeStatus($response['status']),
            money: $money,
            message: 'Split payment created',
            rawResponse: $response
        );
    }

    // ==================== SUB-CONTAS (RECIPIENTS) ====================

    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'type' => 'individual',
        ];

        if ($request->documentNumber) {
            $document = preg_replace('/\D/', '', $request->documentNumber);
            $data['document'] = $document;
            $data['document_type'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
        }

        // Bank account
        if ($request->bankAccount) {
            $data['bank_account'] = [
                'bank' => $request->bankAccount['bank_code'],
                'branch_number' => $request->bankAccount['branch'],
                'branch_check_digit' => $request->bankAccount['branch_digit'] ?? '0',
                'account_number' => $request->bankAccount['account'],
                'account_check_digit' => $request->bankAccount['account_digit'],
                'type' => $request->bankAccount['type'] ?? 'checking',
                'holder_name' => $request->name,
                'holder_document' => preg_replace('/\D/', '', $request->documentNumber),
            ];
        }

        $response = $this->request('POST', '/recipients', $data);

        return new SubAccountResponse(
            success: true,
            subAccountId: $response['id'],
            status: $response['status'],
            rawResponse: $response
        );
    }

    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        $response = $this->request('PUT', "/recipients/{$subAccountId}", $data);

        return new SubAccountResponse(
            success: true,
            subAccountId: $response['id'],
            status: $response['status'],
            rawResponse: $response
        );
    }

    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        $response = $this->request('GET', "/recipients/{$subAccountId}");

        return new SubAccountResponse(
            success: true,
            subAccountId: $response['id'],
            status: $response['status'],
            rawResponse: $response
        );
    }

    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Recipient activation is automatic after validation');
    }

    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Recipient deactivation not available via API - contact Pagar.me support');
    }

    // ==================== WALLETS ====================

    public function createWallet(WalletRequest $request): WalletResponse
    {
        throw new GatewayException('Wallets not available - use Recipients for split payments');
    }

    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallet operations not available');
    }

    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Wallet operations not available');
    }

    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Wallet balance not available');
    }

    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Wallet transfers not available');
    }

    // ==================== ESCROW (CUSTÓDIA) ====================

    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException('Use pre-authorization (capture=false) for escrow-like behavior');
    }

    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Use capturePreAuthorization to release funds');
    }

    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Use capturePreAuthorization with amount parameter');
    }

    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Use cancelPreAuthorization to cancel held funds');
    }

    // ==================== TRANSFERÊNCIAS E SAQUES ====================

    public function transfer(TransferRequest $request): TransferResponse
    {
        $data = [
            'amount' => $this->formatAmount($request->amount),
            'recipient_id' => $request->recipientId,
        ];

        if ($request->metadata) {
            $data['metadata'] = $request->metadata;
        }

        $response = $this->request('POST', '/transfers', $data);

        return TransferResponse::create(
            success: true,
            transferId: $response['id'],
            amount: $this->parseAmount($response['amount']),
            status: $response['status'],
            currency: 'BRL',
            message: 'Transfer created successfully',
            rawResponse: $response
        );
    }

    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        throw new GatewayException('Scheduled transfers not available - use automatic transfer rules');
    }

    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        throw new GatewayException('Transfer cancellation not available');
    }

    // ==================== LINK DE PAGAMENTO ====================

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException('Payment links available via Pagar.me Dashboard - use /checkout endpoint');
    }

    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links not available via API');
    }

    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Payment links not available via API');
    }

    // ==================== ANTIFRAUDE ====================

    public function analyzeTransaction(string $transactionId): array
    {
        $response = $this->request('GET', "/orders/{$transactionId}");
        $charge = $response['charges'][0] ?? [];
        
        return [
            'risk_level' => $charge['last_transaction']['antifraud']['risk_level'] ?? 'unknown',
            'score' => $charge['last_transaction']['antifraud']['score'] ?? 0,
            'provider' => $charge['last_transaction']['antifraud']['provider_name'] ?? null,
        ];
    }

    public function addToBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist management available via Pagar.me Dashboard');
    }

    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Blacklist management available via Pagar.me Dashboard');
    }

    // ==================== WEBHOOKS ====================

    public function registerWebhook(string $url, array $events): array
    {
        $data = [
            'url' => $url,
            'events' => $events,
        ];

        $response = $this->request('POST', '/webhooks', $data);
        return $response;
    }

    public function listWebhooks(): array
    {
        $response = $this->request('GET', '/webhooks');
        return $response['data'] ?? [];
    }

    public function deleteWebhook(string $webhookId): bool
    {
        $this->request('DELETE', "/webhooks/{$webhookId}");
        return true;
    }

    // ==================== SALDO E CONCILIAÇÃO ====================

    public function getBalance(): BalanceResponse
    {
        $response = $this->request('GET', '/balance');

        $available = $this->parseAmount($response['available'][0]['amount'] ?? 0);
        $waitingFunds = $this->parseAmount($response['waiting_funds'][0]['amount'] ?? 0);
        $total = $available + $waitingFunds;

        return new BalanceResponse(
            success: true,
            balance: $total,
            availableBalance: $available,
            pendingBalance: $waitingFunds,
            currency: 'BRL',
            rawResponse: $response
        );
    }

    public function getSettlementSchedule(array $filters = []): array
    {
        throw new GatewayException('Settlement schedule available via Pagar.me Dashboard or Balance API');
    }

    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        throw new GatewayException('Anticipation available via Pagar.me Dashboard - contact support');
    }
}