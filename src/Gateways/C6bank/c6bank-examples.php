<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use IsraelNogueira\PaymentHub\Gateways\C6Bank\C6BankGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Enums\SubscriptionInterval;

// ==================== CONFIGURAÇÃO ====================

$gateway = new C6BankGateway(
    clientId: 'seu_client_id_aqui',
    clientSecret: 'seu_client_secret_aqui',
    sandbox: true,
    personId: '123'
);

echo "=== C6BANK GATEWAY - EXEMPLOS COMPLETOS ===\n\n";

// ==================== 1. PIX ====================

echo "=== 1. PIX ===\n\n";

try {
    $pixRequest = new PixPaymentRequest(
        amount: 150.00,
        currency: 'BRL',
        customerName: 'Maria Silva Santos',
        customerDocument: '12345678900',
        customerEmail: 'maria@example.com',
        pixKey: 'sua_chave_pix@c6bank.com',
        description: 'Pagamento de serviço #12345'
    );

    $payment = $gateway->createPixPayment($pixRequest);
    
    echo "✅ PIX criado!\n";
    echo "Transaction ID: " . $payment->transactionId . "\n";
    echo "Status: " . $payment->status->value . "\n";
    
    $qrCode = $gateway->getPixQrCode($payment->transactionId);
    $pixCode = $gateway->getPixCopyPaste($payment->transactionId);
    
    echo "Código PIX: " . substr($pixCode, 0, 50) . "...\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro PIX: " . $e->getMessage() . "\n\n";
}

// ==================== 2. CARTÃO DE CRÉDITO ====================

echo "=== 2. CARTÃO DE CRÉDITO ===\n\n";

try {
    // Pagamento com cartão
    $ccRequest = new CreditCardPaymentRequest(
        money: new Money(500.00, Currency::BRL),
        cardNumber: '4111111111111111',
        cardHolderName: 'João Silva',
        cardExpiryMonth: '12',
        cardExpiryYear: '2025',
        cardCvv: '123',
        customerDocument: '12345678900',
        customerEmail: 'joao@example.com',
        installments: 3,
        capture: true,
        description: 'Compra parcelada'
    );

    $payment = $gateway->createCreditCardPayment($ccRequest);
    
    echo "✅ Pagamento cartão criado!\n";
    echo "Transaction ID: " . $payment->transactionId . "\n";
    echo "Status: " . $payment->status->value . "\n\n";
    
    // Tokenizar cartão
    $token = $gateway->tokenizeCard([
        'number' => '4111111111111111',
        'holder_name' => 'João Silva',
        'exp_month' => '12',
        'exp_year' => '2025',
        'cvv' => '123'
    ]);
    
    echo "✅ Cartão tokenizado: " . $token . "\n\n";
    
    // Pré-autorização
    $preAuthRequest = new CreditCardPaymentRequest(
        money: new Money(300.00, Currency::BRL),
        cardNumber: '4111111111111111',
        cardHolderName: 'João Silva',
        cardExpiryMonth: '12',
        cardExpiryYear: '2025',
        cardCvv: '123',
        customerDocument: '12345678900',
        customerEmail: 'joao@example.com',
        capture: false,
        description: 'Pré-autorização'
    );
    
    $preAuth = $gateway->createCreditCardPayment($preAuthRequest);
    echo "✅ Pré-autorização criada: " . $preAuth->transactionId . "\n";
    
    // Capturar
    $captured = $gateway->capturePreAuthorization($preAuth->transactionId, 250.00);
    echo "✅ Captura parcial realizada\n";
    
    // Cancelar pré-autorização
    $cancelled = $gateway->cancelPreAuthorization($preAuth->transactionId);
    echo "✅ Pré-autorização cancelada\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Cartão: " . $e->getMessage() . "\n\n";
}

// ==================== 3. CARTÃO DE DÉBITO ====================

echo "=== 3. CARTÃO DE DÉBITO ===\n\n";

