# 💳 Stripe Gateway - Guia Completo

Gateway de integração com a API do Stripe para pagamentos internacionais no PaymentHub.

## 📋 Índice

- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [O que é Suportado](#-o-que-é-suportado)
- [O que NÃO é Suportado](#-o-que-não-é-suportado)
- [Clientes](#-clientes)
- [Cartão de Crédito](#-cartão-de-crédito)
- [Assinaturas](#-assinaturas)
- [Transações](#-transações)
- [Estornos](#-estornos)
- [Stripe Connect (Sub-contas)](#-stripe-connect-sub-contas)
- [Links de Pagamento](#-links-de-pagamento)
- [Antifraude (Radar)](#-antifraude-radar)
- [Webhooks](#-webhooks)
- [Saldo e Payouts](#-saldo-e-payouts)
- [Notas Importantes](#-notas-importantes)

---

## 🚀 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Stripe\StripeGateway;

// Modo teste (sandbox)
$gateway = new StripeGateway(
    apiKey: 'sk_test_sua_api_key_aqui',
    testMode: true
);

// Modo produção
$gateway = new StripeGateway(
    apiKey: 'sk_live_sua_api_key_aqui',
    testMode: false
);

$hub = new PaymentHub($gateway);
```

### Obtendo API Key

1. Acesse [https://dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys)
2. Copie sua **Secret Key**:
   - Teste: `sk_test_...`
   - Produção: `sk_live_...`

---

## ✅ O que é Suportado

| Funcionalidade | Status | Observações |
|---------------|--------|-------------|
| 💳 **Cartão de Crédito** | ✅ Completo | Internacional + bandeiras locais |
| 🔄 **Assinaturas** | ✅ Completo | Recorrência diária, semanal, mensal, anual |
| 💰 **Estornos** | ✅ Completo | Total e parcial |
| 👥 **Clientes** | ✅ Completo | CRUD completo |
| 🔗 **Payment Links** | ✅ Completo | Links de pagamento únicos |
| 🏢 **Stripe Connect** | ✅ Completo | Sub-contas (Express/Custom/Standard) |
| 🛡️ **Antifraude** | ✅ Automático | Stripe Radar integrado |
| 🔔 **Webhooks** | ✅ Completo | Eventos em tempo real |
| 💵 **Saldo** | ✅ Completo | Consulta de saldo disponível/pendente |

---

## ❌ O que NÃO é Suportado

Funcionalidades **não disponíveis** no Stripe (são específicas do Brasil):

| Funcionalidade | Alternativa |
|---------------|-------------|
| ❌ **PIX** | Use Asaas, PagSeguro ou MercadoPago |
| ❌ **Boleto** | Use Asaas, PagSeguro ou MercadoPago |
| ❌ **Cartão de Débito Direto** | Use fluxo de cartão de crédito |
| ❌ **Wallets Nativas** | Use Customer Balance API |
| ❌ **Escrow Direto** | Use Stripe Connect com charges separados |

---

## 👥 Clientes

### Criar Cliente

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

$customer = new CustomerRequest(
    name: 'John Doe',
    email: 'john@example.com',
    phone: '+1 415 555 0123',
    address: [
        'street' => '123 Main St',
        'city' => 'San Francisco',
        'state' => 'CA',
        'zipcode' => '94102',
        'country' => 'US'
    ]
);

$response = $hub->createCustomer($customer);

echo $response->customerId; // cus_xxxxx
```

### Buscar Cliente

```php
$response = $hub->getCustomer('cus_xxxxx');
print_r($response->rawResponse);
```

### Atualizar Cliente

```php
$response = $hub->updateCustomer('cus_xxxxx', [
    'email' => 'newemail@example.com',
    'phone' => '+1 415 555 9999'
]);
```

### Listar Clientes

```php
$customers = $hub->listCustomers([
    'limit' => 10
]);

foreach ($customers as $customer) {
    echo $customer['name'] . "\n";
}
```

---

## 💳 Cartão de Crédito

### Pagamento Simples

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = CreditCardPaymentRequest::create(
    amount: 99.99,
    cardNumber: '4242 4242 4242 4242', // Teste
    cardHolderName: 'JOHN DOE',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerName: 'John Doe',
    customerEmail: 'john@example.com'
);

$response = $hub->createCreditCardPayment($request);

if ($response->isSuccess()) {
    echo "Pagamento aprovado!\n";
    echo "Transaction ID: " . $response->transactionId . "\n";
    echo "Bandeira: " . $response->metadata['card_brand'] . "\n";
    echo "Últimos 4: " . $response->metadata['card_last4'] . "\n";
}
```

### Cartões de Teste (Sandbox)

```
✅ Sucesso: 4242 4242 4242 4242
❌ Declinado: 4000 0000 0000 0002
⚠️ Requer 3D Secure: 4000 0027 6000 3184
💳 Visa: 4242 4242 4242 4242
💳 Mastercard: 5555 5555 5555 4444
💳 Amex: 3782 822463 10005
```

### Salvar Cartão para Uso Futuro

```php
$request = CreditCardPaymentRequest::create(
    amount: 50.00,
    cardNumber: '4242 4242 4242 4242',
    cardHolderName: 'JOHN DOE',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerEmail: 'john@example.com',
    saveCard: true // ✅ Salva para uso futuro
);

$response = $hub->createCreditCardPayment($request);

$paymentMethodId = $response->metadata['payment_method_id'];
echo "Cartão salvo: {$paymentMethodId}\n";
```

### Tokenizar Cartão

```php
$token = $hub->tokenizeCard([
    'number' => '4242 4242 4242 4242',
    'holderName' => 'JOHN DOE',
    'expiryMonth' => '12',
    'expiryYear' => '2028',
    'cvv' => '123'
]);

echo "Token: {$token}\n"; // pm_xxxxx
```

### Pré-autorização (Captura Manual)

```php
// Criar PaymentIntent com captura manual
// (Requer implementação customizada via metadata)

// Capturar depois
$response = $hub->capturePreAuthorization('pi_xxxxx');

// Ou cancelar
$response = $hub->cancelPreAuthorization('pi_xxxxx');
```

---

## 🔄 Assinaturas

### Criar Assinatura Mensal

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$request = SubscriptionRequest::create(
    amount: 29.99,
    interval: 'monthly',
    customerId: 'cus_xxxxx',
    paymentMethod: 'pm_xxxxx',
    description: 'Premium Plan',
    trialDays: 14 // 14 dias grátis
);

$response = $hub->createSubscription($request);

echo "Assinatura criada: " . $response->subscriptionId . "\n";
```

### Intervalos Suportados

- `daily` - Diário
- `weekly` - Semanal
- `monthly` - Mensal
- `yearly` - Anual

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
    'metadata' => [
        'plan_name' => 'Premium Plus'
    ]
]);
```

---

## 📊 Transações

### Consultar Status

```php
$response = $hub->getTransactionStatus('pi_xxxxx');

echo "Status: " . $response->status->label() . "\n";
echo "Valor: " . $response->money->formatted() . "\n";

if ($response->isPaid()) {
    echo "Pagamento confirmado!\n";
}
```

### Status Possíveis

- `succeeded` - Pagamento aprovado
- `processing` - Processando
- `requires_payment_method` - Aguardando método de pagamento
- `requires_action` - Requer ação do cliente (3D Secure)
- `canceled` - Cancelado
- `failed` - Falhou

### Listar Transações

```php
$transactions = $hub->listTransactions([
    'limit' => 50
]);

foreach ($transactions as $transaction) {
    echo $transaction['id'] . " - " . $transaction['amount'] . "\n";
}
```

---

## 💰 Estornos

### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = RefundRequest::create(
    transactionId: 'pi_xxxxx',
    reason: 'Customer requested cancellation'
);

$response = $hub->refund($request);

echo "Reembolso processado: " . $response->refundId . "\n";
```

### Reembolso Parcial

```php
$response = $hub->partialRefund(
    transactionId: 'pi_xxxxx',
    amount: 25.00
);

echo "Reembolsado: $25.00\n";
```

### Listar Chargebacks (Disputas)

```php
$chargebacks = $hub->getChargebacks([
    'limit' => 10
]);

foreach ($chargebacks as $dispute) {
    echo $dispute['id'] . " - " . $dispute['reason'] . "\n";
}
```

### Contestar Chargeback

```php
$response = $hub->disputeChargeback('dp_xxxxx', [
    'customer_name' => 'John Doe',
    'customer_email_address' => 'john@example.com',
    'receipt' => 'file_xxxxx', // Upload file primeiro
    'shipping_documentation' => 'file_yyyyy'
]);
```

---

## 🏢 Stripe Connect (Sub-contas)

### Criar Sub-conta

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;

$request = new SubAccountRequest(
    name: 'Marketplace Seller 1',
    email: 'seller1@marketplace.com',
    documentNumber: null, // Não aplicável no Stripe
    metadata: [
        'seller_id' => '12345',
        'commission_rate' => '10'
    ]
);

$response = $hub->createSubAccount($request);

echo "Sub-conta criada: " . $response->subAccountId . "\n";
```

### Consultar Sub-conta

```php
$response = $hub->getSubAccount('acct_xxxxx');
```

### Desativar Sub-conta

```php
$response = $hub->deactivateSubAccount('acct_xxxxx');
```

**Nota:** Stripe Connect requer configuração adicional no Dashboard.

---

## 🔗 Links de Pagamento

### Criar Link

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 149.90,
    description: 'Online Course - Advanced PHP',
    expiresAt: '2025-12-31'
);

$response = $hub->createPaymentLink($request);

echo "Link: " . $response->url . "\n";
```

### Consultar Link

```php
$response = $hub->getPaymentLink('plink_xxxxx');
```

### Expirar Link

```php
$response = $hub->expirePaymentLink('plink_xxxxx');
```

---

## 🛡️ Antifraude (Radar)

O Stripe Radar analisa **automaticamente** todas as transações.

### Consultar Análise

```php
$analysis = $hub->analyzeTransaction('pi_xxxxx');

echo "Nível de risco: " . $analysis['risk_level'] . "\n";
echo "Score: " . $analysis['risk_score'] . "\n";
```

**Níveis de Risco:**
- `normal` - Risco baixo
- `elevated` - Risco elevado
- `highest` - Risco muito alto

**Nota:** Regras de bloqueio são configuradas no Stripe Dashboard.

---

## 🔔 Webhooks

### Registrar Webhook

```php
$response = $hub->registerWebhook(
    url: 'https://yoursite.com/webhooks/stripe',
    events: [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'charge.refunded',
        'charge.dispute.created'
    ]
);

echo "Webhook ID: " . $response['webhook_id'] . "\n";
echo "Secret: " . $response['secret'] . "\n"; // Guarde isso!
```

### Eventos Importantes

```
payment_intent.succeeded - Pagamento bem-sucedido
payment_intent.payment_failed - Pagamento falhou
customer.subscription.created - Assinatura criada
customer.subscription.updated - Assinatura atualizada
customer.subscription.deleted - Assinatura cancelada
charge.refunded - Reembolso processado
charge.dispute.created - Chargeback aberto
invoice.paid - Fatura paga
invoice.payment_failed - Falha no pagamento da fatura
```

### Verificar Assinatura do Webhook

```php
// No seu endpoint de webhook
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$endpoint_secret = 'whsec_xxxxx'; // Do registro acima

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
    
    // Processar evento
    switch ($event->type) {
        case 'payment_intent.succeeded':
            $paymentIntent = $event->data->object;
            // Fulfillment da ordem
            break;
        // ... outros eventos
    }
    
    http_response_code(200);
} catch (\Exception $e) {
    http_response_code(400);
    echo 'Webhook error: ' . $e->getMessage();
}
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
$deleted = $hub->deleteWebhook('we_xxxxx');
```

---

## 💵 Saldo e Payouts

### Consultar Saldo

```php
$response = $hub->getBalance();

echo "Saldo Total: $" . $response->balance . "\n";
echo "Disponível: $" . $response->availableBalance . "\n";
echo "Pendente: $" . $response->pendingBalance . "\n";
```

### Listar Payouts (Transferências para Conta)

```php
$payouts = $hub->getSettlementSchedule([
    'limit' => 10
]);

foreach ($payouts as $payout) {
    echo $payout['id'] . " - $" . ($payout['amount'] / 100) . "\n";
}
```

**Nota:** Payouts são automáticos (diário/semanal) configurados no Dashboard.

---

## 📝 Notas Importantes

### URLs da API

- **Base URL**: `https://api.stripe.com/v1`
- **Dashboard**: `https://dashboard.stripe.com`
- **Docs**: `https://docs.stripe.com`

### Autenticação

- Header: `Authorization: Bearer sk_xxxxx`
- API Version: `2024-12-18.acacia`

### Ambientes

- Use `sk_test_` para testes
- Use `sk_live_` para produção
- **NUNCA** misture chaves de teste/produção

### Moedas Suportadas

Stripe suporta 135+ moedas, incluindo:
- USD (Dólar)
- EUR (Euro)
- GBP (Libra)
- BRL (Real) - **limitado**

### Valores

- Stripe usa **centavos** (smallest currency unit)
- $10.00 = 1000 centavos
- PaymentHub converte automaticamente

### 3D Secure (SCA)

Stripe **requer** 3D Secure para pagamentos na Europa (PSD2/SCA).
- Automático com PaymentIntents
- Teste com cartão: `4000 0027 6000 3184`

### Funcionalidades Específicas do Brasil

Para PIX, Boleto e split de pagamento no Brasil:
- ✅ Use **Asaas Gateway**
- ✅ Use **PagSeguro Gateway**
- ✅ Use **MercadoPago Gateway**

Stripe é ideal para:
- 🌎 Pagamentos internacionais
- 💳 Cartões internacionais
- 🔄 Assinaturas globais
- 🏢 Marketplaces internacionais

---

## 🎯 Tratamento de Erros

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $response = $hub->createCreditCardPayment($request);
    
    if ($response->isSuccess()) {
        echo "✅ Pagamento aprovado!\n";
    } else {
        echo "⚠️ Status: " . $response->status->label() . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "Código HTTP: " . $e->getCode() . "\n";
    
    $context = $e->getContext();
    if (isset($context['response']['error'])) {
        echo "Tipo: " . $context['response']['error']['type'] . "\n";
        echo "Mensagem: " . $context['response']['error']['message'] . "\n";
    }
}
```

### Tipos de Erro Comum

```
card_declined - Cartão recusado
insufficient_funds - Saldo insuficiente
invalid_card_number - Número de cartão inválido
invalid_expiry_month - Mês de validade inválido
invalid_cvc - CVC inválido
processing_error - Erro de processamento
rate_limit - Limite de requisições excedido
```

---

## 🆘 Suporte

- 📚 Documentação oficial: [https://docs.stripe.com](https://docs.stripe.com)
- 💬 Suporte Stripe: [https://support.stripe.com](https://support.stripe.com)
- 🐛 Issues PaymentHub: [https://github.com/israel-nogueira/payment-hub](https://github.com/israel-nogueira/payment-hub)

---

## 📄 Licença

Este gateway faz parte do PaymentHub.

---

## 🚀 Exemplo Completo

```php
<?php

require 'vendor/autoload.php';

use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Stripe\StripeGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

// Configurar gateway
$gateway = new StripeGateway(
    apiKey: 'sk_test_sua_chave_aqui',
    testMode: true
);

$hub = new PaymentHub($gateway);

try {
    // Criar pagamento
    $request = CreditCardPaymentRequest::create(
        amount: 99.99,
        cardNumber: '4242 4242 4242 4242',
        cardHolderName: 'JOHN DOE',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        customerEmail: 'john@example.com',
        description: 'Order #12345'
    );

    $response = $hub->createCreditCardPayment($request);

    if ($response->isSuccess()) {
        echo "✅ Pagamento aprovado!\n";
        echo "💰 Valor: " . $response->money->formatted() . "\n";
        echo "🆔 ID: " . $response->transactionId . "\n";
        
        // Fulfillment da ordem aqui...
        
    } else {
        echo "⚠️ Pagamento pendente: " . $response->status->label() . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    // Log do erro, notificar admin, etc...
}
```

---

**Pronto para aceitar pagamentos globais com Stripe!** 🌎💳
