# 🟢 Adyen Gateway

Gateway de integração com **Adyen** para o Payment Hub.

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

O **AdyenGateway** integra sua aplicação com a plataforma global de pagamentos Adyen, permitindo processar:

- 💳 **Cartão de Crédito** - Global, à vista ou parcelado (Brasil)
- 💵 **Cartão de Débito** - Pagamentos diretos
- 💰 **PIX** - Pagamentos instantâneos no Brasil
- 📄 **Boleto Bancário** - Boleto brasileiro
- 🔗 **Links de Pagamento** - URLs compartilháveis
- 🌍 **Métodos Locais** - 250+ métodos de pagamento globais

> ⚡ **Adyen** é usado por empresas como Uber, Spotify, Microsoft, eBay e Netflix.

---

## 📦 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

### 1. Obter Credenciais

Acesse o [Adyen Customer Area](https://ca-test.adyen.com/) e:

1. Vá em **Developers** → **API Credentials**
2. Crie ou selecione uma credencial
3. Copie o **API Key**
4. Anote seu **Merchant Account**

### 2. Inicializar Gateway

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Adyen\AdyenGateway;

// Ambiente de Produção
$hub = new PaymentHub(new AdyenGateway(
    apiKey: 'AQE...', // Sua API Key
    merchantAccount: 'MerchantAccountName',
    sandbox: false
));

// Ambiente Sandbox (Testes)
$hub = new PaymentHub(new AdyenGateway(
    apiKey: 'AQE...', // API Key de teste
    merchantAccount: 'MerchantAccountTest',
    sandbox: true
));
```

### 3. Configuração Recomendada

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $hub = new PaymentHub(new AdyenGateway(
        apiKey: $_ENV['ADYEN_API_KEY'],
        merchantAccount: $_ENV['ADYEN_MERCHANT_ACCOUNT'],
        sandbox: $_ENV['APP_ENV'] !== 'production'
    ));
} catch (GatewayException $e) {
    error_log($e->getMessage());
}
```

---

## ✨ Funcionalidades

| Funcionalidade | Status | Notas |
|---------------|--------|-------|
| **PIX** | ✅ | QR Code via resposta |
| **Cartão de Crédito** | ✅ | Global, parcelado no Brasil |
| **Cartão de Débito** | ✅ | Pagamento direto |
| **Boleto** | ✅ | Boleto Bancário |
| **Links de Pagamento** | ✅ | URLs compartilháveis |
| **Pré-autorização** | ✅ | Captura manual |
| **Reembolsos** | ✅ | Total e parcial |
| **Webhooks** | ✅ | Via Customer Area |
| **Métodos Globais** | ✅ | 250+ métodos |
| **Assinaturas** | ⚠️ | Via Recurring API |
| **Split** | ⚠️ | Adyen for Platforms |
| **Sub-contas** | ⚠️ | Adyen for Platforms |
| **Wallets** | ⚠️ | Adyen for Platforms |

---

## 💡 Exemplos de Uso

### 💰 PIX

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

// QR Code vem no metadata
$qrCodeData = $payment->metadata['qr_code_data'];

echo "💰 Valor: {$payment->getFormattedAmount()}\n";
echo "📊 Status: {$payment->getStatusLabel()}\n";
echo "🔑 ID: {$payment->transactionId}\n";
echo "📱 QR Code: {$qrCodeData}\n";
```

---

### 💳 Cartão de Crédito

> ⚠️ **Importante**: Adyen usa **client-side encryption**. Os dados do cartão devem ser criptografados no frontend usando Adyen Web SDK.

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

// Dados criptografados vêm do frontend
$encryptedCard = $_POST['encryptedCardNumber'];
$encryptedExpiry = $_POST['encryptedExpiryMonth'];
$encryptedYear = $_POST['encryptedExpiryYear'];
$encryptedCvv = $_POST['encryptedSecurityCode'];

$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 299.90,
        cardNumber: $encryptedCard, // Dados criptografados
        cardHolderName: 'MARIA SILVA',
        cardExpiryMonth: $encryptedExpiry,
        cardExpiryYear: $encryptedYear,
        cardCvv: $encryptedCvv,
        customerName: 'Maria Silva',
        customerEmail: 'maria@email.com',
        customerDocument: '987.654.321-00'
    )
);

echo "✅ Status: {$payment->getStatusLabel()}\n";
```

#### Parcelamento (Brasil)

```php
$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 1200.00,
        cardNumber: $encryptedCard,
        cardHolderName: 'JOSE SANTOS',
        cardExpiryMonth: $encryptedExpiry,
        cardExpiryYear: $encryptedYear,
        cardCvv: $encryptedCvv,
        installments: 12, // 12 parcelas
        customerEmail: 'jose@email.com'
    )
);
```

#### Pré-autorização

```php
// 1. Criar pré-autorização (não capturar)
$preAuth = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 500.00,
        cardNumber: $encryptedCard,
        cardHolderName: 'CLIENTE',
        cardExpiryMonth: $encryptedExpiry,
        cardExpiryYear: $encryptedYear,
        cardCvv: $encryptedCvv,
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
        dueDate: '2025-03-15',
        description: 'Mensalidade Março/2025'
    )
);

// Dados do boleto vêm no metadata
$barcode = $boleto->metadata['barcode'];
$pdfUrl = $boleto->metadata['pdf_url'];

echo "📄 Código de barras: {$barcode}\n";
echo "🔗 PDF: {$pdfUrl}\n";
```

---

### 🔗 Links de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$link = $hub->createPaymentLink(
    PaymentLinkRequest::create(
        amount: 100.00,
        description: 'Produto XYZ',
        expiresAt: '2025-12-31T23:59:59' // ISO 8601
    )
);