try {
    $dbRequest = new DebitCardPaymentRequest(
        money: new Money(200.00, Currency::BRL),
        cardNumber: '5555555555554444',
        cardHolderName: 'Maria Santos',
        cardExpiryMonth: '06',
        cardExpiryYear: '2026',
        cardCvv: '321',
        customerDocument: '98765432100',
        customerEmail: 'maria@example.com',
        description: 'Compra com débito'
    );

    $payment = $gateway->createDebitCardPayment($dbRequest);
    
    echo "✅ Pagamento débito criado!\n";
    echo "Transaction ID: " . $payment->transactionId . "\n";
    echo "Status: " . $payment->status->value . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Débito: " . $e->getMessage() . "\n\n";
}

// ==================== 4. BOLETO ====================

echo "=== 4. BOLETO ===\n\n";

try {
    $boletoRequest = new BoletoPaymentRequest(
        money: new Money(250.00, Currency::BRL),
        customerName: 'João da Silva',
        customerDocument: '98765432100',
        customerEmail: 'joao@example.com',
        dueDate: new DateTime('+7 days'),
        description: 'Fatura #123',
        customerAddress: [
            'street' => 'Rua Exemplo',
            'number' => '123',
            'complement' => 'Apto 45',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'postal_code' => '01234567'
        ]
    );

    $boleto = $gateway->createBoleto($boletoRequest);
    
    echo "✅ Boleto criado!\n";
    echo "Transaction ID: " . $boleto->transactionId . "\n";
    echo "Status: " . $boleto->status->value . "\n";
    
    $pdfUrl = $gateway->getBoletoUrl($boleto->transactionId);
    echo "PDF URL: " . $pdfUrl . "\n";
    
    // Cancelar boleto
    $gateway->cancelBoleto($boleto->transactionId);
    echo "✅ Boleto cancelado\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Boleto: " . $e->getMessage() . "\n\n";
}

// ==================== 5. ASSINATURAS ====================

echo "=== 5. ASSINATURAS ===\n\n";

try {
    $subRequest = new SubscriptionRequest(
        amount: 99.90,
        interval: SubscriptionInterval::MONTHLY,
        customerName: 'Carlos Souza',
        customerDocument: '11122233344',
        customerEmail: 'carlos@example.com',
        description: 'Assinatura Premium',
        cardToken: 'card_token_123',
        maxCharges: 12,
        startDate: new DateTime('+1 day')
    );

    $subscription = $gateway->createSubscription($subRequest);
    
    echo "✅ Assinatura criada!\n";
    echo "Subscription ID: " . $subscription->subscriptionId . "\n";
    echo "Status: " . $subscription->status . "\n";
    
    // Suspender
    $gateway->suspendSubscription($subscription->subscriptionId);
    echo "✅ Assinatura suspensa\n";
    
    // Reativar
    $gateway->reactivateSubscription($subscription->subscriptionId);
    echo "✅ Assinatura reativada\n";
    
    // Atualizar
    $gateway->updateSubscription($subscription->subscriptionId, [
        'amount' => 119.90,
        'description' => 'Premium Plus'
    ]);
    echo "✅ Assinatura atualizada\n";
    
    // Cancelar
    $gateway->cancelSubscription($subscription->subscriptionId);
    echo "✅ Assinatura cancelada\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Assinatura: " . $e->getMessage() . "\n\n";
}

// ==================== 6. TRANSAÇÕES ====================

echo "=== 6. TRANSAÇÕES ===\n\n";

