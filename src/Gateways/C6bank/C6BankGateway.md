# C6Bank Gateway

Gateway de pagamento para integração com C6Bank via API BaaS (Banking as a Service).

## 📋 Funcionalidades Implementadas

### ✅ PIX
- ✅ Criar cobrança PIX imediata
- ✅ Obter QR Code
- ✅ Obter código PIX copia e cola
- ✅ Consultar status de cobrança
- ✅ Listar cobranças

### ✅ Cartão de Crédito
- ✅ Criar pagamento
- ✅ Tokenização de cartão
- ✅ Pré-autorização
- ✅ Captura de pré-autorização (total/parcial)
- ✅ Cancelamento de pré-autorização
- ✅ Parcelamento
- ✅ Salvar cartão

### ✅ Cartão de Débito
- ✅ Criar pagamento
- ✅ Autenticação 3DS

### ✅ Boleto
- ✅ Criar boleto
- ✅ Obter URL do PDF
- ✅ Cancelar boleto
- ✅ Descontos (valor fixo e percentual)
- ✅ Juros e Multa

### ✅ Assinaturas/Recorrência
- ✅ Criar assinatura
- ✅ Cancelar assinatura
- ✅ Suspender assinatura
- ✅ Reativar assinatura
- ✅ Atualizar assinatura

### ✅ Transações
- ✅ Consultar status
- ✅ Listar transações com filtros
- ✅ Histórico completo

### ✅ Estornos e Chargebacks
- ✅ Estorno total
- ✅ Estorno parcial
- ✅ Listar chargebacks
- ✅ Disputar chargeback

### ✅ Split de Pagamento
- ✅ Pagamentos divididos entre múltiplos recebedores
- ✅ Divisão por valor fixo ou percentual
- ✅ Definir responsável pelas taxas

### ✅ Sub-contas
- ✅ Criar sub-conta
- ✅ Atualizar sub-conta
- ✅ Consultar sub-conta
- ✅ Ativar/Desativar sub-conta
- ✅ Configurar conta bancária

### ✅ Wallets (Carteiras Digitais)
- ✅ Criar wallet
- ✅ Adicionar saldo
- ✅ Deduzir saldo
- ✅ Consultar saldo
- ✅ Transferir entre wallets

### ✅ Escrow (Custódia)
- ✅ Reter valor em custódia
- ✅ Liberar custódia total
- ✅ Liberar custódia parcial
- ✅ Cancelar custódia

### ✅ Transferências e Saques
- ✅ Transferência bancária (TED/PIX)
- ✅ Agendar transferência
- ✅ Cancelar transferência agendada
- ✅ Transferência P2P

### ✅ Links de Pagamento
- ✅ Criar checkout (PIX + Cartão + Boleto)
- ✅ Consultar checkout
- ✅ Expirar/Cancelar checkout
- ✅ Múltiplos métodos de pagamento
- ✅ URL personalizada de retorno

### ✅ Clientes
- ✅ Criar cliente
- ✅ Atualizar cliente
- ✅ Consultar cliente
- ✅ Listar clientes com filtros

### ✅ Antifraude
- ✅ Análise de transação
- ✅ Score de fraude
- ✅ Adicionar à blacklist
- ✅ Remover da blacklist

### ✅ Webhooks
- ✅ Registrar webhook
- ✅ Listar webhooks
- ✅ Deletar webhook
- ✅ Eventos: PIX, Boleto, Cartão, Checkout

### ✅ Saldo e Conciliação
- ✅ Consultar saldo disponível
- ✅ Consultar saldo a receber
- ✅ Agenda de liquidação
- ✅ Antecipação de recebíveis

## 🔧 Configuração

### Obter Credenciais

1. Acesse o portal C6Bank BaaS
2. Obtenha suas credenciais:
   - `client_id`
   - `client_secret`
   - `person_id` (opcional, para boletos)

### Ambientes

- **Sandbox:** `https://baas-api-sandbox.c6bank.info`
- **Produção:** `https://baas-api.c6bank.info`

## 🚀 Uso

### Instanciar Gateway