echo "🔗 Link: {$link->url}\n";
echo "🆔 ID: {$link->linkId}\n";

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
        transactionId: 'ADYEN_PSP_REFERENCE',
        reason: 'Cliente solicitou cancelamento'
    )
);

echo "✅ Reembolso: {$refund->refundId}\n";
```

#### Reembolso Parcial

```php
$partialRefund = $hub->partialRefund(
    transactionId: 'ADYEN_PSP_REFERENCE',
    amount: 50.00
);
```

---

## 🎣 Webhooks

### Configurar Webhooks

Webhooks são configurados via **Adyen Customer Area**:

1. Acesse **Developers** → **Webhooks**
2. Clique em **+ Webhook**
3. Configure:
   - **URL**: `https://seusite.com.br/webhook/adyen`
   - **Events**: Selecione os eventos desejados
   - **HMAC Key**: Gere uma chave para validação

### Processar Notificações

```php
// webhook.php
use IsraelNogueira\PaymentHub\Webhooks\WebhookHandler;

$handler = new WebhookHandler();

$handler->on('AUTHORISATION', function($payload) {
    // Pagamento autorizado
    $pspReference = $payload['pspReference'];
    $success = $payload['success'] === 'true';
    
    if ($success) {
        // Aprovar pedido
    }
});

$handler->on('REFUND', function($payload) {
    // Reembolso processado
    $originalReference = $payload['originalReference'];
    // Atualizar status
});

$handler->on('CHARGEBACK', function($payload) {
    // Chargeback recebido
    // Notificar equipe
});

// Processar
$json = file_get_contents('php://input');
$handler->handle($json);
```

### Validar HMAC

```php
function validateAdyenHmac($payload, $hmacSignature, $hmacKey): bool
{
    $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $merchantSignature = base64_encode(hash_hmac('sha256', $data, pack('H*', $hmacKey), true));
    
    return hash_equals($merchantSignature, $hmacSignature);
}

// Uso
$payload = json_decode(file_get_contents('php://input'), true);
$receivedHmac = $_SERVER['HTTP_HMAC_SIGNATURE'];
$hmacKey = $_ENV['ADYEN_HMAC_KEY'];

if (!validateAdyenHmac($payload, $receivedHmac, $hmacKey)) {
    http_response_code(401);
    exit('Invalid HMAC');
}
```

### Eventos Principais

