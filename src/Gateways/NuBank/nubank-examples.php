<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use IsraelNogueira\PaymentHub\Gateways\NuBank\NuBankGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Enums\SubscriptionInterval;

// ==================== CONFIGURAÇÃO ====================

$gateway = new NuBankGateway(
    clientId:     'seu_client_id_aqui',
    clientSecret: 'seu_client_secret_aqui',
    sandbox:      true,
    // certPath:  '/path/to/nubank.pem',   // necessário em produção
);

echo "=== NUBANK GATEWAY - EXEMPLOS COMPLETOS ===\n\n";

// ==================== 1. PIX ====================

echo "=== 1. PIX ===\n\n";

try {
    $pixRequest = new PixPaymentRequest(
        amount:           150.00,
        currency:         'BRL',
        customerName:     'Maria Silva',
        customerDocument: '12345678900',
        customerEmail:    'maria@example.com',
        pixKey:           'chave@nubank.com.br',
        description:      'Pagamento do pedido #1234',
    );

    $payment  = $gateway->createPixPayment($pixRequest);
    $qrCode   = $gateway->getPixQrCode($payment->transactionId);
    $copyPaste = $gateway->getPixCopyPaste($payment->transactionId);

    echo "✅ PIX criado!\n";
    echo "Transaction ID: {$payment->transactionId}\n";
    echo "Status: {$payment->status->value}\n";
    echo "Código PIX: " . substr($copyPaste, 0, 60) . "...\n\n";

} catch (Exception $e) {
    echo "❌ Erro PIX: {$e->getMessage()}\n\n";
}

// ==================== 2. CARTÃO DE CRÉDITO ====================

echo "=== 2. CARTÃO DE CRÉDITO ===\n\n";

try {
    // Tokenizar cartão (recomendado para segurança PCI)
    $token = $gateway->tokenizeCard([
        'number'       => '4111111111111111',
        'holder_name'  => 'MARIA SILVA',
        'expiry_month' => '12',
        'expiry_year'  => '2028',
        'cvv'          => '123',
    ]);
    echo "✅ Cartão tokenizado: {$token}\n";

    $cardRequest = new CreditCardPaymentRequest(
        amount:           500.00,
        cardToken:        $token,
        installments:     3,
        capture:          true,
        customerDocument: '12345678900',
        customerEmail:    'maria@example.com',
        customerName:     'Maria Silva',
        description:      'Compra parcelada',
    );

    $payment = $gateway->createCreditCardPayment($cardRequest);
    echo "✅ Pagamento no cartão criado!\n";
    echo "Transaction ID: {$payment->transactionId}\n";
    echo "Status: {$payment->status->value}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Cartão: {$e->getMessage()}\n\n";
}

// ==================== 3. BOLETO ====================

echo "=== 3. BOLETO ===\n\n";

try {
    $boletoRequest = new BoletoPaymentRequest(
        amount:           320.00,
        customerName:     'João Oliveira',
        customerDocument: '98765432100',
        customerEmail:    'joao@example.com',
        dueDate:          date('Y-m-d', strtotime('+5 days')),
        description:      'Mensalidade abril/2026',
        customerAddress:  [
            'street'       => 'Rua das Flores',
            'number'       => '100',
            'neighborhood' => 'Centro',
            'city'         => 'São Paulo',
            'state'        => 'SP',
            'postal_code'  => '01310-100',
        ],
    );

    $boleto    = $gateway->createBoleto($boletoRequest);
    $boletoUrl = $gateway->getBoletoUrl($boleto->transactionId);

    echo "✅ Boleto criado!\n";
    echo "Transaction ID: {$boleto->transactionId}\n";
    echo "Barcode: {$boleto->boletoBarcode}\n";
    echo "URL: {$boletoUrl}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Boleto: {$e->getMessage()}\n\n";
}

// ==================== 4. ESTORNO ====================

echo "=== 4. ESTORNO ==\n\n";

