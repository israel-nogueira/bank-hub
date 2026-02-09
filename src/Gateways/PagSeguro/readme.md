# 🟦 PagSeguro Gateway

Gateway de integração com **PagSeguro/PagBank** para o Payment Hub.

---

## 📋 Índice

- [Sobre](#-sobre)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Funcionalidades](#-funcionalidades)
- [Exemplos de Uso](#-exemplos-de-uso)
- [Métodos de Pagamento](#-métodos-de-pagamento)
- [Webhooks](#-webhooks)
- [Limitações](#-limitações)
- [Troubleshooting](#-troubleshooting)
- [Links Úteis](#-links-úteis)

---

## 🎯 Sobre

O **PagSeguroGateway** integra sua aplicação com a API do PagSeguro (PagBank), permitindo processar:

- 💰 **PIX** - Pagamentos instantâneos com QR Code
- 💳 **Cartão de Crédito** - À vista ou parcelado
- 💵 **Cartão de Débito** - Pagamentos diretos
- 📄 **Boleto Bancário** - Com código de barras
- 🔁 **Assinaturas** - Cobranças recorrentes
- 🔗 **Links de Pagamento** - URLs compartilháveis

---

## 📦 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

### 1. Obter Token de Acesso

Acesse o [PagSeguro Dashboard](https://pagseguro.uol.com.br/) e:

1. Vá em **Integrações** → **Tokens de Acesso**
2. Gere um novo token
3. Copie e guarde em local seguro

### 2. Inicializar Gateway

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\PagSeguro\PagSeguroGateway;

// Ambiente de Produção
$hub = new PaymentHub(new PagSeguroGateway(
    token: 'SEU_TOKEN_AQUI',
    sandbox: false
));

// Ambiente Sandbox (Testes)
$hub = new PaymentHub(new PagSeguroGateway(
    token: 'SEU_TOKEN_SANDBOX',
    sandbox: true
));
```

### 3. Configuração Recomendada

```php
// Com tratamento de erros
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $hub = new PaymentHub(new PagSeguroGateway(
        token: $_ENV['PAGSEGURO_TOKEN'],
        sandbox: $_ENV['APP_ENV'] !== 'production'
    ));
} catch (GatewayException $e) {
    // Log do erro
    error_log($e->getMessage());
}
```

---

## ✨ Funcionalidades

| Funcionalidade | Status | Notas |
|---------------|--------|-------|
| **PIX** | ✅ | QR Code + Copia e Cola |
| **Cartão de Crédito** | ✅ | À vista e parcelado |
| **Cartão de Débito** | ✅ | Pagamento direto |
| **Boleto** | ✅ | Com PDF e código de barras |
| **Assinaturas** | ✅ | Recorrência automática |
| **Links de Pagamento** | ✅ | URLs compartilháveis |
| **Reembolsos** | ✅ | Total e parcial |
| **Webhooks** | ✅ | Notificações automáticas |
| **Tokenização** | ✅ | Salvar cartões |
| **Pré-autorização** | ✅ | Captura posterior |
| **Split** | ❌ | Use sub-contas PagBank Business |
| **Wallets** | ❌ | Não suportado |
| **Escrow** | ❌ | Use pré-autorização |

---

## 💡 Exemplos de Uso

### 💰 PIX

#### PIX Básico

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 150.00,
        customerName: 'João Silva',
        customerEmail: 'joao@email.com',
        customerDocument: '123.456.789-00',
        description: 'Pedido #1234'
    )
);

echo "💰 Valor: {$payment->getFormattedAmount()}\n";
echo "📊 Status: {$payment->getStatusLabel()}\n";
echo "🔑 ID: {$payment->transactionId}\n";

// Pegar QR Code (base64 image URL)
$qrCodeUrl = $hub->getPixQrCode($payment->transactionId);
echo "<img src='{$qrCodeUrl}' alt='QR Code PIX'>";

// Pegar código Copia e Cola
$copiaECola = $hub->getPixCopyPaste($payment->transactionId);
echo "PIX Copia e Cola: {$copiaECola}";
```

#### PIX com Expiração

```php
$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 99.90,
        customerEmail: 'cliente@email.com',
        expiresInMinutes: 30, // Expira em 30 minutos
        description: 'Pagamento com prazo'
    )
);
```

---

### 💳 Cartão de Crédito

#### Pagamento à Vista

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 299.90,
        cardNumber: '4111 1111 1111 1111',
        cardHolderName: 'MARIA SILVA',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        customerName: 'Maria Silva',
        customerEmail: 'maria@email.com',
        customerDocument: '987.654.321-00'
    )
);

echo "💳 Bandeira: {$payment->metadata['card_brand']}\n";
echo "✅ Status: {$payment->getStatusLabel()}\n";
```

#### Pagamento Parcelado

```php
$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 1200.00,
        cardNumber: '5555 5555 5555 4444',
        cardHolderName: 'JOSE SANTOS',
        cardExpiryMonth: '08',
        cardExpiryYear: '2027',
        cardCvv: '321',
        installments: 12, // 12 parcelas
        customerEmail: 'jose@email.com'
    )
);

$valorParcela = $payment->money->amount() / 12;
echo "💰 12x de R$ " . number_format($valorParcela, 2, ',', '.');
```

#### Tokenização de Cartão

```php
// Salvar cartão para uso futuro
$token = $hub->tokenizeCard([
    'number' => '4111111111111111',
    'exp_month' => '12',
    'exp_year' => '2028',
    'cvv' => '123',
    'holder_name' => 'MARIA SILVA'
]);

echo "🔒 Token: {$token}";

// Usar token em pagamento futuro
// (Consulte documentação PagSeguro para uso de tokens)
```

#### Pré-autorização (Captura Posterior)

```php
// 1. Criar pré-autorização
$preAuth = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 500.00,
        cardNumber: '4111111111111111',
        cardHolderName: 'CLIENTE',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123',
        capture: false // Não capturar ainda
    )
);

// 2. Capturar total
$captured = $hub->capturePreAuthorization($preAuth->transactionId);

// 3. Ou capturar parcial
$partialCapture = $hub->capturePreAuthorization($preAuth->transactionId, 300.00);

// 4. Ou cancelar
$canceled = $hub->cancelPreAuthorization($preAuth->transactionId);
```

---

### 📄 Boleto Bancário

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$boleto = $hub->createBoleto(
    BoletoPaymentRequest::create(
        amount: 450.00,
        customerName: 'João Silva',
        customerDocument: '123.456.789-00',
        customerEmail: 'joao@email.com',
        dueDate: '2025-03-15', // Data de vencimento
        description: 'Mensalidade Março/2025',
        instructions: 'Não aceitar após o vencimento'
    )
);

// URL do PDF
$urlPdf = $hub->getBoletoUrl($boleto->transactionId);
echo "📄 Baixar boleto: <a href='{$urlPdf}'>Download PDF</a>";

// Código de barras
$barcode = $boleto->metadata['barcode'];
echo "📊 Código: {$barcode}";
```

---

### 🔁 Assinaturas (Recorrência)

#### Criar Assinatura Mensal

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$subscription = $hub->createSubscription(
    SubscriptionRequest::create(
        amount: 49.90,
        interval: 'monthly', // daily, weekly, monthly, yearly
        customerName: 'Maria Silva',
        customerEmail: 'maria@email.com',
        description: 'Plano Premium',
        trialDays: 7 // 7 dias grátis
    )
);

echo "🔁 Assinatura: {$subscription->subscriptionId}\n";
echo "💰 Valor: {$subscription->getFormattedAmount()}/mês\n";
```

#### Gerenciar Assinatura

```php
// Cancelar
$canceled = $hub->cancelSubscription($subscription->subscriptionId);

// Suspender temporariamente
$suspended = $hub->suspendSubscription($subscription->subscriptionId);

// Reativar
$reactivated = $hub->reactivateSubscription($subscription->subscriptionId);

// Atualizar valor
$updated = $hub->updateSubscription($subscription->subscriptionId, [
    'plan' => [
        'amount' => [
            'value' => 5990, // R$ 59,90 em centavos
        ]
    ]
]);
```

---

### 🔗 Links de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$link = $hub->createPaymentLink(
    PaymentLinkRequest::create(
        amount: 100.00,
        description: 'Produto XYZ',
        expiresAt: '2025-12-31' // Opcional
    )
);

echo "🔗 Link: {$link->url}\n";
echo "🆔 ID: {$link->linkId}\n";

// Compartilhar via WhatsApp
$whatsappUrl = "https://wa.me/5511999999999?text=" . urlencode($link->url);

// Consultar link
$linkInfo = $hub->getPaymentLink($link->linkId);

// Expirar link
$expired = $hub->expirePaymentLink($link->linkId);
```

---

### 💸 Reembolsos

#### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$refund = $hub->refund(
    RefundRequest::create(
        transactionId: 'ORDE_123456789',
        reason: 'Cliente solicitou cancelamento'
    )
);

