# 🧪 Sistema de Testes Dinâmicos - Payment Hub

## 📁 Estrutura Criada

```
Tests/
├── Integration/
│   ├── GatewayTestCase.php              # ⭐ Classe base abstrata
│   ├── AllGatewaysDiscoveryTest.php     # 🔍 Teste dinâmico de descoberta
│   ├── FakeBankGatewayTest.php          # ✅ Teste existente
│   └── Gateways/
│       └── FakeBankGatewayTest.php      # ✅ Exemplo usando classe base
├── Fixtures/
│   └── RequestFixtures.php              # 🎯 Fixtures reutilizáveis
└── Unit/
    ├── DataObjects/
    └── ValueObjects/
```

## 🎯 Como Funciona

### 1. **GatewayTestCase.php** (Classe Base)
Classe abstrata que define uma bateria padrão de testes que TODOS os gateways devem passar:

**Testes implementados:**
- ✅ PIX (create, QR Code, Copy/Paste)
- ✅ Cartão de Crédito (create, tokenize, pre-auth)
- ✅ Cartão de Débito
- ✅ Boleto (create, URL)
- ✅ Assinaturas (create, cancel)
- ✅ Refund
- ✅ Customer (create)
- ✅ Payment Link
- ✅ Transaction Status
- ✅ Balance
- ✅ Webhooks

**Recursos:**
- Método `getSupportedMethods()` para customizar quais métodos o gateway suporta
- Testes são automaticamente pulados se o método não for suportado
- Mensagens de erro descritivas

### 2. **AllGatewaysDiscoveryTest.php** (Teste Dinâmico)
Escaneia automaticamente a pasta `src/Gateways/` e:

**Funcionalidades:**
- 🔍 Descobre todos os gateways automaticamente
- ✅ Valida se implementam `PaymentGatewayInterface`
- 📊 Verifica quais métodos estão implementados
- 📈 Gera relatório completo de cobertura
- 🎯 Não quebra se gateway não tiver todos os métodos (apenas reporta)

**Relatórios gerados:**
```
========================================
   GATEWAYS DESCOBERTOS: 11
========================================
✓ Asaas
✓ C6Bank
✓ Adyen
✓ FakeBank
✓ MercadoPago
...
========================================

========================================
   ANÁLISE DE MÉTODOS IMPLEMENTADOS
========================================
✓ FakeBank: 52/52 (100.0%)
✓ Asaas: 52/52 (100.0%)
⚠ Stripe: 45/52 (86.5%)
   Faltam: createPixPayment, getPixQrCode...
========================================
```

### 3. **RequestFixtures.php** (Fixtures Reutilizáveis)
Centraliza a criação de objetos de teste para evitar duplicação:

**Métodos disponíveis:**
```php
RequestFixtures::createPixPaymentRequest();
RequestFixtures::createCreditCardPaymentRequest();
RequestFixtures::createBoletoPaymentRequest();
RequestFixtures::createSubscriptionRequest();
RequestFixtures::createCustomerRequest();
RequestFixtures::getValidCardData();
RequestFixtures::getTestCardNumbers(); // Visa, Master, Amex, etc
RequestFixtures::getValidCPFs();
RequestFixtures::getValidCNPJs();
```

## 🚀 Como Usar

### Criar teste para um novo Gateway

```php
<?php

namespace IsraelNogueira\PaymentHub\Tests\Integration\Gateways;

use IsraelNogueira\PaymentHub\Tests\Integration\GatewayTestCase;
use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\Gateways\Asaas\AsaasGateway;

class AsaasGatewayTest extends GatewayTestCase
{
    protected function getGateway(): PaymentGatewayInterface
    {
        // Configurar gateway com credenciais de teste/sandbox
        return new AsaasGateway('fake_api_key', true);
    }

    // Opcional: customizar métodos suportados
    protected function getSupportedMethods(): array
    {
        return [
            'pix',
            'credit_card',
            'boleto',
            'subscription',
            'refund',
            'customer',
            'balance',
            'webhooks',
        ];
    }
}
```

**Pronto!** Todos os testes serão executados automaticamente. 🎉

### Executar testes

```bash
# Teste de descoberta (verifica todos os gateways)
vendor/bin/phpunit Tests/Integration/AllGatewaysDiscoveryTest.php --testdox

# Teste de um gateway específico
vendor/bin/phpunit Tests/Integration/Gateways/FakeBankGatewayTest.php --testdox

# Todos os testes de integração
vendor/bin/phpunit Tests/Integration/ --testdox

# Gerar relatório de cobertura
vendor/bin/phpunit --coverage-html coverage
```

## 💡 Vantagens do Sistema

### ✅ Padronização
- Todos os gateways seguem o mesmo padrão de testes
- Fácil adicionar novos gateways
- Garante consistência

### ✅ Manutenção
- Testes centralizados em uma classe base
- Mudanças propagam automaticamente
- Menos duplicação de código

### ✅ Descoberta Automática
- Não precisa registrar gateways manualmente
- Detecta automaticamente novos gateways
- Gera relatórios de cobertura

### ✅ Flexibilidade
- Cada gateway pode customizar métodos suportados
- Testes são pulados automaticamente se não suportados
- Fácil adicionar testes específicos

### ✅ Fixtures Reutilizáveis
- Objetos de teste padronizados
- Fácil criar variações com overrides
- Mantém testes limpos e legíveis

## 🎯 Próximos Passos

1. **Criar testes para cada gateway:**
   - AsaasGatewayTest
   - C6BankGatewayTest
   - MercadoPagoGatewayTest
   - etc.

2. **Aumentar cobertura:**
   - Testes de edge cases
   - Testes de erros
   - Testes de validação

3. **CI/CD:**
   - Configurar GitHub Actions
   - Rodar testes automaticamente
   - Gerar badges de cobertura

## 📊 Métricas Atuais

- **Gateways:** 11
- **Métodos por interface:** 52
- **Testes automatizados:** ~15 por gateway
- **Cobertura esperada:** 90%+

---

🚀 **Sistema pronto para uso!** Basta criar os testes específicos de cada gateway herdando de `GatewayTestCase`.
