# 💙 PayPal Gateway - Guia Completo

Gateway de integração com a API do PayPal para pagamentos globais no PaymentHub.

## 📋 Índice

- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [O que é Suportado](#-o-que-é-suportado)
- [O que NÃO é Suportado](#-o-que-não-é-suportado)
- [Cartão de Crédito](#-cartão-de-crédito)
- [PayPal Checkout](#-paypal-checkout)
- [Assinaturas](#-assinaturas)
- [Transações](#-transações)
- [Estornos](#-estornos)
- [Payouts (Transferências)](#-payouts-transferências)
- [Links de Pagamento](#-links-de-pagamento)
- [Webhooks](#-webhooks)
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
use IsraelNogueira\PaymentHub\Gateways\PayPal\PayPalGateway;

// Modo teste (sandbox)
$gateway = new PayPalGateway(
    clientId: 'YOUR_SANDBOX_CLIENT_ID',
    clientSecret: 'YOUR_SANDBOX_CLIENT_SECRET',
    testMode: true
);

// Modo produção
$gateway = new PayPalGateway(
    clientId: 'YOUR_LIVE_CLIENT_ID',
    clientSecret: 'YOUR_LIVE_CLIENT_SECRET',
    testMode: false
);

$hub = new PaymentHub($gateway);
```

### Obtendo Credenciais

1. Acesse [https://developer.paypal.com/dashboard/](https://developer.paypal.com/dashboard/)
2. Vá em **Apps & Credentials**
3. Crie um app (ou use existente)
4. Copie **Client ID** e **Secret**:
   - Sandbox: Para testes
   - Live: Para produção

---

## ✅ O que é Suportado

| Funcionalidade | Status | Observações |
|---------------|--------|-------------|
| 💳 **Cartão de Crédito** | ✅ Completo | Todas as bandeiras principais |
| 💙 **PayPal Checkout** | ✅ Completo | Pagamento com conta PayPal |
| 🔄 **Assinaturas** | ✅ Completo | Billing Plans & Subscriptions |
| 💰 **Estornos** | ✅ Completo | Total e parcial |
| 💸 **Payouts** | ✅ Completo | Transferências em massa |
| 🔗 **Payment Links** | ✅ Completo | Orders API |
| 🛡️ **Antifraude** | ✅ Automático | Seller Protection integrado |
| 🔔 **Webhooks** | ✅ Completo | Eventos em tempo real |
| ⚖️ **Disputas** | ✅ Completo | Gestão de chargebacks |

---

## ❌ O que NÃO é Suportado

Funcionalidades **não disponíveis** no PayPal:

| Funcionalidade | Alternativa |
|---------------|-------------|
| ❌ **PIX** | Use Mercado Pago, Asaas ou PagSeguro |
| ❌ **Boleto** | Use gateways brasileiros |
| ❌ **Cartão Débito Direto** | Use fluxo de cartão de crédito |
| ❌ **Customers API** | Payers são criados dinamicamente |
| ❌ **Saldo via API** | Consulte via Dashboard ou Reporting API |
| ❌ **Sub-contas diretas** | Requer PayPal Partner Program |

---

## 💳 Cartão de Crédito

### Pagamento Simples

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = CreditCardPaymentRequest::create(
    amount: 99.99,
    cardNumber: '4111 1111 1111 1111', // Visa teste
    cardHolderName: 'JOHN DOE',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerName: 'John Doe',
    customerEmail: 'john@example.com',
    description: 'Order #12345'
);

$response = $hub->createCreditCardPayment($request);

if ($response->isSuccess()) {
    echo "✅ Pagamento aprovado!\n";
    echo "💰 Valor: " . $response->money->formatted() . "\n";
    echo "🆔 ID: " . $response->transactionId . "\n";
    echo "📧 Email: " . $response->metadata['payer_email'] . "\n";
}
```

### Cartões de Teste (Sandbox)

```
✅ Aprovado:
Visa: 4111 1111 1111 1111
Mastercard: 5555 5555 5555 4444
Amex: 3782 822463 10005
Discover: 6011 1111 1111 1117

❌ Recusado:
4000 0000 0000 0002

CVV: Qualquer 3 dígitos (123)
Validade: Qualquer data futura
```

### Tokenizar Cartão

```php
$token = $hub->tokenizeCard([
    'number' => '4111 1111 1111 1111',
    'holderName' => 'JOHN DOE',
    'expiryMonth' => '12',
    'expiryYear' => '2028',
    'cvv' => '123'
]);

echo "Token: {$token}\n"; // vault_token_xxxxx
```

### Pré-autorização (Captura Manual)

```php
// Criar com captura manual
$request = CreditCardPaymentRequest::create(
    amount: 500.00,
    cardNumber: '4111 1111 1111 1111',
    cardHolderName: 'JOHN DOE',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerEmail: 'john@example.com',
    capture: false // ✅ Não captura automaticamente
);

$response = $hub->createCreditCardPayment($request);
$orderId = $response->transactionId;

// Capturar depois
$captured = $hub->capturePreAuthorization($orderId);

// Ou cancelar (void)
$cancelled = $hub->cancelPreAuthorization($orderId);
```

---

## 💙 PayPal Checkout

### Criar Pagamento PayPal

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 199.90,
    description: 'Online Course - Advanced PHP',
    metadata: [
        'return_url' => 'https://yoursite.com/success',
        'cancel_url' => 'https://yoursite.com/cancel'
    ]
);

$response = $hub->createPaymentLink($request);

// Redirecionar usuário para PayPal
header("Location: " . $response->url);
exit;
```

### Fluxo Completo

```php
// 1. Criar order
$link = $hub->createPaymentLink($request);
$orderId = $link->linkId;

// 2. Usuário paga no PayPal e retorna para return_url

// 3. Capturar pagamento (no callback)
$captured = $hub->capturePreAuthorization($orderId);

if ($captured->isSuccess()) {
    echo "Pagamento confirmado!";
    // Liberar produto/serviço
}
```

---

## 🔄 Assinaturas

### Criar Assinatura Mensal

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$request = SubscriptionRequest::create(
    amount: 29.99,
    interval: 'monthly',
    description: 'Premium Membership',
    customerEmail: 'john@example.com',
    trialDays: 7, // 7 dias grátis
    cycles: 12, // 12 meses (0 = infinito)
    metadata: [
        'return_url' => 'https://yoursite.com/subscription-success',
        'cancel_url' => 'https://yoursite.com/subscription-cancel'
    ]
);

$response = $hub->createSubscription($request);

echo "Assinatura criada: " . $response->subscriptionId . "\n";
echo "Status: " . $response->status . "\n";
```

### Intervalos Suportados

- `daily` - Diário
- `weekly` - Semanal
- `monthly` - Mensal
- `yearly` - Anual

### Gerenciar Assinatura

```php
// Cancelar
$hub->cancelSubscription($subscriptionId);

// Suspender
$hub->suspendSubscription($subscriptionId);

// Reativar
$hub->reactivateSubscription($subscriptionId);

// Atualizar
$hub->updateSubscription($subscriptionId, [
    'plan' => [
        'billing_cycles' => [
            // Novos ciclos de cobrança
        ]
    ]
]);
```

---

## 📊 Transações

### Consultar Status

```php
$response = $hub->getTransactionStatus($orderId);

echo "Status: " . $response->status->label() . "\n";
echo "Valor: " . $response->money->formatted() . "\n";

if ($response->isPaid()) {
    echo "Pagamento confirmado!\n";
}
```

### Status Possíveis

- `CREATED` - Pedido criado
- `APPROVED` - Aprovado pelo pagador
- `COMPLETED` - Pagamento capturado
- `VOIDED` - Autorização cancelada
- `PENDING` - Aguardando ação
- `DECLINED` - Recusado
- `FAILED` - Falhou

### Listar Transações

```php
$transactions = $hub->listTransactions([
    'start_date' => '2025-01-01T00:00:00Z',
    'end_date' => '2025-12-31T23:59:59Z',
    'transaction_status' => 'S', // Success
]);

foreach ($transactions as $transaction) {
    echo $transaction['transaction_info']['transaction_id'] . "\n";
}
```

---

## 💰 Estornos

### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = RefundRequest::create(
    transactionId: $captureId, // ⚠️ Use capture ID, não order ID
    reason: 'Customer requested refund'
);

$response = $hub->refund($request);

echo "Reembolso processado: " . $response->refundId . "\n";
```

### Reembolso Parcial

```php
$response = $hub->partialRefund(
    transactionId: $captureId,
    amount: 25.00
);

echo "Reembolsado: $25.00\n";
```

### Listar Disputas (Chargebacks)

```php
$disputes = $hub->getChargebacks([
    'dispute_state' => 'OPEN'
]);

foreach ($disputes as $dispute) {
    echo "Disputa: " . $dispute['dispute_id'] . "\n";
    echo "Motivo: " . $dispute['reason'] . "\n";
    echo "Valor: $" . $dispute['dispute_amount']['value'] . "\n";
}
```

### Contestar Disputa

```php
$evidence = [
    'note' => 'Customer received the product on 2025-01-15',
    'documents' => [
        [
            'type' => 'PROOF_OF_DELIVERY',
            'url' => 'https://example.com/tracking.pdf'
        ]
    ]
];

$response = $hub->disputeChargeback($disputeId, $evidence);
```

---

## 💸 Payouts (Transferências)

### Enviar Pagamento em Massa

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

$request = new TransferRequest(
    amount: 100.00,
    recipientEmail: 'seller@example.com',
    description: 'Commission payment',
    currency: Currency::USD
);

$response = $hub->transfer($request);

echo "Payout criado: " . $response->transferId . "\n";
```

### Múltiplos Destinatários

```php
// Para múltiplos pagamentos, use a API diretamente
$data = [
    'sender_batch_header' => [
        'sender_batch_id' => uniqid('batch_', true),
        'email_subject' => 'You have a payment!',
    ],
    'items' => [
        [
            'recipient_type' => 'EMAIL',
            'amount' => ['value' => '10.00', 'currency' => 'USD'],
            'receiver' => 'recipient1@example.com',
        ],
        [
            'recipient_type' => 'EMAIL',
            'amount' => ['value' => '5.00', 'currency' => 'USD'],
            'receiver' => 'recipient2@example.com',
        ]
    ]
];
```

---

## 🔗 Links de Pagamento

### Criar Link

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 149.90,
    description: 'Premium Course Access',
    metadata: [
        'return_url' => 'https://yoursite.com/success',
        'cancel_url' => 'https://yoursite.com/cancel'
    ]
);

$response = $hub->createPaymentLink($request);

echo "Link de pagamento: " . $response->url . "\n";
```

### Consultar Link

```php
$response = $hub->getPaymentLink($orderId);
echo "URL: " . $response->url . "\n";
echo "Status: " . $response->status . "\n";
```

**Nota:** Links do PayPal expiram automaticamente após 3 horas.

---

## 🔔 Webhooks

### Registrar Webhook

```php
$response = $hub->registerWebhook(
    url: 'https://yoursite.com/webhooks/paypal',
    events: [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'CUSTOMER.DISPUTE.CREATED',
    ]
);

echo "Webhook ID: " . $response['webhook_id'] . "\n";
```

### Eventos Importantes

```
PAYMENT.CAPTURE.COMPLETED - Pagamento capturado
PAYMENT.CAPTURE.DENIED - Pagamento recusado
CHECKOUT.ORDER.APPROVED - Order aprovada
BILLING.SUBSCRIPTION.CREATED - Assinatura criada
BILLING.SUBSCRIPTION.CANCELLED - Assinatura cancelada
CUSTOMER.DISPUTE.CREATED - Disputa aberta
PAYMENT.CAPTURE.REFUNDED - Reembolso processado
```

### Processar Webhook

```php
// No seu endpoint de webhook
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Validar webhook signature (recomendado)
$webhookId = 'YOUR_WEBHOOK_ID';
$transmissionId = $headers['Paypal-Transmission-Id'];
$transmissionTime = $headers['Paypal-Transmission-Time'];
$certUrl = $headers['Paypal-Cert-Url'];
$authAlgo = $headers['Paypal-Auth-Algo'];
$transmissionSig = $headers['Paypal-Transmission-Sig'];

// Validação (use PayPal SDK ou implemente manualmente)
// ...

$event = json_decode($payload, true);

switch ($event['event_type']) {
    case 'PAYMENT.CAPTURE.COMPLETED':
        $captureId = $event['resource']['id'];
        // Liberar produto/serviço
        fulfillOrder($captureId);
        break;
        
    case 'BILLING.SUBSCRIPTION.CANCELLED':
        $subscriptionId = $event['resource']['id'];
        // Revogar acesso
        revokeAccess($subscriptionId);
        break;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
```

### Listar Webhooks

```php
$webhooks = $hub->listWebhooks();

foreach ($webhooks as $webhook) {
    echo "ID: " . $webhook['id'] . "\n";
    echo "URL: " . $webhook['url'] . "\n";
}
```

### Deletar Webhook

```php
$deleted = $hub->deleteWebhook($webhookId);
```

---

## 📝 Notas Importantes

### URLs da API

- **Sandbox**: `https://api-m.sandbox.paypal.com`
- **Production**: `https://api-m.paypal.com`
- **Dashboard**: `https://developer.paypal.com/dashboard`
- **Docs**: `https://developer.paypal.com/docs/api/overview/`

### Autenticação

- OAuth 2.0 Client Credentials
- Token válido por 9 horas (32400s)
- Gateway renova automaticamente

### Ambientes

- **Sandbox**: Para testes (contas de teste)
- **Live**: Produção (dinheiro real)
- **NUNCA** misture credenciais

### Moedas Suportadas

PayPal suporta 100+ moedas:
- **USD** (Dólar) 🇺🇸
- **EUR** (Euro) 🇪🇺
- **GBP** (Libra) 🇬🇧
- **BRL** (Real) 🇧🇷
- **CAD** (Dólar Canadense) 🇨🇦
- **AUD** (Dólar Australiano) 🇦🇺
- E muitas outras...

### Taxas PayPal

**Brasil (2025):**
- Vendas nacionais: 4,99% + R$ 0,60
- Vendas internacionais: 6,99% + taxa fixa
- Recebimento de pagamentos: Gratuito
- Transferências: Variável

Confira taxas atualizadas: [https://www.paypal.com/br/webapps/mpp/merchant-fees](https://www.paypal.com/br/webapps/mpp/merchant-fees)

### Seller Protection

PayPal oferece proteção ao vendedor contra:
- ✅ Chargebacks não autorizados
- ✅ Alegações de "Item não recebido"
- ⚠️ Requer comprovação de envio/entrega

### Disputas e Chargebacks

- Prazo para resposta: 10 dias
- Forneça evidências completas
- Use Tracking de envio sempre
- Mantenha comunicação registrada

### Compliance e KYC

- Verifique sua conta PayPal
- Forneça documentos quando solicitado
- Limites removidos após verificação
- Negócios: CNPJ obrigatório

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
    if (isset($context['details'])) {
        foreach ($context['details'] as $detail) {
            echo "- " . $detail['issue'] . ": " . $detail['description'] . "\n";
        }
    }
}
```

### Códigos de Erro Comuns

```
INVALID_REQUEST - Requisição inválida
AUTHENTICATION_FAILURE - Credenciais inválidas
AUTHORIZATION_ERROR - Sem permissão
CARD_DECLINED - Cartão recusado
INSUFFICIENT_FUNDS - Saldo insuficiente
TRANSACTION_REFUSED - Transação recusada
DUPLICATE_INVOICE_ID - ID de fatura duplicado
CURRENCY_NOT_SUPPORTED - Moeda não suportada
```

---

## 🔒 Segurança

### PCI Compliance

- PayPal é **PCI-DSS Level 1** certificado
- Nunca armazene dados de cartão
- Use tokenização quando possível
- HTTPS obrigatório para webhooks

### 3D Secure (SCA)

PayPal suporta 3D Secure 2.0:
- Automático para transações elegíveis
- Requerido na Europa (PSD2)
- Reduz chargebacks

### Sandbox Testing

Crie contas de teste em:
[https://developer.paypal.com/dashboard/accounts](https://developer.paypal.com/dashboard/accounts)

Tipos de conta:
- **Personal** - Comprador
- **Business** - Vendedor

---

## 🆘 Suporte

- 📚 Documentação: [https://developer.paypal.com/docs/](https://developer.paypal.com/docs/)
- 💬 Suporte PayPal: [https://www.paypal.com/br/smarthelp/contact-us](https://www.paypal.com/br/smarthelp/contact-us)
- 🐛 Issues PaymentHub: [https://github.com/israel-nogueira/payment-hub](https://github.com/israel-nogueira/payment-hub)
- 👥 Comunidade: [https://www.paypal-community.com/](https://www.paypal-community.com/)

---

## 📄 Licença

Este gateway faz parte do PaymentHub.

---

## 🚀 Exemplo Completo

```php
<?php

require 'vendor/autoload.php';

use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\PayPal\PayPalGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

// Configurar gateway
$gateway = new PayPalGateway(
    clientId: 'YOUR_SANDBOX_CLIENT_ID',
    clientSecret: 'YOUR_SANDBOX_SECRET',
    testMode: true
);

$hub = new PaymentHub($gateway);

try {
    // Criar pagamento
    $request = CreditCardPaymentRequest::create(
        amount: 99.99,
        cardNumber: '4111 1111 1111 1111',
        cardHolderName: 'JOHN DOE',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        customerEmail: 'john@example.com',
        description: 'Premium Membership'
    );

    $response = $hub->createCreditCardPayment($request);

    if ($response->isSuccess()) {
        echo "✅ Pagamento aprovado!\n";
        echo "💰 Valor: " . $response->money->formatted() . "\n";
        echo "🆔 Order ID: " . $response->transactionId . "\n";
        echo "📧 Email: " . $response->metadata['payer_email'] . "\n";
        
        // Fulfillment da ordem...
        
    } else {
        echo "⚠️ Pagamento não concluído\n";
        echo "Status: " . $response->status->label() . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    // Log do erro, notificar admin...
}
```

---

## 🌍 Marketplace & Multi-currency

### Marketplace Setup

PayPal oferece soluções de marketplace:
- **Commerce Platform** - Para plataformas grandes
- **Partner Referrals** - Onboarding de sellers
- **Payouts** - Pagamentos em massa

Contate PayPal para configuração enterprise.

### Multi-currency

```php
// Aceitar pagamento em EUR
$request = CreditCardPaymentRequest::create(
    amount: 99.99,
    currency: Currency::EUR,
    cardNumber: '4111 1111 1111 1111',
    // ...
);
```

---

**Pronto para aceitar pagamentos globais com PayPal!** 🌎💙