```php
use IsraelNogueira\PaymentHub\Gateways\C6Bank\C6BankGateway;

// Sandbox
$gateway = new C6BankGateway(
    clientId: 'seu_client_id',
    clientSecret: 'seu_client_secret',
    sandbox: true,
    personId: '123' // Opcional
);

// Produção
$gateway = new C6BankGateway(
    clientId: 'seu_client_id',
    clientSecret: 'seu_client_secret',
    sandbox: false,
    personId: '123'
);
```

### Criar Pagamento PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = new PixPaymentRequest(
    amount: 100.00,
    currency: 'BRL',
    customerName: 'João Silva',
    customerDocument: '12345678900',
    customerEmail: 'joao@example.com',
    pixKey: 'sua_chave_pix@c6bank.com',
    description: 'Pagamento de serviço'
);

$payment = $gateway->createPixPayment($request);

// Obter QR Code e Copy-Paste
$qrCode = $gateway->getPixQrCode($payment->transactionId);
$copyPaste = $gateway->getPixCopyPaste($payment->transactionId);
```

### Criar Pagamento com Cartão de Crédito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use IsraelNogueira\PaymentHub\Enums\Currency;

$request = new CreditCardPaymentRequest(
    money: new Money(500.00, Currency::BRL),
    cardNumber: '4111111111111111',
    cardHolderName: 'João Silva',
    cardExpiryMonth: '12',
    cardExpiryYear: '2025',
    cardCvv: '123',
    customerDocument: '12345678900',
    customerEmail: 'joao@example.com',
    installments: 3,
    capture: true,
    description: 'Compra de produto'
);

$payment = $gateway->createCreditCardPayment($request);

// Tokenizar cartão
$token = $gateway->tokenizeCard([
    'number' => '4111111111111111',
    'holder_name' => 'João Silva',
    'exp_month' => '12',
    'exp_year' => '2025',
    'cvv' => '123'
]);
```

### Pré-autorização e Captura

```php
// Pré-autorização
$request = new CreditCardPaymentRequest(
    // ... dados do cartão
    capture: false // Não capturar automaticamente
);

$payment = $gateway->createCreditCardPayment($request);

// Capturar valor total
$captured = $gateway->capturePreAuthorization($payment->transactionId);

// Capturar valor parcial
$captured = $gateway->capturePreAuthorization($payment->transactionId, 250.00);

// Cancelar pré-autorização
$cancelled = $gateway->cancelPreAuthorization($payment->transactionId);
```

### Criar Pagamento com Cartão de Débito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;

$request = new DebitCardPaymentRequest(
    money: new Money(150.00, Currency::BRL),
    cardNumber: '5555555555554444',
    cardHolderName: 'Maria Santos',
    cardExpiryMonth: '06',
    cardExpiryYear: '2026',
    cardCvv: '321',
    customerDocument: '98765432100',
    customerEmail: 'maria@example.com'
);

$payment = $gateway->createDebitCardPayment($request);
```

### Criar Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = new BoletoPaymentRequest(
    money: new Money(250.00, Currency::BRL),
    customerName: 'Maria Santos',
    customerDocument: '98765432100',
    customerEmail: 'maria@example.com',
    dueDate: new DateTime('+7 days'),
    description: 'Fatura #123',
    customerAddress: [
        'street' => 'Rua Exemplo',
        'number' => '123',
        'complement' => 'Apto 45',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '01234567'
    ]
);

$boleto = $gateway->createBoleto($request);
$pdfUrl = $gateway->getBoletoUrl($boleto->transactionId);

// Cancelar boleto
$gateway->cancelBoleto($boleto->transactionId);
```

### Criar Assinatura

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\Enums\SubscriptionInterval;

$request = new SubscriptionRequest(
    amount: 99.90,
    interval: SubscriptionInterval::MONTHLY,
    customerName: 'Carlos Souza',
    customerDocument: '11122233344',
    customerEmail: 'carlos@example.com',
    description: 'Assinatura Premium',
    cardToken: 'card_token_123',
    maxCharges: 12,
    startDate: new DateTime('+1 day')
);

$subscription = $gateway->createSubscription($request);