echo "✅ Reembolso: {$refund->refundId}\n";
echo "💰 Valor: {$refund->getFormattedAmount()}\n";
```

#### Reembolso Parcial

```php
$partialRefund = $hub->partialRefund(
    transactionId: 'ORDE_123456789',
    amount: 50.00 // Reembolsar apenas R$ 50,00
);
```

---

### 👤 Gestão de Clientes

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

// Criar cliente
$customer = $hub->createCustomer(
    CustomerRequest::create(
        name: 'João Silva',
        email: 'joao@email.com',
        documentNumber: '123.456.789-00',
        phone: '11999999999',
        address: [
            'street' => 'Rua Exemplo',
            'number' => '123',
            'complement' => 'Apto 45',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zipcode' => '01310-100'
        ]
    )
);

echo "👤 Cliente ID: {$customer->customerId}\n";

// Atualizar cliente
$updated = $hub->updateCustomer($customer->customerId, [
    'name' => 'João Silva Santos',
    'email' => 'joao.santos@email.com'
]);

// Consultar cliente
$info = $hub->getCustomer($customer->customerId);

// Listar clientes
$customers = $hub->listCustomers([
    'limit' => 50,
    'offset' => 0
]);
```

---

### 📊 Consultar Transação

```php
$status = $hub->getTransactionStatus('ORDE_123456789');

echo "Status: {$status->getStatusLabel()}\n";
echo "Valor: {$status->getFormattedAmount()}\n";

// Listar todas as transações
$transactions = $hub->listTransactions([
    'created_at[gte]' => '2025-01-01',
    'created_at[lte]' => '2025-12-31',
    'limit' => 100
]);
```

