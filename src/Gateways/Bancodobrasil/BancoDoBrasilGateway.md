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
| Webhook Handler (BancoDoBrasilWebhookHandler) | ✅ | — |
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

> Em produção (desde junho/2024), o BB exige um certificado digital registrado no portal. Sem ele a API retorna HTTP 503 `bad_certificate`.

---

## ⚙️ Configuração

```php
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway;

$gateway = new BancoDoBrasilGateway(
    clientId:         $_ENV['BB_CLIENT_ID'],
    clientSecret:     $_ENV['BB_CLIENT_SECRET'],
    developerAppKey:  'gw-dev-app-key',      // sandbox: gw-dev-app-key | produção: gw-app-key
    pixKey:           $_ENV['BB_PIX_KEY'],
    convenio:         (int) $_ENV['BB_CONVENIO'],
    carteira:         17,
    variacaoCarteira: 35,
    agencia:          $_ENV['BB_AGENCIA'],   // 4 dígitos, sem dígito verificador
    conta:            $_ENV['BB_CONTA'],     // sem dígito verificador
    sandbox:          true,
    // certPath:      '/etc/ssl/bb/cert.pem', // obrigatório em produção
);
```

---

## 💠 PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = PixPaymentRequest::create(
    amount:           150.00,
    description:      'Pedido #1234',
    customerName:     'Maria Silva',
    customerDocument: '123.456.789-00',
    customerEmail:    'maria@email.com',
    metadata: ['expiresIn' => 3600],
);

$pix = $hub->createPixPayment($request);

echo $pix->transactionId;               // txid
echo $pix->metadata['pixCopiaECola'];   // string para colar no app bancário
echo $pix->metadata['location'];        // URL do QR Code dinâmico
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
        'nossoNumero'  => '0000000001',  // sequencial único — obrigatório em produção
        'address'      => 'Rua das Flores, 123',
        'neighborhood' => 'Centro',
        'city'         => 'São Paulo',
        'cityCode'     => 3550308,
        'state'        => 'SP',
        'zipCode'      => '01310-100',
    ],
);

$boleto = $hub->createBoleto($request);

// createBoleto() retorna PaymentResponse — dados do boleto ficam em metadata[]
echo $boleto->transactionId;                    // Número do título (campo 'numero' da API BB)
echo $boleto->metadata['linhaDigitavel'];       // Linha digitável
echo $boleto->metadata['boletoUrl'];            // URL para impressão/PDF
echo $boleto->metadata['nossoNumero'];          // Nosso número registrado
echo $boleto->metadata['codigoBarras'] ?? '';   // Código de barras (quando disponível)

// Cancelar boleto — retorna PaymentResponse
$cancelado = $gateway->cancelBoleto($boleto->transactionId);
echo $cancelado->success ? 'Baixado' : $cancelado->message;
```

> **⚠️ Atenção com a baixa:** A baixa é irreversível. Boleto já pago deve ser tratado diretamente com o gerente BB.

---

## 🔀 Boleto Híbrido (Boleto + PIX)

```php
$request = BoletoPaymentRequest::create(
    amount:      500.00,
    description: 'Fatura #789',
    // ... demais campos obrigatórios ...
    metadata: [
        // ... endereço ...
        'hibrido' => true, // ← ativa o Boleto + PIX no mesmo título
    ],
);

$boleto = $hub->createBoleto($request);

// Todos os dados ficam em metadata[]
echo $boleto->metadata['linhaDigitavel'];
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
        'pixKey' => 'carlos@email.com',
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
    bankCode:            '237',
    agency:              '1234',
    account:             '56789',
    accountDigit:        '0',
    accountType:         'checking',
);

$transfer = $gateway->transfer($request);
```

### Agendamento e Cancelamento

```php
$agendado = $gateway->scheduleTransfer($request, '2025-03-31');
$gateway->cancelScheduledTransfer($agendado->transferId);
```

---

## 📊 Saldo e Extrato

```php
// Saldo atual da conta
$saldo = $hub->getBalance();

echo $saldo->availableBalance;                          // Saldo disponível (saldoDisponivel)
echo $saldo->balance;                                   // Saldo contábil (saldoContabil)
echo $saldo->metadata['bloqueado_judicial'];            // Valor bloqueado judicialmente
echo $saldo->metadata['bloqueado_administrativo'];      // Valor bloqueado administrativamente

