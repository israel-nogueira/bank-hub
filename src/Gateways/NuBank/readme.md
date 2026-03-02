# NuBank Gateway — NuPay for Business

Integração com o **NuPay for Business**, a plataforma de pagamentos do Nubank para e-commerces.

> **Documentação oficial:** https://docs.nupaybusiness.com.br/checkout/docs/openapi/index.html
> **Contato NuPay:** oi-nupay@nubank.com.br

---

## ⚠️ O que é o NuPay — leia antes de integrar

O NuPay **não é um gateway de pagamentos tradicional**. Ele é um método de pagamento exclusivo para clientes Nubank que funciona por **redirecionamento**:

```
Sua loja cria o pedido → API retorna paymentUrl
     ↓
Você redireciona o cliente para paymentUrl
     ↓
Cliente abre o app Nubank e confirma com senha de 4 dígitos
     ↓
Nubank redireciona de volta para sua returnUrl
     ↓
Sua loja recebe callback com o status final
```

Sua loja **nunca toca** em dados de cartão, conta bancária ou chave PIX do cliente.

---

## ✅ O que esta API realmente suporta

| Funcionalidade | Endpoint real |
|---|---|
| Criar pagamento NuPay | `POST /v1/checkouts/payments` |
| Consultar status do pagamento | `GET /v1/checkouts/payments/{pspReferenceId}/status` |
| Cancelar pagamento não pago | `POST /v1/checkouts/payments/{pspReferenceId}/cancel` |
| Estorno total ou parcial | `POST /v1/checkouts/payments/{pspReferenceId}/refunds` |
| Consultar status de estorno | `GET /v1/checkouts/payments/{pspReferenceId}/refunds/{refundId}` |

## ❌ O que não existe nesta API

PIX, cartão de crédito/débito, boleto, assinaturas, transferências, wallets, escrow, split, sub-contas, webhooks via API e consulta de saldo **não existem** no NuPay for Business. Todos esses métodos lançam `GatewayException` com a alternativa indicada.

---

## 🔐 Autenticação

Dois headers fixos em **todas** as requisições. Sem OAuth2, sem Bearer token.

```
X-Merchant-Key:   {sua API Key}
X-Merchant-Token: {seu API Token}
```

Obtidos no **Painel do Lojista NuPay for Business → seção Credenciais**.

---

## 🌐 Endpoints

| Ambiente | URL base |
|---|---|
| Sandbox | `https://sandbox-api.spinpay.com.br` |
| Produção | `https://api.spinpay.com.br` |

---

## 🚀 Instalação e configuração

### Instanciar o gateway

```php
use IsraelNogueira\PaymentHub\Gateways\NuBank\NuBankGateway;
use IsraelNogueira\PaymentHub\PaymentHub;

$gateway = new NuBankGateway(
    merchantKey:   env('NUPAY_MERCHANT_KEY'),
    merchantToken: env('NUPAY_MERCHANT_TOKEN'),
    sandbox:       env('NUPAY_SANDBOX', true),
    merchantName:  'Minha Loja',                        // nome exibido no app Nubank
    callbackUrl:   'https://minha-loja.com/nupay/notify', // receber notificações de status
);

$hub = new PaymentHub($gateway);
```

### .env

```env
NUPAY_MERCHANT_KEY=sua_api_key_aqui
NUPAY_MERCHANT_TOKEN=seu_api_token_aqui
NUPAY_SANDBOX=true
```

### config/payment.php

```php
'nubank' => [
    'class'          => \IsraelNogueira\PaymentHub\Gateways\NuBank\NuBankGateway::class,
    'merchant_key'   => env('NUPAY_MERCHANT_KEY'),
    'merchant_token' => env('NUPAY_MERCHANT_TOKEN'),
    'sandbox'        => env('NUPAY_SANDBOX', true),
    'merchant_name'  => env('NUPAY_MERCHANT_NAME', ''),
    'callback_url'   => env('NUPAY_CALLBACK_URL', ''),
    'enabled'        => env('PAYMENT_NUBANK_ENABLED', false),
],
```