| Evento | Descrição |
|--------|-----------|
| `AUTHORISATION` | Pagamento autorizado ou recusado |
| `CAPTURE` | Captura confirmada |
| `REFUND` | Reembolso processado |
| `CANCEL_OR_REFUND` | Cancelamento ou reembolso |
| `CHARGEBACK` | Chargeback iniciado |
| `NOTIFICATION_OF_CHARGEBACK` | Notificação de chargeback |

---

## 🌍 Métodos de Pagamento Globais

Adyen suporta **250+ métodos de pagamento** em 150+ países:

### Europa
- iDEAL (Holanda)
- Sofort (Alemanha)
- Bancontact (Bélgica)
- Giropay (Alemanha)

### Ásia
- Alipay (China)
- WeChat Pay (China)
- GrabPay (Sudeste Asiático)
- PayNow (Singapura)

### América Latina
- OXXO (México)
- Boleto (Brasil)
- PIX (Brasil)
- Efecty (Colômbia)

---

## ⚠️ Limitações

### Funcionalidades Não Suportadas na API Padrão

| Funcionalidade | Motivo | Alternativa |
|---------------|--------|-------------|
| **Assinaturas** | Recurring API separada | Use Adyen Recurring |
| **Consulta de Status** | Via webhooks | Armazene status dos webhooks |
| **Split de Pagamento** | Requer Adyen for Platforms | Contate vendas Adyen |
| **Sub-contas** | Requer Adyen for Platforms | Contate vendas Adyen |
| **Wallets** | Requer Adyen for Platforms | Contate vendas Adyen |
| **Listagem de Transações** | Via Reports API | Use Reports ou Customer Area |

### Client-Side Encryption Obrigatória

Adyen **exige** que dados de cartão sejam criptografados no frontend:

```html
<!-- Incluir Adyen Web SDK -->
<script src="https://checkoutshopper-live.adyen.com/checkoutshopper/sdk/5.0.0/adyen.js"></script>

<script>
const checkout = await AdyenCheckout({
    clientKey: 'test_...',
    environment: 'test'
});

const card = checkout.create('card', {
    onChange: (state, component) => {
        if (state.isValid) {
            // Enviar state.data.paymentMethod para o backend
            fetch('/checkout', {
                method: 'POST',
                body: JSON.stringify(state.data)
            });
        }
    }
});

card.mount('#card-container');
</script>
```

---

## 🔧 Troubleshooting

### Erro: "Invalid Merchant Account"

```php
// ❌ Errado
$gateway = new AdyenGateway('key', 'WrongAccount');

// ✅ Correto - Use o exato nome da sua merchant account
$gateway = new AdyenGateway('key', 'YourCompanyCOM');
```

### Erro: "Unauthorized"

```php
// Certifique-se de usar a API key correta
$gateway = new AdyenGateway(
    apiKey: $_ENV['ADYEN_API_KEY'], // Confira no Customer Area
    merchantAccount: $_ENV['ADYEN_MERCHANT'],
    sandbox: true
);
```

### PIX não retorna QR Code

```php
// QR Code vem no action da resposta
$payment = $hub->createPixPayment($request);
$qrCode = $payment->rawResponse['action']['qrCodeData'] ?? null;

if (!$qrCode) {
    // Verificar se PIX está habilitado na merchant account
}
```

### Webhooks não chegam

1. Verificar URL acessível publicamente
2. Verificar HTTPS válido
3. Testar via **Adyen Customer Area** → **Webhooks** → **Test**
4. Verificar logs no Customer Area

### Validar Ambiente Sandbox

```php
// Certifique-se de usar credenciais de teste
$hub = new PaymentHub(new AdyenGateway(
    apiKey: 'test_...',  // Começa com test_
    merchantAccount: 'TestMerchantAccount',
    sandbox: true // Importante!
));
```

---

## 📊 Taxas Adyen

Adyen usa modelo de **Interchange++** (mais transparente):

- **Interchange**: Taxa da bandeira do cartão
- **Scheme Fee**: Taxa da rede (Visa/Mastercard)
- **Processing Fee**: Taxa Adyen

