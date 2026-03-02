# NuBank Gateway — PaymentHub

Integração com a API NuBank via **NuBaaS / Open Finance**, seguindo o padrão `PaymentGatewayInterface` do PaymentHub.

---

## 📋 Pré-Requisitos

| Requisito | Detalhe |
|---|---|
| Conta NuBaaS ou parceria Open Finance | Solicitada via [developers.nubank.com.br](https://developers.nubank.com.br) |
| Client ID + Client Secret | Gerados no portal NuBank Developer |
| Certificado mTLS (`.pem`) | Obrigatório em **produção**; opcional no sandbox |
| PHP 8.3+ | Requisito do PaymentHub |

---

## 🚀 Instanciar o Gateway

```php
use IsraelNogueira\PaymentHub\Gateways\NuBank\NuBankGateway;
use IsraelNogueira\PaymentHub\PaymentHub;

// Sandbox (desenvolvimento)
$gateway = new NuBankGateway(
    clientId:     'seu_client_id',
    clientSecret: 'seu_client_secret',
    sandbox:      true,
);

// Produção (exige certificado mTLS)
$gateway = new NuBankGateway(
    clientId:      'seu_client_id',
    clientSecret:  'seu_client_secret',
    sandbox:       false,
    certPath:      '/path/to/nubank.pem',
    certPassword:  'senha_do_certificado',
);

$hub = new PaymentHub($gateway);
```

---

## 💡 Exemplos de Uso

### PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = new PixPaymentRequest(
    amount:           150.00,
    currency:         'BRL',
    customerName:     'João Silva',
    customerDocument: '12345678900',
    customerEmail:    'joao@email.com',
    pixKey:           'chave@nubank.com.br',
    description:      'Pedido #1234',
);

$payment  = $hub->createPixPayment($request);
$copyCola = $hub->getPixCopyPaste($payment->transactionId);
$qrCode   = $hub->getPixQrCode($payment->transactionId);
```

### Cartão de Crédito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = new CreditCardPaymentRequest(
    amount:           500.00,
    cardNumber:       '4111111111111111',
    cardHolderName:   'JOAO SILVA',
    cardExpiryMonth:  '12',
    cardExpiryYear:   '2028',
    cardCvv:          '123',
    installments:     3,
    customerDocument: '12345678900',
    customerEmail:    'joao@email.com',
);

$payment = $hub->createCreditCardPayment($request);
```

### Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = new BoletoPaymentRequest(
    amount:           200.00,
    customerName:     'Maria Oliveira',
    customerDocument: '98765432100',
    customerEmail:    'maria@email.com',
    dueDate:          '2026-04-15',
);

$boleto   = $hub->createBoleto($request);
$boletoUrl= $hub->getBoletoUrl($boleto->transactionId);
```

### Estorno

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$refund = $hub->refund(new RefundRequest(
    transactionId: 'txn_abc123',
    amount:        100.00,
    reason:        'Produto não entregue',
));
```

---

## 🔐 Autenticação

O gateway usa **OAuth2 Client Credentials** com renovação automática de token. Em produção, a comunicação é protegida com **mTLS** (certificado digital).

| Ambiente | URL Base |
|---|---|
| Sandbox   | `https://sandbox.nubank.com.br` |
| Produção  | `https://api.nubank.com.br`     |

---

## 📋 Métodos Suportados

| Método | Status |
|---|---|
| `createPixPayment()` | ✅ |
| `getPixQrCode()` | ✅ |
| `getPixCopyPaste()` | ✅ |
| `createCreditCardPayment()` | ✅ |
| `tokenizeCard()` | ✅ |
| `capturePreAuthorization()` | ✅ |
| `cancelPreAuthorization()` | ✅ |
| `createDebitCardPayment()` | ✅ |
| `createBoleto()` | ✅ |
| `getBoletoUrl()` | ✅ |
| `cancelBoleto()` | ✅ |
| `createSubscription()` | ✅ |
| `cancelSubscription()` | ✅ |
| `suspendSubscription()` | ✅ |
| `reactivateSubscription()` | ✅ |
| `updateSubscription()` | ✅ |
| `getTransactionStatus()` | ✅ |
| `listTransactions()` | ✅ |
| `refund()` | ✅ |
| `partialRefund()` | ✅ |
| `getChargebacks()` | ✅ |
| `disputeChargeback()` | ✅ |
| `createSplitPayment()` | ✅ |
| `createSubAccount()` | ✅ |
| `createWallet()` | ✅ |
| `addBalance()` | ✅ |
| `deductBalance()` | ✅ |
| `getWalletBalance()` | ✅ |
| `transferBetweenWallets()` | ✅ |
| `holdInEscrow()` | ✅ |
| `releaseEscrow()` | ✅ |
| `transfer()` | ✅ |
| `scheduleTransfer()` | ✅ |
| `getBalance()` | ✅ |
| `getStatement()` | ✅ |
| `createCustomer()` | ✅ |
| `createPaymentLink()` | ✅ |
| `registerWebhook()` | ✅ |
| `listWebhooks()` | ✅ |
| `deleteWebhook()` | ✅ |

---

## 📁 Estrutura de Arquivos

```
src/Gateways/NuBank/
├── NuBankGateway.php       ← Gateway principal
├── nubank-examples.php     ← Exemplos práticos
└── readme.md               ← Esta documentação
```

**Namespace:** `IsraelNogueira\PaymentHub\Gateways\NuBank`

---

## ⚙️ Configuração (.env)

```env
PAYMENT_GATEWAY=nubank

NUBANK_CLIENT_ID=seu_client_id
NUBANK_CLIENT_SECRET=seu_client_secret
NUBANK_SANDBOX=true
NUBANK_CERT_PATH=/path/to/nubank.pem
NUBANK_CERT_PASSWORD=sua_senha
```

### config/payment.php

```php
'nubank' => [
    'class'         => \IsraelNogueira\PaymentHub\Gateways\NuBank\NuBankGateway::class,
    'client_id'     => env('NUBANK_CLIENT_ID'),
    'client_secret' => env('NUBANK_CLIENT_SECRET'),
    'sandbox'       => env('NUBANK_SANDBOX', true),
    'cert_path'     => env('NUBANK_CERT_PATH'),
    'cert_password' => env('NUBANK_CERT_PASSWORD'),
    'enabled'       => env('PAYMENT_NUBANK_ENABLED', false),
],
```

---

*Integração desenvolvida para o PaymentHub — PHP 8.3+, Type-Safe.*
