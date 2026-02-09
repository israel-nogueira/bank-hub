# 🌎 EBANX Gateway

Gateway de integração com a EBANX, plataforma líder em pagamentos internacionais para América Latina.

## 📋 Índice

- [Características](#-características)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Métodos Suportados](#-métodos-suportados)
- [Exemplos de Uso](#-exemplos-de-uso)
- [Webhooks](#-webhooks)
- [Países e Moedas](#-países-e-moedas)
- [Limitações Conhecidas](#-limitações-conhecidas)

---

## ✨ Características

- ✅ **PIX** - Pagamentos instantâneos (Brasil)
- ✅ **Cartão de Crédito** - Internacional e parcelamento
- ✅ **Cartão de Débito** - Débito online
- ✅ **Boleto Bancário** - Geração e consulta (Brasil)
- ✅ **Recorrência** - Pagamentos via token
- ✅ **Refunds** - Estornos totais e parciais
- ✅ **Pre-authorization** - Captura manual
- ✅ **Tokenização** - Cartões para pagamentos futuros
- ✅ **Antifraude** - Análise automática integrada
- ✅ **Multi-país** - Brasil, México, Argentina, Colômbia, Chile, Peru, Equador
- ✅ **Conversão de Moeda** - Automática para vendas internacionais

---

## 📦 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## 🔧 Configuração

### Obter Credenciais

1. Acesse [EBANX Dashboard](https://dashboard.ebanx.com)
2. Vá em **Settings** → **API Keys**
3. Copie sua **Integration Key** (teste ou produção)
4. Copie sua **Public Integration Key** (para tokenização)

### Inicialização

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Ebanx\EbanxGateway;

// Modo Sandbox (Teste)
$gateway = new EbanxGateway(
    integrationKey: 'your_test_integration_key',
    publicKey: 'your_test_public_key', // Opcional
    sandbox: true,
    country: 'br' // br, mx, co, cl, ar, pe, ec
);

// Modo Produção
$gateway = new EbanxGateway(
    integrationKey: 'your_live_integration_key',
    publicKey: 'your_live_public_key',
    sandbox: false,
    country: 'br'
);

$hub = new PaymentHub($gateway);
```

---

## 🎯 Métodos Suportados

### Pagamentos

| Método | Status | Observações |
|--------|--------|-------------|
| `createPixPayment` | ✅ | QR Code e copia-e-cola (Brasil) |
| `createCreditCardPayment` | ✅ | Parcelamento e captura manual |
| `createDebitCardPayment` | ✅ | Débito online com redirect |
| `createBoleto` | ✅ | PDF e código de barras (Brasil) |
| `tokenizeCard` | ✅ | Para pagamentos futuros |
| `capturePreAuthorization` | ✅ | Captura total ou parcial |
| `cancelPreAuthorization` | ✅ | Cancela autorização |

### Recorrência

| Método | Status | Observações |
|--------|--------|-------------|
| `createSubscription` | ✅ | Usando token de cartão |
| `cancelSubscription` | ✅ | Cancelamento imediato |
| `suspendSubscription` | ❌ | Use cancelamento |
| `reactivateSubscription` | ❌ | Crie nova cobrança |
| `updateSubscription` | ❌ | Via Dashboard |

### Gestão

| Método | Status | Observações |
|--------|--------|-------------|
| `createCustomer` | ❌ | Dados enviados com pagamento |
| `refund` | ✅ | Estorno total |
| `partialRefund` | ✅ | Estorno parcial |
| `getTransactionStatus` | ✅ | Consulta de status |
| `analyzeTransaction` | ✅ | Status de antifraude |

---

## 💡 Exemplos de Uso

### PIX - Básico

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 100.00,
        customerName: 'João Silva',
        customerEmail: 'joao@email.com',
        customerDocument: '123.456.789-00'
    )
);

echo "Transaction ID: {$payment->transactionId}\n";
echo "PIX Code: {$payment->metadata['pix_emv']}\n";
echo "QR Code: {$payment->metadata['pix_qrcode']}\n";

// Buscar QR Code depois
$qrCode = $hub->getPixQrCode($payment->transactionId);
$copiaECola = $hub->getPixCopyPaste($payment->transactionId);
```

### Cartão de Crédito - Parcelado

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 599.90,
        cardNumber: '4111 1111 1111 1111',
        cardHolderName: 'MARIA SANTOS',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        installments: 3, // 3x
        customerEmail: 'maria@email.com',
        customerDocument: '987.654.321-00'
    )
);

echo "Status: {$payment->getStatusLabel()}\n";
echo "Parcelas: 3x\n";
```

### Cartão - Com Captura Manual (Pre-auth)

```php
$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 299.90,
        cardNumber: '4111 1111 1111 1111',
        cardHolderName: 'JOSE LIMA',
        cardExpiryMonth: '08',
        cardExpiryYear: '2027',
        cardCvv: '321',
        capture: false // Apenas autorizar
    )
);

// Depois, capturar
$captured = $hub->capturePreAuthorization(
    $payment->transactionId,
    amount: 299.90 // Opcional: captura parcial
);
```

### Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$boleto = $hub->createBoleto(
    BoletoPaymentRequest::create(
        amount: 450.00,
        customerName: 'Pedro Oliveira',
        customerDocument: '111.222.333-44',
        customerEmail: 'pedro@email.com',
        dueDate: '2025-03-15',
        description: 'Mensalidade Março/2025',
        address: [
            'street' => 'Rua Exemplo, 123',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zipcode' => '01234-567',
        ]
    )
);

echo "URL PDF: {$boleto->metadata['boleto_url']}\n";
echo "Código de Barras: {$boleto->metadata['boleto_barcode']}\n";
```

### Tokenizar Cartão

```php
$token = $hub->tokenizeCard([
    'number' => '4111111111111111',
    'holder_name' => 'TESTE SILVA',
    'expiry_month' => '12',
    'expiry_year' => '2028',
    'cvv' => '123',
]);

echo "Card Token: {$token}\n";

// Usar token em pagamento recorrente
$subscription = $hub->createSubscription(
    SubscriptionRequest::create(
        amount: 49.90,
        interval: 'monthly',
        customerEmail: 'cliente@email.com',
        cardToken: $token
    )
);
```

### Débito Online

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;

$payment = $hub->createDebitCardPayment(
    DebitCardPaymentRequest::create(
        amount: 150.00,
        customerName: 'Ana Costa',
        customerEmail: 'ana@email.com',
        customerDocument: '555.666.777-88'
    )
);

// Redirecionar usuário para autenticação
$redirectUrl = $payment->metadata['redirect_url'];
header("Location: {$redirectUrl}");
```

### Estorno Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$refund = $hub->refund(
    RefundRequest::create(
        transactionId: 'hash_from_payment',
        metadata: ['reason' => 'Produto com defeito']
    )
);

echo "Refund ID: {$refund->refundId}\n";
echo "Valor: {$refund->money->formatted()}\n";
```

### Estorno Parcial

```php
$refund = $hub->partialRefund(
    transactionId: 'hash_from_payment',
    amount: 50.00 // Estornar apenas R$ 50,00
);
```

### Consultar Status

```php
$status = $hub->getTransactionStatus('hash_from_payment');

echo "Status: {$status->status->label()}\n";
echo "Valor: {$status->money->formatted()}\n";
```

### Verificar Antifraude

```php
$fraud = $hub->analyzeTransaction('hash_from_payment');

echo "Fraud Status: {$fraud['fraud_status']}\n";
print_r($fraud['fraud_analysis']);
```

---

## 🎣 Webhooks

### Configurar Webhook

Configure webhooks via **EBANX Dashboard**:

1. Acesse **Settings** → **Notifications**
2. Adicione sua URL de webhook
3. Selecione os eventos desejados

### Processar Webhook

```php
use IsraelNogueira\PaymentHub\Webhooks\WebhookHandler;

$handler = new WebhookHandler($hub);

$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// EBANX envia POST com os dados da transação
switch ($event['notification_type']) {
    case 'update':
        $hash = $event['hash_codes'];
        // Consultar status atualizado
        $status = $hub->getTransactionStatus($hash);
        break;
        
    case 'refund':
        // Estorno processado
        break;
        
    case 'chargeback':
        // Chargeback recebido
        break;
}
```

### Eventos Disponíveis

- `update` - Atualização de status
- `refund` - Estorno processado
- `chargeback` - Chargeback recebido
- `pending` - Pagamento pendente
- `paid` - Pagamento confirmado

---

## 🌍 Países e Moedas

### Países Suportados

| País | Código | Moeda | Métodos Disponíveis |
|------|--------|-------|---------------------|
| 🇧🇷 Brasil | `br` | BRL | PIX, Cartão, Boleto, Débito Online |
| 🇲🇽 México | `mx` | MXN | Cartão, OXXO, SPEI |
| 🇦🇷 Argentina | `ar` | ARS | Cartão, Rapipago, PagoFacil |
| 🇨🇴 Colômbia | `co` | COP | Cartão, Baloto, PSE |
| 🇨🇱 Chile | `cl` | CLP | Cartão, Sencillito, Servipag |
| 🇵🇪 Peru | `pe` | PEN | Cartão, PagoEfectivo, SafetyPay |
| 🇪🇨 Equador | `ec` | USD | Cartão |

### Converter Moeda Automaticamente

```php
// Venda em dólares, receba em reais
$gateway = new EbanxGateway(
    integrationKey: 'your_key',
    country: 'br' // Receberá em BRL
);

// Cliente paga em USD, você recebe em BRL com conversão automática
```

---

## ⚠️ Limitações Conhecidas

### Não Suportado via API

- ❌ **Gestão de Clientes** - Dados enviados com cada pagamento
- ❌ **Payment Links** - Disponível via Dashboard
- ❌ **Split Payments** - Requer EBANX Marketplace
- ❌ **Sub-contas** - Requer EBANX Marketplace
- ❌ **Wallets** - Não disponível
- ❌ **Transferências** - Via Dashboard
- ❌ **Listagem de Transações** - Use relatórios

### Observações Importantes

1. **Débito Online**: Requer redirect do usuário para autenticação
2. **Recorrência**: Use tokenização de cartão
3. **Refund**: Disponível até 120 dias após transação
4. **Boleto**: Válido por até 3 dias após vencimento
5. **PIX**: Expira em 24 horas por padrão

---

## 📊 Status de Transações

| Status EBANX | Status Payment Hub | Descrição |
|--------------|-------------------|-----------|
| `CO` | `PAID` | Confirmado |
| `CA` | `CANCELLED` | Cancelado |
| `PE` | `PENDING` | Pendente |
| `OP` | `PROCESSING` | Aguardando pagamento |
| `ND` | `FAILED` | Recusado |

---

## 🔗 Links Úteis

- [Documentação Oficial EBANX](https://developers.ebanx.com/)
- [Dashboard EBANX](https://dashboard.ebanx.com)
- [API Reference](https://developers.ebanx.com/api-reference/)
- [Status da API](https://status.ebanx.com)
- [Suporte](https://www.ebanx.com/br/suporte)

---

## 💬 Suporte

Para questões específicas do Payment Hub:
- 📧 Email: contato@israelnogueira.com
- 🐛 Issues: [GitHub Issues](https://github.com/israel-nogueira/payment-hub/issues)

Para questões da EBANX:
- 🌐 Site: https://www.ebanx.com
- 📧 Email: merchants@ebanx.com
- 💬 Chat: Disponível no Dashboard

---

**Última atualização**: Fevereiro 2025
