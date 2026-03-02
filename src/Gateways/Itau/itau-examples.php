<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║         ITAÚ GATEWAY — EXEMPLOS COMPLETOS               ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Exemplos práticos de uso do ItauGateway no PaymentHub.
 * Configure suas credenciais antes de executar.
 *
 * Execução:
 *   php src/Gateways/Itau/itau-examples.php
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use IsraelNogueira\PaymentHub\Gateways\Itau\ItauGateway;
use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
use DateTime;

// ══════════════════════════════════════════════════════════════
//  CONFIGURAÇÃO
// ══════════════════════════════════════════════════════════════

$gateway = new ItauGateway(
    clientId:     'seu_client_id_aqui',
    clientSecret: 'seu_client_secret_aqui',
    sandbox:      true,
    pixKey:       'empresa@itau.com.br',   // Sua chave PIX cadastrada
    convenio:     '12345',                  // Convênio de cobrança (boletos)
    // Em produção, adicione:
    // certPath:     '/path/to/certificado.pfx',
    // certPassword: 'senha_do_certificado',
);

$hub = new PaymentHub($gateway);

echo str_repeat('═', 70) . "\n";
echo "   ITAÚ GATEWAY — EXEMPLOS DE USO\n";
echo str_repeat('═', 70) . "\n\n";

// ══════════════════════════════════════════════════════════════
//  1. COBRANÇA PIX
// ══════════════════════════════════════════════════════════════

echo "=== 1. COBRANÇA PIX ===\n\n";

$txid = null;

try {
    $pixRequest = new PixPaymentRequest(
        amount:           150.75,
        currency:         'BRL',
        customerName:     'Maria Silva Santos',
        customerDocument: '12345678909',
        customerEmail:    'maria@example.com',
        description:      'Pedido #12345 — Loja Online',
        metadata: [
            'expiracao'      => 3600,
            'infoAdicionais' => [
                ['nome' => 'Pedido', 'valor' => '12345'],
                ['nome' => 'Loja',   'valor' => 'Minha Loja'],
            ],
        ],
    );

    $pix = $hub->createPixPayment($pixRequest);
    $txid = $pix->transactionId;

    echo "✅ PIX criado!\n";
    echo "   txid:     " . $pix->transactionId . "\n";
    echo "   Status:   " . $pix->status->value . "\n";
    echo "   Valor:    R$ " . number_format($pix->money->amount(), 2, ',', '.') . "\n";
    echo "   Mensagem: " . $pix->message . "\n";
    echo "   Location: " . ($pix->metadata['location'] ?? 'N/A') . "\n\n";

    // QR Code e Copia & Cola
    $qrCode    = $hub->getPixQrCode($txid);
    $copyPaste = $hub->getPixCopyPaste($txid);

    echo "✅ QR Code obtido! (primeiros 60 chars)\n";
    echo "   " . substr($qrCode, 0, 60) . "...\n\n";

    echo "✅ Copia e Cola:\n";
    echo "   " . substr($copyPaste, 0, 80) . "...\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro PIX: " . $e->getMessage() . " (HTTP " . $e->getCode() . ")\n\n";
}

// ══════════════════════════════════════════════════════════════
//  2. CONSULTAR STATUS DA TRANSAÇÃO
// ══════════════════════════════════════════════════════════════

echo "=== 2. STATUS DA TRANSAÇÃO ===\n\n";