---

## 💡 Exemplos de uso

### 1. Criar pagamento NuPay

O gateway usa `PixPaymentRequest` como veículo de dados por ser o tipo mais próximo estruturalmente. O campo `metadata` transporta os campos específicos do NuPay.

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = new PixPaymentRequest(
    amount:           150.00,
    currency:         'BRL',
    customerName:     'João Silva',
    customerDocument: '64262091040',     // CPF ou CNPJ
    customerEmail:    'joao@email.com',
    description:      'Pedido #1234',
    metadata: [
        // ─── OBRIGATÓRIOS ────────────────────────────────────────
        'merchantOrderReference' => 'pedido-1234',          // único por loja
        'referenceId'            => 'uuid-v4-unico',        // único por pagamento

        // ─── URLS DE REDIRECIONAMENTO ────────────────────────────
        'returnUrl' => 'https://minha-loja.com/obrigado',   // após pagamento aprovado
        'cancelUrl' => 'https://minha-loja.com/cancelado',  // se cliente cancelar no app

        // ─── OPCIONAIS ───────────────────────────────────────────
        'storeName'          => 'Filial Paulista',
        'delayToAutoCancel'  => 15,     // minutos. padrão: 30. mínimo: 1
        'authorizationType'  => 'manually_authorized', // padrão para fluxo 2FA

        // Itens do pedido
        'items' => [
            [
                'id'          => '132981',
                'description' => 'Camiseta Azul M',
                'value'       => 150.00,
                'quantity'    => 1,
            ],
        ],

        // Frete (opcional)
        'shipping' => [
            'value'   => 15.00,
            'company' => 'Correios',
            'address' => [
                'country'      => 'BRA',
                'street'       => 'Rua das Flores',
                'number'       => '100',
                'neighborhood' => 'Centro',
                'postalCode'   => '01310100',
                'city'         => 'São Paulo',
                'state'        => 'SP',
            ],
        ],

        // Endereço de cobrança (opcional)
        'billingAddress' => [
            'country'      => 'BRA',
            'street'       => 'Av. Paulista',
            'number'       => '1000',
            'neighborhood' => 'Bela Vista',
            'postalCode'   => '01310100',
            'city'         => 'São Paulo',
            'state'        => 'SP',
        ],

        // Beneficiários finais — exigência BCB Circular 3.978/2020 (opcional)
        'recipients' => [
            [
                'referenceId' => 'uuid-do-recebedor',
                'amount'      => ['value' => 150.00, 'currency' => 'BRL'],
            ],
        ],
    ],
);

$payment = $hub->createPixPayment($request);

// ─── O QUE FAZER COM A RESPOSTA ─────────────────────────────────────────────
// pspReferenceId = ID do pagamento para consultas futuras
$pspReferenceId = $payment->transactionId;

// paymentUrl = REDIRECIONE o cliente para cá (abre o app Nubank)
$paymentUrl = $payment->rawResponse['_paymentUrl'];

// Redirecionar o cliente
header("Location: {$paymentUrl}");
exit;
```

### 2. Consultar status do pagamento

```php
$status = $hub->getTransactionStatus($pspReferenceId);

echo $status->status->value;
// PENDING   = aguardando pagamento no app
// PROCESSING = autorizado, processando
// APPROVED  = pagamento concluído
// CANCELLED = cancelado
// FAILED    = erro
```

**Status reais retornados pela API NuPay:**

| Status NuPay | Status PaymentHub | Descrição |
|---|---|---|
| `WAITING_PAYMENT_METHOD` | `PENDING` | Aguardando cliente pagar no app |
| `AUTHORIZED` | `PROCESSING` | Cliente pagou, processando |
| `COMPLETED` | `APPROVED` | Pagamento concluído com sucesso |
| `CANCELLING` | `PROCESSING` | Cancelamento em andamento |
| `CANCELLED` | `CANCELLED` | Cancelado |
| `DENIED` | `FAILED` | Negado |
| `ERROR` | `FAILED` | Erro no processamento |
| `OPEN` / `REFUNDING` | `PROCESSING` | Estorno em andamento |

### 3. Cancelar pagamento não pago

Só funciona para pagamentos com status `WAITING_PAYMENT_METHOD`. Para pagamentos já aprovados, use estorno.

```php
// Reutiliza cancelBoleto() — ação de "cancelar pedido pendente"
$result = $gateway->cancelBoleto($pspReferenceId);
```

### 4. Estorno total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$refund = $hub->refund(new RefundRequest(
    transactionId: $pspReferenceId,
    amount:        150.00,
    reason:        'Produto esgotado',
));

echo $refund->refundId; // ID para acompanhar o estorno
```