// Gerenciar assinatura
$gateway->suspendSubscription($subscription->subscriptionId);
$gateway->reactivateSubscription($subscription->subscriptionId);
$gateway->updateSubscription($subscription->subscriptionId, [
    'amount' => 119.90,
    'description' => 'Assinatura Premium Plus'
]);
$gateway->cancelSubscription($subscription->subscriptionId);
```

### Consultar e Listar Transações

```php
// Consultar status de uma transação
$status = $gateway->getTransactionStatus('txn_123456');

// Listar transações com filtros
$transactions = $gateway->listTransactions([
    'start_date' => '2024-01-01',
    'end_date' => '2024-12-31',
    'status' => 'APPROVED',
    'limit' => 50,
    'offset' => 0
]);
```

### Estornos

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

// Estorno total
$request = new RefundRequest(
    transactionId: 'txn_123456',
    reason: 'Solicitação do cliente'
);

$refund = $gateway->refund($request);

// Estorno parcial
$refund = $gateway->partialRefund('txn_123456', 50.00);

// Listar chargebacks
$chargebacks = $gateway->getChargebacks([
    'start_date' => '2024-01-01',
    'end_date' => '2024-12-31'
]);

// Disputar chargeback
$dispute = $gateway->disputeChargeback('chb_123', [
    'documents' => ['invoice.pdf', 'shipping_proof.pdf'],
    'description' => 'Produto foi entregue conforme evidências'
]);
```

### Split de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;

$request = new SplitPaymentRequest(
    totalAmount: 1000.00,
    description: 'Venda no marketplace',
    splits: [
        [
            'recipientId' => 'recipient_1',
            'amount' => 700.00,
            'feeLiable' => true
        ],
        [
            'recipientId' => 'recipient_2',
            'amount' => 300.00,
            'feeLiable' => false
        ]
    ]
);

$payment = $gateway->createSplitPayment($request);
```

### Sub-contas

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;

// Criar sub-conta
$request = new SubAccountRequest(
    name: 'Loja do João',
    taxId: '12345678900',
    email: 'loja@joao.com',
    phone: '11999999999',
    address: [
        'street' => 'Rua A',
        'number' => '100',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '01234567'
    ],
    bankAccount: [
        'bank_code' => '336',
        'agency' => '0001',
        'account' => '12345',
        'account_digit' => '6',
        'type' => 'checking'
    ]
);

$subAccount = $gateway->createSubAccount($request);

// Gerenciar sub-conta
$gateway->updateSubAccount($subAccount->subAccountId, [
    'email' => 'novoemail@loja.com'
]);
$gateway->deactivateSubAccount($subAccount->subAccountId);
$gateway->activateSubAccount($subAccount->subAccountId);
$info = $gateway->getSubAccount($subAccount->subAccountId);
```

### Wallets (Carteiras)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;

// Criar wallet
$request = new WalletRequest(
    name: 'Minha Carteira',
    customerId: 'customer_123',
    description: 'Carteira principal'
);

$wallet = $gateway->createWallet($request);

// Gerenciar saldo
$gateway->addBalance($wallet->walletId, 500.00);
$gateway->deductBalance($wallet->walletId, 150.00);
$balance = $gateway->getWalletBalance($wallet->walletId);

// Transferir entre wallets
$transfer = $gateway->transferBetweenWallets(
    fromWalletId: 'wallet_1',
    toWalletId: 'wallet_2',
    amount: 200.00
);
```

### Escrow (Custódia)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;

// Reter em custódia
$request = new EscrowRequest(
    amount: 1000.00,
    transactionId: 'txn_123',
    description: 'Custódia até entrega do produto',
    releaseDate: new DateTime('+7 days')
);

$escrow = $gateway->holdInEscrow($request);

// Liberar custódia
$gateway->releaseEscrow($escrow->escrowId);

// Liberar parcialmente
$gateway->partialReleaseEscrow($escrow->escrowId, 500.00);

// Cancelar custódia
$gateway->cancelEscrow($escrow->escrowId);
```

### Transferências

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;

// Transferência imediata
$request = new TransferRequest(
    amount: 500.00,
    bankCode: '001',
    agency: '1234',
    account: '56789',
    accountDigit: '0',
    accountType: 'checking',
    beneficiaryName: 'João Silva',
    beneficiaryDocument: '12345678900',
    description: 'Pagamento fornecedor'
);

