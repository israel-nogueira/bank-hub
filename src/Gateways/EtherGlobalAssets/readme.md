# 📘 Documentação - Ether Global Assets Gateway

## 📋 Índice

- [Visão Geral](#visão-geral)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Funcionalidades Disponíveis](#funcionalidades-disponíveis)
- [Métodos Implementados](#métodos-implementados)
  - [PIX](#pix)
  - [Transações](#transações)
  - [Saldo](#saldo)
  - [Transferências](#transferências)
- [Exemplos de Uso](#exemplos-de-uso)
- [Webhooks](#webhooks)
- [Tratamento de Erros](#tratamento-de-erros)
- [Limitações](#limitações)

---

## 🎯 Visão Geral

O **EtherGlobalAssetsGateway** é uma implementação do `PaymentGatewayInterface` para integração com a API da Ether Global Assets. Este gateway permite realizar operações de pagamento via PIX, consultar transações e gerenciar saldo.

### ✅ Recursos Implementados

- ✅ Autenticação automática com renovação de token
- ✅ Criação de PIX para depósito (QR Code dinâmico)
- ✅ Realização de saques via PIX
- ✅ Consulta de saldo
- ✅ Listagem de transações com filtros
- ✅ Consulta de status de transação específica
- ✅ Conversão automática de valores (centavos ↔ reais)

### ❌ Recursos Não Disponíveis

- ❌ Cartão de crédito
- ❌ Cartão de débito
- ❌ Boleto bancário
- ❌ Assinaturas/Recorrência
- ❌ Split de pagamento
- ❌ Sub-contas
- ❌ Escrow (custódia)
- ❌ Antifraude

---

## 📦 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

### 1. Criar Conta

Acesse: https://banking.etherglobalassets.com.br/account-creation

### 2. Obter Credenciais

Entre em contato com o suporte para gerar suas credenciais:
- **Email**: suporte@etherglobalassets.com.br
- **Credenciais**: `clientId` e `clientSecret`

### 3. Inicializar Gateway

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\EtherGlobalAssetsGateway;

$clientId = 'seu_client_id_aqui';
$clientSecret = 'seu_client_secret_aqui';

$gateway = new EtherGlobalAssetsGateway($clientId, $clientSecret);
$paymentHub = new PaymentHub($gateway);
```

---

## 🚀 Funcionalidades Disponíveis

| Funcionalidade | Status | Método |
|----------------|--------|--------|
| Criar PIX Depósito | ✅ | `createPixPayment()` |
| Realizar Saque PIX | ✅ | `transfer()` |
| Consultar Saldo | ✅ | `getBalance()` |
| Listar Transações | ✅ | `listTransactions()` |
| Status de Transação | ✅ | `getTransactionStatus()` |
| Cartões | ❌ | - |
| Boleto | ❌ | - |
| Assinaturas | ❌ | - |

---

## 📖 Métodos Implementados

### 🔵 PIX

#### `createPixPayment(PixPaymentRequest $request): PaymentResponse`

Cria um PIX dinâmico para receber pagamentos.

**Características:**
- QR Code expira em **5 minutos**
- Valores em **centavos** (convertido automaticamente)
- Retorna chave PIX copia e cola

**Exemplo:**

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$pixRequest = PixPaymentRequest::create(
    amount: 150.00,        // R$ 150,00
    currency: 'BRL',
    description: 'Pagamento do pedido #12345',
    metadata: [
        'order_id' => '12345',
        'customer_name' => 'João Silva'
    ]
);

$response = $paymentHub->createPixPayment($pixRequest);

if ($response->isSuccess()) {
    echo "Transaction ID: {$response->transactionId}\n";
    echo "Status: {$response->getStatusLabel()}\n";
    echo "Valor: {$response->getFormattedAmount()}\n";
    
    // Dados do QR Code
    $qrCodeId = $response->rawResponse['qrCodeId'];
    $pixKey = $response->rawResponse['pixKey'];
    $expireAt = $response->rawResponse['expireAt'];
    
    echo "PIX Copia e Cola: {$pixKey}\n";
    echo "Expira em: {$expireAt}\n";
}
```

**Response:**
```php
[
    'success' => true,
    'transactionId' => 'u1u2i3d4-v5a6-7890-abcd-ef1234567890',
    'status' => 'pending',
    'amount' => 150.00,
    'currency' => 'BRL',
    'message' => 'PIX payment created successfully',
    'rawResponse' => [
        'uuid' => '...',
        'qrCodeId' => '...',
        'pixKey' => '00020126870014BR.GOV.BCB.PIX...',
        'expireAt' => '2026-01-14T17:04:33.665Z'
    ],
    'metadata' => [
        'qr_code_id' => '...',
        'pix_key' => '...',
        'expire_at' => '...'
    ]
]
```

**Importante:**
- ⚠️ O PIX expira em **5 minutos**
- ⚠️ Pagamento deve ser feito com **mesmo CPF/CNPJ** (contas Crypto)
- ⚠️ Contas tipo Pagamento aceitam qualquer CPF/CNPJ

---

### 🔵 Transferências

#### `transfer(TransferRequest $request): TransferResponse`

Realiza saque via PIX para uma chave específica.

**Tipos de Chave PIX Suportados:**
- `EMAIL` - Email válido
- `CPF` - 11 dígitos sem formatação
- `CNPJ` - 14 dígitos sem formatação
- `PHONE` - Telefone com DDD (sem +55)
- `RANDOM` - Chave aleatória (UUID)

**Limites:**
- Mínimo: **R$ 1,00**
- Máximo: **R$ 500.000,00**

**Exemplo:**

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

$transferRequest = TransferRequest::create(
    amount: 50.00,
    pixKey: 'usuario@email.com',
    description: 'Pagamento fornecedor',
    metadata: [
        'pixKeyType' => 'EMAIL'
    ]
);

$response = $paymentHub->transfer($transferRequest);

if ($response->success) {
    echo "Transfer ID: {$response->transferId}\n";
    echo "Status: {$response->status}\n";
    echo "Mensagem: {$response->message}\n";
}
```

**Response:**
```php
[
    'success' => true,
    'transferId' => 'FAKE_TRANSFER_123',
    'amount' => 50.00,
    'status' => 'processing',
    'message' => 'PIX transfer initiated',
    'rawResponse' => [
        'transactionId' => '...',
        'pixId' => '...',
        'status' => 'CONFIRMED',
        'e2e' => 'E12345678920260114141322873459636',
        'executedAt' => '2026-01-14T14:13:24.000Z'
    ]
]
```

**Exemplos de Chaves PIX:**

```php
// Email
TransferRequest::create(
    amount: 100.00,
    pixKey: 'cliente@exemplo.com.br',
    metadata: ['pixKeyType' => 'EMAIL']
);

// CPF
TransferRequest::create(
    amount: 100.00,
    pixKey: '12345678901',
    metadata: ['pixKeyType' => 'CPF']
);

// CNPJ
TransferRequest::create(
    amount: 100.00,
    pixKey: '12345678000199',
    metadata: ['pixKeyType' => 'CNPJ']
);

// Telefone
TransferRequest::create(
    amount: 100.00,
    pixKey: '11999999999',
    metadata: ['pixKeyType' => 'PHONE']
);

// Chave Aleatória
TransferRequest::create(
    amount: 100.00,
    pixKey: '550e8400-e29b-41d4-a716-446655440000',
    metadata: ['pixKeyType' => 'RANDOM']
);
```

---

### 🔵 Saldo

#### `getBalance(): BalanceResponse`

Consulta o saldo atual da conta em reais.

**Exemplo:**

```php
$balance = $paymentHub->getBalance();

if ($balance->success) {
    echo "Saldo Total: R$ " . number_format($balance->balance, 2, ',', '.') . "\n";
    echo "Saldo Disponível: R$ " . number_format($balance->availableBalance, 2, ',', '.') . "\n";
    echo "Saldo Pendente: R$ " . number_format($balance->pendingBalance, 2, ',', '.') . "\n";
    echo "Moeda: {$balance->currency}\n";
}
```

**Response:**
```php
[
    'success' => true,
    'balance' => 1000.00,
    'availableBalance' => 1000.00,
    'pendingBalance' => 0.0,
    'currency' => 'BRL',
    'rawResponse' => [
        'balance' => 100000 // em centavos
    ]
]
```

---

### 🔵 Transações

#### `listTransactions(array $filters = []): array`

Lista todas as transações com suporte a filtros e paginação.

**Filtros Disponíveis:**

| Parâmetro | Tipo | Descrição | Valores |
|-----------|------|-----------|---------|
| `page` | int | Número da página | Qualquer inteiro positivo |
| `limit` | int | Itens por página | 1 a 100 (padrão: 10) |
| `type` | string | Tipo de transação | `PIX_CASH_IN`, `PIX_CASH_OUT` |
| `status` | string | Status | `PENDING`, `COMPLETED`, `FAILED`, `CANCELLED` |

**Exemplo 1 - Listar Todas:**

```php
$result = $paymentHub->listTransactions([
    'page' => 1,
    'limit' => 10
]);

echo "Total de itens: {$result['pagination']['totalItens']}\n";
echo "Total de páginas: {$result['pagination']['totalPages']}\n\n";

foreach ($result['transactions'] as $tx) {
    echo "ID: {$tx['id']}\n";
    echo "Tipo: {$tx['type']}\n";
    echo "Status: {$tx['status']}\n";
    echo "Valor: R$ " . number_format(abs($tx['amount']) / 100, 2, ',', '.') . "\n\n";
}
```

**Exemplo 2 - Apenas PIX Pendentes:**

```php
$result = $paymentHub->listTransactions([
    'status' => 'PENDING',
    'type' => 'PIX_CASH_IN'
]);

foreach ($result['transactions'] as $pix) {
    echo "PIX aguardando pagamento:\n";
    echo "ID: {$pix['id']}\n";
    echo "Valor: R$ " . number_format($pix['amount'] / 100, 2, ',', '.') . "\n";
    echo "Criado em: {$pix['createdAt']}\n\n";
}
```

**Exemplo 3 - Transações Concluídas:**

```php
$result = $paymentHub->listTransactions([
    'status' => 'COMPLETED',
    'page' => 1,
    'limit' => 20
]);
```

**Response:**
```php
[
    'transactions' => [
        [
            'id' => '50546280-a3dd-4cdd-961d-000000000000',
            'type' => 'PIX_CASH_OUT',
            'provider' => 'DEFAULT_PROVIDER',
            'amount' => -99,
            'netAmount' => -100,
            'status' => 'COMPLETED',
            'balanceAfter' => 81,
            'totalFeeAmount' => 1,
            'balanceBefore' => 182,
            'createdAt' => '2025-04-03T01:35:27.977Z',
            'updatedAt' => '2025-04-03T01:35:27.977Z',
            'currency' => 'BRL',
            'metadata' => [...]
        ],
        // ... mais transações
    ],
    'pagination' => [
        'itens' => 10,
        'totalItens' => 45,
        'totalPages' => 5,
        'page' => 1,
        'limit' => 10
    ],
    'filters' => []
]
```

---

#### `getTransactionStatus(string $transactionId): TransactionStatusResponse`

Consulta detalhes completos de uma transação específica.

**Exemplo:**

```php
$transactionId = 'f03f3a4f-4412-4626-8f9a-000000000000';

$status = $paymentHub->getTransactionStatus($transactionId);

if ($status->success) {
    echo "ID: {$status->transactionId}\n";
    echo "Status: {$status->getStatusLabel()}\n";
    echo "Valor: {$status->getFormattedAmount()}\n";
    echo "Moeda: {$status->getCurrency()}\n\n";
    
    // Acessar dados completos
    $raw = $status->rawResponse;
    
    // Dados da transação
    $transaction = $raw['transaction'];
    echo "Tipo: {$transaction['type']}\n";
    echo "Status: {$transaction['status']}\n";
    echo "Criado em: {$transaction['createdAt']}\n\n";
    
    // Dados financeiros
    $financial = $raw['financial'];
    echo "Valor bruto: R$ " . number_format($financial['amount'] / 100, 2, ',', '.') . "\n";
    echo "Valor líquido: R$ " . number_format($financial['netAmount'] / 100, 2, ',', '.') . "\n";
    echo "Taxa: R$ " . number_format($financial['totalFeeAmount'] / 100, 2, ',', '.') . "\n";
    echo "Saldo antes: R$ " . number_format($financial['balanceBefore'] / 100, 2, ',', '.') . "\n";
    echo "Saldo depois: R$ " . number_format($financial['balanceAfter'] / 100, 2, ',', '.') . "\n\n";
    
    // Dados do PIX (se houver)
    if (isset($raw['pix'])) {
        $pix = $raw['pix'];
        echo "PIX ID: {$pix['id']}\n";
        echo "Tipo: {$pix['type']}\n";
        echo "E2E: {$pix['e2e']}\n";
        
        if (isset($pix['senderInfo'])) {
            echo "\nRemetente:\n";
            echo "Nome: {$pix['senderInfo']['name']}\n";
            echo "Documento: {$pix['senderInfo']['document']}\n";
            echo "Banco: {$pix['senderInfo']['bank']['name']}\n";
        }
    }
}
```

**Response:**
```php
[
    'success' => true,
    'transactionId' => 'f03f3a4f-4412-4626-8f9a-000000000000',
    'status' => 'completed',
    'amount' => 184.00,
    'currency' => 'BRL',
    'rawResponse' => [
        'transaction' => [...],
        'financial' => [...],
        'participants' => [...],
        'pix' => [...]
    ]
]
```

---

## 💡 Exemplos de Uso

### Exemplo Completo - Fluxo de Recebimento

```php
<?php

require_once 'vendor/autoload.php';

use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\EtherGlobalAssetsGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

// 1. Inicializar
$gateway = new EtherGlobalAssetsGateway('client_id', 'client_secret');
$paymentHub = new PaymentHub($gateway);

// 2. Consultar saldo inicial
$saldoInicial = $paymentHub->getBalance();
echo "Saldo inicial: R$ " . number_format($saldoInicial->balance, 2, ',', '.') . "\n\n";

// 3. Criar PIX para receber R$ 100
$pixRequest = PixPaymentRequest::create(
    amount: 100.00,
    currency: 'BRL',
    description: 'Pedido #12345'
);

$pixResponse = $paymentHub->createPixPayment($pixRequest);

if ($pixResponse->isSuccess()) {
    echo "✅ PIX criado com sucesso!\n";
    echo "Transaction ID: {$pixResponse->transactionId}\n";
    echo "Chave PIX: {$pixResponse->rawResponse['pixKey']}\n";
    echo "Expira em: {$pixResponse->rawResponse['expireAt']}\n\n";
    
    // 4. Cliente paga o QR Code (webhook será recebido)
    echo "Aguardando pagamento...\n";
    echo "(Webhook 'pix.deposit.confirmed' será enviado quando pago)\n\n";
    
    // 5. Verificar PIX pendentes
    $pendentes = $paymentHub->listTransactions([
        'status' => 'PENDING',
        'type' => 'PIX_CASH_IN'
    ]);
    
    echo "PIX pendentes: " . count($pendentes['transactions']) . "\n";
}
```

### Exemplo Completo - Fluxo de Pagamento

```php
<?php

use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

// 1. Verificar saldo
$balance = $paymentHub->getBalance();

if ($balance->balance >= 50.00) {
    // 2. Fazer saque de R$ 50
    $saque = TransferRequest::create(
        amount: 50.00,
        pixKey: 'fornecedor@empresa.com',
        description: 'Pagamento fornecedor',
        metadata: ['pixKeyType' => 'EMAIL']
    );
    
    $response = $paymentHub->transfer($saque);
    
    if ($response->success) {
        echo "✅ Saque iniciado!\n";
        echo "Transfer ID: {$response->transferId}\n";
        echo "Status: {$response->status}\n\n";
        
        // 3. Consultar status
        sleep(5); // Aguardar processamento
        
        $status = $paymentHub->getTransactionStatus($response->transferId);
        echo "Status atualizado: {$status->getStatusLabel()}\n";
    }
} else {
    echo "❌ Saldo insuficiente!\n";
}
```

---

## 🔔 Webhooks

A Ether Global Assets envia webhooks para notificar eventos de transações.

### Eventos Disponíveis:

| Evento | Descrição |
|--------|-----------|
| `pix.deposit.created` | PIX para depósito criado |
| `pix.deposit.confirmed` | Depósito PIX confirmado |
| `pix.withdraw.initiated` | Saque PIX iniciado |
| `pix.withdraw.completed` | Saque PIX concluído |
| `pix.withdraw.failed` | Saque PIX falhou |

### Exemplo de Webhook - PIX Depósito Confirmado

```json
{
  "data": {
    "event": "pix.deposit.confirmed",
    "data": {
      "pix": {
        "id": "p1x2i3d4-e5f6-7890-abcd-ef1234567890",
        "userId": "u1s2e3r4-i5d6-7f8g-9h0i-j1k2l3m4n5o6",
        "uuid": "u1u2i3d4-v5a6-7890-abcd-ef1234567890",
        "amount": 500,
        "status": "CONFIRMED",
        "type": "DEPOSIT",
        "pixKeyType": "QRCODE",
        "e2e": "E12345678920250813123456ABCDEF123456",
        "executedAt": "2025-08-13T18:07:45.453Z",
        "createdAt": "2025-08-13T18:05:06.397Z",
        "senderInfo": {
          "name": "MARIA SILVA SANTOS",
          "document": "12345678000199",
          "bank": {
            "code": "12345678",
            "name": "BANCO EXEMPLO"
          }
        },
        "user": {
          "id": "u1s2e3r4-i5d6-7f8g-9h0i-j1k2l3m4n5o6",
          "email": "pedro.santos@empresa.com.br",
          "name": "PEDRO SANTOS OLIVEIRA"
        }
      },
      "timestamp": "2025-08-13T18:07:45.689Z"
    }
  },
  "id": "ev1nt2id3-4ev5-6nt7-8id9-0ev1nt2id3ev4",
  "eventType": "pix.deposit.confirmed",
  "timestamp": 1755108465701
}
```

### Como Processar Webhooks

```php
<?php

// Receber payload do webhook
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

$eventType = $data['data']['event'];

switch ($eventType) {
    case 'pix.deposit.confirmed':
        $pix = $data['data']['data']['pix'];
        
        echo "✅ PIX confirmado!\n";
        echo "ID: {$pix['id']}\n";
        echo "Valor: R$ " . number_format($pix['amount'] / 100, 2, ',', '.') . "\n";
        echo "Remetente: {$pix['senderInfo']['name']}\n";
        
        // Atualizar seu sistema
        // ...
        break;
        
    case 'pix.withdraw.completed':
        $pix = $data['data']['data']['pix'];
        
        echo "✅ Saque concluído!\n";
        echo "ID: {$pix['id']}\n";
        echo "E2E: {$pix['e2e']}\n";
        
        // Atualizar seu sistema
        // ...
        break;
}

// Retornar 200 OK
http_response_code(200);
```

---

## 🚨 Tratamento de Erros

### Tipos de Exceções

```php
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

try {
    $response = $paymentHub->createPixPayment($request);
} catch (GatewayException $e) {
    echo "Erro: {$e->getMessage()}\n";
    echo "Código: {$e->getCode()}\n";
    
    // Contexto adicional
    $context = $e->getContext();
    print_r($context);
}
```

### Códigos de Erro Comuns

| Código | Erro | Solução |
|--------|------|---------|
| 400 | Bad Request | Verificar parâmetros da requisição |
| 401 | Unauthorized | Token inválido ou expirado (renovação automática) |
| 500 | Internal Server Error | Erro no servidor, tentar novamente |

### Validações Automáticas

O gateway já valida:
- ✅ Valores mínimos e máximos
- ✅ Formato de chaves PIX
- ✅ Conversão de centavos
- ✅ Renovação de token

---

## ⚠️ Limitações

### Valores

| Operação | Mínimo | Máximo |
|----------|--------|--------|
| PIX Depósito | R$ 0,01 | Sem limite |
| PIX Saque | R$ 1,00 | R$ 500.000,00 |

### Restrições

- ⚠️ PIX dinâmico expira em **5 minutos**
- ⚠️ Contas Crypto: apenas CPF/CNPJ titular
- ⚠️ Contas Pagamento: qualquer CPF/CNPJ
- ⚠️ Token expira em **1 hora** (renovação automática)

### Taxas

Consulte as taxas diretamente com a Ether Global Assets:
- Email: suporte@etherglobalassets.com.br

---

## 📞 Suporte

### Ether Global Assets

- **Site**: https://banking.etherglobalassets.com.br
- **Email**: suporte@etherglobalassets.com.br
- **Documentação**: https://docs.etherglobalassets.com.br

### PaymentHub

- **GitHub**: https://github.com/israel-nogueira/payment-hub
- **Autor**: Israel Nogueira
- **Email**: contato@israelnogueira.com

---

## 📝 Changelog

### v1.0.0 (2026-02-04)

- ✅ Implementação inicial
- ✅ Autenticação automática
- ✅ PIX Depósito
- ✅ PIX Saque
- ✅ Consulta de saldo
- ✅ Listagem de transações
- ✅ Status de transação

---

## 📄 Licença

MIT License - Veja LICENSE para mais detalhes.

---

**Desenvolvido com ❤️ por Israel Nogueira**