try {
    $status = $hub->getTransactionStatus($txid ?? 'txid_exemplo_123');

    echo "✅ Status consultado!\n";
    echo "   txid:   " . $status->transactionId . "\n";
    echo "   Status: " . $status->status->value . "\n";
    echo "   Valor:  R$ " . number_format($status->money->amount(), 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Status: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  3. ESTORNO PIX (DEVOLUÇÃO)
// ══════════════════════════════════════════════════════════════

echo "=== 3. ESTORNO PIX ===\n\n";

try {
    // Estorno total
    $estornoRequest = new RefundRequest(
        transactionId: 'E00341934302501151234567890123',
        amount:        150.75,
        reason:        'Produto fora de estoque — pedido cancelado',
        metadata: [
            'e2eId' => 'E00341934302501151234567890123',
        ],
    );

    $estorno = $hub->refund($estornoRequest);

    echo "✅ Estorno solicitado!\n";
    echo "   ID:     " . $estorno->refundId . "\n";
    echo "   Status: " . $estorno->status->value . "\n";
    echo "   Valor:  R$ " . number_format($estorno->money->amount(), 2, ',', '.') . "\n\n";

    // Estorno parcial
    $estornoParcial = $hub->partialRefund(
        transactionId: 'E00341934302501151234567890456',
        amount:        50.00
    );

    echo "✅ Estorno parcial solicitado!\n";
    echo "   ID:     " . $estornoParcial->refundId . "\n";
    echo "   Valor:  R$ " . number_format($estornoParcial->money->amount(), 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Estorno: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  4. BOLETO BANCÁRIO
// ══════════════════════════════════════════════════════════════

echo "=== 4. BOLETO BANCÁRIO ===\n\n";

$boleto = null;

try {
    $boletoRequest = new BoletoPaymentRequest(
        amount:           350.00,
        currency:         'BRL',
        customerName:     'João Pedro Oliveira',
        customerDocument: '98765432100',
        customerEmail:    'joao@example.com',
        dueDate:          new DateTime('+5 days'),
        description:      'Fatura de serviços — Ref. Jan/2025',
        metadata: [
            'carteira'  => '109',
            'especie'   => 'DUPLICATA_MERCANTIL',
            'endereco'  => 'Av. Paulista, 1000',
            'bairro'    => 'Bela Vista',
            'cidade'    => 'São Paulo',
            'uf'        => 'SP',
            'cep'       => '01310100',
            'seuNumero' => 'NF-2025-001',
        ],
    );

    $boleto = $hub->createBoleto($boletoRequest);

    echo "✅ Boleto registrado!\n";
    echo "   Nosso Número:     " . $boleto->transactionId . "\n";
    echo "   Status:           " . $boleto->status->value . "\n";
    echo "   Valor:            R$ " . number_format($boleto->money->amount(), 2, ',', '.') . "\n";
    echo "   Linha Digitável:  " . ($boleto->metadata['linhaDigitavel'] ?? 'N/A') . "\n";
    echo "   Código de Barras: " . ($boleto->metadata['codigoBarras']   ?? 'N/A') . "\n\n";

    // URL do boleto para impressão
    $url = $hub->getBoletoUrl($boleto->transactionId);
    echo "✅ URL do Boleto:\n";
    echo "   " . $url . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Boleto: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  5. CANCELAR BOLETO
// ══════════════════════════════════════════════════════════════

echo "=== 5. CANCELAR BOLETO ===\n\n";

try {
    $cancelamento = $hub->cancelBoleto($boleto?->transactionId ?? '12345678901');

    echo "✅ Boleto cancelado!\n";
    echo "   Status: " . $cancelamento->status->value . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Cancelamento: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  6. TRANSFERÊNCIA VIA PIX
// ══════════════════════════════════════════════════════════════

echo "=== 6. TRANSFERÊNCIA VIA PIX ===\n\n";

try {
    // Por chave PIX
    $pixTransfer = $hub->transfer(new TransferRequest(
        amount:        200.00,
        recipientName: 'Carlos Eduardo Mendes',
        description:   'Pagamento de fornecedor — Ref. 2025/001',
        metadata: [
            'pixKey' => 'carlos@email.com',
        ],
    ));

    echo "✅ PIX enviado!\n";
    echo "   EndToEndId: " . $pixTransfer->transferId . "\n";
    echo "   Status:     " . $pixTransfer->status->value . "\n";
    echo "   Valor:      R$ " . number_format($pixTransfer->money->amount(), 2, ',', '.') . "\n\n";

    // Por dados bancários (sem chave PIX)
    $pixTransferConta = $hub->transfer(new TransferRequest(
        amount:        500.00,
        recipientName: 'Ana Paula Costa',
        description:   'Reembolso de despesas',
        metadata: [
            'recipientDocument' => '11122233344',
            'bankCode'          => '341',
            'agency'            => '1234',
            'account'           => '56789-0',
            'accountType'       => 'corrente',
        ],
    ));

    echo "✅ PIX por dados bancários enviado!\n";
    echo "   ID:     " . $pixTransferConta->transferId . "\n";
    echo "   Status: " . $pixTransferConta->status->value . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Transferência PIX: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  7. TRANSFERÊNCIA VIA TED
// ══════════════════════════════════════════════════════════════

echo "=== 7. TRANSFERÊNCIA VIA TED ===\n\n";

try {
    $ted = $hub->transfer(new TransferRequest(
        amount:        1500.00,
        recipientName: 'Empresa XYZ Ltda',
        description:   'Pagamento de serviços — NF 2025-0042',
        metadata: [
            'method'            => 'ted',
            'recipientDocument' => '12345678000199',
            'bankCode'          => '237',
            'agency'            => '0001',
            'account'           => '123456-7',
            'accountType'       => 'corrente',
        ],
    ));

    echo "✅ TED enviada!\n";
    echo "   ID:     " . $ted->transferId . "\n";
    echo "   Status: " . $ted->status->value . "\n";
    echo "   Valor:  R$ " . number_format($ted->money->amount(), 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro TED: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  8. AGENDAR TRANSFERÊNCIA
// ══════════════════════════════════════════════════════════════

echo "=== 8. AGENDAR TRANSFERÊNCIA ===\n\n";

try {
    $agendamento = $hub->scheduleTransfer(
        new TransferRequest(
            amount:        800.00,
            recipientName: 'Luciana Ferreira',
            description:   'Salário — competência Fevereiro/2025',
            metadata: [
                'recipientDocument' => '55566677788',
                'bankCode'          => '341',
                'agency'            => '4567',
                'account'           => '99876-5',
                'accountType'       => 'corrente',
            ],
        ),
        date: '2025-02-05'
    );

    echo "✅ Transferência agendada!\n";
    echo "   ID:       " . $agendamento->transferId . "\n";
    echo "   Status:   " . $agendamento->status->value . "\n";
    echo "   Mensagem: " . $agendamento->message . "\n\n";

    // Cancelar agendamento
    $cancelAgendamento = $hub->cancelScheduledTransfer($agendamento->transferId);
    echo "✅ Agendamento cancelado!\n";
    echo "   Status: " . $cancelAgendamento->status->value . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Agendamento: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  9. SALDO DA CONTA CORRENTE
// ══════════════════════════════════════════════════════════════

echo "=== 9. SALDO DA CONTA ===\n\n";

try {
    $saldo = $hub->getBalance();

    echo "✅ Saldo consultado!\n";
    echo "   Saldo disponível: R$ " . number_format($saldo->availableBalance, 2, ',', '.') . "\n";
    echo "   Saldo bloqueado:  R$ " . number_format($saldo->pendingBalance, 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Saldo: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  10. EXTRATO DA CONTA
// ══════════════════════════════════════════════════════════════

echo "=== 10. EXTRATO ===\n\n";

try {
    $lancamentos = $hub->getSettlementSchedule([
        'dataInicio' => '2025-01-01',
        'dataFim'    => '2025-01-31',
        'pagina'     => 1,
    ]);

    echo "✅ Extrato obtido!\n";
    echo "   Lançamentos encontrados: " . count($lancamentos) . "\n";

    foreach (array_slice($lancamentos, 0, 5) as $lancamento) {
        $sinal = ($lancamento['tipo'] ?? 'C') === 'C' ? '+ ' : '- ';
        echo "   [{$lancamento['data']}] {$sinal}R$ "
            . number_format($lancamento['valor'] ?? 0, 2, ',', '.')
            . " — " . ($lancamento['descricao'] ?? 'Sem descrição') . "\n";
    }
    echo "\n";

} catch (GatewayException $e) {
    echo "❌ Erro Extrato: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  11. LISTAR COBRANÇAS PIX
// ══════════════════════════════════════════════════════════════

echo "=== 11. LISTAR COBRANÇAS PIX ===\n\n";

try {
    $cobranças = $hub->listTransactions([
        'inicio' => '2025-01-01T00:00:00Z',
        'fim'    => '2025-01-31T23:59:59Z',
        'cpf'    => '12345678909',
    ]);

    echo "✅ Cobranças encontradas: " . count($cobranças) . "\n";
    foreach (array_slice($cobranças, 0, 3) as $cob) {
        echo "   txid: " . ($cob['txid'] ?? 'N/A')
            . " | Status: " . ($cob['status'] ?? 'N/A')
            . " | Valor: R$ " . number_format($cob['valor']['original'] ?? 0, 2, ',', '.') . "\n";
    }
    echo "\n";

} catch (GatewayException $e) {
    echo "❌ Erro Listagem: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  12. WEBHOOKS
// ══════════════════════════════════════════════════════════════

echo "=== 12. WEBHOOKS ===\n\n";

try {
    $webhook = $hub->registerWebhook(
        url:    'https://seusite.com/webhooks/itau',
        events: ['pix.recebido', 'pix.devolucao']
    );

    echo "✅ Webhook registrado!\n";
    echo "   ID:    " . $webhook['webhookId'] . "\n";
    echo "   URL:   " . $webhook['url'] . "\n";
    echo "   Chave: " . $webhook['chave'] . "\n\n";

    $webhooks = $hub->listWebhooks();
    echo "✅ Webhooks ativos: " . count($webhooks) . "\n\n";

    $hub->deleteWebhook($webhook['webhookId']);
    echo "✅ Webhook removido!\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Webhook: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  13. GESTÃO DE CLIENTES
// ══════════════════════════════════════════════════════════════

echo "=== 13. CLIENTES ===\n\n";

try {
    $customerRequest = new CustomerRequest(
        name:  'Fernanda Lima Souza',
        taxId: '44455566677',
        email: 'fernanda@example.com',
        phone: '11987654321',
    );

    $customer = $hub->createCustomer($customerRequest);

    echo "✅ Cliente criado!\n";
    echo "   ID:     " . $customer->customerId . "\n";
    echo "   Nome:   " . $customer->name . "\n";
    echo "   Email:  " . $customer->email . "\n";
    echo "   Status: " . $customer->status . "\n\n";

    $updated = $hub->updateCustomer($customer->customerId, [
        'email'    => 'fernanda.novo@example.com',
        'telefone' => '11987654321',
    ]);
    echo "✅ Cliente atualizado!\n";
    echo "   Novo e-mail: " . $updated->email . "\n\n";

    $found = $hub->getCustomer($customer->customerId);
    echo "✅ Cliente encontrado: " . $found->name . "\n\n";

    $lista = $hub->listCustomers(['pagina' => 1]);
    echo "✅ Clientes na conta: " . count($lista) . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Cliente: " . $e->getMessage() . "\n\n";
}

// ══════════════════════════════════════════════════════════════
//  14. MÉTODOS NÃO SUPORTADOS (comportamento esperado)
// ══════════════════════════════════════════════════════════════

echo "=== 14. MÉTODOS NÃO SUPORTADOS ===\n\n";

$unsupported = [
    'Cartão de Crédito' => static fn () => $hub->createCreditCardPayment(
        CreditCardPaymentRequest::create(
            amount:          100.00,
            cardNumber:      '4111111111111111',
            cardHolderName:  'Test',
            cardExpiryMonth: '12',
            cardExpiryYear:  '2026',
            cardCvv:         '123',
        )
    ),
    'Assinatura' => static fn () => $hub->createSubscription(
        SubscriptionRequest::create(
            amount:        29.90,
            interval:      'monthly',
            customerEmail: 'test@test.com',
            cardToken:     'tok_123',
        )
    ),
    'Split' => static fn () => $hub->createSplitPayment(
        SplitPaymentRequest::create(
            amount:        100.00,
            splits:        [],
            paymentMethod: 'pix',
        )
    ),
    'Escrow' => static fn () => $hub->holdInEscrow(
        EscrowRequest::create(
            amount:   100.00,
            currency: Currency::BRL,
        )
    ),
];

foreach ($unsupported as $label => $fn) {
    try {
        $fn();
        echo "⚠️  {$label}: deveria ter lançado GatewayException!\n";
    } catch (GatewayException $e) {
        echo "✅ {$label} (esperado): " . substr($e->getMessage(), 0, 70) . "\n";
    }
}

echo "\n";
echo str_repeat('═', 70) . "\n";
echo "   Exemplos concluídos!\n";
echo str_repeat('═', 70) . "\n";