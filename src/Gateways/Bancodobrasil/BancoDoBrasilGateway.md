# 🏦 Banco do Brasil Gateway

Gateway de integração com as APIs do **Banco do Brasil** para o PaymentHub.

> Parte do [PaymentHub](../../readme.md) — o orquestrador universal de pagamentos em PHP.

---

## 📋 Índice

- [Funcionalidades](#-funcionalidades)
- [Pré-requisitos e Credenciais](#-pré-requisitos-e-credenciais)
- [Configuração](#-configuração)
- [PIX](#-pix)
- [Boleto Bancário](#-boleto-bancário)
- [Boleto Híbrido](#-boleto-híbrido-boleto--pix)
- [Transferências (PIX / TED)](#-transferências-pix--ted)
- [Saldo e Extrato](#-saldo-e-extrato)
- [Webhooks](#-webhooks)
- [Estorno PIX](#-estorno-pix)
- [Estrutura de Arquivos](#-estrutura-de-arquivos)
- [Métodos Suportados](#-métodos-suportados)
- [Tratamento de Erros](#-tratamento-de-erros)
- [Ambientes](#-ambientes)

---

## ✅ Funcionalidades

| Funcionalidade | Status | API BB Utilizada |
|---|---|---|
| PIX — Cobrança imediata (QR Code Dinâmico) | ✅ | API PIX v2 |
| PIX — QR Code e Copia & Cola | ✅ | API PIX v2 |
| PIX — Estorno (devolução) | ✅ | API PIX v2 |
| PIX — Consulta de status | ✅ | API PIX v2 |
| PIX — Listagem de cobranças | ✅ | API PIX v2 |
| Boleto Bancário — Registro | ✅ | API Cobrança v2 |
| Boleto Híbrido — Boleto + PIX | ✅ | API Cobrança v2 |
| Boleto — Baixa (cancelamento) | ✅ | API Cobrança v2 |
| Boleto — Consulta de URL | ✅ | API Cobrança v2 |
| Transferência via PIX | ✅ | API Pagamentos v1 |
| Transferência via TED | ✅ | API Pagamentos v1 |
| Transferência Agendada | ✅ | API Pagamentos v1 |
| Cancelamento de Agendamento | ✅ | API Pagamentos v1 |
| Consulta de Saldo | ✅ | API Conta Corrente v1 |
| Consulta de Extrato | ✅ | API Conta Corrente v1 |
| Webhooks (PIX + Boleto) | ✅ | APIs PIX e Cobrança |
| Cartão de Crédito/Débito | ❌ | Não disponível via API BB |
| Assinaturas | ❌ | Use Asaas ou PagarMe |
| Split de Pagamento | ❌ | Não disponível via API BB |
| Escrow/Custódia | ❌ | Use C6Bank ou PagarMe |

---

## 🔑 Pré-requisitos e Credenciais

### 1. Cadastro no Portal Developers BB

Acesse: **https://app.developers.bb.com.br** e crie sua conta com CPF.

### 2. Criar Aplicação

No portal, crie uma nova aplicação e habilite as APIs necessárias:
- **API PIX** — para cobranças PIX e transferências
- **API Cobrança** — para boletos
- **API Conta Corrente** — para saldo e extrato
- **API Pagamentos** — para TED

### 3. Credenciais obtidas

```
clientId        → Gerado automaticamente no portal
clientSecret    → Gerado automaticamente no portal
developerAppKey → Sua chave de desenvolvedor
                  Sandbox: gw-dev-app-key
                  Produção: gw-app-key
```

### 4. Dados do Convênio (para Boletos)

Obtidos com seu gerente de relacionamento BB:
```
numeroConvenio       → Número do convênio de cobrança (ex: 3128557)
numeroCarteira       → Carteira (ex: 17)
variacaoCarteira     → Variação da carteira (ex: 35)
```

### 5. Certificado Digital (apenas Produção)

> Em produção (desde junho/2024), o BB exige um certificado digital registrado no portal. Cadastre seu certificado `.pfx` no menu de credenciais do portal antes de subir para produção.

---

## ⚙️ Configuração

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway;

$gateway = new BancoDoBrasilGateway(
    clientId:         'seu-client-id',
    clientSecret:     'seu-client-secret',
    developerAppKey:  'sua-developer-app-key',
    pixKey:           'sua-chave-pix@empresa.com',  // e-mail, CPF, CNPJ, telefone ou aleatória
    convenio:         1234567,                       // Para boletos
    carteira:         17,
    variacaoCarteira: 35,
    agencia:          '0001',
    conta:            '123456',
    sandbox:          true,   // false em produção
);

$hub = new PaymentHub($gateway);
```

---

## 💰 PIX

### Criar Cobrança

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = PixPaymentRequest::create(
    amount:           150.00,
    description:      'Pedido #1234',
    customerName:     'Maria Silva',
    customerDocument: '123.456.789-00',
    metadata: [
        'expiresIn' => 3600, // segundos (padrão: 86400 = 24h)
    ],
);

$pix = $hub->createPixPayment($request);

echo $pix->transactionId;              // txid único
echo $pix->metadata['pixCopiaECola']; // código para copiar e colar
echo $pix->metadata['qrCode'];         // imagem do QR Code
echo $pix->metadata['location'];       // URL do location PIX
```

### Obter QR Code e Copia & Cola

```php
$qrCode    = $hub->getPixQrCode($pix->transactionId);
$copyPaste = $hub->getPixCopyPaste($pix->transactionId);
```

### Consultar Status

```php
$status = $hub->getTransactionStatus($pix->transactionId);
// status: PENDING | APPROVED | CANCELLED
```

### Listar Cobranças

```php
$cobranças = $gateway->listTransactions([
    'inicio' => (new DateTime('-7 days'))->format(DateTime::RFC3339),
    'fim'    => (new DateTime())->format(DateTime::RFC3339),
    'status' => 'ATIVA', // ATIVA | CONCLUIDA | REMOVIDA_PELO_USUARIO_RECEBEDOR
]);
```

---

## 🧾 Boleto Bancário

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = BoletoPaymentRequest::create(
    amount:           299.90,
    description:      'Mensalidade Janeiro/2025',
    customerName:     'João Pereira',
    customerDocument: '987.654.321-00',
    dueDate:          date('Y-m-d', strtotime('+5 days')),
    metadata: [
        'address'      => 'Rua das Flores, 123',
        'neighborhood' => 'Centro',
        'city'         => 'São Paulo',
        'cityCode'     => 3550308,  // Código IBGE da cidade
        'state'        => 'SP',
        'zipCode'      => '01310-100',
        'fine'         => 2.0,      // 2% ao mês (juros mora)
        'discount'     => 10.00,    // R$ 10,00 de desconto até o vencimento
    ],
);

$boleto = $hub->createBoleto($request);

echo $boleto->boletoId;       // Número do título
echo $boleto->linhaDigitavel; // Linha digitável
echo $boleto->barCode;        // Código de barras
echo $boleto->boletoUrl;      // URL para impressão

// Cancelar boleto
$gateway->cancelBoleto($boleto->boletoId);
```

> **⚠️ Atenção com a baixa:** A baixa (cancelamento) é irreversível. Se o boleto já foi pago, entre em contato com o gerente para resolver.

---

## 🔀 Boleto Híbrido (Boleto + PIX)

O **Boleto Híbrido** é um título que permite pagamento tanto via **código de barras** quanto via **QR Code PIX** — tudo no mesmo documento.

```php
$request = BoletoPaymentRequest::create(
    amount:      500.00,
    description: 'Fatura #789',
    // ... demais campos obrigatórios ...
    metadata: [
        // ... endereço ...
        'hibrido' => true, // ← ativa o Boleto + PIX
    ],
);

$boleto = $hub->createBoleto($request);

// Dados do Boleto
echo $boleto->linhaDigitavel;

// Dados do PIX embutido
echo $boleto->metadata['pixCopiaECola'];
echo $boleto->metadata['qrCodePix'];
```

---

## 🏧 Transferências (PIX / TED)

O roteamento é **automático**: se `metadata['pixKey']` for informado, usa PIX; caso contrário, usa TED.

### Transferência via PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

$request = new TransferRequest(
    amount:              200.00,
    beneficiaryName:     'Carlos Mendes',
    beneficiaryDocument: '111.222.333-44',
    description:         'Pagamento de fornecedor',
    metadata: [
        'pixKey' => 'carlos@email.com', // CPF, CNPJ, e-mail, telefone ou chave aleatória
    ],
);

$transfer = $gateway->transfer($request);
```

### Transferência via TED

```php
$request = new TransferRequest(
    amount:              1500.00,
    beneficiaryName:     'Empresa XYZ Ltda',
    beneficiaryDocument: '12.345.678/0001-99',
    description:         'Pagamento NF 001234',
    bankCode:            '237',  // Bradesco
    agency:              '1234',
    account:             '56789',
    accountDigit:        '0',
    accountType:         'checking', // ou 'savings' para poupança
);

$transfer = $gateway->transfer($request);
```

### Agendamento e Cancelamento

```php
// Agendar
$agendado = $gateway->scheduleTransfer($request, '2025-03-31');

// Cancelar o agendamento
$gateway->cancelScheduledTransfer($agendado->transferId);
```

---

## 📊 Saldo e Extrato

```php
// Saldo atual da conta
$saldo = $hub->getBalance();

echo $saldo->availableBalance; // Saldo disponível
echo $saldo->totalBalance;     // Saldo total (incluindo bloqueios)
echo $saldo->metadata['bloqueado_judicial'];       // Valor bloqueado judicialmente
echo $saldo->metadata['bloqueado_administrativo']; // Valor bloqueado administrativamente

// Extrato de um período
$lancamentos = $gateway->getStatement(
    new DateTime('-30 days'),
    new DateTime()
);

foreach ($lancamentos as $l) {
    echo "[{$l['data']}] R$ {$l['valor']} — {$l['descricao']}\n";
}
```

---

## 🔔 Webhooks

O BB envia notificações para a sua URL quando eventos ocorrem (PIX pago, boleto liquidado, etc.).

```php
// Registrar webhook
$webhook = $gateway->registerWebhook(
    'https://seusite.com/webhooks/bb',
    ['pix', 'boleto'] // ou vazio para registrar ambos
);

// Listar webhooks
$webhooks = $gateway->listWebhooks();

// Remover webhook
$gateway->deleteWebhook($webhook->webhookId);
```

### Exemplo de payload recebido — PIX pago

```json
{
  "pix": [
    {
      "endToEndId": "E00038166202501141052152649956",
      "txid": "abc123def456",
      "valor": "150.00",
      "horario": "2025-01-14T10:52:15.649Z",
      "infoPagador": "Pagamento ref. pedido 1234"
    }
  ]
}
```

### Processando o webhook

```php
// No seu endpoint (ex: /webhooks/bb)
$payload = json_decode(file_get_contents('php://input'), true);

foreach ($payload['pix'] ?? [] as $pix) {
    $txid  = $pix['txid'];
    $valor = $pix['valor'];
    // ... confirmar pedido no seu sistema
}
```

---

## ↩️ Estorno PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = new RefundRequest(
    transactionId: 'E00038166202501141052152649956', // E2EId da transação original
    amount:        50.00,
    reason:        'Produto devolvido',
    metadata: [
        'e2eId' => 'E00038166202501141052152649956',
    ],
);

$estorno = $hub->refund($request);
echo $estorno->status; // EM_PROCESSAMENTO | DEVOLVIDO
```

---

## 📁 Estrutura de Arquivos

```
src/Gateways/BancoDoBrasil/
├── BancoDoBrasilGateway.php    ← Implementação principal
├── bb-examples.php             ← Exemplos práticos de uso
└── readme.md                   ← Esta documentação
```

---

## 📋 Métodos Suportados

### ✅ Suportados

| Método | Descrição |
|---|---|
| `createPixPayment()` | Cobrança PIX imediata (QR Code Dinâmico) |
| `getPixQrCode()` | Imagem/URL do QR Code |
| `getPixCopyPaste()` | Código PIX Copia e Cola |
| `createBoleto()` | Registro de boleto (simples ou híbrido) |
| `getBoletoUrl()` | URL do boleto para impressão |
| `cancelBoleto()` | Baixa (cancelamento) do boleto |
| `refund()` | Estorno/devolução de PIX |
| `transfer()` | Transferência automática (PIX ou TED) |
| `scheduleTransfer()` | Agendamento de transferência |
| `cancelScheduledTransfer()` | Cancelamento de agendamento |
| `getBalance()` | Saldo da conta corrente |
| `getStatement()` | Extrato da conta corrente |
| `getTransactionStatus()` | Status de cobrança PIX ou boleto |
| `listTransactions()` | Lista de cobranças PIX |
| `registerWebhook()` | Registrar notificação |
| `listWebhooks()` | Listar webhooks |
| `deleteWebhook()` | Remover webhook |

### ❌ Não suportados (com alternativa sugerida)

| Método | Alternativa |
|---|---|
| `createCreditCardPayment()` | Asaas, PagarMe, Adyen, Stripe |
| `createDebitCardPayment()` | Asaas, PagarMe |
| `createSubscription()` | Asaas, PagarMe, C6Bank |
| `holdInEscrow()` | C6Bank, PagarMe |
| `splitPayment()` | Asaas, PagarMe, C6Bank |
| `createPaymentLink()` | Asaas, PagarMe, C6Bank |
| `createSubAccount()` | C6Bank, Asaas |

---

## 🔧 Tratamento de Erros

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $pix = $hub->createPixPayment($request);

} catch (GatewayException $e) {
    echo "Erro: "    . $e->getMessage() . "\n";
    echo "HTTP: "    . $e->getCode() . "\n";

    // Contexto detalhado da resposta do BB
    $ctx = $e->getContext();
    echo "Resposta: " . json_encode($ctx['response'] ?? []) . "\n";
}
```

### Erros comuns do BB

| Código HTTP | Mensagem | Solução |
|---|---|---|
| 401 | Unauthorized | Verifique clientId e clientSecret |
| 403 | Forbidden | Verifique o developerAppKey |
| 422 | Chave não encontrada | Cadastre a chave PIX na conta BB |
| 422 | Convênio inválido | Verifique o número do convênio |
| 503 | bad_certificate | Cadastre o certificado no portal (produção) |

---

## 🌐 Ambientes

| Ambiente | API Base URL | OAuth URL |
|---|---|---|
| Sandbox | `https://api.sandbox.bb.com.br` | `https://oauth.sandbox.bb.com.br` |
| Produção | `https://api.bb.com.br` | `https://oauth.bb.com.br` |

### Header de autenticação

| Ambiente | Header da App Key |
|---|---|
| Sandbox | `gw-dev-app-key` |
| Produção | `gw-app-key` |

### Links úteis

- **Portal Developers:** https://app.developers.bb.com.br
- **Documentação oficial:** https://developers.bb.com.br
- **Suporte:** Fórum no próprio portal Developers BB
- **Política de migração:** API PIX v1 encerra em 31/03/2026 — use sempre a v2
