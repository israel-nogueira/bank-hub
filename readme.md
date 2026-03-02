# 💳 Payment Hub

<div align="center">

![PHP Version](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)
![Tests](https://img.shields.io/badge/Tests-Passing-success?style=flat-square)
![Type Safe](https://img.shields.io/badge/Type%20Safe-100%25-blue?style=flat-square)

**A biblioteca PHP mais simples e elegante para pagamentos no Brasil** 🇧🇷

### 📚 Navegação Rápida
[Instalação](#-instalação) • [Conceitos](docs/core-concepts.md) • [Cartão](docs/credit-card.md) • [PIX](docs/pix.md) • [Boleto](docs/boleto.md) • [Assinaturas](docs/subscriptions.md) • [Money](docs/money.md) • [Enums](docs/enums.md) • [FAQ](docs/faq.md) • [Banco do Brasil](src/Gateways/Bancodobrasil/BancoDoBrasilGateway.md)

</div>

---

## 🎯 O Que é o Payment Hub?

**Payment Hub** é a solução definitiva para processar pagamentos em PHP sem dor de cabeça. Esqueça integrações complexas, APIs diferentes e código verboso. Com uma **interface única e padronizada**, você integra múltiplos gateways de pagamento e pode trocar entre eles mudando apenas 1 linha de código.

### 💡 Comece testando AGORA - Sem precisar de API keys!

O Payment Hub inclui o **FakeBankGateway** - um gateway de pagamento simulado que implementa **TODAS as funcionalidades** da biblioteca. Você pode desenvolver, testar e validar toda sua lógica de negócio **sem depender de APIs externas, sem sandbox, sem credenciais**. Quando estiver pronto, basta trocar para um gateway real e tudo continua funcionando!

**🚀 Perfeito para:**
- Desenvolver sua aplicação offline
- Testar fluxos completos sem custos
- Criar testes automatizados confiáveis
- Validar sua lógica antes de integrar APIs reais
- Demonstrações e protótipos

---

## 🚀 Gateways Suportados

| Gateway | Status | Métodos Suportados | Documentação |
|---------|--------|---------|--------------|
| 🧪 **FakeBankGateway** | ✅ Pronto | **TODOS** os métodos (PIX, Cartões, Boleto, Assinaturas, Split, Escrow, etc.) - **Perfeito para desenvolvimento e testes SEM precisar de API real!** | [📖 Docs](src/Gateways/FakeBank/readme.md) |
| 🟣 **Asaas** | ✅ Pronto | PIX, Cartão de Crédito, Boleto, Assinaturas, Split, Sub-contas, Wallets, Escrow, Transferências, Clientes, Refunds | [📖 Docs](src/Gateways/Asaas/readme.md) |
| 🟡 **Pagar.me** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Assinaturas, Split, Recipients, Clientes, Refunds, Pre-auth, Webhooks | [📖 Docs](src/Gateways/PagarMe/readme.md) |
| 🟣 **C6 Bank** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Assinaturas, Split, Sub-contas, Wallets, Escrow, Transferências, Clientes, Refunds, Payment Links | [📖 Docs](src/Gateways/C6bank/readme.md) |
| 🌎 **EBANX** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Recorrência, Refunds, Pre-auth, Multi-país (7 países) | [📖 Docs](src/Gateways/Ebanx/readme.md) |
| 💚 **MercadoPago** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Assinaturas, Split, Clientes, Refunds, Pre-auth | [📖 Docs](src/Gateways/MercadoPago/readme.md) |
| 🟠 **PagSeguro** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Assinaturas, Split, Clientes, Refunds, Pre-auth | [📖 Docs](src/Gateways/PagSeguro/readme.md) |
| 🔴 **Adyen** | ✅ Pronto | PIX, Cartão Crédito/Débito, Boleto, Payment Links, Refunds, Pre-auth/Capture | [📖 Docs](src/Gateways/Adyen/readme.md) |
| 🔵 **Stripe** | ✅ Pronto | Cartão de Crédito, Assinaturas, Payment Intents, Clientes, Refunds, Pre-auth/Capture | [📖 Docs](src/Gateways/Stripe/readme.md) |
| 💙 **PayPal** | ✅ Pronto | Cartão de Crédito, Assinaturas, PayPal Checkout, Refunds, Pre-auth/Capture | [📖 Docs](src/Gateways/PayPal/readme.md) |
| 🟢 **EtherGlobalAssets** | ✅ Pronto | PIX (apenas) | [📖 Docs](src/Gateways/EtherGlobalAssets/readme.md) |
| 🏦 **Banco do Brasil** | ✅ Pronto | PIX (QR Code Dinâmico v2), Boleto Bancário, Boleto Híbrido (Boleto + PIX), Estorno PIX, Transferências PIX/TED, Agendamento, Saldo, Extrato, Webhooks — mTLS obrigatório em produção | [📖 Docs](src/Gateways/Bancodobrasil/BancoDoBrasilGateway.md) |
| 🏦 **Bank of America CashPro** | ✅ Pronto | Zelle (instantâneo, 24/7), ACH Same-Day, ACH Standard, Wire/Fedwire — roteamento automático por valor, webhooks Push Notification, agendamento ACH, cancelamento, saldo e extrato | [📖 Docs](src/Gateways/BofACashPro/readme.md) |
| 🟣 **NuBank (NuPay)** | ✅ Pronto | Pagamento via app Nubank (redirecionamento), Estorno total/parcial, Consulta de status — método exclusivo para clientes Nubank | [📖 Docs](src/Gateways/NuBank/readme.md) |
| 🏦 **Itaú Unibanco** | ✅ Pronto | PIX (QR Code Dinâmico v2), Boleto Bancário, Estorno PIX, Transferências PIX/TED, Agendamento, Saldo, Extrato, Webhooks PIX, Gestão de Clientes — mTLS obrigatório em produção | [📖 Docs](src/Gateways/Itau/ItauGateway.md) |

> 🧪 **FakeBankGateway**: Gateway simulado completo que funciona **SEM internet, SEM API keys, SEM sandbox**. Use para desenvolver toda sua aplicação localmente e só conecte com APIs reais quando estiver pronto para produção!
>
> 📝 **Nota**: Gateways brasileiros (Asaas, Pagar.me, C6 Bank, MercadoPago, PagSeguro, EBANX) suportam PIX e Boleto. Gateways internacionais (Stripe, PayPal, Adyen) não suportam esses métodos nativos do Brasil.
>
> 🌎 **EBANX**: Gateway especializado em pagamentos internacionais para América Latina (7 países).
>
> 🏦 **Banco do Brasil**: Gateway bancário oficial do BB. Ideal para empresas que já possuem conta BB e precisam integrar PIX, boleto e transferências diretamente com o banco, sem intermediários. Exige certificado digital (mTLS) em produção e convênio para boletos — obtenha com seu gerente de relacionamento BB.
>
> 🏦 **Bank of America CashPro**: Gateway corporativo para operações bancárias nos EUA via Zelle, ACH e Wire. Ideal para fintechs que operam nos EUA e precisam receber e enviar dólares programaticamente. Requer conta BofA CashPro Online e licença Money Transmitter (FinCEN + estadual).
>
> 🟣 **NuBank (NuPay)**: Método de pagamento exclusivo para clientes Nubank via redirecionamento para o app. Não é PIX nem cartão — o cliente confirma com a senha de 4 dígitos diretamente no app Nubank. Requer cadastro no [NuPay for Business](https://nupaybusiness.com.br). Use `createPayment()` em vez de `createPixPayment()`.
>
> 🏦 **Itaú Unibanco**: Gateway bancário oficial do Itaú. Ideal para empresas que já possuem conta Itaú e precisam integrar PIX, boleto e transferências diretamente com o banco, sem intermediários. Autenticação via OAuth 2.0 com renovação automática de token; certificado digital mTLS (ICP-Brasil) obrigatório em produção. Convênio necessário para emitir boletos — obtenha com seu gerente Itaú.

**📢 Quer contribuir?** Implemente seu próprio gateway! [Veja como →](docs/creating-gateway.md)

---

## 🎯 Por que Payment Hub?

```php
// ❌ Antes: código verboso e complexo
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, 'https://api.gateway.com/v1/payments');
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer xyz']);
// ... 20 linhas depois...

// ✅ Agora: simples e elegante
$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 100.00,
        customerEmail: 'cliente@email.com'
    )
);
```

### ✨ Características

- 🚀 **Zero configuração inicial** - comece testando com FakeBankGateway (sem APIs)
- 🧪 **FakeBankGateway incluído** - gateway simulado completo para desenvolvimento
- 🎨 **Type-safe** - PHP 8.3+ com tipos estritos
- 💰 **ValueObjects** - Money, CPF, CardNumber validados automaticamente
- 🔄 **Fácil migração** - troque de gateway sem alterar código
- 🇧🇷 **100% em português** - documentação e código
- 🛡️ **Pronto para produção** - validações robustas e tratamento de erros

### 🎯 Funcionalidades Completas

<table>
<tr>
<td width="50%">

**💳 Pagamentos**
- ✅ PIX (com QR Code)
- ✅ Cartão de Crédito (à vista/parcelado)
- ✅ Cartão de Débito
- ✅ Boleto Bancário
- ✅ Link de Pagamento

**💸 Operações Financeiras**
- ✅ Reembolsos (total/parcial)
- ✅ Split de Pagamento
- ✅ Transferências (PIX/TED)
- ✅ Agendamento de Transferências
- ✅ Antecipação de Recebíveis

**🔒 Gestão Avançada**
- ✅ Escrow (Custódia)
- ✅ Liberação Parcial/Total
- ✅ Cancelamento de Custódia

</td>
<td width="50%">

**🔁 Recorrência**
- ✅ Criar Assinaturas
- ✅ Cancelar/Suspender
- ✅ Reativar Assinatura
- ✅ Atualizar Dados

**🏢 Multi-tenant**
- ✅ Sub-contas (Marketplaces)
- ✅ Ativar/Desativar contas
- ✅ Gestão de Permissões

**👛 Wallets**
- ✅ Criar Carteiras
- ✅ Adicionar/Deduzir Saldo
- ✅ Transferir entre Wallets
- ✅ Consultar Saldo

**👤 Gestão de Clientes**
- ✅ Cadastrar Clientes
- ✅ Atualizar Dados
- ✅ Listar e Buscar

**🛡️ Segurança**
- ✅ Análise Antifraude
- ✅ Blacklist/Whitelist
- ✅ Webhooks
- ✅ Tokenização de Cartões

</td>
</tr>
</table>

---

## 📦 Instalação

```bash
composer require israel-nogueira/payment-hub
```

---

## ⚡ Início Rápido

### 1️⃣ Testando sem API (FakeBankGateway)

**🎯 O que é o FakeBankGateway?**

É um gateway de pagamento **simulado** que vem incluído na biblioteca. Ele:
- ✅ Funciona **offline** (sem internet)
- ✅ Não precisa de **credenciais ou API keys**
- ✅ Implementa **TODAS** as funcionalidades (PIX, cartões, boleto, etc.)
- ✅ Retorna dados **realistas** como se fosse uma API real
- ✅ Perfeito para **desenvolver e testar** sua aplicação

**💡 Use para:**
- Desenvolver sem depender de sandbox
- Criar testes automatizados confiáveis
- Validar fluxos de pagamento antes de ir para produção
- Demonstrações e protótipos

```php
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\FakeBankGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;

// Cria o hub com FakeBankGateway (NÃO precisa de API real!)
$hub = new PaymentHub(new FakeBankGateway());

// Faz um pagamento PIX de teste
$payment = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 150.00,
        customerName: 'João Silva',
        customerEmail: 'joao@email.com',
        description: 'Pedido #123'
    )
);

echo "✅ Pagamento criado: {$payment->transactionId}\n";
echo "💰 Valor: {$payment->getFormattedAmount()}\n";
echo "📊 Status: {$payment->getStatusLabel()}\n";

// Pega QR Code do PIX (funcionando mesmo offline!)
$qrCode = $hub->getPixQrCode($payment->transactionId);
```

**Saída:**
```
✅ Pagamento criado: FAKE_PIX_65a8b2c4d1e9f
💰 Valor: R$ 150,00
📊 Status: Aprovado
```

> 🚀 **Pronto!** Você já está processando pagamentos sem precisar de API. Quando quiser usar um gateway real, basta trocar `FakeBankGateway()` por `AsaasGateway()` ou outro.

---

## 💳 Exemplos Práticos

### PIX - O Mais Simples Possível

```php
// Pagamento PIX básico
$pix = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 50.00,
        customerEmail: 'cliente@email.com'
    )
);

// Pega o código copia-e-cola
$copiaECola = $hub->getPixCopyPaste($pix->transactionId);

// Exibe para o usuário
echo "Pague com este código PIX:\n{$copiaECola}";
```

### PIX com Expiração

```php
// PIX que expira em 30 minutos
$pix = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount: 250.00,
        customerEmail: 'cliente@email.com',
        expiresInMinutes: 30
    )
);
```

---

### 💳 Cartão de Crédito

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;

// Pagamento à vista
$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 299.90,
        cardNumber: '4111 1111 1111 1111',
        cardHolderName: 'MARIA SILVA',
        cardExpiryMonth: '12',
        cardExpiryYear: '2028',
        cardCvv: '123'
    )
);

// Parcelado em 3x
$payment = $hub->createCreditCardPayment(
    CreditCardPaymentRequest::create(
        amount: 899.90,
        cardNumber: '5555 5555 5555 4444',
        cardHolderName: 'JOSE SANTOS',
        cardExpiryMonth: '08',
        cardExpiryYear: '2027',
        cardCvv: '321',
        installments: 3
    )
);

echo "💳 Cartão: {$payment->getCardBrand()}\n";
echo "💰 3x de R$ " . number_format(899.90/3, 2, ',', '.') . "\n";
```

---

### 📄 Boleto

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

// Pega a URL do boleto em PDF
$urlPdf = $hub->getBoletoUrl($boleto->transactionId);

echo "📄 Boleto gerado!\n";
echo "🔗 Download: {$urlPdf}\n";
echo "📅 Vencimento: 15/03/2025\n";
```

---

### 🏦 PIX + Boleto com Banco do Brasil

```php
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway;

$gateway = new BancoDoBrasilGateway(
    clientId:        $_ENV['BB_CLIENT_ID'],
    clientSecret:    $_ENV['BB_CLIENT_SECRET'],
    developerAppKey: $_ENV['BB_APP_KEY'],
    pixKey:          'sua-chave@pix.com',
    convenio:        (int) $_ENV['BB_CONVENIO'],
    carteira:        17,
    variacaoCarteira: 35,
    sandbox:         true,
);

$hub = new PaymentHub($gateway);

// PIX — QR Code Dinâmico
$pix = $hub->createPixPayment(
    PixPaymentRequest::create(
        amount:           150.00,
        customerName:     'Maria Silva',
        customerDocument: '123.456.789-00',
        description:      'Pedido #1234',
    )
);
echo $pix->metadata['pixCopiaECola']; // código Copia e Cola
echo $pix->metadata['location'];      // URL do QR Code

// Boleto Híbrido (Boleto + PIX no mesmo título)
$boleto = $hub->createBoleto(
    BoletoPaymentRequest::create(
        amount:           299.90,
        customerName:     'João Pereira',
        customerDocument: '987.654.321-00',
        dueDate:          date('Y-m-d', strtotime('+5 days')),
        metadata: [
            'nossoNumero' => '0000000042', // sequencial único — obrigatório em produção
            'address'     => 'Rua das Flores, 123',
            'city'        => 'São Paulo',
            'state'       => 'SP',
            'zipCode'     => '01310-100',
            'hibrido'     => true,         // ativa Boleto + PIX no mesmo título
        ],
    )
);
echo $boleto->metadata['linhaDigitavel'];
echo $boleto->metadata['pixCopiaECola'];

// Transferência (roteamento automático PIX ou TED)
$transfer = $gateway->transfer(new TransferRequest(
    amount:          200.00,
    beneficiaryName: 'Carlos Mendes',
    description:     'Pagamento fornecedor',
    metadata:        ['pixKey' => 'carlos@email.com'], // omita para TED
));
```

> ⚠️ **Em produção** o BB exige certificado digital mTLS (`certPath`) registrado no [Portal Developers BB](https://app.developers.bb.com.br). Sem ele a API retorna HTTP 503.

---

## 🚀 Funcionalidades Avançadas

### 🔁 Assinaturas Recorrentes

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;

// Criar assinatura mensal
$subscription = $hub->createSubscription(
    SubscriptionRequest::create(
        amount: 49.90,
        interval: 'monthly',
        customerId: 'cust_123',
        cardToken: 'tok_456',
        description: 'Plano Premium',
        trialDays: 7 // 7 dias grátis
    )
);

echo "🔁 Assinatura criada: {$subscription->subscriptionId}\n";
```

### 💸 Split de Pagamento (Marketplaces)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;

// Dividir pagamento entre vendedor e marketplace
$payment = $hub->createSplitPayment(
    SplitPaymentRequest::create(
        amount: 1000.00,
        splits: [
            ['recipient_id' => 'seller_1', 'amount' => 850.00],  // 85%
            ['recipient_id' => 'marketplace', 'amount' => 150.00] // 15%
        ],
        paymentMethod: 'credit_card'
    )
);
```

### 🔒 Escrow (Custódia)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;

// Segurar valor em custódia por 7 dias
$escrow = $hub->holdInEscrow(
    EscrowRequest::create(
        amount: 500.00,
        recipientId: 'seller_123',
        holdDays: 7,
        description: 'Aguardando entrega'
    )
);

// Liberar quando produto for entregue
$release = $hub->releaseEscrow($escrow->escrowId);
```

### 👛 Wallets (Carteiras Digitais)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;

// Criar carteira
$wallet = $hub->createWallet(
    WalletRequest::create(
        userId: 'user_123',
        currency: 'BRL'
    )
);

// Adicionar saldo
$hub->addBalance($wallet->walletId, 100.00);

// Transferir entre carteiras
$transfer = $hub->transferBetweenWallets(
    fromWalletId: 'wallet_abc',
    toWalletId: 'wallet_xyz',
    amount: 50.00
);
```

### 🏢 Sub-contas (Multi-tenant)

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;

// Criar sub-conta para vendedor
$subAccount = $hub->createSubAccount(
    SubAccountRequest::create(
        name: 'Loja do João',
        document: '12.345.678/0001-90',
        email: 'joao@loja.com',
        type: 'seller'
    )
);

echo "🏢 Sub-conta criada: {$subAccount->subAccountId}\n";
```

### 💰 Reembolsos

```php
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

// Reembolso total
$refund = $hub->refund(
    RefundRequest::create(
        transactionId: 'txn_123',
        reason: 'Cliente solicitou cancelamento'
    )
);

// Reembolso parcial
$partialRefund = $hub->partialRefund(
    transactionId: 'txn_456',
    amount: 50.00
);
```

---

## 🔄 Mudando para Gateway Real

Quando estiver pronto, **troque apenas 1 linha**:

```php
// Era assim (fake):
$hub = new PaymentHub(new FakeBankGateway());

// Agora é assim (Asaas):
$hub = new PaymentHub(new AsaasGateway(
    apiKey: 'sua-api-key-aqui',
    sandbox: true
));

// Ou com Pagar.me:
$hub = new PaymentHub(new PagarMeGateway(
    secretKey: 'sk_test_xxxxxxxxxxxxxx',
    publicKey: 'pk_test_xxxxxxxxxxxxxx',
    sandbox: true
));

// Ou com Banco do Brasil (conta BB + convênio):
$hub = new PaymentHub(new BancoDoBrasilGateway(
    clientId:        $_ENV['BB_CLIENT_ID'],
    clientSecret:    $_ENV['BB_CLIENT_SECRET'],
    developerAppKey: $_ENV['BB_APP_KEY'],
    pixKey:          $_ENV['BB_PIX_KEY'],
    convenio:        (int) $_ENV['BB_CONVENIO'],
    sandbox:         false,
    certPath:        '/etc/ssl/bb/cert.pem', // obrigatório em produção
));

// Ou com NuPay (botão "Pagar com Nubank"):
$hub = new PaymentHub(new NuBankGateway(
    merchantKey:   $_ENV['NUPAY_MERCHANT_KEY'],
    merchantToken: $_ENV['NUPAY_MERCHANT_TOKEN'],
    sandbox:       true,
    merchantName:  'Minha Loja',
    callbackUrl:   'https://minha-loja.com/nupay/notify',
));
// ⚠️ NuPay usa createPayment() — não createPixPayment()
// A resposta contém rawResponse['_paymentUrl'] → redirecione o cliente para lá

// Todo o resto do código continua igual! 🎉
```

[🔝 Ver todos os gateways suportados](#-gateways-suportados)

---

## 🎨 ValueObjects - Validação Automática

```php
// CPF é validado automaticamente
$request = PixPaymentRequest::create(
    amount: 100.00,
    customerDocument: '123.456.789-00' // ✅ Válido
);

// ❌ Lança InvalidDocumentException
$request = PixPaymentRequest::create(
    amount: 100.00,
    customerDocument: '000.000.000-00' // CPF inválido
);

// Cartões validam Luhn automaticamente
$request = CreditCardPaymentRequest::create(
    amount: 100.00,
    cardNumber: '4111 1111 1111 1111' // ✅ Válido
);

// Money previne valores negativos
$money = Money::from(-50.00); // ❌ InvalidAmountException
```

---

## 📚 Documentação Completa

- 📖 [Conceitos Principais](docs/core-concepts.md)
- 💳 [Pagamentos com Cartão](docs/credit-card.md)
- 💰 [PIX](docs/pix.md)
- 📄 [Boleto](docs/boleto.md)
- 🔁 [Assinaturas](docs/subscriptions.md)
- 💸 [Split de Pagamento](docs/split-payments.md)
- 🎣 [Webhooks](docs/webhooks.md)
- 🏗️ [Criar Seu Próprio Gateway](docs/creating-gateway.md)
- ❓ [FAQ](docs/faq.md)

---

## 🧪 Testando

```bash
# Rodar todos os testes
composer test

# Com cobertura
composer test:coverage

# PHPStan (análise estática)
composer analyse
```

---

## 🤝 Contribuindo

Contribuições são muito bem-vindas! 

1. Fork o projeto
2. Crie sua feature branch (`git checkout -b feature/MinhaFeature`)
3. Commit suas mudanças (`git commit -m 'Add: MinhaFeature'`)
4. Push para a branch (`git push origin feature/MinhaFeature`)
5. Abra um Pull Request

Veja [CONTRIBUTING.md](docs/contributing.md) para mais detalhes.

---

## 📄 Licença

Este projeto está sob a licença MIT. Veja [LICENSE](LICENSE) para mais detalhes.

---

## 💬 Suporte

- 📧 Email: contato@israelnogueira.com
- 🐛 Issues: [GitHub Issues](https://github.com/israel-nogueira/payment-hub/issues)
- 💬 Discussões: [GitHub Discussions](https://github.com/israel-nogueira/payment-hub/discussions)

---

<div align="center">

**Feito com ❤️ para a comunidade PHP brasileira** 🇧🇷

⭐ Se este projeto te ajudou, deixe uma estrela no GitHub!

</div>