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
 * NuBank Gateway — NuPay for Business
 *
 * ⚠️  O NuPay NÃO é um gateway de pagamentos tradicional.
 *     É um método de pagamento exclusivo para clientes Nubank,
 *     que funciona via REDIRECIONAMENTO para o app do Nubank.
 *     O cliente autoriza com a senha de 4 dígitos dentro do app.
 *     Sua loja nunca toca em dados de cartão ou conta bancária.
 *
 * ✅  O QUE ESTA API REALMENTE FAZ:
 *     - Criar pedido de pagamento NuPay → retorna paymentUrl
 *     - Consultar status do pagamento
 *     - Cancelar pagamento ainda não pago (WAITING_PAYMENT_METHOD)
 *     - Estornar pagamento total ou parcialmente
 *     - Consultar status de um estorno
 *
 * ❌  O QUE NÃO EXISTE NESTA API (lança GatewayException):
 *     - PIX (use C6Bank, Asaas, PagarMe, Banco do Brasil)
 *     - Cartão de crédito/débito direto
 *     - Boleto
 *     - Assinaturas/recorrência
 *     - Transferências, Wallets, Escrow, Split, Sub-contas
 *     - Saldo, Conciliação (existe API separada de conciliação)
 *
 * AUTENTICAÇÃO — dois headers fixos, sem OAuth2, sem Bearer token:
 *     X-Merchant-Key:   {sua API Key}
 *     X-Merchant-Token: {seu API Token}
 *     Obtidos no Painel do Lojista → seção Credenciais.
 *
 * ENDPOINTS REAIS (documentação oficial NuPay for Business):
 *     Sandbox:   https://sandbox-api.spinpay.com.br
 *     Produção:  https://api.spinpay.com.br
 *
 * @see https://docs.nupaybusiness.com.br/checkout/docs/openapi/index.html
 * @author  PaymentHub
 * @version 1.0.0
 */
class NuBankGateway implements PaymentGatewayInterface
{
    // URLs reais — confirmadas na documentação NuPay for Business
    private const PRODUCTION_URL = 'https://api.spinpay.com.br';
    private const SANDBOX_URL    = 'https://sandbox-api.spinpay.com.br';

    private string $baseUrl;

