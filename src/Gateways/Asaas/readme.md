# 🏦 Asaas Gateway - Guia Completo

Gateway de integração com a API do Asaas para o PaymentHub.

## 📋 Índice

- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Clientes](#-clientes)
- [PIX](#-pix)
- [Cartão de Crédito](#-cartão-de-crédito)
- [Cartão de Débito](#-cartão-de-débito)
- [Boleto](#-boleto)
- [Assinaturas](#-assinaturas)
- [Transações](#-transações)
- [Estornos e Chargebacks](#-estornos-e-chargebacks)
- [Split de Pagamento](#-split-de-pagamento)
- [Sub-contas](#-sub-contas)
- [Wallets](#-wallets)
- [Escrow (Custódia)](#-escrow-custódia)
- [Transferências](#-transferências)
- [Links de Pagamento](#-links-de-pagamento)
- [Antifraude](#-antifraude)
- [Webhooks](#-webhooks)
- [Saldo e Conciliação](#-saldo-e-conciliação)

---

## 🚀 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Asaas\AsaasGateway;

// Sandbox (desenvolvimento)
$gateway = new AsaasGateway(
    apiKey: 'sua-api-key-sandbox',
    sandbox: true
);

// Produção
$gateway = new AsaasGateway(
    apiKey: 'sua-api-key-producao',
    sandbox: false
);

$hub = new PaymentHub($gateway);
```

---

## 👥 Clientes

### Criar Cliente

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

$customer = new CustomerRequest(
    name: 'João Silva',
    email: 'joao@email.com',
    documentNumber: '12345678900',
    phone: '11999999999',
    address: [
        'street' => 'Rua Exemplo',
        'number' => '123',
        'complement' => 'Apto 45',
        'district' => 'Centro',
        'zipcode' => '12345-678',
        'city' => 'São Paulo',
        'state' => 'SP'
    ]
);

$response = $hub->createCustomer($customer);

echo $response->customerId; // cus_xxxxx
```

### Atualizar Cliente

```php
$response = $hub->updateCustomer(
    customerId: 'cus_xxxxx',
    data: [
        'email' => 'novoemail@email.com',
        'phone' => '11988888888'
    ]
);
```

### Buscar Cliente

```php
$response = $hub->getCustomer('cus_xxxxx');

print_r($response->rawResponse);
```

### Listar Clientes

```php
$customers = $hub->listCustomers([
    'offset' => 0,
    'limit' => 10
]);

foreach ($customers as $customer) {
    echo $customer['name'] . "\n";
}
```

---

## 💳 PIX

### Criar Pagamento PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = PixPaymentRequest::create(
    amount: 100.00,
    customerName: 'João Silva',
    customerEmail: 'joao@email.com',
    customerDocument: '123.456.789-00',
    description: 'Pagamento de serviço',
    expiresInMinutes: 30
);

$response = $hub->createPixPayment($request);

if ($response->isSuccess()) {
    echo "Transaction ID: " . $response->transactionId . "\n";
    echo "Status: " . $response->status->label() . "\n";
}
```

### Obter QR Code do PIX

```php
$qrCode = $hub->getPixQrCode('pay_xxxxx');
// Retorna base64 da imagem do QR Code
```

### Obter Código Copia e Cola

```php
$pixCode = $hub->getPixCopyPaste('pay_xxxxx');
echo $pixCode; // String EMV do PIX
```

---

## 💳 Cartão de Crédito

### Criar Pagamento com Cartão

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = CreditCardPaymentRequest::create(
    amount: 250.00,
    installments: 3,
    cardNumber: '5162306219378829',
    cardHolderName: 'João Silva',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerName: 'João Silva',
    customerEmail: 'joao@email.com',
    customerDocument: '123.456.789-00'
);

$response = $hub->createCreditCardPayment($request);

if ($response->isSuccess()) {
    echo "Pagamento aprovado!\n";
    echo "Valor: " . $response->money->formatted() . "\n";
}
```

### Tokenizar Cartão

```php
$token = $hub->tokenizeCard([
    'number' => '5162306219378829',
    'holderName' => 'João Silva',
    'expiryMonth' => '12',
    'expiryYear' => '2028',
    'cvv' => '123'
]);

echo "Token: " . $token;
```

### Cancelar Pré-autorização

```php
$response = $hub->cancelPreAuthorization('pay_xxxxx');
```

---

## 💳 Cartão de Débito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;

// Débito no Asaas é processado através do invoiceUrl
// Não há pagamento direto via API
```

---

## 🧾 Boleto

### Criar Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = BoletoPaymentRequest::create(
    amount: 150.00,
    customerName: 'João Silva',
    customerDocument: '123.456.789-00',
    customerEmail: 'joao@email.com',
    dueDate: '2025-03-15',
    description: 'Mensalidade Março/2025',
    finePercentage: 2.0,        // 2% de multa
    interestPercentage: 1.0,    // 1% ao mês de juros
    discountAmount: 10.00,      // R$ 10 de desconto
    discountLimitDate: '2025-03-10'
);

$response = $hub->createBoleto($request);

echo "Boleto criado!\n";
echo "URL: " . $response->metadata['bank_slip_url'] . "\n";
```

### Obter URL do Boleto

```php
$url = $hub->getBoletoUrl('pay_xxxxx');
echo $url;
```

### Cancelar Boleto

```php
$response = $hub->cancelBoleto('pay_xxxxx');
```

---

## 🔄 Assinaturas

### Criar Assinatura

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$request = SubscriptionRequest::create(
    amount: 49.90,
    interval: 'monthly',
    customerId: 'cus_xxxxx',
    paymentMethod: 'CREDIT_CARD',
    cardToken: 'tok_xxxxx',
    description: 'Plano Premium',
    trialDays: 7,
    cycles: 12 // null = ilimitado
);

$response = $hub->createSubscription($request);

echo "Assinatura criada: " . $response->subscriptionId . "\n";
```

### Cancelar Assinatura

```php
$response = $hub->cancelSubscription('sub_xxxxx');
```

### Suspender Assinatura

```php
$response = $hub->suspendSubscription('sub_xxxxx');
```

### Reativar Assinatura

```php
$response = $hub->reactivateSubscription('sub_xxxxx');
```

### Atualizar Assinatura

```php
$response = $hub->updateSubscription('sub_xxxxx', [
    'value' => 59.90,
    'description' => 'Plano Premium Plus'
]);
```

---

## 📊 Transações

### Consultar Status

```php
$response = $hub->getTransactionStatus('pay_xxxxx');

echo "Status: " . $response->status->label() . "\n";
echo "Valor: " . $response->money->formatted() . "\n";

if ($response->isPaid()) {
    echo "Pagamento confirmado!\n";
}
```

### Listar Transações

```php
$transactions = $hub->listTransactions([
    'status' => 'RECEIVED',
    'dateCreatedGe' => '2025-01-01',
    'dateCreatedLe' => '2025-12-31',
    'offset' => 0,
    'limit' => 50
]);

foreach ($transactions as $transaction) {
    echo $transaction['id'] . " - " . $transaction['value'] . "\n";
}
```

---

## 💰 Estornos e Chargebacks

### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = RefundRequest::create(
    transactionId: 'pay_xxxxx',
    reason: 'Cliente solicitou cancelamento'
);

$response = $hub->refund($request);

echo "Reembolso processado: " . $response->refundId . "\n";
```

### Reembolso Parcial

```php
$response = $hub->partialRefund(
    transactionId: 'pay_xxxxx',
    amount: 50.00
);

echo "Reembolsado: R$ 50,00\n";
```

### Listar Chargebacks

```php
$chargebacks = $hub->getChargebacks([
    'status' => 'REQUESTED'
]);

foreach ($chargebacks as $chargeback) {
    echo $chargeback['id'] . "\n";
}
```

### Contestar Chargeback

```php
$response = $hub->disputeChargeback('chb_xxxxx', [
    'attachments' => ['documento1.pdf', 'comprovante.jpg'],
    'description' => 'Serviço foi prestado conforme contratado'
]);
```

---

## 🔀 Split de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;

$request = new SplitPaymentRequest(
    /* configuração do split */
);

$response = $hub->createSplitPayment($request);
```

---

## 🏢 Sub-contas

### Criar Sub-conta

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;

$request = new SubAccountRequest(
    name: 'Marketplace Vendedor 1',
    email: 'vendedor1@marketplace.com',
    documentNumber: '12345678900',
    metadata: [
        'commission_percentage' => 10
    ]
);

$response = $hub->createSubAccount($request);
echo "Sub-conta criada: " . $response->subAccountId . "\n";
```

### Atualizar Sub-conta

```php
$response = $hub->updateSubAccount('acc_xxxxx', [
    'email' => 'novoemail@marketplace.com'
]);
```

### Consultar Sub-conta

```php
$response = $hub->getSubAccount('acc_xxxxx');
```

### Ativar Sub-conta

```php
$response = $hub->activateSubAccount('acc_xxxxx');
```

### Desativar Sub-conta

```php
$response = $hub->deactivateSubAccount('acc_xxxxx');
```

---

## 👛 Wallets

### Criar Wallet

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;

$request = new WalletRequest(
    customerId: 'cus_xxxxx',
    metadata: ['type' => 'cashback']
);

$response = $hub->createWallet($request);
```

### Adicionar Saldo

```php
$response = $hub->addBalance('wallet_xxxxx', 100.00);
```

### Deduzir Saldo

```php
$response = $hub->deductBalance('wallet_xxxxx', 50.00);
```

### Consultar Saldo

```php
$response = $hub->getWalletBalance('wallet_xxxxx');
echo "Saldo: R$ " . $response->balance . "\n";
```

### Transferir Entre Wallets

```php
$response = $hub->transferBetweenWallets(
    fromWalletId: 'wallet_aaa',
    toWalletId: 'wallet_bbb',
    amount: 75.00
);
```

---

## 🔒 Escrow (Custódia)

### Segurar em Custódia

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;

$request = new EscrowRequest(
    /* configuração */
);

$response = $hub->holdInEscrow($request);
```

### Liberar Custódia

```php
$response = $hub->releaseEscrow('escrow_xxxxx');
```

### Liberação Parcial

```php
$response = $hub->partialReleaseEscrow('escrow_xxxxx', 100.00);
```

### Cancelar Custódia

```php
$response = $hub->cancelEscrow('escrow_xxxxx');
```

---

## 💸 Transferências

### Transferência PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use IsraelNogueira\PaymentHub\Enums\Currency;

$request = new TransferRequest(
    money: Money::from(500.00, Currency::BRL),
    description: 'Pagamento fornecedor',
    metadata: [
        'pix_key' => 'chavepix@email.com'
    ]
);

$response = $hub->transfer($request);

echo "Transferência realizada: " . $response->transferId . "\n";
```

### Transferência Bancária

```php
$request = new TransferRequest(
    money: Money::from(1000.00, Currency::BRL),
    description: 'Pagamento',
    metadata: [
        'bank_code' => '341',
        'account_name' => 'João Silva',
        'owner_name' => 'João Silva',
        'owner_birth_date' => '1990-01-15',
        'document' => '12345678900',
        'agency' => '1234',
        'account' => '12345',
        'account_digit' => '6'
    ]
);

$response = $hub->transfer($request);
```

### Agendar Transferência

```php
$response = $hub->scheduleTransfer($request, '2025-03-15');
```

### Cancelar Transferência Agendada

```php
$response = $hub->cancelScheduledTransfer('transfer_xxxxx');
```

---

## 🔗 Links de Pagamento

### Criar Link

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 199.90,
    description: 'Curso Online - PHP Avançado',
    maxUses: 100,
    expiresAt: '2025-12-31',
    metadata: [
        'due_date_limit_days' => 30,
        'max_installments' => 12
    ]
);

$response = $hub->createPaymentLink($request);

echo "Link: " . $response->url . "\n";
```

### Consultar Link

```php
$response = $hub->getPaymentLink('link_xxxxx');
```

### Expirar Link

```php
$response = $hub->expirePaymentLink('link_xxxxx');
```

---

## 🛡️ Antifraude

```php
// Análise automática - não disponível via API
// O Asaas faz análise automática de todas as transações

// Gerenciamento de blacklist - não disponível via API
// Gerenciar pelo painel administrativo do Asaas
```

---

## 🔔 Webhooks

### Registrar Webhook

```php
$response = $hub->registerWebhook(
    url: 'https://seusite.com/webhooks/asaas',
    events: ['all'] // Asaas envia todos os eventos
);

echo "Webhook ID: " . $response['webhook_id'] . "\n";
echo "Auth Token: " . $response['auth_token'] . "\n";
```

### Listar Webhooks

```php
$webhooks = $hub->listWebhooks();

foreach ($webhooks as $webhook) {
    echo $webhook['url'] . "\n";
}
```

### Deletar Webhook

```php
$deleted = $hub->deleteWebhook('webhook_xxxxx');
```

---

## 💰 Saldo e Conciliação

### Consultar Saldo

```php
$response = $hub->getBalance();

echo "Saldo Total: R$ " . $response->balance . "\n";
echo "Saldo Disponível: R$ " . $response->availableBalance . "\n";
```

### Antecipar Recebíveis

```php
$response = $hub->anticipateReceivables(['pay_xxxxx', 'pay_yyyyy']);

echo "Antecipação solicitada!\n";
echo "Valor: " . $response->money->formatted() . "\n";
```

---

## 🎯 Tratamento de Erros

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $response = $hub->createPixPayment($request);
    
    if ($response->isSuccess()) {
        echo "✅ Pagamento criado!\n";
    } else {
        echo "⚠️ Status: " . $response->status->label() . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "Código HTTP: " . $e->getCode() . "\n";
    
    // Dados adicionais da resposta
    $context = $e->getContext();
    print_r($context['response'] ?? []);
}
```

---

## 📝 Notas Importantes

### URLs da API
- **Sandbox**: `https://api-sandbox.asaas.com/v3`
- **Produção**: `https://api.asaas.com/v3`

### Autenticação
- Header: `access_token: sua-api-key`
- Obtenha suas chaves em: https://www.asaas.com/

### Ambientes
- Use `sandbox: true` para testes
- Use `sandbox: false` para produção

### Funcionalidades Limitadas
Algumas funcionalidades não estão disponíveis via API:
- ❌ Captura de pré-autorização direta
- ❌ Análise antifraude manual
- ❌ Gerenciamento de blacklist
- ❌ Agenda de liquidação detalhada
- ❌ Pagamento com cartão de débito direto

### Status de Pagamentos
- `PENDING` - Aguardando pagamento
- `RECEIVED` - Pagamento confirmado
- `CONFIRMED` - Pagamento aprovado
- `OVERDUE` - Vencido
- `REFUNDED` - Reembolsado
- Veja todos em: `PaymentStatus` enum

---

## 🆘 Suporte

- 📚 Documentação oficial: https://docs.asaas.com
- 💬 Suporte Asaas: suporte@asaas.com
- 🐛 Issues: https://github.com/israel-nogueira/payment-hub

---

## 📄 Licença

Este gateway faz parte do PaymentHub.
