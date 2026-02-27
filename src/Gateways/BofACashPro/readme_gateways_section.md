# 📋 readme.md — Trecho atualizado: Seção "Gateways Suportados"

> **Como usar:** Abra o `readme.md` do projeto e substitua toda a seção
> `## 🚀 Gateways Suportados` até a linha `**📢 Quer contribuir?**`
> pelo conteúdo abaixo.

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
| 🏦 **Bank of America CashPro** | ✅ Pronto | Zelle (instantâneo, 24/7), ACH Same-Day, ACH Standard, Wire/Fedwire — roteamento automático por valor, webhooks Push Notification, agendamento ACH, cancelamento, saldo e extrato | [📖 Docs](src/Gateways/BofACashPro/readme.md) |

> 🧪 **FakeBankGateway**: Gateway simulado completo que funciona **SEM internet, SEM API keys, SEM sandbox**. Use para desenvolver toda sua aplicação localmente e só conecte com APIs reais quando estiver pronto para produção!
>
> 📝 **Nota**: Gateways brasileiros (Asaas, Pagar.me, C6 Bank, MercadoPago, PagSeguro, EBANX) suportam PIX e Boleto. Gateways internacionais (Stripe, PayPal, Adyen) não suportam esses métodos nativos do Brasil.
>
> 🌎 **EBANX**: Gateway especializado em pagamentos internacionais para América Latina (7 países).
>
> 🏦 **Bank of America CashPro**: Gateway corporativo para operações bancárias nos EUA via Zelle, ACH e Wire. Ideal para fintechs que operam nos EUA e precisam receber e enviar dólares programaticamente. Requer conta BofA CashPro Online e licença Money Transmitter (FinCEN + estadual).

**📢 Quer contribuir?** Implemente seu próprio gateway! [Veja como →](docs/creating-gateway.md)