    public function __construct(
        /** API Key — Painel do Lojista NuPay → seção Credenciais */
        private readonly string  $merchantKey,
        /** API Token — Painel do Lojista NuPay → seção Credenciais */
        private readonly string  $merchantToken,
        private readonly bool    $sandbox      = false,
        /** Nome da loja exibido ao cliente no app Nubank */
        private readonly ?string $merchantName = null,
        /**
         * URL de callback global para receber notificações de status.
         * Pode ser sobrescrita por pagamento via metadata['callbackUrl'].
         */
        private readonly ?string $callbackUrl  = null,
    ) {
        $this->baseUrl = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    // ==================================================================
    //  HTTP CLIENT
    // ==================================================================

    /**
     * Executa uma requisição autenticada à API NuPay.
     *
     * Autenticação: X-Merchant-Key + X-Merchant-Token em todos os requests.
     * NÃO usa OAuth2. NÃO usa Bearer token.
     *
     * @param  string               $method  GET | POST
     * @param  string               $path    Ex.: /v1/checkouts/payments
     * @param  array<string, mixed> $body    Payload JSON (apenas para POST)
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Merchant-Key: '   . $this->merchantKey,
            'X-Merchant-Token: ' . $this->merchantToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if (!empty($body) && strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new GatewayException("NuPay: erro de conexão — {$curlError}", 0);
        }

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? "HTTP {$httpCode}";
            throw new GatewayException("NuPay: {$msg}", $httpCode, null, ['response' => $decoded]);
        }

        return $decoded;
    }

    // ==================================================================
    //  HELPER — mapear status NuPay → PaymentStatus interno
    // ==================================================================

    /**
     * Status documentados pela API NuPay for Business:
     *
     * Pagamento : WAITING_PAYMENT_METHOD | AUTHORIZED | COMPLETED | CANCELLED | ERROR
     * Cancelamento: CANCELLING | CANCELLED | DENIED | ERROR
     * Estorno   : OPEN | REFUNDING | ERROR
     */
    private function mapStatus(string $nuPayStatus): PaymentStatus
    {
        return match (strtoupper($nuPayStatus)) {
            'WAITING_PAYMENT_METHOD',
            'WAITING_FOR_PAYMENT_METHOD' => PaymentStatus::PENDING,
            'AUTHORIZED'                 => PaymentStatus::PROCESSING,
            'COMPLETED'                  => PaymentStatus::APPROVED,
            'CANCELLING'                 => PaymentStatus::PROCESSING,
            'CANCELLED'                  => PaymentStatus::CANCELLED,
            'DENIED'                     => PaymentStatus::FAILED,
            'OPEN', 'REFUNDING'          => PaymentStatus::PROCESSING,
            'ERROR'                      => PaymentStatus::FAILED,
            default                      => PaymentStatus::PENDING,
        };
    }

    // ==================================================================
    //  ✅  CRIAR PAGAMENTO NUPAY
    //      POST /v1/checkouts/payments
    //
    //  O NuPay usa PixPaymentRequest como veículo de dados pois é o
    //  tipo de request de pagamento mais próximo estruturalmente.
    //  O campo pixKey NÃO é usado — NuPay não é PIX.
    //
    //  Campos obrigatórios via metadata:
    //    metadata['merchantOrderReference'] — ID único do pedido na sua loja
    //    metadata['referenceId']            — UUID único por pagamento
    //
    //  Campos opcionais via metadata:
    //    metadata['returnUrl']        — redirecionar após pagamento aprovado
    //    metadata['cancelUrl']        — redirecionar se cliente cancelar no app
    //    metadata['storeName']        — nome da filial/loja
    //    metadata['delayToAutoCancel']— minutos até auto-cancelar (padrão: 30)
    //    metadata['authorizationType']— 'manually_authorized' (padrão) ou OAuth2
    //    metadata['items']            — array de itens do pedido
    //    metadata['shipping']         — dados de frete
    //    metadata['billingAddress']   — endereço de cobrança
    //    metadata['callbackUrl']      — sobrescreve o callbackUrl do construtor
    //    metadata['recipients']       — beneficiários finais (Circular BCB 3.978/2020)
    //
    //  A resposta contém rawResponse['_paymentUrl']:
    //  REDIRECIONE o cliente para essa URL para abrir o app Nubank.
    // ==================================================================

    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        $meta = $request->metadata ?? [];

        if (empty($meta['merchantOrderReference'])) {
            throw new \InvalidArgumentException(
                'NuPay: metadata[merchantOrderReference] é obrigatório e deve ser único por loja.'
            );
        }

        if (empty($meta['referenceId'])) {
            throw new \InvalidArgumentException(
                'NuPay: metadata[referenceId] é obrigatório e deve ser único por pagamento (use UUID v4).'
            );
        }

        // Separar primeiro nome e sobrenome do customerName
        $nameParts = explode(' ', trim($request->customerName ?? ''), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $doc     = preg_replace('/\D/', '', $request->getCustomerDocument() ?? '');
        $docType = strlen($doc) === 14 ? 'CNPJ' : 'CPF';

        $body = [
            'merchantOrderReference' => (string) $meta['merchantOrderReference'],
            'referenceId'            => (string) $meta['referenceId'],
            'amount' => [
                'value'    => $request->getAmount(),
                'currency' => 'BRL',
            ],
            'paymentMethod' => [
                'type'              => 'nupay',
                'authorizationType' => $meta['authorizationType'] ?? 'manually_authorized',
            ],
            'shopper' => [
                'reference'    => $request->customerEmail ?? '',
                'firstName'    => $firstName,
                'lastName'     => $lastName,
                'document'     => $doc,
                'documentType' => $docType,
                'email'        => $request->customerEmail ?? '',
                'locale'       => 'pt-BR',
            ],
            'items' => $meta['items'] ?? [
                [
                    'id'          => '1',
                    'description' => $request->description ?? 'Pedido',
                    'value'       => $request->getAmount(),
                    'quantity'    => 1,
                ],
            ],
            'delayToAutoCancel' => max(1, (int) ($meta['delayToAutoCancel'] ?? 30)),
        ];

        // URLs de redirecionamento pós-pagamento
        if (!empty($meta['returnUrl']) || !empty($meta['cancelUrl'])) {
            $body['paymentFlow'] = [
                'returnUrl' => $meta['returnUrl'] ?? '',
                'cancelUrl' => $meta['cancelUrl'] ?? '',
            ];
        }

        if ($this->merchantName || !empty($meta['storeName'])) {
            $body['merchantName'] = $this->merchantName ?? '';
            $body['storeName']    = $meta['storeName'] ?? '';
        }

        // callbackUrl: metadata tem prioridade sobre o construtor
        $callbackUrl = $meta['callbackUrl'] ?? $this->callbackUrl;
        if ($callbackUrl) {
            $body['callbackUrl'] = $callbackUrl;
        }

        if (!empty($meta['shipping'])) {
            $body['shipping'] = $meta['shipping'];
        }

        if (!empty($meta['billingAddress'])) {
            $body['billingAddress'] = $meta['billingAddress'];
        }

        // Beneficiários finais (exigência BCB Circular 3.978/2020)
        if (!empty($meta['recipients'])) {
            $body['recipients'] = $meta['recipients'];
        }

        $response = $this->request('POST', '/v1/checkouts/payments', $body);

        return PaymentResponse::create(
            success:         isset($response['pspReferenceId']),
            transactionId:   $response['pspReferenceId'] ?? '',
            status:          $this->mapStatus($response['status'] ?? 'WAITING_PAYMENT_METHOD'),
            amount:          $request->getAmount(),
            currency:        Currency::BRL,
            gatewayResponse: $response,
            rawResponse: [
                // ⬇  DADO MAIS IMPORTANTE: redirecione o cliente para esta URL
                '_paymentUrl'        => $response['paymentUrl'] ?? null,
                '_paymentMethodType' => $response['paymentMethodType'] ?? 'nupay',
                '_referenceId'       => $response['referenceId'] ?? null,
                '_pspReferenceId'    => $response['pspReferenceId'] ?? null,
                '_status'            => $response['status'] ?? null,
                '_raw'               => $response,
            ],
        );
    }

    // ==================================================================
    //  ✅  CONSULTAR STATUS DO PAGAMENTO
    //      GET /v1/checkouts/payments/{pspReferenceId}/status
    // ==================================================================

    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        $response = $this->request('GET', "/v1/checkouts/payments/{$transactionId}/status");

        return TransactionStatusResponse::create(
            success:       true,
            transactionId: $response['pspReferenceId'] ?? $transactionId,
            status:        $this->mapStatus($response['status'] ?? 'WAITING_PAYMENT_METHOD'),
            amount:        (float) ($response['amount']['value'] ?? 0),
            currency:      Currency::BRL,
            rawResponse:   $response,
        );
    }