try {
    $refund = $gateway->refund(new RefundRequest(
        transactionId: 'txn_exemplo_123',
        amount:        150.00,
        reason:        'Produto devolvido pelo cliente',
    ));

    echo "✅ Estorno criado!\n";
    echo "Refund ID: {$refund->refundId}\n";
    echo "Status: {$refund->status}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Estorno: {$e->getMessage()}\n\n";
}

// ==================== 5. TRANSFERÊNCIA VIA PIX ====================

echo "=== 5. TRANSFERÊNCIA ==\n\n";

try {
    $transfer = $gateway->transfer(new TransferRequest(
        amount:        200.00,
        recipientName: 'Carlos Souza',
        description:   'Pagamento de fornecedor',
        metadata:      ['pix_key' => 'carlos@email.com'],
    ));

    echo "✅ Transferência enviada!\n";
    echo "Transfer ID: {$transfer->transferId}\n";
    echo "Status: {$transfer->status->value}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Transferência: {$e->getMessage()}\n\n";
}

// ==================== 6. ASSINATURA ====================

echo "=== 6. ASSINATURA ===\n\n";

try {
    $subscription = $gateway->createSubscription(new SubscriptionRequest(
        amount:        49.90,
        interval:      SubscriptionInterval::MONTHLY,
        description:   'Plano Premium',
        customerName:  'Ana Costa',
        customerEmail: 'ana@example.com',
        cardToken:     'tok_exemplo_123',
    ));

    echo "✅ Assinatura criada!\n";
    echo "Subscription ID: {$subscription->subscriptionId}\n";
    echo "Status: {$subscription->status->value}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Assinatura: {$e->getMessage()}\n\n";
}

// ==================== 7. SALDO ====================

echo "=== 7. SALDO ===\n\n";

try {
    $balance = $gateway->getBalance();

    echo "✅ Saldo obtido!\n";
    echo "Saldo disponível: R$ " . number_format($balance->balance, 2, ',', '.') . "\n\n";

} catch (Exception $e) {
    echo "❌ Erro Saldo: {$e->getMessage()}\n\n";
}

// ==================== 8. CLIENTE ====================

echo "=== 8. CLIENTE ===\n\n";

try {
    $customer = $gateway->createCustomer(new CustomerRequest(
        name:   'Pedro Lima',
        email:  'pedro@example.com',
        taxId:  '11122233344',
        phone:  '11987654321',
    ));

    echo "✅ Cliente criado!\n";
    echo "Customer ID: {$customer->customerId}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Cliente: {$e->getMessage()}\n\n";
}

// ==================== 9. LINK DE PAGAMENTO ====================

echo "=== 9. LINK DE PAGAMENTO ===\n\n";

try {
    $link = $gateway->createPaymentLink(new PaymentLinkRequest(
        amount:      99.90,
        description: 'Compra online - Produto XYZ',
        expiresAt:   date('Y-m-d\TH:i:s\Z', strtotime('+7 days')),
    ));

    echo "✅ Link criado!\n";
    echo "Link ID: {$link->paymentLinkId}\n";
    echo "URL: {$link->url}\n\n";

} catch (Exception $e) {
    echo "❌ Erro Link: {$e->getMessage()}\n\n";
}

// ==================== 10. WEBHOOK ====================

echo "=== 10. WEBHOOK ===\n\n";

try {
    $webhook = $gateway->registerWebhook(
        'https://seusite.com.br/webhooks/nubank',
        ['pix.received', 'payment.paid', 'boleto.paid', 'refund.completed']
    );

    echo "✅ Webhook registrado!\n";
    echo "Webhook ID: " . ($webhook['id'] ?? 'N/A') . "\n\n";

    $webhooks = $gateway->listWebhooks();
    echo "Total de webhooks: " . count($webhooks) . "\n\n";

} catch (Exception $e) {
    echo "❌ Erro Webhook: {$e->getMessage()}\n\n";
}

echo "=== FIM DOS EXEMPLOS ===\n";
