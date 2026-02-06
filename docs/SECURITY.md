# 🔒 Política de Segurança

## Versões Suportadas

| Versão | Suportada          |
| ------ | ------------------ |
| 1.0.x  | :white_check_mark: |
| < 1.0  | :x:                |

## 🛡️ Compromisso com a Segurança

A segurança do Payment Hub e de seus usuários é nossa prioridade máxima. Levamos muito a sério todas as questões de segurança e agradecemos aos pesquisadores de segurança e usuários que reportam vulnerabilidades de forma responsável.

## 📢 Reportar uma Vulnerabilidade

Se você descobriu uma vulnerabilidade de segurança no Payment Hub, **NÃO crie uma issue pública**.

### Como Reportar

**Por favor, reporte vulnerabilidades de segurança para:**

📧 **Email:** contato@israelnogueira.com

### Informações a Incluir

Para nos ajudar a entender e resolver o problema rapidamente, por favor inclua:

1. **Descrição detalhada** da vulnerabilidade
2. **Passos para reproduzir** o problema
3. **Versão afetada** do Payment Hub
4. **Impacto potencial** da vulnerabilidade
5. **Sugestões de correção** (se houver)
6. **Seu nome/pseudônimo** (para créditos, se desejar)

### O que Esperar

1. **Confirmação inicial**: Responderemos em até **48 horas**
2. **Avaliação**: Avaliaremos o problema em até **5 dias úteis**
3. **Correção**: Trabalharemos para corrigir a vulnerabilidade o mais rápido possível
4. **Divulgação coordenada**: Coordenaremos a divulgação pública com você
5. **Créditos**: Daremos crédito apropriado (se desejar)

## 🔐 Boas Práticas de Segurança

### Para Desenvolvedores

#### 1. **Nunca exponha credenciais**
```php
// ❌ ERRADO
$hub = new PaymentHub(new AsaasGateway(
    apiKey: 'minha-chave-secreta-aqui'
));

// ✅ CORRETO
$hub = new PaymentHub(new AsaasGateway(
    apiKey: $_ENV['ASAAS_API_KEY']
));
```

#### 2. **Use variáveis de ambiente**
```bash
# .env
ASAAS_API_KEY=sua-chave-aqui
PAGARME_SECRET_KEY=sua-chave-secreta
```

#### 3. **Nunca commite credenciais**
```bash
# .gitignore
.env
.env.*
config/credentials.php
```

#### 4. **Use HTTPS em produção**
```php
// Sempre use SSL/TLS
$hub = new PaymentHub(new AsaasGateway(
    apiKey: $_ENV['ASAAS_API_KEY'],
    sandbox: false  // Produção sempre usa HTTPS
));
```

#### 5. **Valide dados do usuário**
```php
// ValueObjects fazem validação automática
$request = PixPaymentRequest::create(
    amount: $amount,  // Validado automaticamente
    customerDocument: $cpf,  // CPF validado
    customerEmail: $email  // Email validado
);
```

#### 6. **Trate erros adequadamente**
```php
try {
    $payment = $hub->createPixPayment($request);
} catch (GatewayException $e) {
    // NÃO exponha detalhes técnicos ao usuário
    $this->logger->error('Payment failed', [
        'error' => $e->getMessage(),
        'transaction' => $request->toArray()
    ]);
    
    // Mensagem genérica para o usuário
    return 'Erro ao processar pagamento. Tente novamente.';
}
```

#### 7. **Proteja webhooks**
```php
// Valide assinaturas de webhook
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!$this->validateWebhookSignature($payload, $signature)) {
    http_response_code(401);
    exit;
}
```

#### 8. **Limite tentativas de pagamento**
```php
// Implemente rate limiting
if ($this->hasTooManyAttempts($customerId)) {
    throw new TooManyAttemptsException();
}
```

### Para Usuários da Biblioteca

1. **Mantenha a biblioteca atualizada**
   ```bash
   composer update israel-nogueira/payment-hub
   ```

2. **Use sempre a versão estável**
   ```json
   {
     "require": {
       "israel-nogueira/payment-hub": "^1.0"
     }
   }
   ```

3. **Revise o CHANGELOG** antes de atualizar
   - Veja [CHANGELOG.md](CHANGELOG.md)

4. **Teste em ambiente sandbox** primeiro
   ```php
   $hub = new PaymentHub(new AsaasGateway(
       apiKey: $_ENV['ASAAS_API_KEY'],
       sandbox: true  // Teste primeiro!
   ));
   ```

## 🔍 Auditoria de Segurança

### Dependências

O Payment Hub tem **zero dependências externas** (exceto PSR-3 para logging), minimizando a superfície de ataque.

```json
"require": {
    "php": ">=8.3",
    "psr/log": "^3.0"
}
```

### Análise Estática

Usamos PHPStan nível 8 para análise estática:

```bash
composer analyse
```

### Testes de Segurança

```bash
# Rode os testes
composer test

# Com cobertura
composer test:coverage
```

## 🚨 Vulnerabilidades Conhecidas

### Versão 1.0.0
- Nenhuma vulnerabilidade conhecida

## 📜 Histórico de Segurança

### 2026-02-05 - v1.0.0
- ✅ Lançamento inicial
- ✅ Todas as validações implementadas
- ✅ Zero vulnerabilidades conhecidas

## 🙏 Agradecimentos

Agradecemos aos pesquisadores de segurança que ajudaram a tornar o Payment Hub mais seguro:

- *Seja o primeiro a contribuir!*

## 📚 Recursos Adicionais

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/pt_BR/security.php)
- [PCI DSS Compliance](https://www.pcisecuritystandards.org/)

## 📞 Contato

Para questões de segurança urgentes:
- 📧 Email: contato@israelnogueira.com
- 🐛 GitHub Issues (apenas para problemas não-sensíveis)

---

**Última atualização:** 2025-02-05