    // ==================================================================
    //  ✅  CANCELAR PAGAMENTO NÃO PAGO
    //      POST /v1/checkouts/payments/{pspReferenceId}/cancel
    //      Só funciona para status WAITING_PAYMENT_METHOD.
    //      Para pagamentos já pagos, use refund().
    // ==================================================================

    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        $response = $this->request('POST', "/v1/checkouts/payments/{$transactionId}/cancel");

        return PaymentResponse::create(
            success:         in_array($response['status'] ?? '', ['CANCELLING', 'CANCELLED'], true),
            transactionId:   $response['pspReferenceId'] ?? $transactionId,
            status:          $this->mapStatus($response['status'] ?? 'CANCELLED'),
            amount:          0,
            currency:        Currency::BRL,
            gatewayResponse: $response,
            rawResponse:     $response,
        );
    }

    // ==================================================================
    //  ✅  ESTORNO TOTAL OU PARCIAL
    //      POST /v1/checkouts/payments/{pspReferenceId}/refunds
    //      Estornos parciais são suportados.
    //      A soma dos estornos não pode ultrapassar o valor total.
    // ==================================================================

    public function refund(RefundRequest $request): RefundResponse
    {
        $body = [
            'amount'              => [
                'value'    => $request->amount ?? 0,
                'currency' => 'BRL',
            ],
            'transactionRefundId' => uniqid('refund_', true),
            'notes'               => $request->reason ?? '',
        ];

        $response = $this->request(
            'POST',
            "/v1/checkouts/payments/{$request->transactionId}/refunds",
            $body
        );

        return RefundResponse::create(
            success:       in_array($response['status'] ?? '', ['REFUNDING', 'OPEN'], true),
            refundId:      $response['refundId'] ?? '',
            transactionId: $request->transactionId,
            amount:        $request->amount ?? 0,
            status:        $this->mapStatus($response['status'] ?? 'REFUNDING')->value,
            rawResponse:   $response,
        );
    }

    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        $body = [
            'amount'              => ['value' => $amount, 'currency' => 'BRL'],
            'transactionRefundId' => uniqid('partial_refund_', true),
        ];

        $response = $this->request('POST', "/v1/checkouts/payments/{$transactionId}/refunds", $body);

        return RefundResponse::create(
            success:       in_array($response['status'] ?? '', ['REFUNDING', 'OPEN'], true),
            refundId:      $response['refundId'] ?? '',
            transactionId: $transactionId,
            amount:        $amount,
            status:        $this->mapStatus($response['status'] ?? 'REFUNDING')->value,
            rawResponse:   $response,
        );
    }

    // ==================================================================
    //  ✅  CONSULTAR STATUS DE UM ESTORNO
    //      GET /v1/checkouts/payments/{pspReferenceId}/refunds/{refundId}
    //      Use: getChargebacks(['pspReferenceId' => '...', 'refundId' => '...'])
    // ==================================================================

    public function getChargebacks(array $filters = []): array
    {
        if (!empty($filters['pspReferenceId']) && !empty($filters['refundId'])) {
            $response = $this->request(
                'GET',
                "/v1/checkouts/payments/{$filters['pspReferenceId']}/refunds/{$filters['refundId']}"
            );
            return [$response];
        }

        throw new GatewayException(
            'NuPay: informe filters[pspReferenceId] e filters[refundId] para consultar um estorno. ' .
            'Listagem geral de chargebacks não existe nesta API — use o Painel do Lojista NuPay for Business.',
            501
        );
    }

    // ==================================================================
    //  ❌  MÉTODOS NÃO SUPORTADOS
    //      Padrão idêntico ao PayPal e EBANX no projeto.
    //      Cada método lança GatewayException com explicação e alternativa.
    // ==================================================================

    /** @throws GatewayException sempre */
    private function notSupported(string $feature, string $alternative = ''): never
    {
        $msg = "NuPay for Business não suporta {$feature}. "
             . "O NuPay é exclusivo para pagamentos via app Nubank.";
        if ($alternative) {
            $msg .= " Use: {$alternative}.";
        }
        throw new GatewayException($msg, 501);
    }

    // PIX — NuPay e PIX são produtos completamente diferentes
    public function getPixQrCode(string $transactionId): string
    {
        $this->notSupported('PIX QR Code', 'C6Bank, Asaas, PagarMe, Banco do Brasil');
    }

    public function getPixCopyPaste(string $transactionId): string
    {
        $this->notSupported('PIX Copia e Cola', 'C6Bank, Asaas, PagarMe, Banco do Brasil');
    }

    // Cartão de crédito
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        $this->notSupported('cartão de crédito', 'Asaas, PagarMe, C6Bank, Adyen, Stripe');
    }

    public function tokenizeCard(array $cardData): string
    {
        $this->notSupported('tokenização de cartão', 'Asaas, PagarMe, C6Bank, Adyen, Stripe');
    }

    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        $this->notSupported('pré-autorização de cartão', 'Asaas, PagarMe, C6Bank, Adyen');
    }

    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        $this->notSupported('cancelamento de pré-autorização', 'Asaas, PagarMe, C6Bank, Adyen');
    }

    // Cartão de débito
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        $this->notSupported('cartão de débito', 'Asaas, PagarMe, C6Bank');
    }

    // Boleto — cancelBoleto() foi reaproveitado para cancelar pedido NuPay não pago
    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        $this->notSupported('boleto bancário', 'Asaas, PagarMe, C6Bank, Banco do Brasil');
    }

    public function getBoletoUrl(string $transactionId): string
    {
        $this->notSupported('boleto bancário', 'Asaas, PagarMe, C6Bank, Banco do Brasil');
    }

    // Assinaturas
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->notSupported('assinaturas/recorrência', 'Asaas, PagarMe, C6Bank');
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->notSupported('assinaturas/recorrência', 'Asaas, PagarMe, C6Bank');
    }

    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->notSupported('assinaturas/recorrência', 'Asaas, PagarMe, C6Bank');
    }

    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->notSupported('assinaturas/recorrência', 'Asaas, PagarMe, C6Bank');
    }

    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        $this->notSupported('assinaturas/recorrência', 'Asaas, PagarMe, C6Bank');
    }

    // Listagem de transações
    public function listTransactions(array $filters = []): array
    {
        $this->notSupported(
            'listagem de transações',
            'use a Conciliation API: docs.nupaybusiness.com.br/checkout/sellers/conciliation-api/openapi'
        );
    }

    // Chargebacks
    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        $this->notSupported('disputa de chargeback via API', 'Painel do Lojista NuPay for Business');
    }

    // Split — NuPay suporta "recipients" na criação, mas não split independente
    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        $this->notSupported(
            'split de pagamento como endpoint independente',
            'passe metadata[recipients] em createPixPayment() para definir beneficiários finais'
        );
    }

    // Sub-contas
    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        $this->notSupported('sub-contas', 'Asaas, PagarMe, C6Bank');
    }

    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        $this->notSupported('sub-contas', 'Asaas, PagarMe, C6Bank');
    }

    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        $this->notSupported('sub-contas', 'Asaas, PagarMe, C6Bank');
    }

    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        $this->notSupported('sub-contas', 'Asaas, PagarMe, C6Bank');
    }

    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        $this->notSupported('sub-contas', 'Asaas, PagarMe, C6Bank');
    }

    // Wallets
    public function createWallet(WalletRequest $request): WalletResponse
    {
        $this->notSupported('wallets', 'C6Bank, Asaas');
    }

    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        $this->notSupported('wallets', 'C6Bank, Asaas');
    }

    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        $this->notSupported('wallets', 'C6Bank, Asaas');
    }

    public function getWalletBalance(string $walletId): BalanceResponse
    {
        $this->notSupported('wallets', 'C6Bank, Asaas');
    }

    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        $this->notSupported('wallets', 'C6Bank, Asaas');
    }

    // Escrow
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        $this->notSupported('escrow/custódia', 'C6Bank, PagarMe');
    }

    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        $this->notSupported('escrow/custódia', 'C6Bank, PagarMe');
    }

    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        $this->notSupported('escrow/custódia', 'C6Bank, PagarMe');
    }

    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        $this->notSupported('escrow/custódia', 'C6Bank, PagarMe');
    }

    // Transferências
    public function transfer(TransferRequest $request): TransferResponse
    {
        $this->notSupported('transferências', 'C6Bank, Banco do Brasil, Asaas');
    }

    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        $this->notSupported('transferências agendadas', 'C6Bank, Banco do Brasil');
    }

    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        $this->notSupported('transferências agendadas', 'C6Bank, Banco do Brasil');
    }

    // Links de pagamento
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        $this->notSupported('links de pagamento', 'Asaas, PagarMe, C6Bank');
    }

    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        $this->notSupported('links de pagamento', 'Asaas, PagarMe, C6Bank');
    }

    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        $this->notSupported('links de pagamento', 'Asaas, PagarMe, C6Bank');
    }

    // Clientes — dados do shopper vão junto à criação do pagamento
    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        $this->notSupported(
            'gestão de clientes como recurso separado',
            'os dados do cliente (shopper) são enviados na criação de cada pagamento'
        );
    }

    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        $this->notSupported('gestão de clientes como recurso separado');
    }

    public function getCustomer(string $customerId): CustomerResponse
    {
        $this->notSupported('gestão de clientes como recurso separado');
    }

    public function listCustomers(array $filters = []): array
    {
        $this->notSupported('gestão de clientes como recurso separado');
    }

    // Antifraude — análise interna do Nubank, não exposta via API
    public function analyzeTransaction(string $transactionId): array
    {
        $this->notSupported(
            'análise de antifraude via API',
            'a análise é feita internamente pelo Nubank de forma automática e transparente'
        );
    }

    public function addToBlacklist(string $identifier, string $type): bool
    {
        $this->notSupported('blacklist via API', 'Painel do Lojista NuPay for Business');
    }

    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        $this->notSupported('blacklist via API', 'Painel do Lojista NuPay for Business');
    }

    // Webhooks — configurados via callbackUrl na criação ou no Painel
    public function registerWebhook(string $url, array $events): array
    {
        $this->notSupported(
            'registro de webhooks via API',
            'passe callbackUrl no construtor do gateway ou em metadata[callbackUrl] por pagamento. ' .
            'Configuração global é feita no Painel do Lojista NuPay for Business.'
        );
    }

    public function listWebhooks(): array
    {
        $this->notSupported('listagem de webhooks via API', 'Painel do Lojista NuPay for Business');
    }

    public function deleteWebhook(string $webhookId): bool
    {
        $this->notSupported('remoção de webhooks via API', 'Painel do Lojista NuPay for Business');
    }

    // Saldo — liquidação automática em D+1 útil, sem necessidade de saque
    public function getBalance(): BalanceResponse
    {
        $this->notSupported(
            'consulta de saldo via API',
            'liquidação é automática em D+1 útil. Consulte o Painel do Lojista NuPay for Business.'
        );
    }

    public function getSettlementSchedule(array $filters = []): array
    {
        $this->notSupported(
            'agenda de liquidação via esta API',
            'use a Conciliation API: docs.nupaybusiness.com.br/checkout/sellers/conciliation-api/openapi'
        );
    }

    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        $this->notSupported(
            'antecipação de recebíveis',
            'NuPay liquida automaticamente em D+1 útil — não há antecipação disponível'
        );
    }
}