---

### 💰 Consultar Saldo

```php
$balance = $hub->getBalance();

echo "💰 Disponível: R$ " . number_format($balance->available, 2, ',', '.') . "\n";
echo "⏳ A receber: R$ " . number_format($balance->pending, 2, ',', '.') . "\n";
```

---

## 🎣 Webhooks

### Configurar Webhook

```php
// Registrar URL de webhook
$webhook = $hub->registerWebhook(
    url: 'https://seusite.com.br/webhook/pagseguro',
    events: [
        'CHARGE.PAID',
        'CHARGE.DECLINED',
        'CHARGE.REFUNDED',
        'SUBSCRIPTION.CANCELED'
    ]
);

// Listar webhooks
$webhooks = $hub->listWebhooks();

// Deletar webhook
$hub->deleteWebhook($webhook['id']);
```

### Processar Notificação

```php
// webhook.php
use IsraelNogueira\PaymentHub\Webhooks\WebhookHandler;

$handler = new WebhookHandler();

$handler->on('CHARGE.PAID', function($payload) {
    // Pagamento aprovado
    $orderId = $payload['charges'][0]['reference_id'];
    // Liberar produto, ativar serviço, etc.
});

$handler->on('CHARGE.DECLINED', function($payload) {
    // Pagamento recusado
    $orderId = $payload['charges'][0]['reference_id'];
    // Notificar cliente
});

$handler->on('CHARGE.REFUNDED', function($payload) {
    // Reembolso processado
    // Atualizar status no banco
});

// Processar
$handler->handle(file_get_contents('php://input'));
```

### Eventos Disponíveis

| Evento | Descrição |
|--------|-----------|
| `CHARGE.PAID` | Pagamento aprovado |
| `CHARGE.DECLINED` | Pagamento recusado |
| `CHARGE.REFUNDED` | Pagamento reembolsado |
| `CHARGE.IN_ANALYSIS` | Em análise (antifraude) |
| `SUBSCRIPTION.CREATED` | Assinatura criada |
| `SUBSCRIPTION.CANCELED` | Assinatura cancelada |
| `SUBSCRIPTION.SUSPENDED` | Assinatura suspensa |

---

## ⚠️ Limitações

### Funcionalidades Não Suportadas

| Funcionalidade | Motivo | Alternativa |
|---------------|--------|-------------|
| **Split de Pagamento** | Não disponível na API padrão | Use PagBank for Business |
| **Sub-contas** | Requer PagBank for Business | Contate vendas PagSeguro |
| **Wallets** | Não disponível | Use saldo da conta |
| **Escrow** | Não disponível | Use pré-autorização |
| **Transferências API** | Gerenciado no dashboard | Use dashboard PagSeguro |
| **Antecipação** | Gerenciado no dashboard | Use dashboard PagSeguro |

### Taxas PagSeguro

- **PIX**: 0,99% por transação
- **Boleto**: R$ 3,49 por boleto
- **Cartão de Débito**: 2,99% por transação
- **Cartão de Crédito**:
  - À vista: 3,99%
  - 2-6x: 4,99%
  - 7-12x: 5,99%