// Extrato — página 1, 50 registros (padrão)
$extrato = $gateway->getStatement(
    new DateTime('-30 days'),
    new DateTime(),
);

// O retorno é um array estruturado:
// $extrato['lancamentos']         → array de lançamentos
// $extrato['quantidadeRegistros'] → total de registros no período
// $extrato['pagina']              → página atual
// $extrato['totalPaginas']        → total de páginas
// $extrato['indicePrimeiro']      → offset usado na chamada à API

foreach ($extrato['lancamentos'] as $l) {
    $sinal = ($l['creditoDebito'] ?? '') === 'C' ? '+' : '-';
    echo "[{$l['data']}] {$sinal} R$ {$l['valor']} — {$l['descricao']}\n";
}

// Paginação explícita
$pagina2 = $gateway->getStatement(
    new DateTime('-30 days'),
    new DateTime(),
    page:    2,
    perPage: 25,
);
echo "Página 2: " . count($pagina2['lancamentos']) . " registros\n";
echo "Total:    " . $pagina2['quantidadeRegistros'] . " lançamentos\n";
echo "Páginas:  " . $pagina2['totalPaginas'] . "\n";
```

---

## 🔔 Webhooks

O BB envia notificações para a sua URL quando eventos ocorrem (PIX pago, boleto liquidado, etc.).

```php
// Registrar webhook
$gateway->registerWebhook(
    'https://seusite.com/webhooks/bb',
    ['type' => 'pix'] // 'pix' (padrão) ou 'boleto'
);

// Listar webhooks da chave PIX configurada
$webhooks = $gateway->listWebhooks();

// Remover webhook — usa internamente a pixKey configurada no construtor
// (o argumento $webhookId é exigido pela interface mas ignorado pelo BB)
$gateway->deleteWebhook('qualquer-valor');
```

> **Nota:** O BB não tem um ID de webhook como outros gateways. O registro e a remoção são feitos direto na chave PIX configurada (`$pixKey`). Para boleto, use `['type' => 'boleto']` no `registerWebhook()`.

### Processando com BancoDoBrasilWebhookHandler

```php
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilWebhookHandler;

$handler = new BancoDoBrasilWebhookHandler(
    webhookToken: 'seu-token-secreto',
);

$handler->onPixRecebido(function (array $pix) {
    // $pix['txid'], $pix['valor'], $pix['pagador'], etc.
    // confirmar pedido no sistema
});

$handler->onBoletoLiquidado(function (array $boleto) {
    // $boleto['nossoNumero'], $boleto['valor'], etc.
});

$handler->handle(); // valida token, processa payload, responde 200
```

### Exemplo de payload — PIX pago

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

---

## ↩️ Estorno PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = new RefundRequest(
    transactionId: 'E00038166202501141052152649956',
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
src/Gateways/Bancodobrasil/
├── BancoDoBrasilGateway.php          ← Implementação principal
├── BancoDoBrasilWebhookHandler.php   ← Handler de webhooks PIX e Boleto
├── bb-examples.php                   ← Exemplos práticos de uso
└── BancoDoBrasilGateway.md           ← Esta documentação
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
| `cancelBoleto()` | Baixa (cancelamento) — retorna `PaymentResponse` |
| `refund()` | Estorno/devolução de PIX |
| `transfer()` | Transferência automática (PIX ou TED) |
| `scheduleTransfer()` | Agendamento de transferência |
| `cancelScheduledTransfer()` | Cancelamento de agendamento |
| `getBalance()` | Saldo da conta corrente |
| `getStatement()` | Extrato paginado da conta corrente |
| `getTransactionStatus()` | Status de cobrança PIX ou boleto |
| `listTransactions()` | Lista de cobranças PIX |
| `registerWebhook()` | Registrar URL de notificação |
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

### Header da App Key

| Ambiente | Header |
|---|---|
| Sandbox | `gw-dev-app-key` |
| Produção | `gw-app-key` |

### Links úteis

- **Portal Developers:** https://app.developers.bb.com.br
- **Documentação oficial:** https://developers.bb.com.br
- **Suporte:** Fórum no próprio portal Developers BB
- **Política de migração:** API PIX v1 encerra em 31/03/2026 — use sempre a v2