**Exemplo Brasil**:
- Cartão Nacional: ~2-3%
- Cartão Internacional: ~3-4%
- PIX: 0,99% + R$ 0,10
- Boleto: R$ 2,50 fixo

> 💡 Taxas variam por volume. Contate Adyen para pricing customizado.

---

## 📚 Links Úteis

- 📖 [Documentação Oficial Adyen](https://docs.adyen.com/)
- 🔑 [Customer Area](https://ca-live.adyen.com/)
- 🧪 [Customer Area Teste](https://ca-test.adyen.com/)
- 💻 [Web SDK](https://docs.adyen.com/online-payments/web-sdk)
- 🎯 [API Explorer](https://docs.adyen.com/api-explorer/)
- 🔔 [Webhooks Guide](https://docs.adyen.com/development-resources/webhooks)
- 🐛 [Issues Payment Hub](https://github.com/israel-nogueira/payment-hub/issues)

---

## 🎓 Exemplo Completo de Checkout

```php
// checkout.php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\Adyen\AdyenGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$hub = new PaymentHub(new AdyenGateway(
    apiKey: $_ENV['ADYEN_API_KEY'],
    merchantAccount: $_ENV['ADYEN_MERCHANT'],
    sandbox: true
));

// Dados criptografados vêm do frontend
$paymentData = json_decode($_POST['payment_data'], true);

try {
    $payment = $hub->createCreditCardPayment(
        CreditCardPaymentRequest::create(
            amount: 299.90,
            cardNumber: $paymentData['encryptedCardNumber'],
            cardHolderName: $paymentData['holderName'],
            cardExpiryMonth: $paymentData['encryptedExpiryMonth'],
            cardExpiryYear: $paymentData['encryptedExpiryYear'],
            cardCvv: $paymentData['encryptedSecurityCode'],
            customerEmail: $_POST['email'],
            customerDocument: $_POST['cpf']
        )
    );

    if ($payment->status->isPaid()) {
        // Pagamento aprovado
        header('Location: /sucesso?ref=' . $payment->transactionId);
    } else {
        // Pagamento pendente ou recusado
        header('Location: /falha?reason=' . $payment->status->value);
    }
} catch (GatewayException $e) {
    // Erro no processamento
    error_log($e->getMessage());
    header('Location: /erro');
}
```

### Frontend (HTML + JavaScript)

```html
<!DOCTYPE html>
<html>
<head>
    <title>Checkout</title>
    <script src="https://checkoutshopper-test.adyen.com/checkoutshopper/sdk/5.0.0/adyen.js"></script>
    <link rel="stylesheet" href="https://checkoutshopper-test.adyen.com/checkoutshopper/sdk/5.0.0/adyen.css">
</head>
<body>
    <div id="card-container"></div>
    <button id="pay-button">Pagar R$ 299,90</button>

    <script>
    (async () => {
        const checkout = await AdyenCheckout({
            clientKey: 'test_YOUR_CLIENT_KEY',
            environment: 'test',
            locale: 'pt-BR'
        });

        const card = checkout.create('card');
        card.mount('#card-container');

        document.getElementById('pay-button').addEventListener('click', async () => {
            const state = card.state;
            
            if (state.isValid) {
                const response = await fetch('/checkout.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        payment_data: JSON.stringify(state.data.paymentMethod),
                        email: document.getElementById('email').value,
                        cpf: document.getElementById('cpf').value
                    })
                });

                if (response.ok) {
                    window.location = await response.text();
                }
            }
        });
    })();
    </script>
</body>
</html>
```

---

## 💬 Suporte

Problemas com o gateway? Abra uma issue:
- 🐛 [GitHub Issues](https://github.com/israel-nogueira/payment-hub/issues)
- 📧 Email: contato@israelnogueira.com

Para suporte Adyen:
- 📞 [Suporte Adyen](https://www.adyen.com/contact)
- 💬 Customer Area Chat (disponível 24/7)

---

<div align="center">

**Desenvolvido com ❤️ para a comunidade PHP brasileira** 🇧🇷

⭐ Se este gateway te ajudou, deixe uma estrela no [Payment Hub](https://github.com/israel-nogueira/payment-hub)!

</div>
