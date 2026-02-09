# 💳 Mercado Pago Gateway - Guia Completo

Gateway de integração com a API do Mercado Pago para pagamentos na América Latina no PaymentHub.

## 📋 Índice

- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [O que é Suportado](#-o-que-é-suportado)
- [O que NÃO é Suportado](#-o-que-não-é-suportado)
- [Clientes](#-clientes)
- [PIX](#-pix)
- [Cartão de Crédito](#-cartão-de-crédito)
- [Cartão de Débito](#-cartão-de-débito)
- [Boleto](#-boleto)
- [Assinaturas](#-assinaturas)
- [Transações](#-transações)
- [Estornos](#-estornos)
- [Split de Pagamento (Marketplace)](#-split-de-pagamento-marketplace)
- [Links de Pagamento (Checkout Pro)](#-links-de-pagamento-checkout-pro)
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
use IsraelNogueira\PaymentHub\Gateways\MercadoPago\MercadoPagoGateway;

// Modo teste (sandbox)
$gateway = new MercadoPagoGateway(
    accessToken: 'TEST-1234567890-123456-abc123def456-789012345',
    publicKey: 'TEST-abc123-def456-ghi789', // Opcional para tokenização frontend
    testMode: true
);

// Modo produção
$gateway = new MercadoPagoGateway(
    accessToken: 'APP_USR-1234567890-123456-abc123def456-789012345',
    publicKey: 'APP_USR-abc123-def456-ghi789',
    testMode: false
);

$hub = new PaymentHub($gateway);
```

### Obtendo Credenciais

1. Acesse [https://www.mercadopago.com.br/developers/panel](https://www.mercadopago.com.br/developers/panel)
2. Vá em **Suas integrações** > **Criar aplicação**
3. Copie suas credenciais:
   - **Teste**: `TEST-...`
   - **Produção**: `APP_USR-...`

---

## ✅ O que é Suportado

| Funcionalidade | Status | Observações |
|---------------|--------|-------------|
| 🟢 **PIX** | ✅ Completo | Pagamento instantâneo com QR Code |
| 💳 **Cartão de Crédito** | ✅ Completo | Todas as bandeiras + parcelamento |
| 💳 **Cartão de Débito** | ✅ Completo | Débito online |
| 📄 **Boleto** | ✅ Completo | Boleto bancário com vencimento |
| 🔄 **Assinaturas** | ✅ Completo | Recorrência via Preapproval |
| 💰 **Estornos** | ✅ Completo | Total e parcial |
| 👥 **Clientes** | ✅ Completo | CRUD completo |
| 🔗 **Checkout Pro** | ✅ Completo | Links de pagamento |
| 🏪 **Marketplace** | ⚠️ Parcial | Split payments via Advanced Payments |
| 🛡️ **Antifraude** | ✅ Automático | Sistema interno do Mercado Pago |

---

## ❌ O que NÃO é Suportado

Funcionalidades **não disponíveis** diretamente via API:

| Funcionalidade | Alternativa |
|---------------|-------------|
| ❌ **Sub-contas (OAuth)** | Marketplace requer OAuth flow |
| ❌ **Wallets Nativas** | Use Money In/Out API separadamente |
| ❌ **Saldo via API** | Consulte via Dashboard ou Reports API |
| ❌ **Webhooks via API** | Configure no Dashboard |
| ❌ **Antecipação de Recebíveis** | Entre em contato com suporte MP |

---

## 👥 Clientes

### Criar Cliente

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

$customer = new CustomerRequest(
    name: 'João Silva',
    email: 'joao@example.com',
    phone: '11987654321',
    documentNumber: '12345678900', // CPF
    address: [
        'street' => 'Rua Exemplo',
        'number' => '123',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zipcode' => '01234-567'
    ]
);

$response = $hub->createCustomer($customer);

echo $response->customerId; // 123456789
```

### Buscar Cliente

```php
$response = $hub->getCustomer('123456789');
print_r($response->rawResponse);
```

### Atualizar Cliente

```php
$response = $hub->updateCustomer('123456789', [
    'email' => 'novoemail@example.com',
    'phone' => [
        'area_code' => '11',
        'number' => '912345678'
    ]
]);
```

### Listar Clientes

```php
$customers = $hub->listCustomers([
    'limit' => 50,
    'offset' => 0
]);

foreach ($customers as $customer) {
    echo $customer['email'] . "\n";
}
```

---

## 🟢 PIX

### Criar Pagamento PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = PixPaymentRequest::create(
    amount: 150.00,
    customerName: 'João Silva',
    customerEmail: 'joao@example.com',
    customerDocument: '12345678900',
    description: 'Pedido #12345',
    expiresInMinutes: 30
);

$response = $hub->createPixPayment($request);

if ($response->status === PaymentStatus::PENDING) {
    echo "PIX gerado com sucesso!\n";
    echo "QR Code (Base64): " . $response->metadata['qr_code_base64'] . "\n";
    echo "Código Copia e Cola: " . $response->metadata['qr_code'] . "\n";
    echo "URL do Ticket: " . $response->metadata['ticket_url'] . "\n";
}
```

### Obter QR Code do PIX

```php
// QR Code em Base64 (para exibir na tela)
$qrCodeBase64 = $hub->getPixQrCode($transactionId);
echo "<img src='data:image/png;base64,{$qrCodeBase64}' />";

// Código Copia e Cola
$pixCode = $hub->getPixCopyPaste($transactionId);
echo "Código PIX: {$pixCode}";
```

### Status do PIX

O PIX inicia como `PENDING` e muda para `APPROVED` automaticamente quando pago.

```php
$status = $hub->getTransactionStatus($transactionId);

if ($status->status === PaymentStatus::APPROVED) {
    echo "PIX pago com sucesso!";
}
```

---

## 💳 Cartão de Crédito

### Pagamento Simples

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = CreditCardPaymentRequest::create(
    amount: 299.90,
    cardNumber: '5031 4332 1540 6351', // Teste: aprovado
    cardHolderName: 'JOAO SILVA',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    installments: 3, // 3x sem juros
    customerName: 'João Silva',
    customerEmail: 'joao@example.com',
    customerDocument: '12345678900'
);

$response = $hub->createCreditCardPayment($request);

if ($response->isSuccess()) {
    echo "Pagamento aprovado!\n";
    echo "Transaction ID: " . $response->transactionId . "\n";
    echo "Bandeira: " . $response->metadata['card_brand'] . "\n";
    echo "Últimos 4: " . $response->metadata['card_last4'] . "\n";
    echo "Parcelas: " . $response->metadata['installments'] . "x\n";
}
```

### Cartões de Teste (Sandbox)

```
✅ Aprovado: 5031 4332 1540 6351
❌ Recusado: 5031 4332 1540 6351 (CVV: 999)
⚠️ Pendente: 5031 7557 3453 0604

Mastercard: 5031 4332 1540 6351
Visa: 4509 9535 6623 3704
Amex: 3711 803032 57522
Elo: 6362 9707 9777 7693
```

### Tokenizar Cartão (para uso futuro)

```php
$token = $hub->tokenizeCard([
    'number' => '5031 4332 1540 6351',
    'holderName' => 'JOAO SILVA',
    'expiryMonth' => '12',
    'expiryYear' => '2028',
    'cvv' => '123'
]);

echo "Token: {$token}\n"; // card_token_xxxxx
```

### Usar Token Salvo

```php
$request = CreditCardPaymentRequest::create(
    amount: 99.90,
    cardToken: 'card_token_xxxxx', // Token salvo anteriormente
    customerEmail: 'joao@example.com',
    installments: 1
);

$response = $hub->createCreditCardPayment($request);
```

### Pré-autorização (Captura Manual)

```php
// Criar com captura manual
$request = CreditCardPaymentRequest::create(
    amount: 500.00,
    cardNumber: '5031 4332 1540 6351',
    cardHolderName: 'JOAO SILVA',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerEmail: 'joao@example.com',
    capture: false // ✅ Não captura automaticamente
);

$response = $hub->createCreditCardPayment($request);
$transactionId = $response->transactionId;

// Capturar depois
$captured = $hub->capturePreAuthorization($transactionId);

// Ou cancelar
$cancelled = $hub->cancelPreAuthorization($transactionId);
```

---

## 💳 Cartão de Débito

### Pagamento com Débito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;

$request = DebitCardPaymentRequest::create(
    amount: 99.90,
    cardToken: 'card_token_xxxxx', // Token criado no frontend
    customerEmail: 'joao@example.com',
    customerDocument: '12345678900'
);

$response = $hub->createDebitCardPayment($request);
```

**Nota:** Débito no Mercado Pago requer tokenização no frontend via MercadoPago.js.

---

## 📄 Boleto

### Criar Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = BoletoPaymentRequest::create(
    amount: 350.00,
    customerName: 'João Silva',
    customerEmail: 'joao@example.com',
    customerDocument: '12345678900',
    dueDate: '2025-12-31',
    description: 'Pedido #12345'
);

$response = $hub->createBoleto($request);

echo "Boleto gerado!\n";
echo "URL: " . $response->metadata['boleto_url'] . "\n";
echo "Código de Barras: " . $response->metadata['barcode'] . "\n";
echo "Vencimento: " . $response->metadata['due_date'] . "\n";
```

### Obter URL do Boleto

```php
$boletoUrl = $hub->getBoletoUrl($transactionId);
echo "Link do boleto: {$boletoUrl}";
```

### Cancelar Boleto

```php
$response = $hub->cancelBoleto($transactionId);
```

---

## 🔄 Assinaturas

### Criar Assinatura Mensal

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$request = SubscriptionRequest::create(
    amount: 49.90,
    interval: 'monthly',
    description: 'Plano Premium',
    customerEmail: 'joao@example.com',
    paymentMethod: 'card_token_xxxxx', // Token do cartão
    trialDays: 7 // 7 dias grátis
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
$response = $hub->cancelSubscription($subscriptionId);
```

### Suspender Assinatura

```php
$response = $hub->suspendSubscription($subscriptionId);
```

### Reativar Assinatura

```php
$response = $hub->reactivateSubscription($subscriptionId);
```

---

## 📊 Transações

### Consultar Status

```php
$response = $hub->getTransactionStatus($transactionId);

echo "Status: " . $response->status->label() . "\n";
echo "Valor: " . $response->money->formatted() . "\n";

if ($response->isPaid()) {
    echo "Pagamento confirmado!\n";
}
```

### Status Possíveis

- `approved` - Pagamento aprovado
- `pending` - Aguardando pagamento
- `authorized` - Pré-autorizado (aguardando captura)
- `in_process` - Processando
- `in_mediation` - Em mediação (disputa)
- `rejected` - Recusado
- `cancelled` - Cancelado
- `refunded` - Estornado
- `charged_back` - Chargeback

### Listar Transações

```php
$transactions = $hub->listTransactions([
    'limit' => 50,
    'offset' => 0,
    'sort' => 'date_created',
    'criteria' => 'desc'
]);

foreach ($transactions as $transaction) {
    echo $transaction['id'] . " - R$ " . $transaction['transaction_amount'] . "\n";
}
```

---

## 💰 Estornos

### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = RefundRequest::create(
    transactionId: $transactionId,
    reason: 'Cliente solicitou cancelamento'
);

$response = $hub->refund($request);

echo "Reembolso processado: " . $response->refundId . "\n";
```

### Reembolso Parcial

```php
$response = $hub->partialRefund(
    transactionId: $transactionId,
    amount: 50.00
);

echo "Reembolsado: R$ 50,00\n";
```

### Listar Chargebacks (Disputas)

```php
$chargebacks = $hub->getChargebacks([
    'limit' => 10
]);

foreach ($chargebacks as $claim) {
    echo "Disputa: " . $claim['id'] . " - " . $claim['status'] . "\n";
}
```

**Nota:** Contestação de chargebacks deve ser feita pelo Dashboard do Mercado Pago.

---

## 🏪 Split de Pagamento (Marketplace)

### Criar Pagamento com Split

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;

$request = new SplitPaymentRequest(
    money: Money::from(1000.00, Currency::BRL),
    platformFee: 100.00, // Taxa da plataforma (10%)
    splits: [
        [
            'recipient_id' => 'seller_1_account_id',
            'amount' => 450.00
        ],
        [
            'recipient_id' => 'seller_2_account_id',
            'amount' => 450.00
        ]
    ]
);

$response = $hub->createSplitPayment($request);
```

**Importante:** 
- Requer **Mercado Pago Marketplace** configurado
- Vendedores precisam conectar via OAuth
- Use **Advanced Payments API** para controle total

Documentação: [https://www.mercadopago.com.br/developers/pt/docs/marketplace](https://www.mercadopago.com.br/developers/pt/docs/marketplace)

---

## 🔗 Links de Pagamento (Checkout Pro)

### Criar Link de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 199.90,
    description: 'Curso Online - PHP Avançado',
    expiresAt: '2025-12-31',
    metadata: [
        'success_url' => 'https://seusite.com/sucesso',
        'failure_url' => 'https://seusite.com/falha',
        'pending_url' => 'https://seusite.com/pendente'
    ]
);

$response = $hub->createPaymentLink($request);

echo "Link: " . $response->url . "\n";
```

### Consultar Link

```php
$response = $hub->getPaymentLink($linkId);
echo "URL: " . $response->url . "\n";
```

### Expirar Link

```php
$response = $hub->expirePaymentLink($linkId);
```

---

## 🔔 Webhooks

### Configurar Webhooks

**⚠️ IMPORTANTE:** Webhooks do Mercado Pago são configurados **apenas pelo Dashboard**, não pela API.

1. Acesse: [https://www.mercadopago.com.br/developers/panel/webhooks](https://www.mercadopago.com.br/developers/panel/webhooks)
2. Clique em **Configurar notificações**
3. Adicione sua URL: `https://seusite.com/webhooks/mercadopago`
4. Selecione os eventos:
   - `payment` - Pagamentos
   - `merchant_order` - Pedidos
   - `chargebacks` - Contestações

### Eventos Importantes

```
payment.created - Pagamento criado
payment.updated - Pagamento atualizado (aprovado/rejeitado)
mp-connect.application.deauthorized - Seller desconectou
```

### Processar Webhook

```php
// No seu endpoint de webhook
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if ($data['type'] === 'payment') {
    $paymentId = $data['data']['id'];
    
    // Consultar detalhes do pagamento
    $payment = $hub->getTransactionStatus($paymentId);
    
    if ($payment->status === PaymentStatus::APPROVED) {
        // Liberar produto/serviço
        fulfillOrder($paymentId);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
```

### Validar Assinatura do Webhook

```php
// Header enviado pelo Mercado Pago
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

// Validar usando sua chave secreta
$secret = 'sua_webhook_secret'; // Do dashboard

$dataId = $data['data']['id'];
$expectedSignature = hash_hmac('sha256', $dataId, $secret);

if ($signature !== $expectedSignature) {
    http_response_code(401);
    die('Invalid signature');
}
```

---

## 📝 Notas Importantes

### URLs da API

- **Base URL**: `https://api.mercadopago.com`
- **Dashboard**: `https://www.mercadopago.com.br/developers/panel`
- **Docs**: `https://www.mercadopago.com.br/developers/pt/docs`

### Autenticação

- Header: `Authorization: Bearer ACCESS_TOKEN`
- Idempotência: `X-Idempotency-Key` (gerado automaticamente)

### Ambientes

- Use `TEST-` para testes (sandbox)
- Use `APP_USR-` para produção
- **NUNCA** misture credenciais de teste/produção

### Moedas Suportadas

Mercado Pago suporta moedas da América Latina:
- **BRL** (Real - Brasil) 🇧🇷
- **ARS** (Peso - Argentina) 🇦🇷
- **MXN** (Peso - México) 🇲🇽
- **CLP** (Peso - Chile) 🇨🇱
- **COP** (Peso - Colômbia) 🇨🇴
- **PEN** (Sol - Peru) 🇵🇪
- **UYU** (Peso - Uruguai) 🇺🇾

### Valores

- Mercado Pago trabalha com valores decimais (não centavos)
- R$ 10,00 = `10.00` (não 1000)
- PaymentHub converte automaticamente

### Certificação PCI

Mercado Pago é **PCI-DSS Level 1** certificado. Para maior segurança:
- Use **tokenização no frontend** (MercadoPago.js)
- **Nunca** envie dados de cartão pelo backend em produção
- Use **3D Secure** quando disponível

### Funcionalidades do Brasil

Para pagamentos no Brasil, o Mercado Pago oferece:
- ✅ PIX (pagamento instantâneo)
- ✅ Boleto bancário
- ✅ Cartão de crédito com parcelamento
- ✅ Mercado Crédito (crediário)
- ✅ Saldo Mercado Pago

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
        echo "Mensagem: " . $response->message . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "Código HTTP: " . $e->getCode() . "\n";
    
    $context = $e->getContext();
    if (isset($context['cause'])) {
        foreach ($context['cause'] as $cause) {
            echo "Causa: " . $cause['description'] . "\n";
        }
    }
}
```

### Códigos de Erro Comuns

```
cc_rejected_bad_filled_card_number - Número de cartão inválido
cc_rejected_bad_filled_date - Data de validade inválida
cc_rejected_bad_filled_security_code - CVV inválido
cc_rejected_insufficient_amount - Saldo insuficiente
cc_rejected_call_for_authorize - Ligar para autorizar
cc_rejected_card_disabled - Cartão desabilitado
cc_rejected_high_risk - Alto risco de fraude
```

---

## 🆘 Suporte

- 📚 Documentação oficial: [https://www.mercadopago.com.br/developers](https://www.mercadopago.com.br/developers)
- 💬 Suporte Mercado Pago: [https://www.mercadopago.com.br/developers/pt/support](https://www.mercadopago.com.br/developers/pt/support)
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
use IsraelNogueira\PaymentHub\Gateways\MercadoPago\MercadoPagoGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

// Configurar gateway
$gateway = new MercadoPagoGateway(
    accessToken: 'TEST-1234567890-123456-abc123def456-789012345',
    testMode: true
);

$hub = new PaymentHub($gateway);

try {
    // Criar pagamento
    $request = CreditCardPaymentRequest::create(
        amount: 299.90,
        cardNumber: '5031 4332 1540 6351',
        cardHolderName: 'JOAO SILVA',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        installments: 3,
        customerName: 'João Silva',
        customerEmail: 'joao@example.com',
        customerDocument: '12345678900',
        description: 'Pedido #12345'
    );

    $response = $hub->createCreditCardPayment($request);

    if ($response->isSuccess()) {
        echo "✅ Pagamento aprovado!\n";
        echo "💰 Valor: " . $response->money->formatted() . "\n";
        echo "🆔 ID: " . $response->transactionId . "\n";
        echo "💳 Bandeira: " . $response->metadata['card_brand'] . "\n";
        echo "🔢 Parcelas: " . $response->metadata['installments'] . "x\n";
        
        // Fulfillment do pedido aqui...
        
    } else {
        echo "⚠️ Pagamento pendente/rejeitado\n";
        echo "Status: " . $response->status->label() . "\n";
        echo "Mensagem: " . $response->message . "\n";
    }
    
} catch (GatewayException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    // Log do erro, notificar admin, etc...
}
```

---

**Pronto para aceitar pagamentos na América Latina com Mercado Pago!** 🌎💳