> 💡 Taxas podem variar. Consulte [PagSeguro Taxas](https://pagseguro.uol.com.br/taxas)

---

## 🔧 Troubleshooting

### Erro: "Invalid access token"

```php
// ❌ Errado
$gateway = new PagSeguroGateway('token-errado');

// ✅ Correto
$gateway = new PagSeguroGateway($_ENV['PAGSEGURO_TOKEN']);
```

### Erro: "Customer tax_id invalid"

```php
// ❌ CPF/CNPJ inválido
customerDocument: '000.000.000-00'

// ✅ Use CPF/CNPJ válido
customerDocument: '123.456.789-00'
```

### PIX não gera QR Code

```php
// Aguarde alguns segundos após criar o pagamento
sleep(2);
$qrCode = $hub->getPixQrCode($transactionId);
```

### Erro em Ambiente Sandbox

```php
// Certifique-se de usar token do sandbox
$gateway = new PagSeguroGateway(
    token: $_ENV['PAGSEGURO_SANDBOX_TOKEN'],
    sandbox: true // Importante!
);
```

### Verificar Logs

```php
try {
    $payment = $hub->createPixPayment($request);
} catch (GatewayException $e) {
    // Ver resposta completa da API
    var_dump($e->getContext());
    
    // Código HTTP
    echo "HTTP Code: " . $e->getCode();
    
    // Mensagem
    echo "Error: " . $e->getMessage();
}
```

---

## 📚 Links Úteis

- 📖 [Documentação Oficial PagSeguro](https://dev.pagseguro.uol.com.br/reference/intro)
- 🔑 [Obter Credenciais](https://pagseguro.uol.com.br/)
- 💰 [Taxas e Tarifas](https://pagseguro.uol.com.br/taxas)
- 🎯 [Sandbox (Testes)](https://sandbox.pagseguro.uol.com.br/)
- 📞 [Suporte PagSeguro](https://pagseguro.uol.com.br/atendimento)
- 🐛 [Issues Payment Hub](https://github.com/israel-nogueira/payment-hub/issues)

---

## 🎓 Exemplos Avançados

### Checkout Completo

```php
// checkout.php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\PagSeguro\PagSeguroGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$hub = new PaymentHub(new PagSeguroGateway($_ENV['PAGSEGURO_TOKEN']));

// Dados do carrinho
$total = 299.90;
$items = [
    ['name' => 'Produto A', 'price' => 199.90],
    ['name' => 'Produto B', 'price' => 100.00],
];

// Criar pagamento PIX
$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: $total,
        customerName: $_POST['name'],
        customerEmail: $_POST['email'],
        customerDocument: $_POST['cpf'],
        description: 'Pedido #' . uniqid(),
        expiresInMinutes: 30
    )
);

// Salvar no banco
// saveOrder($payment->transactionId, $total, $items);

// Redirecionar para página de pagamento
header("Location: pagamento.php?id={$payment->transactionId}");
```

### Página de Pagamento

```php
// pagamento.php
$transactionId = $_GET['id'];
$qrCode = $hub->getPixQrCode($transactionId);
$copiaECola = $hub->getPixCopyPaste($transactionId);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pagamento PIX</title>
</head>
<body>
    <h1>Finalize seu Pagamento</h1>
    
    <div>
        <h2>Escaneie o QR Code</h2>
        <img src="<?= $qrCode ?>" alt="QR Code PIX">
    </div>
    
    <div>
        <h2>Ou copie o código</h2>
        <input type="text" value="<?= $copiaECola ?>" readonly>
        <button onclick="navigator.clipboard.writeText('<?= $copiaECola ?>')">
            Copiar
        </button>
    </div>
    
    <script>
        // Verificar status a cada 5 segundos
        setInterval(() => {
            fetch(`verificar-pagamento.php?id=<?= $transactionId ?>`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'PAID') {
                        window.location = 'sucesso.php';
                    }
                });
        }, 5000);
    </script>
</body>
</html>
```

---

## 💬 Suporte

Problemas com o gateway? Abra uma issue:
- 🐛 [GitHub Issues](https://github.com/israel-nogueira/payment-hub/issues)
- 📧 Email: contato@israelnogueira.com

---

<div align="center">

**Desenvolvido com ❤️ para a comunidade PHP brasileira** 🇧🇷

⭐ Se este gateway te ajudou, deixe uma estrela no [Payment Hub](https://github.com/israel-nogueira/payment-hub)!

</div>