### 5. Estorno parcial

```php
$refund = $hub->partialRefund($pspReferenceId, 50.00);
```

> A soma dos estornos parciais não pode ultrapassar o valor total do pagamento.

### 6. Consultar status de um estorno

```php
$result = $hub->getChargebacks([
    'pspReferenceId' => $pspReferenceId,
    'refundId'       => $refund->refundId,
]);
```

### 7. Receber notificações de status (Webhook/Callback)

O NuPay envia `POST` para o `callbackUrl` configurado sempre que o status muda.
Implemente o endpoint no seu servidor:

```php
// routes/nupay-callback.php
$payload = json_decode(file_get_contents('php://input'), true);

$pspReferenceId = $payload['pspReferenceId'] ?? null;
$status         = $payload['status'] ?? null;
$referenceId    = $payload['referenceId'] ?? null; // seu ID interno

// Atualizar pedido no banco de dados
match ($status) {
    'COMPLETED'  => $orderService->markAsPaid($referenceId),
    'CANCELLED'  => $orderService->markAsCancelled($referenceId),
    'REFUNDING'  => $orderService->markAsRefunding($referenceId),
    default      => null,
};

http_response_code(200);
echo json_encode(['received' => true]);
```

---

## 📋 Mapeamento completo de métodos

| Método | Suporte | Observação |
|---|---|---|
| `createPixPayment()` | ✅ | Cria pagamento NuPay (não é PIX) |
| `getTransactionStatus()` | ✅ | Consulta status por pspReferenceId |
| `cancelBoleto()` | ✅ | Cancela pedido WAITING_PAYMENT_METHOD |
| `refund()` | ✅ | Estorno total |
| `partialRefund()` | ✅ | Estorno parcial |
| `getChargebacks()` | ✅ | Consulta estorno (requer pspReferenceId + refundId) |
| `getPixQrCode()` | ❌ | NuPay não é PIX |
| `getPixCopyPaste()` | ❌ | NuPay não é PIX |
| `createCreditCardPayment()` | ❌ | Não suportado |
| `createDebitCardPayment()` | ❌ | Não suportado |
| `createBoleto()` | ❌ | Não suportado |
| `createSubscription()` | ❌ | Não suportado |
| `transfer()` | ❌ | Não suportado |
| `createSplitPayment()` | ❌ | Use metadata[recipients] |
| `getBalance()` | ❌ | Liquidação automática D+1 |
| `registerWebhook()` | ❌ | Use callbackUrl no construtor |
| Todos os outros | ❌ | Não suportado — lança GatewayException |

---

## 📁 Estrutura de arquivos

```
src/Gateways/NuBank/
├── NuBankGateway.php   ← Gateway principal
└── readme.md           ← Esta documentação
```

**Namespace:** `IsraelNogueira\PaymentHub\Gateways\NuBank`

---

## 🏦 Liquidação financeira

- Liquidação automática em **D+1 útil**
- Não é necessário sacar ou agendar retirada
- Valores transacionados até 00h00 são liquidados no próximo dia útil
- Relatórios de conciliação disponíveis via [Conciliation API](https://docs.nupaybusiness.com.br/checkout/sellers/conciliation-api/openapi) e Painel do Lojista

---

## 🆘 Suporte

- **Email:** oi-nupay@nubank.com.br
- **Documentação:** https://docs.nupaybusiness.com.br
- **Painel do Lojista:** sandbox e produção via acesso enviado por email no onboarding