# 🏦 Bank of America — CashPro Gateway

Integração com a plataforma **CashPro** do Bank of America para transferências bancárias corporativas nos EUA via **Zelle**, **ACH** e **Wire Transfer**.

> Parte do [PaymentHub](../../readme.md) — o orquestrador universal de pagamentos em PHP.

---

## 📋 Índice

- [Visão Geral](#visão-geral)
- [Instalação e Configuração](#instalação)
- [Transferências](#transferências)
  - [Roteamento Automático](#roteamento-automático)
  - [Zelle](#zelle)
  - [ACH](#ach)
  - [Wire Transfer](#wire-transfer)
  - [Transferência Agendada](#agendamento)
  - [Cancelamento](#cancelamento)
- [Consultas](#consultas)
  - [Status de Transação](#status)
  - [Listar Transações](#listar)
  - [Saldo](#saldo)
- [Webhooks](#webhooks)
  - [Configuração](#configuração-webhook)
  - [PAYMENT_RECEIVED](#payment_received)
  - [PAYMENT_SENT](#payment_sent)
  - [PAYMENT_FAILED](#payment_failed)
  - [PAYMENT_RETURNED](#payment_returned)
  - [BALANCE_BELOW_THRESHOLD](#balance_below_threshold)
  - [Endpoint Completo](#endpoint-completo)
- [Fluxo Completo — Fintech](#fluxo-fintech)
- [Métodos Suportados](#métodos-suportados)
- [Onboarding BofA — Guia Prático](#onboarding)
- [Regulações — O Caminho Mais Curto](#regulações)
- [Limitações](#limitações)
- [Estrutura de Arquivos](#estrutura)

---

## Visão Geral <a name="visão-geral"></a>

O `BofACashProGateway` conecta o PaymentHub diretamente à conta corporativa BofA via CashPro API, permitindo enviar e receber dinheiro nos EUA de forma programática.

### Três métodos de transferência em um

```
Qualquer transferência → transfer($request)
                              ↓
              ┌───────────────────────────┐
              │   Roteamento automático   │
              │   por valor (USD)         │
              └───────────────────────────┘
                    ↙       ↓        ↘
              Zelle       ACH        Wire
           ≤ $3.500    ≤ $50.000   > $50.000
         Instantâneo   Same-day    Mesmo dia
          Custo zero   ~$0,30      ~$25-35
```

### Por que três métodos?

| Critério | Zelle | ACH | Wire |
|---|---|---|---|
| Velocidade | ⚡ Segundos | 🕐 Same-day / 3d | 🕐 Mesmo dia útil |
| Limite | Negociado BofA | Sem limite prático | Sem limite prático |
| Reversível | ❌ Não | ✅ Antes da liquidação | ❌ Não |
| Custo | Grátis | Mínimo | ~$25-$35/tx |
| Dados necessários | Email ou telefone | Routing + Account | Routing + Account + Bank |
| Disponibilidade | 24/7 | Dias úteis | Dias úteis |

---

## Instalação e Configuração <a name="instalação"></a>

### Pré-requisitos

- Conta corporativa **CashPro Online** ativa no Bank of America
- Client ID e Client Secret obtidos via [developer.bankofamerica.com](https://developer.bankofamerica.com)
- IPs da sua aplicação cadastrados no portal do BofA (whitelist obrigatória)
- **Licença Money Transmitter** para o modelo de redirecionar fundos de terceiros

> **Onboarding:** Envie solicitação para GlobalAPIOps@bofa.com. O Client Secret chega via Secure Message em ~15 dias.

### Configuração básica

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProGateway;

// Produção
$gateway = new BofACashProGateway(
    clientId:     $_ENV['BOFA_CLIENT_ID'],
    clientSecret: $_ENV['BOFA_CLIENT_SECRET'],
    accountId:    $_ENV['BOFA_ACCOUNT_ID'],   // ID da conta corporativa BofA
    sandbox:      false,
);

$hub = new PaymentHub($gateway);
```

### Sandbox (desenvolvimento)

```php
// Sandbox — sem transações reais
$gateway = new BofACashProGateway(
    clientId:     'seu-client-id-sandbox',
    clientSecret: 'seu-client-secret-sandbox',
    accountId:    '000000000',
    sandbox:      true,
);
```

### Limites de roteamento customizados

```php
// Customizar os limiares de roteamento automático
$gateway = new BofACashProGateway(
    clientId:        $_ENV['BOFA_CLIENT_ID'],
    clientSecret:    $_ENV['BOFA_CLIENT_SECRET'],
    accountId:       $_ENV['BOFA_ACCOUNT_ID'],
    sandbox:         false,
    zelleThreshold:  5000.00,   // Zelle para até $5.000 (se negociado com o BofA)
    achThreshold:    100000.00, // ACH para até $100.000
    // Acima de $100.000 → Wire automático
);
```

### .env recomendado

```env
BOFA_CLIENT_ID=seu-client-id
BOFA_CLIENT_SECRET=seu-client-secret
BOFA_ACCOUNT_ID=123456789
BOFA_WEBHOOK_SECRET=sua-chave-hmac-configurada-no-portal
BOFA_SANDBOX=false
```

---

## Transferências <a name="transferências"></a>

### Roteamento Automático <a name="roteamento-automático"></a>

Use `transfer()` na maioria dos casos. O gateway escolhe o método correto automaticamente com base no valor.

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

// $500 → vai via Zelle (≤ $3.500)
$request = TransferRequest::create(
    amount:      500.00,
    recipientName: 'John Doe',
    description: 'Saque #12345',
    metadata: [
        'recipientEmail' => 'john@example.com',
        'memo'           => 'REF-USER-7890', // Identificador do cliente na plataforma
    ]
);

$response = $hub->transfer($request);

echo $response->transferId;  // PAY-abc123
echo $response->getStatus(); // processing
echo $response->rawResponse['_method']; // zelle
```

```php
// $15.000 → vai via ACH (> $3.500 e ≤ $50.000)
$request = TransferRequest::create(
    amount:        15000.00,
    recipientName: 'Jane Smith',
    description:   'Saque #67890',
    metadata: [
        'routingNumber' => '021000021',
        'accountNumber' => '123456789',
        'accountType'   => 'checking',
        'memo'          => 'REF-USER-4567',
    ]
);

$response = $hub->transfer($request);
echo $response->rawResponse['_method']; // ach
echo $response->rawResponse['_sameDay']; // true (Same-Day ACH por padrão)
```

```php
// $75.000 → vai via Wire (> $50.000)
$request = TransferRequest::create(
    amount:        75000.00,
    recipientName: 'Acme Corp',
    description:   'Distribuição #99',
    metadata: [
        'routingNumber' => '021000021',
        'accountNumber' => '987654321',
        'bankName'      => 'JPMorgan Chase Bank',
        'memo'          => 'Distribution REF-99',
    ]
);

$response = $hub->transfer($request);
echo $response->rawResponse['_method']; // wire
```

---

### Zelle <a name="zelle"></a>

Transferência instantânea para qualquer pessoa nos EUA com Zelle ativo.

```php
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProGateway;

// Enviar pelo email
$response = $gateway->sendZelle(
    TransferRequest::create(
        amount:        250.00,
        recipientName: 'Alice Johnson',
        description:   'Withdrawal',
        metadata: [
            'recipientEmail' => 'alice@example.com',
            'memo'           => 'REF-USER-1001', // Seu identificador interno
        ]
    )
);

// Enviar pelo telefone (formato E.164)
$response = $gateway->sendZelle(
    TransferRequest::create(
        amount:        100.00,
        recipientName: 'Bob Williams',
        description:   'Withdrawal',
        metadata: [
            'recipientPhone' => '+15555551234',
            'memo'           => 'REF-USER-1002',
        ]
    )
);

if ($response->success) {
    echo "Zelle enviado! ID: {$response->transferId}";
}
```

> ⚠️ **Zelle é irreversível.** Valide email/telefone antes de chamar. Se o destinatário não tiver Zelle, receberá convite por 14 dias; se não aceitar, o valor retorna automaticamente.

---

### ACH <a name="ach"></a>

Transferência bancária padrão nos EUA via roteamento ABA. Reversível antes da liquidação.

```php
// Same-Day ACH (padrão) — liquidação no mesmo dia útil
$response = $gateway->sendACH(
    TransferRequest::create(
        amount:        8500.00,
        recipientName: 'Carol Davis',
        description:   'Withdrawal',
        metadata: [
            'routingNumber' => '021000021',     // ABA routing number (9 dígitos)
            'accountNumber' => '456789012',
            'accountType'   => 'checking',       // 'checking' ou 'savings'
            'memo'          => 'Withdrawal REF-USER-2001',
            'sameDay'       => true,             // Default: true
        ]
    )
);

// ACH Standard — liquidação em 1-3 dias úteis (mais barato)
$response = $gateway->sendACH(
    TransferRequest::create(
        amount:        8500.00,
        recipientName: 'Carol Davis',
        description:   'Withdrawal',
        metadata: [
            'routingNumber' => '021000021',
            'accountNumber' => '456789012',
            'accountType'   => 'checking',
            'sameDay'       => false,
            'effectiveDate' => '2025-03-05',    // Data de liquidação desejada
            'memo'          => 'Withdrawal REF-USER-2001',
            'companyEntryDesc' => 'FINTECH',    // Aparece no extrato do destinatário (max 10 chars)
        ]
    )
);

echo "ACH Status: {$response->getStatus()}";
```

> 📅 **Cutoff Same-Day ACH do BofA:** 10h30 ET (liquidação 13h) e 14h45 ET (liquidação 17h). Após 14h45 ET, cai para Standard ACH no próximo dia útil.

---

### Wire Transfer <a name="wire-transfer"></a>

Transferência de alto valor via Fedwire. Liquidação garantida no mesmo dia útil.

```php
$response = $gateway->sendWire(
    TransferRequest::create(
        amount:        120000.00,
        recipientName: 'Horizon Capital LLC',
        description:   'Investment distribution',
        metadata: [
            'routingNumber' => '021000021',
            'accountNumber' => '789012345',
            'bankName'      => 'Citibank N.A.',
            'bankAddress'   => '399 Park Ave, New York, NY 10022', // Opcional
            // OBI: mensagem no extrato do destinatário (até 140 chars)
            'memo'          => 'Distribution Q1-2025 REF-HORIZON-001',
        ]
    )
);

echo "Wire ID: {$response->transferId}";
echo "Status: {$response->getStatus()}"; // processing
```

> ⚠️ **Wire é irreversível.** Confirme os dados bancários com o destinatário antes de enviar. Cutoff BofA: ~17h ET em dias úteis.

---

### Transferência Agendada <a name="agendamento"></a>

Agenda um ACH Standard para liquidar em uma data futura.

```php
// Agendar saque para daqui a 3 dias úteis
$response = $hub->scheduleTransfer(
    request: TransferRequest::create(
        amount:        3000.00,
        recipientName: 'David Lee',
        description:   'Scheduled withdrawal',
        metadata: [
            'routingNumber' => '021000021',
            'accountNumber' => '321654987',
            'accountType'   => 'savings',
            'memo'          => 'REF-USER-3001',
        ]
    ),
    date: '2025-03-10', // YYYY-MM-DD
);

echo "Agendado! ID: {$response->transferId}";
echo "Status: {$response->getStatus()}"; // scheduled
```

> ⚠️ Zelle não suporta agendamento (sempre instantâneo). Wire não suporta agendamento via API — use o painel CashPro para isso. `scheduleTransfer()` só funciona com ACH (valores ≤ $50.000).

---

### Cancelamento <a name="cancelamento"></a>

Cancela um ACH agendado antes da liquidação.

```php
$response = $hub->cancelScheduledTransfer('PAY-abc123');

echo $response->getStatus(); // cancelled
```

> ⚠️ Zelle e Wire **não podem ser cancelados** após enviados. Para ACH Same-Day, a janela de cancelamento é muito pequena (minutos).

---

## Consultas <a name="consultas"></a>

### Status de Transação <a name="status"></a>

```php
$status = $hub->getTransactionStatus('PAY-abc123');

echo $status->status;  // completed
echo $status->message; // Payment settled successfully

// Possíveis status: pending, processing, completed, failed, cancelled, returned
```

---

### Listar Transações <a name="listar"></a>

```php
// Todas as transações dos últimos 7 dias
$transactions = $hub->listTransactions([
    'startDate' => '2025-02-20',
    'endDate'   => '2025-02-27',
]);

// Filtrar só Zelles recebidos
$zellesRecebidos = $hub->listTransactions([
    'startDate'   => '2025-02-27',
    'endDate'     => '2025-02-27',
    'paymentType' => 'ZELLE',
    'status'      => 'COMPLETED',
]);

// Com paginação
$page2 = $hub->listTransactions([
    'startDate' => '2025-02-01',
    'endDate'   => '2025-02-27',
    'page'      => 2,
    'pageSize'  => 100,
]);

foreach ($transactions as $tx) {
    echo "{$tx['paymentType']} | \${$tx['amount']} | {$tx['status']} | {$tx['memo']}";
}
```

---

### Saldo <a name="saldo"></a>

```php
$balance = $hub->getBalance();

echo "Disponível: \${$balance->availableBalance}";  // Para uso imediato
echo "Contábil:   \${$balance->balance}";           // Total incluindo pendentes
echo "Pendente:   \${$balance->pendingBalance}";    // Em processamento
echo "Moeda: {$balance->currency}";                 // USD
```

---

## Webhooks <a name="webhooks"></a>

O BofA envia eventos em tempo real para a URL cadastrada quando algo acontece na conta. É o mecanismo central da fintech: quando um Zelle chega, o webhook dispara instantaneamente.

### Configuração do Webhook <a name="configuração-webhook"></a>

#### 1. Registrar a URL no BofA

```php
$result = $hub->registerWebhook(
    url:    'https://sua-fintech.com/webhooks/bofa',
    events: [
        'PAYMENT_RECEIVED',          // ⭐ Mais importante — Zelle/ACH recebido
        'PAYMENT_SENT',              // Transferência enviada confirmada
        'PAYMENT_FAILED',            // Transferência falhou
        'PAYMENT_RETURNED',          // ACH devolvido
        'BALANCE_BELOW_THRESHOLD',   // Alerta de saldo baixo
        'STATEMENT_AVAILABLE',       // Extrato disponível
    ]
);

echo "Webhook ID: {$result['webhookId']}";
echo "Status: {$result['status']}";   // active
```

#### 2. Configurar o Handler

```php
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProWebhookHandler;

$handler = new BofACashProWebhookHandler(
    webhookSecret: $_ENV['BOFA_WEBHOOK_SECRET'], // Chave HMAC do portal BofA
);
```

---

### PAYMENT_RECEIVED <a name="payment_received"></a>

O evento mais importante: dispara quando Zelle, ACH ou Wire é **creditado** na conta corporativa.

```php
$handler->onPaymentReceived(function(array $event) {
    // Dados disponíveis no $event:
    // $event['eventId']      → ID único para deduplicação
    // $event['paymentType']  → ZELLE | ACH_SAME_DAY | ACH_STANDARD | WIRE
    // $event['amount']       → float (USD)
    // $event['senderEmail']  → Email do remetente (Zelle)
    // $event['senderPhone']  → Telefone do remetente (Zelle)
    // $event['senderName']   → Nome do remetente
    // $event['memo']         → Mensagem enviada pelo remetente
    // $event['paymentId']    → ID do pagamento no BofA
    // $event['timestamp']    → ISO 8601

    // Deduplicação: evita processar o mesmo evento duas vezes
    if (DB::exists('processed_webhooks', $event['eventId'])) {
        return; // Já processado, ignora reenvio do BofA
    }

    // Identifica o cliente pelo memo (padrão: "REF-USER-{id}")
    $userId = extractUserIdFromMemo($event['memo']);

    if (!$userId) {
        // Zelle anônimo — registra para revisão manual
        Alert::send("Zelle sem referência: \${$event['amount']} de {$event['senderEmail']}");
        return;
    }

    // Credita o saldo do cliente na plataforma
    Wallet::credit(
        userId:      $userId,
        amount:      $event['amount'],
        currency:    'USD',
        description: "Depósito via {$event['paymentType']}",
        reference:   $event['paymentId'],
    );

    // Notifica o cliente
    Notification::send($userId, "Seu depósito de \${$event['amount']} foi confirmado! 🎉");

    // Marca evento como processado
    DB::insert('processed_webhooks', ['event_id' => $event['eventId']]);
});
```

---

### PAYMENT_SENT <a name="payment_sent"></a>

```php
$handler->onPaymentSent(function(array $event) {
    // Atualiza status do saque no banco de dados
    Withdrawal::updateStatus(
        paymentId: $event['paymentId'],
        status:    'completed',
        settledAt: $event['timestamp'],
    );

    // Notifica o cliente que o saque foi concluído
    $withdrawal = Withdrawal::findByPaymentId($event['paymentId']);
    Notification::send(
        $withdrawal->userId,
        "Seu saque de \${$event['amount']} foi concluído via {$event['paymentType']}!"
    );
});
```

---

### PAYMENT_FAILED <a name="payment_failed"></a>

```php
$handler->onPaymentFailed(function(array $event) {
    // Estorna o saldo do cliente (o dinheiro não saiu da conta BofA)
    Wallet::credit(
        userId:      Withdrawal::findByPaymentId($event['paymentId'])->userId,
        amount:      $event['amount'],
        currency:    'USD',
        description: "Estorno de saque falho — {$event['failureReason']}",
    );

    // Notifica o cliente com o motivo da falha
    $errorMessage = match ($event['failureCode']) {
        'RECIPIENT_NOT_FOUND'  => 'Destinatário Zelle não encontrado.',
        'LIMIT_EXCEEDED'       => 'Limite de transferência excedido.',
        'INVALID_ACCOUNT'      => 'Dados bancários inválidos.',
        default                => 'Falha ao processar o saque. Tente novamente.',
    };

    Notification::send(
        Withdrawal::findByPaymentId($event['paymentId'])->userId,
        "Seu saque falhou: {$errorMessage}"
    );

    // Log para análise
    Log::error('Payment failed', [
        'paymentId'     => $event['paymentId'],
        'amount'        => $event['amount'],
        'failureCode'   => $event['failureCode'],
        'failureReason' => $event['failureReason'],
    ]);
});
```

---

### PAYMENT_RETURNED <a name="payment_returned"></a>

ACH devolvido pelo banco receptor. Exclusivo de ACH.

```php
$handler->onPaymentReturned(function(array $event) {
    // Estorna o saldo do cliente (o ACH foi devolvido)
    Wallet::credit(
        userId:      Withdrawal::findByPaymentId($event['paymentId'])->userId,
        amount:      $event['amount'],
        currency:    'USD',
        description: "Estorno de ACH retornado — Código {$event['returnCode']}",
    );

    // Mensagem amigável por código de retorno ACH
    $message = match ($event['returnCode']) {
        'R01' => 'Saldo insuficiente na conta de destino.',
        'R02' => 'Conta bancária de destino foi encerrada.',
        'R03' => 'Conta bancária de destino não existe.',
        'R04' => 'Número de conta bancária inválido.',
        'R10' => 'Débito não autorizado pelo titular.',
        default => "Devolução bancária ({$event['returnCode']}).",
    };

    Notification::send(
        Withdrawal::findByPaymentId($event['paymentId'])->userId,
        "Seu saque foi devolvido pelo banco: {$message} Verifique os dados bancários e tente novamente."
    );
});
```

---

### BALANCE_BELOW_THRESHOLD <a name="balance_below_threshold"></a>

```php
$handler->onBalanceBelowThreshold(function(array $event) {
    // Alerta a equipe financeira
    Slack::send(
        channel: '#financeiro-alertas',
        message: "⚠️ *Alerta de Saldo BofA*\n" .
                 "Saldo atual: \${$event['currentBalance']}\n" .
                 "Limite configurado: \${$event['threshold']}\n" .
                 "Ação necessária: depositar fundos na conta corporativa."
    );
});
```

---

### Endpoint Completo <a name="endpoint-completo"></a>

Exemplo de arquivo PHP completo para o endpoint de webhook:

```php
<?php
// webhooks/bofa.php

require_once __DIR__ . '/../vendor/autoload.php';

use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProWebhookHandler;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

// Apenas POST é aceito
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$handler = new BofACashProWebhookHandler(
    webhookSecret: $_ENV['BOFA_WEBHOOK_SECRET'],
    validateIp:    true,
    allowedIps:    ['198.51.100.1', '198.51.100.2'], // IPs do BofA — consulte documentação
);

// Registra todos os handlers de eventos
$handler
    ->onPaymentReceived(function(array $event) {
        // Deduplicação
        if (ProcessedWebhook::exists($event['eventId'])) return;

        $userId = extractUserIdFromMemo($event['memo']);
        if ($userId) {
            Wallet::credit($userId, $event['amount'], 'USD', $event['paymentId']);
            Notification::depositConfirmed($userId, $event['amount']);
        }

        ProcessedWebhook::save($event['eventId']);
    })
    ->onPaymentSent(function(array $event) {
        Withdrawal::markCompleted($event['paymentId']);
    })
    ->onPaymentFailed(function(array $event) {
        Withdrawal::markFailed($event['paymentId'], $event['failureReason']);
        Wallet::refund($event['paymentId']);
    })
    ->onPaymentReturned(function(array $event) {
        Withdrawal::markReturned($event['paymentId'], $event['returnCode']);
        Wallet::refund($event['paymentId']);
    })
    ->onBalanceBelowThreshold(function(array $event) {
        Alert::lowBalance($event['currentBalance']);
    })
    ->onUnhandled(function(array $event) {
        // Loga eventos desconhecidos para análise futura
        Log::info('Unhandled BofA webhook', ['eventType' => $event['eventType']]);
    });

// Processa o evento
try {
    $result = $handler->handle();

    // Responde 200 imediatamente ao BofA (evita reenvios)
    $handler->respondOk($result);

    // Processamento assíncrono pode vir aqui (filas, jobs, etc.)

} catch (GatewayException $e) {
    // Assinatura inválida ou payload malformado
    Log::error('BofA webhook error', ['message' => $e->getMessage()]);
    http_response_code(401);
    exit;

} catch (\Throwable $e) {
    // Erro interno — retorna 500 para que o BofA reenvie
    Log::critical('BofA webhook processing error', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit;
}
```

---

## Fluxo Completo — Fintech <a name="fluxo-fintech"></a>

### Depósito (cliente envia Zelle para a fintech)

```
Cliente abre app → informa $500
       ↓
Envia Zelle para zelle@sua-fintech.com
       ↓
BofA recebe instantaneamente
       ↓
BofA dispara POST /webhooks/bofa
       ↓
onPaymentReceived() é chamado
       ↓
Identifica cliente pelo memo "REF-USER-7890"
       ↓
Wallet::credit($userId, 500.00)
       ↓
Notifica cliente: "Depósito de $500 confirmado!"
```

### Saque (cliente solicita retirada)

```
Cliente solicita saque de $15.000
       ↓
Plataforma valida saldo (Wallet::getBalance)
       ↓
Plataforma debita o saldo internamente
       ↓
$hub->transfer($request)  ← $15.000
       ↓
Roteamento automático: ACH ($15k ≤ $50k)
       ↓
BofA processa Same-Day ACH
       ↓
BofA dispara onPaymentSent() na liquidação
       ↓
Withdrawal::markCompleted($paymentId)
       ↓
Notifica cliente: "Saque de $15.000 concluído!"
```

### Identificação do cliente por memo (Zelle)

O Zelle não tem campo estruturado para identificar o pagador. A estratégia recomendada é usar o campo **memo** com um código único por cliente:

```php
// No cadastro do cliente — gerar código único
$user->zelle_ref = 'REF-' . strtoupper(substr(hash('sha256', $user->id . $user->email), 0, 8));
// Exemplo: REF-A3F7B2C1

// Instruções exibidas para o cliente no app:
"Para depositar via Zelle, envie para: deposits@sua-fintech.com
 IMPORTANTE: Inclua este código no campo 'Memo': REF-A3F7B2C1"

// Extração no webhook
function extractUserIdFromMemo(string $memo): ?int {
    if (preg_match('/REF-([A-F0-9]{8})/i', $memo, $matches)) {
        return User::findByZelleRef('REF-' . $matches[1])?->id;
    }
    return null;
}
```

---

## Métodos Suportados <a name="métodos-suportados"></a>

### ✅ Suportados

| Método | Descrição |
|---|---|
| `transfer(TransferRequest)` | Roteamento automático Zelle/ACH/Wire por valor |
| `sendZelle(TransferRequest)` | Envio direto via Zelle |
| `sendACH(TransferRequest)` | Envio direto via ACH Same-Day ou Standard |
| `sendWire(TransferRequest)` | Envio direto via Wire (Fedwire) |
| `scheduleTransfer(TransferRequest, string)` | Agendamento de ACH para data futura |
| `cancelScheduledTransfer(string)` | Cancelamento de ACH agendado |
| `getTransactionStatus(string)` | Consulta status de transação |
| `listTransactions(array)` | Lista transações com filtros |
| `getBalance()` | Consulta saldo disponível e contábil |
| `registerWebhook(string, array)` | Registra URL de webhook |
| `listWebhooks()` | Lista webhooks registrados |
| `deleteWebhook(string)` | Remove webhook |
| `getSettlementSchedule(array)` | Cronograma de liquidações |

### ❌ Não suportados (com motivo)

| Método | Motivo |
|---|---|
| `createPixPayment()` | PIX é exclusivo do Brasil. Use gateways BR (Asaas, C6Bank, etc.) |
| `createCreditCardPayment()` | CashPro não processa cartões |
| `createBoleto()` | Boleto é produto separado para clientes BR |
| `createSubscription()` | Sem mecanismo nativo. Implemente via `scheduleTransfer()` recorrente |
| `refund()` | Zelle e Wire são irreversíveis. ACH: use `cancelScheduledTransfer()` |
| `createSubAccount()` | Sub-contas por usuário final são gerenciadas na camada de aplicação |
| `createWallet()` | Wallets são gerenciadas no PaymentHub, não no BofA |
| `holdInEscrow()` | Escrow não disponível via CashPro API |
| `createPaymentLink()` | Não é produto CashPro |
| `createCustomer()` | Gestão de clientes é da camada de aplicação |
| `analyzeTransaction()` | Antifraude é externo ao CashPro |


---

## Regulações — O Caminho Mais Curto <a name="regulações"></a>

> **Contexto:** O modelo desta fintech (receber de clientes via Zelle e distribuir fundos) se enquadra em **Money Transmission** nos EUA. Operar sem licença é crime federal.

### O que você precisa

Duas camadas de licenciamento, que rodam em paralelo:

```
Federal (FinCEN)          Estadual (cada estado)
─────────────────         ──────────────────────────
Registrar como MSB   +    Money Transmitter License
(Money Services           em cada estado onde você
Business)                 tiver clientes
Prazo: 2-4 semanas        Prazo: 3-12 meses por estado
Custo: gratuito           Custo: $1k–$5k por estado
```

---

### Passo 1 — Registro Federal (FinCEN) — Faça primeiro

**O que é:** Cadastro obrigatório de toda empresa que transmite dinheiro nos EUA.

**Como fazer:**

1. Acesse: [https://bsaefiling.fincen.treas.gov](https://bsaefiling.fincen.treas.gov)
2. Clique em **"Register"** → **"New Filer Registration"**
3. Tipo de entidade: **Money Services Business (MSB)**
4. Atividade: marque **"Money Transmitter"**
5. Preencha dados da empresa (EIN, endereço, responsável)
6. Submeta — confirmação chega em 2-4 semanas por email
7. Guarde o **número de registro BSA/AML** — o BofA vai pedir

**Custo:** Gratuito  
**Obrigação contínua:** Renovação anual + relatórios SAR/CTR quando exigidos

---

### Passo 2 — Licenças Estaduais (NMLS)

**O que é:** Cada estado exige licença própria de Money Transmitter. Você precisa de licença nos estados onde seus clientes residem.

**Estratégia prática para MVP:**

Não precisa licenciar todos os 50 estados de uma vez. Comece pelos estados com mais volume e expanda gradualmente.

**Os 5 estados mais comuns para fintechs:**

| Estado | Prazo médio | Custo aprox. | Observação |
|--------|------------|--------------|------------|
| Wyoming | 2-3 meses | ~$500 | Mais ágil, ideal para MVP |
| Delaware | 3-4 meses | ~$1.000 | Muitas fintechs domiciliadas aqui |
| Florida | 4-6 meses | ~$2.500 | Grande mercado hispânico |
| Texas | 4-6 meses | ~$2.000 | Segundo maior mercado |
| New York (BitLicense alternativa) | 6-12 meses | ~$5.000 | Mais rigoroso, necessário para escala |

**Como fazer:**

1. Acesse: [https://mortgage.nationwidelicensingsystem.org](https://mortgage.nationwidelicensingsystem.org)
2. Crie conta em **NMLS Consumer Access**
3. Selecione **"Money Transmitter License"** para o estado desejado
4. Preencha formulário MU1 (empresa) — exige:
   - Balanço patrimonial auditado
   - Surety Bond (seguro fiança, ~$25k-$500k dependendo do estado)
   - Plano de negócios
   - Políticas de AML/BSA escritas
   - Background check dos sócios
5. Pague a taxa e aguarde revisão

---

### Passo 3 — Programa AML/BSA Interno

O BofA **exige** que você tenha um programa escrito antes de abrir a conta corporativa. É mais simples do que parece:

```
Documentos necessários:
├── Política de AML (Anti-Money Laundering)      — 5-10 páginas
├── Procedimento de KYC (Know Your Customer)     — checklist de verificação
├── Política de Monitoramento de Transações      — quando acionar SAR
└── Treinamento de equipe                        — registro de quem foi treinado
```

**Atalho:** Contrate um compliance officer freelancer por projeto (~$2k-$5k) para montar o pacote completo. Buscar em: [https://www.upwork.com](https://www.upwork.com) com "BSA AML compliance fintech".

---

### Cronograma realista

```
Mês 1:  Registrar FinCEN (MSB) + Contratar compliance officer
Mês 2:  Montar políticas AML/BSA + Solicitar licença Wyoming
Mês 3:  Onboarding BofA CashPro (15 dias após aprovação MSB)
Mês 4:  MVP operacional em Wyoming
Mês 5+: Expandir para outros estados conforme demanda
```

---

### Recursos oficiais

| Recurso | URL |
|---------|-----|
| FinCEN MSB Registration | https://bsaefiling.fincen.treas.gov |
| NMLS Licensing | https://mortgage.nationwidelicensingsystem.org |
| Guia oficial FinCEN para MSBs | https://www.fincen.gov/msb-state-selector |
| Mapa interativo de licenças por estado | https://www.fincen.gov/msb-state-selector |
| Modelo de política AML (FFIEC) | https://www.ffiec.gov/bsa_aml_infobase |

> 💡 **Dica prática:** Para o BofA especificamente, informe na solicitação de conta que você já tem o registro FinCEN e as políticas AML escritas. Isso acelera significativamente a aprovação.

---

## Onboarding BofA — Guia Prático <a name="onboarding"></a>

### Visão geral do processo

```
Você                              BofA
──────                            ────
1. Cria conta Developer Portal    
2. Solicita acesso Production  →  Analisa (~15 dias úteis)
3. Aguarda                     ←  Envia Client Secret via Secure Message
4. Configura .env e testa        
5. Solicita conta corporativa  →  Abre conta CashPro Online
6. Configura webhooks             
                               ←  Conta ativa, pronto para produção
```

---

### Parte A — Credenciais de API (Developer Portal)

**Passo 1 — Criar conta no Developer Portal**

```
1. Acesse: https://developer.bankofamerica.com
2. Clique em "Sign Up" (canto superior direito)
3. Preencha com email corporativo (domínio da empresa, não Gmail)
4. Confirme o email
```

**Passo 2 — Criar um App e solicitar acesso Production**

```
1. Faça login → clique em "My Apps" → "New App"
2. Nome do App: ex. "YourFintech-CashPro-Production"
3. Description: descreva brevemente o caso de uso
4. Na aba "APIs", selecione:
   ✅ CashPro Payments API
   ✅ CashPro Account Information API  
   ✅ CashPro Push Notifications API
5. Clique em "Request Production Access"
6. Preencha o formulário:
   - Integration Type: "Direct" (não Hosted)
   - Use Case: descreva o fluxo (receber Zelle + enviar ACH/Wire)
   - Expected Volume: transações mensais estimadas
   - CashPro User ID: você recebe isso quando abre a conta corporativa
7. Faça whitelist dos IPs da sua aplicação (obrigatório)
8. Submit
```

**Passo 3 — Aguardar aprovação**

O BofA revisa em **~15 dias úteis**. Você receberá:
- Email notificando que as credenciais estão prontas
- **Secure Message** no portal com o `Client Secret` (não vem por email comum por segurança)

Para acessar o Secure Message:
```
Developer Portal → Messages → Inbox
```

**Suas credenciais finais:**

```env
# Obtidos no Developer Portal (aba "Keys")
BOFA_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx

# Obtido via Secure Message (nunca no email direto)  
BOFA_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Obtido ao abrir conta corporativa CashPro Online
BOFA_ACCOUNT_ID=123456789
```

---

### Parte B — Conta Corporativa CashPro Online

Esta é a conta bancária real onde o dinheiro vai entrar e sair.

**Passo 1 — Abrir a conta**

```
1. Ligue para: 1-888-852-5000 (BofA Business Banking)
   OU vá a uma agência BofA com:
   - EIN (Employer Identification Number)
   - Artigos de Incorporação da empresa
   - Comprovante de endereço comercial
   - Documento dos sócios (passaporte ou driver's license)
   - Registro FinCEN MSB (se já tiver — acelera muito)
   - Políticas AML/BSA escritas

2. Solicite especificamente:
   "Quero abrir uma conta com acesso ao CashPro Online 
    e integração via CashPro API (REST)"

3. Mencione que é fintech/money transmitter — eles têm um
   processo específico para isso (Treasury Management)
```

**Passo 2 — Configurar Zelle na conta corporativa**

```
Após abrir a conta:
1. Acesse CashPro Online (cashproonline.bankofamerica.com)
2. Menu: Payments → Zelle for Business
3. Registre o email/telefone que seus clientes usarão para enviar
4. Configure limites (negociação com o gerente de conta)
```

**Passo 3 — Vincular conta ao Developer App**

```
1. Volte ao Developer Portal
2. Abra seu App → Settings
3. Em "CashPro Account ID", informe o ID da conta recém-aberta
4. Salve — isso vincula a API à conta bancária real
```

---

### Parte C — Configurar Webhooks

Após ter a conta e as credenciais:

```php
// Configure no seu servidor primeiro, depois registre:
$result = $hub->registerWebhook(
    url:    'https://suafintech.com/webhooks/bofa',
    events: [
        'PAYMENT_RECEIVED',
        'PAYMENT_SENT', 
        'PAYMENT_FAILED',
        'PAYMENT_RETURNED',
        'BALANCE_BELOW_THRESHOLD',
    ]
);

// Guarde o webhookId para referência
$_ENV['BOFA_WEBHOOK_ID'] = $result['webhookId'];
```

Copie o HMAC Secret gerado e adicione no `.env`:
```env
BOFA_WEBHOOK_SECRET=cole-aqui-o-hmac-secret-do-portal
```

---

### Checklist completo antes de ir a produção

```
Regulatório:
[ ] Registro FinCEN MSB confirmado (número BSA/AML em mãos)
[ ] Licença Money Transmitter do estado do MVP aprovada
[ ] Políticas AML/BSA escritas e assinadas
[ ] Surety Bond contratado

BofA / API:
[ ] Conta corporativa CashPro Online aberta
[ ] Zelle for Business ativado na conta
[ ] Developer Portal: App criado com acesso Production aprovado
[ ] Client ID e Client Secret salvos com segurança
[ ] Account ID da conta corporativa anotado
[ ] IPs da aplicação cadastrados no Developer Portal (whitelist)
[ ] Webhook registrado e HMAC Secret salvo no .env

Código:
[ ] .env de produção configurado (não commitar no git!)
[ ] Teste de autenticação OAuth2 funcionando
[ ] Teste de getBalance() retornando saldo real
[ ] Endpoint de webhook respondendo 200 OK
[ ] Logs de webhook configurados
[ ] Tratamento de PAYMENT_RECEIVED creditando wallet do cliente
[ ] Tratamento de PAYMENT_FAILED estornando wallet do cliente
```

---

## Limitações <a name="limitações"></a>

| Limitação | Detalhe | Mitigação |
|---|---|---|
| Documentação técnica fechada | Payloads exatos só confirmados após acesso ao sandbox | Testar no sandbox após onboarding |
| Zelle irreversível | Impossível cancelar após envio | Validação rigorosa de dados antes de enviar |
| Wire irreversível | Impossível cancelar após envio | Confirmação dupla para valores altos |
| Same-Day ACH tem cutoff | Após 14h45 ET, processa no próximo dia | Monitorar horário de envio |
| Zelle sem campo de referência estruturado | Identificação do pagador por memo | Instruir clientes a usar código REF |
| Setup leva ~15 dias | Burocracia de onboarding corporativo | Iniciar processo o quanto antes |
| Licença Money Transmitter obrigatória | Para redirecionar fundos de terceiros | Em finalização |

---

## Estrutura de Arquivos <a name="estrutura"></a>

```
src/Gateways/BofACashPro/
├── BofACashProGateway.php          ← Gateway principal (Zelle, ACH, Wire)
└── BofACashProWebhookHandler.php   ← Processador de eventos Push Notification
```

**Namespace:** `IsraelNogueira\PaymentHub\Gateways\BofACashPro`

**Instanciar:**
```php
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProGateway;
use IsraelNogueira\PaymentHub\Gateways\BofACashPro\BofACashProWebhookHandler;
```

---

## Referências

- [CashPro Developer Portal](https://developer.bankofamerica.com)
- [Onboarding: GlobalAPIOps@bofa.com](mailto:GlobalAPIOps@bofa.com)
- [FinCEN MSB Registration](https://bsaefiling.fincen.treas.gov)
- [NMLS Licensing Portal](https://mortgage.nationwidelicensingsystem.org)
- [FFIEC BSA/AML InfoBase](https://www.ffiec.gov/bsa_aml_infobase)
- [BofACashPro_API_Skills.md](../../BofACashPro_API_Skills.md) — Mapeamento completo de capacidades
- [PaymentHub README](../../readme.md)

---

*Integração desenvolvida para o PaymentHub — PHP 8.3+, Type-Safe, zero dependências externas.*
