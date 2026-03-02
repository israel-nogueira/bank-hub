# 🏦 Itaú Gateway

Integração com a **API do Itaú Unibanco** via [Itaú Developer Platform](https://devportal.itau.com.br).

---

## ✅ Funcionalidades Suportadas

| Método | Descrição |
|---|---|
| `createPixPayment()` | Cobrança PIX imediata (QR Code Dinâmico BACEN v2) |
| `getPixQrCode()` | Imagem base64 do QR Code |
| `getPixCopyPaste()` | Código EMV Pix Copia e Cola |
| `refund()` | Devolução total de PIX recebido |
| `partialRefund()` | Devolução parcial de PIX |
| `createBoleto()` | Registro de boleto bancário |
| `getBoletoUrl()` | URL PDF do boleto para impressão |
| `cancelBoleto()` | Baixa/cancelamento de boleto |
| `transfer()` | Transferência via PIX ou TED (seleção automática) |
| `scheduleTransfer()` | Agendamento de transferência |
| `cancelScheduledTransfer()` | Cancelamento de agendamento |
| `getBalance()` | Saldo disponível e bloqueado |
| `getSettlementSchedule()` | Extrato de lançamentos paginado |
| `getTransactionStatus()` | Status de cobrança PIX ou boleto |
| `listTransactions()` | Lista de cobranças PIX com filtros |
| `registerWebhook()` | Registrar URL de notificação |
| `listWebhooks()` | Listar webhooks ativos |
| `deleteWebhook()` | Remover webhook |
| `createCustomer()` | Criar cliente |
| `updateCustomer()` | Atualizar dados do cliente |
| `getCustomer()` | Buscar cliente por ID |
| `listCustomers()` | Listar clientes |

## ❌ Não Suportados

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

---

## 🚀 Instalação e Uso
```php
use IsraelNogueira\PaymentHub\Gateways\Itau\ItauGateway;
use IsraelNogueira\PaymentHub\PaymentHub;

// Sandbox
$gateway = new ItauGateway(
    clientId:     'seu_client_id',
    clientSecret: 'seu_client_secret',
    sandbox:      true,
    pixKey:       'empresa@itau.com.br',
    convenio:     '12345',
);

// Produção (mTLS obrigatório)
$gateway = new ItauGateway(
    clientId:     'prod_client_id',
    clientSecret: 'prod_client_secret',
    sandbox:      false,
    pixKey:       'empresa@itau.com.br',
    convenio:     '12345',
    certPath:     '/certs/itau-prod.pfx',
    certPassword: 'senha_cert',
);

$hub = new PaymentHub($gateway);
```

---

## 🔐 Autenticação

OAuth 2.0 Client Credentials com renovação automática de token (cache interno com margem de 60s).

Em **produção**, o Itaú exige **mTLS** com certificado **ICP-Brasil** registrado no [Itaú Developer Portal](https://devportal.itau.com.br). Sem o certificado, a API retorna `HTTP 401`.

---

## 🔔 Webhooks PIX
```php
$webhook = $hub->registerWebhook(
    url:    'https://seusite.com/webhooks/itau',
    events: ['pix.recebido', 'pix.devolucao']
);
```

O Itaú vincula o webhook à **chave PIX** configurada no construtor.
Certifique-se de que a URL seja **HTTPS** acessível publicamente.

---

## 📁 Estrutura de Arquivos
```
src/Gateways/Itau/
├── ItauGateway.php       ← Implementação principal
├── itau-examples.php     ← Exemplos práticos
└── ItauGateway.md        ← Esta documentação
```

---

## 🧪 Testes
```bash
ITAU_CLIENT_ID=xxx \
ITAU_CLIENT_SECRET=yyy \
ITAU_PIX_KEY=empresa@itau.com.br \
ITAU_CONVENIO=12345 \
./vendor/bin/phpunit --filter ItauGatewayTest
```

Sem variáveis de ambiente, os testes são **pulados automaticamente**.