$transfer = $gateway->transfer($request);

// Agendar transferência
$scheduled = $gateway->scheduleTransfer($request, '2024-12-31');

// Cancelar transferência agendada
$gateway->cancelScheduledTransfer($scheduled->transferId);
```

### Links de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 199.90,
    description: 'Produto XYZ',
    externalReferenceId: 'LINK123',
    customerName: 'Cliente Teste',
    customerEmail: 'cliente@email.com',
    enablePix: true,
    enableCard: true,
    enableBoleto: true,
    maxInstallments: 6,
    expiresAt: new DateTime('+7 days'),
    redirectUrl: 'https://seusite.com/sucesso'
);

$link = $gateway->createPaymentLink($request);
echo "URL: " . $link->url;

// Consultar link
$linkInfo = $gateway->getPaymentLink($link->linkId);

// Expirar link
$gateway->expirePaymentLink($link->linkId);
```

### Gestão de Clientes

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

// Criar cliente
$request = new CustomerRequest(
    name: 'Pedro Oliveira',
    taxId: '12345678900',
    email: 'pedro@example.com',
    phone: '11988887777',
    address: [
        'street' => 'Av. Paulista',
        'number' => '1000',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postal_code' => '01310100'
    ]
);

$customer = $gateway->createCustomer($request);

// Atualizar cliente
$gateway->updateCustomer($customer->customerId, [
    'email' => 'novoemail@example.com',
    'phone' => '11977776666'
]);

// Consultar cliente
$customerInfo = $gateway->getCustomer($customer->customerId);

// Listar clientes
$customers = $gateway->listCustomers([
    'limit' => 100,
    'offset' => 0
]);
```

### Antifraude

```php
// Analisar transação
$analysis = $gateway->analyzeTransaction('txn_123456');
echo "Score: " . $analysis['score'];
echo "Status: " . $analysis['status'];

// Blacklist
$gateway->addToBlacklist('12345678900', 'cpf');
$gateway->addToBlacklist('fraudster@email.com', 'email');
$gateway->removeFromBlacklist('12345678900', 'cpf');
```

### Webhooks

```php
// Registrar webhooks
$webhooks = $gateway->registerWebhook(
    'https://seusite.com/webhook/c6bank',
    ['payment.created', 'payment.approved', 'payment.failed', 'refund.created']
);

// Listar webhooks
$webhooks = $gateway->listWebhooks();

// Deletar webhook
$gateway->deleteWebhook('webhook_id_123');
```

### Saldo e Conciliação

```php
// Consultar saldo
$balance = $gateway->getBalance();
echo "Disponível: R$ " . $balance->available;
echo "A receber: R$ " . $balance->pending;

// Agenda de liquidação
$schedule = $gateway->getSettlementSchedule([
    'start_date' => '2024-01-01',
    'end_date' => '2024-12-31'
]);