try {
    // Listar transações
    $transactions = $gateway->listTransactions([
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'APPROVED',
        'limit' => 10
    ]);
    
    echo "✅ Total transações: " . count($transactions) . "\n";
    
    if (count($transactions) > 0) {
        $txnId = $transactions[0]['id'] ?? null;
        if ($txnId) {
            $status = $gateway->getTransactionStatus($txnId);
            echo "✅ Status: " . $status->status->value . "\n";
        }
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Erro Transações: " . $e->getMessage() . "\n\n";
}

// ==================== 7. ESTORNOS ====================

echo "=== 7. ESTORNOS ===\n\n";

try {
    // Estorno total
    $refundRequest = new RefundRequest(
        transactionId: 'txn_123456',
        reason: 'Solicitação do cliente'
    );
    
    $refund = $gateway->refund($refundRequest);
    echo "✅ Estorno total realizado\n";
    echo "Refund ID: " . $refund->refundId . "\n";
    
    // Estorno parcial
    $partialRefund = $gateway->partialRefund('txn_789', 50.00);
    echo "✅ Estorno parcial: R$ 50.00\n";
    
    // Listar chargebacks
    $chargebacks = $gateway->getChargebacks([
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31'
    ]);
    echo "✅ Chargebacks: " . count($chargebacks) . "\n";
    
    // Disputar chargeback
    if (count($chargebacks) > 0) {
        $dispute = $gateway->disputeChargeback('chb_123', [
            'documents' => ['invoice.pdf'],
            'description' => 'Produto entregue'
        ]);
        echo "✅ Disputa registrada\n";
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Erro Estorno: " . $e->getMessage() . "\n\n";
}

// ==================== 8. SPLIT DE PAGAMENTO ====================

echo "=== 8. SPLIT DE PAGAMENTO ===\n\n";

try {
    $splitRequest = new SplitPaymentRequest(
        totalAmount: 1000.00,
        description: 'Venda marketplace',
        splits: [
            [
                'recipientId' => 'recipient_1',
                'amount' => 700.00,
                'feeLiable' => true
            ],
            [
                'recipientId' => 'recipient_2',
                'amount' => 300.00,
                'feeLiable' => false
            ]
        ]
    );

    $payment = $gateway->createSplitPayment($splitRequest);
    
    echo "✅ Split criado!\n";
    echo "Transaction ID: " . $payment->transactionId . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Split: " . $e->getMessage() . "\n\n";
}

// ==================== 9. SUB-CONTAS ====================

echo "=== 9. SUB-CONTAS ===\n\n";

try {
    $subAccountRequest = new SubAccountRequest(
        name: 'Loja do João',
        taxId: '12345678900',
        email: 'loja@joao.com',
        phone: '11999999999',
        address: [
            'street' => 'Rua A',
            'number' => '100',
            'city' => 'São Paulo',
            'state' => 'SP',
            'postal_code' => '01234567'
        ],
        bankAccount: [
            'bank_code' => '336',
            'agency' => '0001',
            'account' => '12345',
            'account_digit' => '6',
            'type' => 'checking'
        ]
    );

    $subAccount = $gateway->createSubAccount($subAccountRequest);
    
    echo "✅ Sub-conta criada!\n";
    echo "Sub-account ID: " . $subAccount->subAccountId . "\n";
    
    // Atualizar
    $gateway->updateSubAccount($subAccount->subAccountId, [
        'email' => 'novo@email.com'
    ]);
    echo "✅ Sub-conta atualizada\n";
    
    // Desativar
    $gateway->deactivateSubAccount($subAccount->subAccountId);
    echo "✅ Sub-conta desativada\n";
    
    // Ativar
    $gateway->activateSubAccount($subAccount->subAccountId);
    echo "✅ Sub-conta ativada\n";
    
    // Consultar
    $info = $gateway->getSubAccount($subAccount->subAccountId);
    echo "✅ Status: " . $info->status . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Sub-conta: " . $e->getMessage() . "\n\n";
}

// ==================== 10. WALLETS ====================

echo "=== 10. WALLETS ===\n\n";

try {
    $walletRequest = new WalletRequest(
        name: 'Minha Carteira',
        customerId: 'customer_123',
        description: 'Carteira principal'
    );

    $wallet = $gateway->createWallet($walletRequest);
    
    echo "✅ Wallet criada!\n";
    echo "Wallet ID: " . $wallet->walletId . "\n";
    echo "Saldo inicial: R$ " . number_format($wallet->balance, 2) . "\n";
    
    // Adicionar saldo
    $gateway->addBalance($wallet->walletId, 500.00);
    echo "✅ Saldo adicionado: R$ 500.00\n";
    
    // Deduzir saldo
    $gateway->deductBalance($wallet->walletId, 150.00);
    echo "✅ Saldo deduzido: R$ 150.00\n";
    
    // Consultar saldo
    $balance = $gateway->getWalletBalance($wallet->walletId);
    echo "✅ Saldo atual: R$ " . number_format($balance->available, 2) . "\n";
    
    // Transferir entre wallets
    $transfer = $gateway->transferBetweenWallets('wallet_1', 'wallet_2', 200.00);
    echo "✅ Transferência realizada\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Wallet: " . $e->getMessage() . "\n\n";
}

// ==================== 11. ESCROW (CUSTÓDIA) ====================

echo "=== 11. ESCROW ===\n\n";

try {
    $escrowRequest = new EscrowRequest(
        amount: 1000.00,
        transactionId: 'txn_123',
        description: 'Custódia entrega produto',
        releaseDate: new DateTime('+7 days')
    );

    $escrow = $gateway->holdInEscrow($escrowRequest);
    
    echo "✅ Custódia criada!\n";
    echo "Escrow ID: " . $escrow->escrowId . "\n";
    echo "Valor: R$ " . number_format($escrow->amount, 2) . "\n";
    
    // Liberar parcialmente
    $gateway->partialReleaseEscrow($escrow->escrowId, 500.00);
    echo "✅ Liberação parcial: R$ 500.00\n";
    
    // Liberar total
    $gateway->releaseEscrow($escrow->escrowId);
    echo "✅ Custódia liberada totalmente\n";
    
    // Cancelar custódia
    $gateway->cancelEscrow($escrow->escrowId);
    echo "✅ Custódia cancelada\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Escrow: " . $e->getMessage() . "\n\n";
}

// ==================== 12. TRANSFERÊNCIAS ====================

echo "=== 12. TRANSFERÊNCIAS ===\n\n";

try {
    $transferRequest = new TransferRequest(
        amount: 500.00,
        bankCode: '001',
        agency: '1234',
        account: '56789',
        accountDigit: '0',
        accountType: 'checking',
        beneficiaryName: 'João Silva',
        beneficiaryDocument: '12345678900',
        description: 'Pagamento fornecedor'
    );

    $transfer = $gateway->transfer($transferRequest);
    
    echo "✅ Transferência criada!\n";
    echo "Transfer ID: " . $transfer->transferId . "\n";
    echo "Status: " . $transfer->status . "\n";
    
    // Agendar transferência
    $scheduled = $gateway->scheduleTransfer($transferRequest, '2024-12-31');
    echo "✅ Transferência agendada para 31/12/2024\n";
    
    // Cancelar transferência agendada
    $gateway->cancelScheduledTransfer($scheduled->transferId);
    echo "✅ Transferência agendada cancelada\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Transferência: " . $e->getMessage() . "\n\n";
}

// ==================== 13. LINKS DE PAGAMENTO ====================

echo "=== 13. LINKS DE PAGAMENTO ===\n\n";

try {
    $linkRequest = new PaymentLinkRequest(
        amount: 199.90,
        description: 'Curso Online XYZ',
        externalReferenceId: 'LINK123',
        customerName: 'Cliente Teste',
        customerEmail: 'cliente@email.com',
        enablePix: true,
        enableCard: true,
        enableBoleto: true,
        maxInstallments: 6,
        expiresAt: new DateTime('+7 days'),
        redirectUrl: 'https://seusite.com/sucesso'
    );

    $link = $gateway->createPaymentLink($linkRequest);
    
    echo "✅ Link criado!\n";
    echo "Link ID: " . $link->linkId . "\n";
    echo "URL: " . $link->url . "\n";
    
    // Consultar link
    $linkInfo = $gateway->getPaymentLink($link->linkId);
    echo "✅ Link consultado\n";
    
    // Expirar link
    $gateway->expirePaymentLink($link->linkId);
    echo "✅ Link expirado\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Link: " . $e->getMessage() . "\n\n";
}

// ==================== 14. CLIENTES ====================

echo "=== 14. CLIENTES ===\n\n";

try {
    $customerRequest = new CustomerRequest(
        name: 'Pedro Oliveira',
        taxId: '12345678900',
        email: 'pedro@example.com',
        phone: '11988887777',
        address: [
            'street' => 'Av. Paulista',
            'number' => '1000',
            'city' => 'São Paulo',
            'state' => 'SP',
            'postal_code' => '01310100'
        ]
    );

    $customer = $gateway->createCustomer($customerRequest);
    
    echo "✅ Cliente criado!\n";
    echo "Customer ID: " . $customer->customerId . "\n";
    echo "Nome: " . $customer->name . "\n";
    
    // Atualizar
    $gateway->updateCustomer($customer->customerId, [
        'email' => 'novoemail@example.com'
    ]);
    echo "✅ Cliente atualizado\n";
    
    // Consultar
    $customerInfo = $gateway->getCustomer($customer->customerId);
    echo "✅ Email: " . $customerInfo->email . "\n";
    
    // Listar
    $customers = $gateway->listCustomers(['limit' => 10]);
    echo "✅ Total clientes: " . count($customers) . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Cliente: " . $e->getMessage() . "\n\n";
}

// ==================== 15. ANTIFRAUDE ====================

echo "=== 15. ANTIFRAUDE ===\n\n";

try {
    // Analisar transação
    $analysis = $gateway->analyzeTransaction('txn_123456');
    
    echo "✅ Análise realizada!\n";
    echo "Score: " . $analysis['score'] . "\n";
    echo "Status: " . $analysis['status'] . "\n";
    
    // Blacklist
    $gateway->addToBlacklist('12345678900', 'cpf');
    echo "✅ CPF adicionado à blacklist\n";
    
    $gateway->addToBlacklist('fraud@email.com', 'email');
    echo "✅ Email adicionado à blacklist\n";
    
    $gateway->removeFromBlacklist('12345678900', 'cpf');
    echo "✅ CPF removido da blacklist\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Antifraude: " . $e->getMessage() . "\n\n";
}

// ==================== 16. WEBHOOKS ====================

echo "=== 16. WEBHOOKS ===\n\n";

try {
    // Registrar webhooks
    $webhooks = $gateway->registerWebhook(
        'https://seusite.com/webhook/c6bank',
        [
            'payment.created',
            'payment.approved',
            'payment.failed',
            'refund.created',
            'subscription.created',
            'subscription.cancelled'
        ]
    );
    
    echo "✅ Webhooks registrados: " . count($webhooks) . "\n";
    
    // Listar webhooks
    $allWebhooks = $gateway->listWebhooks();
    echo "✅ Webhooks ativos: " . count($allWebhooks) . "\n";
    
    // Deletar webhook
    if (count($allWebhooks) > 0) {
        $webhookId = $allWebhooks[0]['id'] ?? null;
        if ($webhookId) {
            $gateway->deleteWebhook($webhookId);
            echo "✅ Webhook deletado\n";
        }
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Erro Webhooks: " . $e->getMessage() . "\n\n";
}

// ==================== 17. SALDO E CONCILIAÇÃO ====================

echo "=== 17. SALDO E CONCILIAÇÃO ===\n\n";

try {
    // Consultar saldo
    $balance = $gateway->getBalance();
    
    echo "✅ Saldo consultado!\n";
    echo "Disponível: R$ " . number_format($balance->available, 2) . "\n";
    echo "A receber: R$ " . number_format($balance->pending, 2) . "\n";
    
    // Agenda de liquidação
    $schedule = $gateway->getSettlementSchedule([
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31'
    ]);
    echo "✅ Agendamentos: " . count($schedule) . "\n";
    
    // Antecipar recebíveis
    $anticipation = $gateway->anticipateReceivables([
        'txn_123',
        'txn_456'
    ]);
    echo "✅ Antecipação solicitada\n";
    echo "Transaction ID: " . $anticipation->transactionId . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Erro Saldo: " . $e->getMessage() . "\n\n";
}

echo "=== FIM DOS EXEMPLOS ===\n";
echo "\n";
echo "📝 Nota: Este exemplo demonstra todas as funcionalidades do C6Bank Gateway.\n";
echo "   Ajuste as credenciais e dados conforme sua necessidade.\n";
echo "   Algumas operações podem falhar no sandbox se não estiverem habilitadas.\n";
