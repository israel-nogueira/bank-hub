# 🏦 Itaú Gateway

Gateway de integração com a **API do Itaú Unibanco** via [Itaú Developer Platform](https://devportal.itau.com.br).

> Parte do [PaymentHub](../../readme.md) — o orquestrador universal de pagamentos em PHP.

---

## 📋 Índice

- [Funcionalidades](#-funcionalidades)
- [Pré-requisitos e Credenciais](#-pré-requisitos-e-credenciais)
- [Configuração](#-configuração)
- [PIX](#-pix)
- [Boleto Bancário](#-boleto-bancário)
- [Transferências (PIX / TED)](#-transferências-pix--ted)
- [Saldo e Extrato](#-saldo-e-extrato)
- [Webhooks](#-webhooks-pix)
- [Gestão de Clientes](#-gestão-de-clientes)
- [Estrutura de Arquivos](#-estrutura-de-arquivos)
- [Métodos Suportados](#-métodos-suportados)
- [Tratamento de Erros](#-tratamento-de-erros)
- [Ambientes](#-ambientes)
- [Testes](#-testes)

---

## ✅ Funcionalidades

| Funcionalidade | Status | Endpoint Itaú |
|---|---|---|
| PIX — Cobrança imediata (QR Code Dinâmico BACEN v2) | ✅ | `PUT /pix/v2/cob/{txid}` |
| PIX — QR Code (base64) | ✅ | `GET /pix/v2/cob/{txid}` |
| PIX — Copia e Cola (EMV) | ✅ | `GET /pix/v2/cob/{txid}` |
| PIX — Estorno total | ✅ | `PUT /pix/v2/pix/{e2eId}/devolucao/{id}` |
| PIX — Estorno parcial | ✅ | `PUT /pix/v2/pix/{e2eId}/devolucao/{id}` |
| PIX — Status da cobrança | ✅ | `GET /pix/v2/cob/{txid}` |
| PIX — Listagem de cobranças | ✅ | `GET /pix/v2/cob` |
| Boleto — Registro | ✅ | `POST /itau-ep9-gtw-cobranca-v2/v2/boletos` |
| Boleto — URL de impressão | ✅ | `GET /itau-ep9-gtw-cobranca-v2/v2/boletos/{id}` |
| Boleto — Cancelamento (baixa) | ✅ | `POST /itau-ep9-gtw-cobranca-v2/v2/boletos/{id}/baixa` |
| Transferência via PIX | ✅ | `POST /pix/v2/pix` |
| Transferência via TED | ✅ | `POST /conta-corrente/v1/transferencias/ted` |
| Transferência Agendada | ✅ | `POST /conta-corrente/v1/transferencias/agendadas` |
| Cancelamento de Agendamento | ✅ | `DELETE /conta-corrente/v1/transferencias/agendadas/{id}` |
| Saldo da conta corrente | ✅ | `GET /conta-corrente/v1/saldo` |
| Extrato paginado | ✅ | `GET /conta-corrente/v1/extrato` |
| Webhooks PIX (registrar/listar/remover) | ✅ | `PUT/GET/DELETE /pix/v2/webhook/{chave}` |
| Gestão de Clientes (CRUD) | ✅ | `POST/PATCH/GET /conta-corrente/v1/clientes` |
| Cartão de Crédito/Débito | ❌ | Use Adyen, Stripe ou PagarMe |
| Assinaturas | ❌ | Use Asaas ou PagarMe |
| Split de Pagamento | ❌ | Use Asaas ou PagarMe |
| Escrow/Custódia | ❌ | Use C6Bank |
| Links de Pagamento | ❌ | Use Asaas, PagarMe ou C6Bank |
| Sub-contas | ❌ | Use C6Bank ou Asaas |

---

## 🔑 Pré-requisitos e Credenciais

### 1. Cadastro no Itaú Developer Portal

Acesse: **https://devportal.itau.com.br** e crie sua conta.

### 2. Criar Aplicação e Habilitar APIs

No portal, crie uma nova aplicação e habilite as APIs necessárias:
- **API PIX** — cobranças PIX, estornos, transferências, webhooks
- **API Cobrança v2** — boletos bancários
- **API Conta Corrente v1** — saldo, extrato, clientes, transferências TED e agendamentos

### 3. Credenciais Obtidas

```
client_id     → Gerado automaticamente na aplicação do portal
client_secret → Gerado automaticamente na aplicação do portal
```

### 4. Dados Adicionais

```
pixKey   → Chave PIX da conta (obrigatória para cobranças PIX e webhooks)
convenio → Código de convênio de cobrança (obrigatório para boletos — solicitar ao gerente Itaú)
```

### 5. Para Produção: Certificado mTLS

O Itaú exige autenticação **mTLS** em produção com certificado **ICP-Brasil** (`.pfx`).
Registre o certificado em: **https://devportal.itau.com.br** → Aplicação → Certificados.

> ⚠️ Sem o certificado registrado, a API de produção retorna `HTTP 401`.

---

## ⚙️ Configuração

### Sandbox (Desenvolvimento)

```php
use IsraelNogueira\PaymentHub\Gateways\Itau\ItauGateway;
use IsraelNogueira\PaymentHub\PaymentHub;

$gateway = new ItauGateway(
    clientId:     'seu_client_id',
    clientSecret: 'seu_client_secret',
    sandbox:      true,
    pixKey:       'empresa@itau.com.br',
    convenio:     '12345',
);

$hub = new PaymentHub($gateway);
```

### Produção (mTLS obrigatório)

```php
$gateway = new ItauGateway(
    clientId:     'prod_client_id',
    clientSecret: 'prod_client_secret',
    sandbox:      false,
    pixKey:       'empresa@itau.com.br',
    convenio:     '12345',
    certPath:     '/certs/itau-prod.pfx',
    certPassword: 'senha_do_certificado',
);

$hub = new PaymentHub($gateway);
```

### Via variáveis de ambiente (recomendado)

```php
$gateway = new ItauGateway(
    clientId:     $_ENV['ITAU_CLIENT_ID'],
    clientSecret: $_ENV['ITAU_CLIENT_SECRET'],
    sandbox:      $_ENV['APP_ENV'] !== 'production',
    pixKey:       $_ENV['ITAU_PIX_KEY'],
    convenio:     $_ENV['ITAU_CONVENIO']      ?? null,
    certPath:     $_ENV['ITAU_CERT_PATH']     ?? null,
    certPassword: $_ENV['ITAU_CERT_PASSWORD'] ?? null,
);
```

---

## 🔐 Autenticação

OAuth 2.0 **Client Credentials** com renovação automática de token (cache interno com margem de 60s). Em produção, o mTLS é aplicado automaticamente quando `certPath` estiver configurado.

| Ambiente | Base URL |
|---|---|
| Sandbox | `https://sandbox.devportal.itau.com.br` |
| Produção | `https://api.itau.com.br` |

---

## 💰 PIX

### Criar Cobrança

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$pix = $hub->createPixPayment(new PixPaymentRequest(
    amount:           150.75,
    currency:         'BRL',
    customerName:     'Maria Silva Santos',
    customerDocument: '12345678909',
    customerEmail:    'maria@example.com',
    description:      'Pedido #12345',
    metadata: [
        'expiracao'      => 3600,  // segundos (padrão: 3600)
        'infoAdicionais' => [
            ['nome' => 'Pedido', 'valor' => '12345'],
        ],
    ],
));

echo $pix->transactionId;            // txid (26-35 chars alfanuméricos, BACEN)
echo $pix->metadata['txid'];         // mesmo txid
echo $pix->metadata['pixCopyPaste']; // código EMV (Copia e Cola)
echo $pix->metadata['qrCodeBase64']; // QR Code em base64
```

> ⚠️ `pixKey` é obrigatória no construtor. Sem ela, `createPixPayment()` lança `GatewayException`.

### Obter QR Code e Copia e Cola separadamente

```php
$qrCodeBase64 = $hub->getPixQrCode($pix->transactionId);
$copyPaste    = $hub->getPixCopyPaste($pix->transactionId);
```

### Consultar Status

```php
$status = $hub->getTransactionStatus($pix->transactionId);
// Tenta PIX primeiro; faz fallback para boleto automaticamente em caso de HTTP 404
echo $status->status->value; // pending | paid | cancelled
echo $status->money->amount();
```

### Listar Cobranças PIX

```php
$cobranças = $hub->listTransactions([
    'inicio' => '2025-01-01T00:00:00Z',
    'fim'    => '2025-01-31T23:59:59Z',
    'cpf'    => '12345678909',  // opcional
    'cnpj'   => '12345678000199', // opcional
]);
```

### Estorno PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

// Estorno total
$refund = $hub->refund(new RefundRequest(
    transactionId: 'E00038166...',
    amount:        150.75,
    reason:        'Pedido cancelado',
    metadata:      ['e2eId' => 'E00038166...'],
));

// Estorno parcial
$refund = $hub->partialRefund(new RefundRequest(
    transactionId: 'E00038166...',
    amount:        50.00,
    reason:        'Devolução parcial',
    metadata:      ['e2eId' => 'E00038166...'],
));

echo $refund->refundId; // devolucaoId gerado deterministicamente
```

> O `metadata['e2eId']` é o `endToEndId` do PIX recebido. O `devolucaoId` é gerado via `sha256(e2eId + valor)`, garantindo idempotência em retries.

---

## 📄 Boleto Bancário

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$boleto = $hub->createBoleto(new BoletoPaymentRequest(
    amount:           350.00,
    currency:         'BRL',
    customerName:     'João Pedro Oliveira',
    customerDocument: '98765432100',
    customerEmail:    'joao@example.com',
    dueDate:          new DateTime('+5 days'),
    description:      'Fatura de serviços — Ref. Jan/2025',
    metadata: [
        'carteira'  => '109',              // carteira de cobrança Itaú
        'especie'   => 'DUPLICATA_MERCANTIL',
        'endereco'  => 'Av. Paulista, 1000',
        'bairro'    => 'Bela Vista',
        'cidade'    => 'São Paulo',
        'uf'        => 'SP',
        'cep'       => '01310100',
        'seuNumero' => 'NF-2025-001',      // referência interna (seuNumero)
    ],
));

echo $boleto->transactionId;                  // nosso número
echo $boleto->metadata['linhaDigitavel'];     // linha digitável
echo $boleto->metadata['codigoBarras'];       // código de barras

// URL do PDF para impressão
$url = $hub->getBoletoUrl($boleto->transactionId);

// Cancelar (baixa com motivo SOLICITACAO_DO_CLIENTE)
$hub->cancelBoleto($boleto->transactionId);
```

> ⚠️ `convenio` no construtor é **obrigatório** para boletos. Sem ele, `createBoleto()` lança `GatewayException`.

---

## 🔄 Transferências (PIX / TED)

O `transfer()` detecta o método pela presença de `metadata['pixKey']`:
- **Com `pixKey`** → PIX via `POST /pix/v2/pix`
- **Sem `pixKey`** e com campo `method => 'ted'` → TED via `POST /conta-corrente/v1/transferencias/ted`
- **Sem `pixKey`** e sem `method` → PIX com dados bancários do favorecido

### Transferência PIX por chave

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

$transfer = $hub->transfer(new TransferRequest(
    amount:        200.00,
    recipientName: 'Carlos Eduardo Mendes',
    description:   'Pagamento fornecedor',
    metadata: [
        'pixKey' => 'carlos@email.com',
    ],
));

echo $transfer->transferId; // endToEndId retornado pelo Itaú
```

### Transferência PIX por dados bancários (sem chave)

```php
$transfer = $hub->transfer(new TransferRequest(
    amount:        500.00,
    recipientName: 'Ana Paula Costa',
    description:   'Reembolso',
    metadata: [
        'recipientDocument' => '11122233344',
        'bankCode'          => '341',
        'agency'            => '1234',
        'account'           => '56789-0',
        'accountType'       => 'corrente',
    ],
));
```

### Transferência TED

```php
$ted = $hub->transfer(new TransferRequest(
    amount:        1500.00,
    recipientName: 'Empresa XYZ Ltda',
    description:   'Pagamento NF-2025-0042',
    metadata: [
        'method'            => 'ted',
        'recipientDocument' => '12345678000199',
        'bankCode'          => '237',
        'agency'            => '0001',
        'account'           => '123456-7',
        'accountType'       => 'corrente',
    ],
));

echo $ted->transferId; // ID retornado pelo Itaú (prefixo TED_ITAU_ em sandbox)
```

### Agendar e Cancelar Transferência

```php
$scheduled = $hub->scheduleTransfer(
    new TransferRequest(
        amount:        800.00,
        recipientName: 'Parceiro ABC',
        description:   'Pagamento agendado',
        metadata:      ['pixKey' => 'parceiro@email.com'],
    ),
    '2025-02-28'  // YYYY-MM-DD
);

echo $scheduled->transferId; // id_agendamento retornado pelo Itaú

// Cancelar
$hub->cancelScheduledTransfer($scheduled->transferId);
```

---

## 💼 Saldo e Extrato

```php
// Saldo — GET /conta-corrente/v1/saldo
$balance = $hub->getBalance();
echo $balance->availableBalance; // campo saldo_disponivel
echo $balance->pendingBalance;   // campo saldo_bloqueado

// Extrato — GET /conta-corrente/v1/extrato
$lancamentos = $hub->getSettlementSchedule([
    'dataInicio' => '2025-01-01',
    'dataFim'    => '2025-01-31',
    'pagina'     => 1,
]);
```

---

## 🔔 Webhooks PIX

O Itaú vincula o webhook à **chave PIX** configurada no construtor (não por URL arbitrária). A URL deve ser **HTTPS** e acessível publicamente.

```php
// Registrar — PUT /pix/v2/webhook/{chave}
$webhook = $hub->registerWebhook(
    url:    'https://seusite.com/webhooks/itau',
    events: ['pix.recebido', 'pix.devolucao']
);
echo $webhook['webhookId'];
echo $webhook['url'];
echo $webhook['chave']; // chave PIX vinculada ao webhook

// Listar — GET /pix/v2/webhook/{chave}
$webhooks = $hub->listWebhooks();

// Remover — DELETE /pix/v2/webhook/{chave}
// O $webhookId é aceito por compatibilidade com a interface,
// mas o Itaú identifica o webhook pela chave PIX (não por ID).
$hub->deleteWebhook($webhook['webhookId']);
```

> `pixKey` é obrigatória em todas as operações de webhook. Sem ela, é lançada `GatewayException`.

---

## 👥 Gestão de Clientes

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

// Criar — POST /conta-corrente/v1/clientes
$customer = $hub->createCustomer(new CustomerRequest(
    name:  'Fernanda Lima Souza',
    taxId: '44455566677',
    email: 'fernanda@example.com',
    phone: '11987654321',
));
echo $customer->customerId; // id retornado pelo Itaú

// Atualizar — PATCH /conta-corrente/v1/clientes/{id}
$hub->updateCustomer($customer->customerId, [
    'email'    => 'fernanda.novo@example.com',
    'telefone' => '11987654321',
]);

// Buscar — GET /conta-corrente/v1/clientes/{id}
$found = $hub->getCustomer($customer->customerId);

// Listar — GET /conta-corrente/v1/clientes
$list = $hub->listCustomers(['pagina' => 1]);
```

---

## 📁 Estrutura de Arquivos

```
src/Gateways/Itau/
├── ItauGateway.php          ← Implementação principal
├── ItauGatewayTest.php      ← Testes de integração
├── itau-examples.php        ← Exemplos práticos de uso
└── ItauGateway.md           ← Esta documentação
```

---

## 📋 Métodos Suportados

### ✅ Suportados

| Método | Descrição |
|---|---|
| `createPixPayment()` | Cobrança PIX imediata (QR Code Dinâmico BACEN v2) |
| `getPixQrCode()` | Imagem base64 do QR Code |
| `getPixCopyPaste()` | Código EMV Pix Copia e Cola |
| `refund()` | Devolução total de PIX |
| `partialRefund()` | Devolução parcial de PIX |
| `createBoleto()` | Registro de boleto bancário |
| `getBoletoUrl()` | URL PDF do boleto para impressão |
| `cancelBoleto()` | Baixa/cancelamento de boleto |
| `transfer()` | Transferência via PIX ou TED (roteamento por `metadata['pixKey']`) |
| `scheduleTransfer()` | Agendamento de transferência |
| `cancelScheduledTransfer()` | Cancelamento de agendamento |
| `getBalance()` | Saldo disponível e bloqueado |
| `getSettlementSchedule()` | Extrato paginado |
| `getTransactionStatus()` | Status de PIX ou boleto |
| `listTransactions()` | Lista de cobranças PIX com filtros |
| `registerWebhook()` | Registrar URL de notificação PIX |
| `listWebhooks()` | Listar webhooks da chave PIX |
| `deleteWebhook()` | Remover webhook da chave PIX |
| `createCustomer()` | Criar cliente |
| `updateCustomer()` | Atualizar dados do cliente |
| `getCustomer()` | Buscar cliente por ID |
| `listCustomers()` | Listar clientes |

### ❌ Não Suportados

| Método | Alternativa Sugerida |
|---|---|
| `createCreditCardPayment()` | Adyen, Stripe, PagarMe |
| `createDebitCardPayment()` | PagarMe, C6Bank |
| `createSubscription()` | Asaas, PagarMe, C6Bank |
| `createSplitPayment()` | Asaas, PagarMe |
| `holdInEscrow()` | C6Bank |
| `createWallet()` | C6Bank |
| `createPaymentLink()` | Asaas, PagarMe, C6Bank |
| `createSubAccount()` | C6Bank, Asaas |
| `analyzeTransaction()` | — (Itaú não expõe antifraude via API pública) |
| `anticipateReceivables()` | — (contate seu gerente Itaú) |

---

## 🔧 Tratamento de Erros

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $pix = $hub->createPixPayment($request);

} catch (GatewayException $e) {
    echo "Mensagem:      " . $e->getMessage() . "\n";
    echo "HTTP:          " . $e->getCode() . "\n";

    // Inclui resposta Itaú e correlationID para rastreabilidade
    $ctx = $e->getContext();
    echo "Resposta:      " . json_encode($ctx['response']      ?? []) . "\n";
    echo "CorrelationID: " . ($ctx['correlationId'] ?? '') . "\n";
}
```

Códigos de erro comuns:

| HTTP | Significado | Ação |
|---|---|---|
| 400 | Payload inválido | Verifique campos obrigatórios |
| 401 | Token expirado ou mTLS ausente | Verifique credenciais e certificado |
| 403 | Permissão negada | Habilite a API no portal Itaú |
| 404 | Recurso não encontrado | Verifique o txid/nossoNumero |
| 422 | Regra de negócio violada | Leia o campo `mensagem` no retorno |
| 429 | Rate limit | Retry automático com backoff exponencial (máx. 3x) |
| 503 | Serviço indisponível | Retry automático com backoff exponencial (máx. 3x) |

---

## 🌐 Ambientes

| Ambiente | Base URL | mTLS |
|---|---|---|
| **Sandbox** | `https://sandbox.devportal.itau.com.br` | Opcional |
| **Produção** | `https://api.itau.com.br` | **Obrigatório** |

---

## 🧪 Testes

```bash
ITAU_CLIENT_ID=xxx \
ITAU_CLIENT_SECRET=yyy \
ITAU_PIX_KEY=empresa@itau.com.br \
ITAU_CONVENIO=12345 \
./vendor/bin/phpunit --filter ItauGatewayTest
```

Sem variáveis de ambiente, os testes são **pulados automaticamente** via `markTestSkipped`.

Para um método específico:

```bash
./vendor/bin/phpunit --filter ItauGatewayTest::testCreatePixPaymentReturnsValidResponse
```

---

## 🔗 Links Úteis

- [Itaú Developer Portal](https://devportal.itau.com.br)
- [Exemplos completos de uso](itau-examples.php)
- [Testes de integração](ItauGatewayTest.php)
- [Voltar ao README principal](../../readme.md)