// Antecipar recebíveis
$anticipation = $gateway->anticipateReceivables([
    'txn_123',
    'txn_456',
    'txn_789'
]);
```

## 📊 Status de Pagamento

| Status C6Bank | Status PaymentHub | Descrição |
|---------------|-------------------|-----------|
| ATIVA | PENDING | Cobrança ativa aguardando pagamento |
| CONCLUIDA | APPROVED | Pagamento aprovado |
| REMOVIDA_PELO_USUARIO_RECEBEDOR | CANCELLED | Cancelado pelo recebedor |
| REMOVIDA_PELO_PSP | CANCELLED | Cancelado pelo PSP |
| CREATED | PENDING | Criado |
| REGISTERED | PENDING | Registrado |
| PAID | APPROVED | Pago |
| CANCELLED | CANCELLED | Cancelado |
| EXPIRED | EXPIRED | Expirado |
| AUTHORIZED | PENDING | Autorizado |
| PROCESSING | PROCESSING | Processando |
| APPROVED | APPROVED | Aprovado |
| DENIED | FAILED | Negado |
| REFUNDED | REFUNDED | Estornado |

## 🔐 Autenticação

O gateway gerencia automaticamente a autenticação OAuth2:

1. Obtém token de acesso via `client_credentials`
2. Armazena token em memória
3. Renova automaticamente quando expira
4. Adiciona token em todas as requisições

## 📝 Estrutura da API

### Endpoints PIX
- `PUT /v1/pix/cob/{txid}` - Criar cobrança
- `GET /v1/pix/cob/{txid}` - Consultar cobrança

### Endpoints Cartão
- `POST /v1/payments/credit-card` - Pagamento crédito
- `POST /v1/payments/debit-card` - Pagamento débito
- `POST /v1/cards/tokenize` - Tokenizar cartão
- `POST /v1/payments/{id}/capture` - Capturar pré-auth
- `POST /v1/payments/{id}/void` - Cancelar pré-auth

### Endpoints Boleto
- `POST /v1/bank-slips` - Criar boleto
- `GET /v1/bank-slips/{id}` - Consultar boleto
- `DELETE /v1/bank-slips/{id}` - Cancelar boleto

### Endpoints Assinaturas
- `POST /v1/subscriptions` - Criar assinatura
- `DELETE /v1/subscriptions/{id}` - Cancelar
- `POST /v1/subscriptions/{id}/suspend` - Suspender
- `POST /v1/subscriptions/{id}/reactivate` - Reativar
- `PATCH /v1/subscriptions/{id}` - Atualizar

### Endpoints Transações
- `GET /v1/payments/{id}` - Consultar
- `GET /v1/payments` - Listar

### Endpoints Estornos
- `POST /v1/payments/{id}/refund` - Estornar
- `GET /v1/chargebacks` - Listar chargebacks
- `POST /v1/chargebacks/{id}/dispute` - Disputar

### Endpoints Split
- `POST /v1/payments/split` - Split payment

### Endpoints Sub-contas
- `POST /v1/sub-accounts` - Criar
- `PATCH /v1/sub-accounts/{id}` - Atualizar
- `GET /v1/sub-accounts/{id}` - Consultar
- `POST /v1/sub-accounts/{id}/activate` - Ativar
- `POST /v1/sub-accounts/{id}/deactivate` - Desativar

### Endpoints Wallets
- `POST /v1/wallets` - Criar
- `POST /v1/wallets/{id}/credit` - Adicionar saldo
- `POST /v1/wallets/{id}/debit` - Deduzir saldo
- `GET /v1/wallets/{id}` - Consultar
- `POST /v1/wallets/transfer` - Transferir

### Endpoints Escrow
- `POST /v1/escrow/hold` - Reter
- `POST /v1/escrow/{id}/release` - Liberar
- `POST /v1/escrow/{id}/partial-release` - Liberar parcial
- `POST /v1/escrow/{id}/cancel` - Cancelar

### Endpoints Transferências
- `POST /v1/transfers` - Transferir
- `POST /v1/transfers/scheduled` - Agendar
- `DELETE /v1/transfers/scheduled/{id}` - Cancelar agendada

### Endpoints Links
- `POST /v1/payment-links` - Criar
- `GET /v1/payment-links/{id}` - Consultar
- `DELETE /v1/payment-links/{id}` - Expirar

### Endpoints Clientes
- `POST /v1/customers` - Criar
- `PATCH /v1/customers/{id}` - Atualizar
- `GET /v1/customers/{id}` - Consultar
- `GET /v1/customers` - Listar

### Endpoints Antifraude
- `GET /v1/fraud-analysis/{id}` - Analisar
- `POST /v1/blacklist` - Adicionar
- `DELETE /v1/blacklist` - Remover

### Endpoints Webhooks
- `POST /v1/webhooks` - Registrar
- `GET /v1/webhooks` - Listar
- `DELETE /v1/webhooks/{id}` - Deletar

### Endpoints Saldo
- `GET /v1/balance` - Consultar saldo
- `GET /v1/settlements` - Agenda liquidação
- `POST /v1/receivables/anticipate` - Antecipar

## 📦 Dependências

Requer PHP 8.3+ com:
- cURL extension
- JSON extension

## 🔗 Links Úteis

- [Documentação Oficial C6Bank BaaS](https://developers.c6bank.com.br/)
- [Portal do Desenvolvedor](https://portal.c6bank.com.br/)
