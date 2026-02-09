# 🧪 FakeBank Gateway - Documentação Completa

Gateway de simulação para testes do PaymentHub. Simula todos os métodos de pagamento sem integração real.

## 📋 Índice

- [O que é o FakeBank?](#-o-que-é-o-fakebank)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Persistência de Dados](#-persistência-de-dados)
- [Clientes](#-clientes)
- [PIX](#-pix)
- [Cartão de Crédito](#-cartão-de-crédito)
- [Cartão de Débito](#-cartão-de-débito)
- [Boleto](#-boleto)
- [Assinaturas](#-assinaturas)
- [Transações](#-transações)
- [Estornos e Chargebacks](#-estornos-e-chargebacks)
- [Split de Pagamento](#-split-de-pagamento)
- [Sub-contas](#-sub-contas)
- [Wallets](#-wallets)
- [Escrow (Custódia)](#-escrow-custódia)
- [Transferências](#-transferências)
- [Links de Pagamento](#-links-de-pagamento)
- [Antifraude](#-antifraude)
- [Webhooks](#-webhooks)
- [Saldo e Conciliação](#-saldo-e-conciliação)
- [Utilitários](#-utilitários)

---

## 🎯 O que é o FakeBank?

O **FakeBank Gateway** é um gateway de **simulação completo** para:

✅ **Testes unitários e integração**  
✅ **Desenvolvimento local sem API real**  
✅ **Demonstrações e protótipos**  
✅ **CI/CD sem dependências externas**  
✅ **Validação de fluxos de pagamento**

### Características

- 🔄 **Simula todos os métodos de pagamento**
- 💾 **Persistência em JSON** (opcional)
- 🎲 **Dados realistas** (QR Codes, URLs, tokens)
- 🚀 **Sem dependências externas**
- ⚡ **Respostas instantâneas**
- 🧹 **Fácil limpeza de dados**

### ⚠️ Importante

> **NUNCA USE EM PRODUÇÃO!**  
> Este gateway é APENAS para testes. Não processa pagamentos reais.

---

## 🚀 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚙️ Configuração

### Básico

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\FakeBank\FakeBankGateway;

// Configuração padrão (armazena em memória)
$gateway = new FakeBankGateway();

$hub = new PaymentHub($gateway);
```

### Com Persistência Customizada

```php
// Definir caminho personalizado para arquivos JSON
$gateway = new FakeBankGateway(
    storagePath: '/tmp/meus-testes/fakebank'
);

$hub = new PaymentHub($gateway);
```

---

## 💾 Persistência de Dados

O FakeBank usa o `FakeBankStorage` que salva dados em arquivos JSON.

### Estrutura de Armazenamento

```
/storage/fakebank/
├── transactions.json
├── customers.json
├── tokens.json
├── wallets.json
├── subscriptions.json
├── sub_accounts.json
├── escrows.json
├── payment_links.json
├── refunds.json
└── transfers.json
```

### Acessar Storage Diretamente

```php
// Obter storage
$storage = $gateway->getStorage(); // Disponível via reflection ou criar método público

// Buscar transação
$transaction = $storage->get('transactions', 'FAKE_PIX_123');

// Listar todos clientes
$customers = $storage->getAll('customers');

// Buscar com filtro
$approved = $storage->find('transactions', ['status' => 'approved']);

// Limpar dados de teste
$storage->clear('transactions');
$storage->clearAll();
```

---

## 👥 Clientes

### Criar Cliente

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;

$customer = new CustomerRequest(
    name: 'João Silva',
    email: 'joao@teste.com',
    documentNumber: '12345678900',
    phone: '11999999999',
    address: [
        'street' => 'Rua Teste',
        'number' => '123',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zipcode' => '01234-567'
    ]
);

$response = $hub->createCustomer($customer);

// ID gerado: FAKE_CUSTOMER_abc123
echo $response->customerId;
```

### Atualizar Cliente

```php
$response = $hub->updateCustomer(
    customerId: 'FAKE_CUSTOMER_abc123',
    data: ['email' => 'novoemail@teste.com']
);
```

### Buscar Cliente

```php
$response = $hub->getCustomer('FAKE_CUSTOMER_abc123');
print_r($response->rawResponse);
```

### Listar Clientes

```php
$customers = $hub->listCustomers();
// Retorna array com todos os clientes
```

---

## 💳 PIX

### Criar Pagamento PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

$request = PixPaymentRequest::create(
    amount: 100.00,
    customerName: 'João Silva',
    customerEmail: 'joao@teste.com',
    customerDocument: '12345678900',
    description: 'Teste PIX'
);

$response = $hub->createPixPayment($request);

// ✅ Sempre retorna APROVADO
echo $response->transactionId; // FAKE_PIX_xyz789
echo $response->status->value;  // 'approved'
```

### Obter QR Code

```php
$qrCode = $hub->getPixQrCode('FAKE_PIX_xyz789');
// Retorna: data:image/png;base64,iVBORw0KG...
```

### Obter Código Copia e Cola

```php
$pixCode = $hub->getPixCopyPaste('FAKE_PIX_xyz789');
// Retorna: 00020126330014BR.GOV.BCB.PIX0111FAKE_PIX_xyz789
```

---

## 💳 Cartão de Crédito

### Criar Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

$request = CreditCardPaymentRequest::create(
    amount: 250.00,
    installments: 3,
    cardNumber: '4111111111111111',
    cardHolderName: 'JOAO SILVA',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123',
    customerName: 'João Silva',
    customerEmail: 'joao@teste.com',
    customerDocument: '12345678900'
);

$response = $hub->createCreditCardPayment($request);

// ✅ Sempre APROVADO
echo $response->transactionId; // FAKE_CC_abc123
```

### 🎯 Simular Cartões Recusados

```php
// Cartões que sempre são RECUSADOS:
$cartoesRecusados = [
    '4111111111111112', // Saldo insuficiente
    '5555555555554444', // Cartão bloqueado
    '0000000000000000', // Cartão inválido
];

$request = CreditCardPaymentRequest::create(
    amount: 100.00,
    cardNumber: '4111111111111112', // ❌ Recusado
    // ... outros dados
);

$response = $hub->createCreditCardPayment($request);
echo $response->status->value; // 'declined'
```

### Tokenizar Cartão

```php
$token = $hub->tokenizeCard([
    'number' => '4111111111111111',
    'holderName' => 'JOAO SILVA',
    'expiryMonth' => '12',
    'expiryYear' => '2028',
    'cvv' => '123'
]);

echo $token; // FAKE_TOKEN_xyz789
```

### Capturar Pré-autorização

```php
// Captura total
$response = $hub->capturePreAuthorization('FAKE_CC_abc123');

// Captura parcial
$response = $hub->capturePreAuthorization('FAKE_CC_abc123', 100.00);
```

### Cancelar Pré-autorização

```php
$response = $hub->cancelPreAuthorization('FAKE_CC_abc123');
```

---

## 💳 Cartão de Débito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;

$request = DebitCardPaymentRequest::create(
    amount: 50.00,
    cardNumber: '4111111111111111',
    cardHolderName: 'JOAO SILVA',
    cardExpiryMonth: '12',
    cardExpiryYear: '2028',
    cardCvv: '123'
);

$response = $hub->createDebitCardPayment($request);

// ✅ Sempre APROVADO
```

---

## 🧾 Boleto

### Criar Boleto

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;

$request = BoletoPaymentRequest::create(
    amount: 150.00,
    customerName: 'João Silva',
    customerDocument: '12345678900',
    customerEmail: 'joao@teste.com',
    dueDate: '2025-03-15',
    description: 'Teste Boleto'
);

$response = $hub->createBoleto($request);

echo $response->transactionId; // FAKE_BOLETO_abc123
```

### Obter URL do Boleto

```php
$url = $hub->getBoletoUrl('FAKE_BOLETO_abc123');
// https://fakebank.local/boleto/FAKE_BOLETO_abc123
```

### Cancelar Boleto

```php
$response = $hub->cancelBoleto('FAKE_BOLETO_abc123');
```

---

## 🔄 Assinaturas

### Criar Assinatura

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

$request = SubscriptionRequest::create(
    amount: 49.90,
    interval: 'monthly',
    customerId: 'FAKE_CUSTOMER_abc',
    cardToken: 'FAKE_TOKEN_xyz',
    description: 'Plano Premium'
);

$response = $hub->createSubscription($request);

echo $response->subscriptionId; // FAKE_SUB_abc123
```

### Cancelar Assinatura

```php
$response = $hub->cancelSubscription('FAKE_SUB_abc123');
```

### Suspender/Reativar

```php
// Suspender
$response = $hub->suspendSubscription('FAKE_SUB_abc123');

// Reativar
$response = $hub->reactivateSubscription('FAKE_SUB_abc123');
```

### Atualizar Assinatura

```php
$response = $hub->updateSubscription('FAKE_SUB_abc123', [
    'value' => 59.90,
    'description' => 'Plano Premium Plus'
]);
```

---

## 📊 Transações

### Consultar Status

```php
$response = $hub->getTransactionStatus('FAKE_PIX_abc123');

echo $response->status->value;           // 'approved'
echo $response->status->label();         // 'Aprovado'
echo $response->money->formatted();      // 'R$ 100,00'

// Checagens
if ($response->isPaid()) {
    echo "Pagamento confirmado!";
}
```

### Listar Transações

```php
$transactions = $hub->listTransactions([
    'status' => 'approved',
    'type' => 'pix'
]);

foreach ($transactions as $tx) {
    echo $tx['id'] . " - " . $tx['amount'] . "\n";
}
```

---

## 💰 Estornos e Chargebacks

### Reembolso Total

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

$request = RefundRequest::create(
    transactionId: 'FAKE_CC_abc123',
    reason: 'Cliente solicitou'
);

$response = $hub->refund($request);

echo $response->refundId; // FAKE_REFUND_xyz789
```

### Reembolso Parcial

```php
$response = $hub->partialRefund('FAKE_CC_abc123', 50.00);
```

### Listar Chargebacks

```php
$chargebacks = $hub->getChargebacks(['status' => 'pending']);
```

### Contestar Chargeback

```php
$response = $hub->disputeChargeback('FAKE_CB_abc', [
    'evidence' => ['comprovante.pdf'],
    'description' => 'Serviço foi entregue'
]);
```

---

## 🔀 Split de Pagamento

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;

$request = new SplitPaymentRequest(
    amount: 1000.00,
    splits: [
        ['account_id' => 'FAKE_ACC_1', 'percentage' => 70],
        ['account_id' => 'FAKE_ACC_2', 'percentage' => 30],
    ]
);

$response = $hub->createSplitPayment($request);
```

---

## 🏢 Sub-contas

### Criar Sub-conta

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;

$request = new SubAccountRequest(
    name: 'Vendedor Teste',
    email: 'vendedor@teste.com',
    documentNumber: '12345678900'
);

$response = $hub->createSubAccount($request);

echo $response->subAccountId; // FAKE_SUBACC_abc123
```

### Gerenciar Sub-conta

```php
// Atualizar
$response = $hub->updateSubAccount('FAKE_SUBACC_abc', ['email' => 'novo@teste.com']);

// Buscar
$response = $hub->getSubAccount('FAKE_SUBACC_abc');

// Ativar/Desativar
$hub->activateSubAccount('FAKE_SUBACC_abc');
$hub->deactivateSubAccount('FAKE_SUBACC_abc');
```

---

## 👛 Wallets

### Criar Wallet

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;

$request = new WalletRequest(
    customerId: 'FAKE_CUSTOMER_abc',
    initialBalance: 100.00
);

$response = $hub->createWallet($request);

echo $response->walletId; // FAKE_WALLET_abc123
```

### Gerenciar Saldo

```php
// Adicionar
$hub->addBalance('FAKE_WALLET_abc', 50.00);

// Deduzir
$hub->deductBalance('FAKE_WALLET_abc', 30.00);

// Consultar
$response = $hub->getWalletBalance('FAKE_WALLET_abc');
echo "Saldo: R$ " . $response->balance;
```

### Transferir Entre Wallets

```php
$response = $hub->transferBetweenWallets(
    fromWalletId: 'FAKE_WALLET_1',
    toWalletId: 'FAKE_WALLET_2',
    amount: 75.00
);
```

---

## 🔒 Escrow (Custódia)

### Segurar em Custódia

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;

$request = new EscrowRequest(
    transactionId: 'FAKE_CC_abc',
    amount: 500.00
);

$response = $hub->holdInEscrow($request);

echo $response->escrowId; // FAKE_ESCROW_abc123
```

### Liberar Custódia

```php
// Liberação total
$hub->releaseEscrow('FAKE_ESCROW_abc');

// Liberação parcial
$hub->partialReleaseEscrow('FAKE_ESCROW_abc', 250.00);

// Cancelar
$hub->cancelEscrow('FAKE_ESCROW_abc');
```

---

## 💸 Transferências

### Transferência PIX

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use IsraelNogueira\PaymentHub\Enums\Currency;

$request = new TransferRequest(
    money: Money::from(500.00, Currency::BRL),
    description: 'Pagamento teste',
    metadata: ['pix_key' => 'teste@pix.com']
);

$response = $hub->transfer($request);

echo $response->transferId; // FAKE_TRANSFER_abc123
```

### Agendar Transferência

```php
$response = $hub->scheduleTransfer($request, '2025-03-15');
```

### Cancelar Transferência

```php
$response = $hub->cancelScheduledTransfer('FAKE_TRANSFER_abc');
```

---

## 🔗 Links de Pagamento

### Criar Link

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

$request = new PaymentLinkRequest(
    amount: 199.90,
    description: 'Curso Online',
    maxUses: 50,
    expiresAt: '2025-12-31'
);

$response = $hub->createPaymentLink($request);

echo $response->url; // https://fakebank.local/pay/FAKE_LINK_abc123
echo $response->linkId; // FAKE_LINK_abc123
```

### Consultar Link

```php
$response = $hub->getPaymentLink('FAKE_LINK_abc');
```

### Expirar Link

```php
$response = $hub->expirePaymentLink('FAKE_LINK_abc');
```

---

## 🛡️ Antifraude

```php
// Análise de transação
$analysis = $hub->analyzeTransaction('FAKE_CC_abc');
print_r($analysis);

// Blacklist
$hub->addToBlacklist('12345678900', 'cpf');
$hub->removeFromBlacklist('12345678900', 'cpf');
```

---

## 🔔 Webhooks

### Registrar Webhook

```php
$response = $hub->registerWebhook(
    url: 'https://meusite.com/webhook',
    events: ['payment.approved', 'payment.refunded']
);

echo $response['webhook_id']; // FAKE_WEBHOOK_abc123
```

### Listar Webhooks

```php
$webhooks = $hub->listWebhooks();
```

### Deletar Webhook

```php
$deleted = $hub->deleteWebhook('FAKE_WEBHOOK_abc');
```

---

## 💰 Saldo e Conciliação

### Consultar Saldo

```php
$response = $hub->getBalance();

echo "Saldo: R$ " . $response->balance;          // 10000.00 (padrão)
echo "Disponível: R$ " . $response->availableBalance;
```

### Agenda de Liquidação

```php
$schedule = $hub->getSettlementSchedule([
    'date_from' => '2025-01-01',
    'date_to' => '2025-12-31'
]);
```

### Antecipar Recebíveis

```php
$response = $hub->anticipateReceivables([
    'FAKE_PIX_abc',
    'FAKE_CC_xyz'
]);
```

---

## 🛠️ Utilitários

### Limpar Dados de Teste

```php
// Acessar storage (adicione método público no gateway)
class FakeBankGateway {
    public function getStorage(): FakeBankStorage {
        return $this->storage;
    }
}

$storage = $gateway->getStorage();

// Limpar transações
$storage->clear('transactions');

// Limpar tudo
$storage->clearAll();
```

### Inspecionar Dados Salvos

```php
// Ver todas as transações
$transactions = $storage->getAll('transactions');
print_r($transactions);

// Buscar específica
$tx = $storage->get('transactions', 'FAKE_PIX_abc123');

// Filtrar
$approved = $storage->find('transactions', ['status' => 'approved']);
```

### Modificar Dados Manualmente

```php
// Atualizar transação
$storage->update('transactions', 'FAKE_PIX_abc', [
    'status' => 'pending'
]);

// Deletar
$storage->delete('transactions', 'FAKE_PIX_abc');
```

---

## 🎯 Casos de Uso

### Testes Unitários

```php
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    private PaymentHub $hub;
    
    protected function setUp(): void
    {
        $gateway = new FakeBankGateway('/tmp/test-storage');
        $this->hub = new PaymentHub($gateway);
    }
    
    protected function tearDown(): void
    {
        $gateway->getStorage()->clearAll();
    }
    
    public function testPixPayment()
    {
        $request = PixPaymentRequest::create(
            amount: 100.00,
            customerName: 'Test User'
        );
        
        $response = $this->hub->createPixPayment($request);
        
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('approved', $response->status->value);
    }
}
```

### CI/CD Pipeline

```yaml
# .github/workflows/test.yml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Run Tests
        run: |
          composer install
          vendor/bin/phpunit
        # FakeBank não precisa de configuração externa!
```

### Desenvolvimento Local

```php
// .env.local
PAYMENT_GATEWAY=fakebank

// bootstrap.php
$gateway = match(env('PAYMENT_GATEWAY')) {
    'asaas' => new AsaasGateway(env('ASAAS_KEY')),
    'fakebank' => new FakeBankGateway(),
    default => throw new Exception('Gateway inválido')
};
```

---

## 🎲 Comportamentos Simulados

### Cartões de Crédito

| Número do Cartão | Status | Motivo |
|-----------------|--------|---------|
| `4111111111111111` | ✅ Aprovado | Cartão válido |
| `4111111111111112` | ❌ Recusado | Saldo insuficiente |
| `5555555555554444` | ❌ Recusado | Cartão bloqueado |
| `0000000000000000` | ❌ Recusado | Cartão inválido |

### IDs Gerados

Todos os IDs seguem o padrão: `FAKE_{TIPO}_{UNIQID}`

- PIX: `FAKE_PIX_abc123`
- Cartão: `FAKE_CC_xyz789`
- Boleto: `FAKE_BOLETO_def456`
- Cliente: `FAKE_CUSTOMER_ghi789`
- Etc.

### Status Padrões

- Pagamentos: **sempre aprovados** (exceto cartões específicos)
- Refunds: **sempre processados**
- Transfers: **sempre bem-sucedidas**
- Subscriptions: **sempre ativas**

---

## ⚠️ Limitações

1. ❌ **Não processa pagamentos reais**
2. ❌ **Não valida CPF/CNPJ**
3. ❌ **Não valida cartões de verdade**
4. ❌ **Não envia webhooks reais**
5. ❌ **Persistência local apenas (JSON)**
6. ⚠️ **Não usar em produção**

---

## 💡 Dicas

### ✅ Boas Práticas

```php
// ✅ Usar em testes
$gateway = new FakeBankGateway('/tmp/test');

// ✅ Limpar após cada teste
$gateway->getStorage()->clearAll();

// ✅ Validar comportamento, não integração
$this->assertTrue($response->isSuccess());
```

### ❌ O que NÃO fazer

```php
// ❌ Usar em produção
if (env('APP_ENV') === 'production') {
    $gateway = new FakeBankGateway(); // NUNCA!
}

// ❌ Confiar em dados persistidos entre deploys
// ❌ Testar integrações reais com FakeBank
// ❌ Compartilhar storage entre testes
```

---

## 🔍 Debugging

### Ver Logs das Transações

```php
$storage = $gateway->getStorage();
$transactions = $storage->getAll('transactions');

foreach ($transactions as $tx) {
    echo "{$tx['id']}: {$tx['status']} - R$ {$tx['amount']}\n";
}
```

### Inspecionar Storage

```php
// Caminho padrão
echo $gateway->getStorage()->getStoragePath();
// /caminho/do/projeto/storage/fakebank

// Listar arquivos
ls -la storage/fakebank/
```

---

## 📄 Licença

Parte do PaymentHub - Israel Nogueira

---

## 🆘 FAQ

**P: Posso usar em produção?**  
R: ❌ **NÃO!** Apenas para testes e desenvolvimento.

**P: Os dados são persistidos?**  
R: ✅ Sim, em arquivos JSON locais.

**P: Precisa de internet?**  
R: ❌ Não, funciona 100% offline.

**P: Simula webhooks reais?**  
R: ❌ Não, apenas retorna sucesso ao registrar.

**P: Valida CPF/Cartão de verdade?**  
R: ❌ Não, aceita qualquer formato.

**P: Como resetar dados?**  
R: `$storage->clearAll()`

---

🧪 **Happy Testing!**
