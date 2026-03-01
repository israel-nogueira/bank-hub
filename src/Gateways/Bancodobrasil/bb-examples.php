<?php

/**
 * ═══════════════════════════════════════════════════════════
 *  EXEMPLOS DE USO — Banco do Brasil Gateway
 *  PaymentHub · github.com/israel-nogueira/payment-hub
 * ═══════════════════════════════════════════════════════════
 *
 * ANTES DE USAR:
 * 1. Cadastre-se em: https://app.developers.bb.com.br
 * 2. Crie uma aplicação e obtenha as credenciais
 * 3. Em sandbox, use as chaves de teste fornecidas pelo portal
 * 4. Em produção, cadastre o certificado digital no portal
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use IsraelNogueira\PaymentHub\PaymentHub;
use IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil\BancoDoBrasilGateway;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

// ─────────────────────────────────────────────────────────────
//  CONFIGURAÇÃO DO GATEWAY
// ─────────────────────────────────────────────────────────────

$gateway = new BancoDoBrasilGateway(
    clientId:         'seu-client-id-aqui',         // Portal Developers BB
    clientSecret:     'seu-client-secret-aqui',     // Portal Developers BB
    developerAppKey:  'sua-developer-app-key-aqui', // gw-dev-app-key (sandbox) / gw-app-key (produção)
    pixKey:           'sua-chave-pix@email.com',    // Chave PIX da conta BB
    convenio:         1234567,                       // Número do convênio de cobrança
    carteira:         17,                            // Carteira de cobrança
    variacaoCarteira: 35,                            // Variação da carteira
    agencia:          '0001',                        // Agência da conta
    conta:            '123456',                      // Número da conta
    sandbox:          true,                          // true = testes, false = produção
);

$hub = new PaymentHub($gateway);

// ─────────────────────────────────────────────────────────────
//  1. PIX — Cobrança Imediata (QR Code Dinâmico)
// ─────────────────────────────────────────────────────────────

echo "=== 1. PIX — COBRANÇA IMEDIATA ===\n\n";

try {
    $pixRequest = PixPaymentRequest::create(
        amount:           150.00,
        description:      'Pedido #1234 — Loja Online',
        customerName:     'Maria Silva',
        customerDocument: '123.456.789-00',
        customerEmail:    'maria@email.com',
        metadata: [
            'expiresIn' => 3600, // 1 hora (em segundos)
        ],
    );

    $pix = $hub->createPixPayment($pixRequest);

    echo "✅ Cobrança PIX criada!\n";
    echo "   TxID:           " . $pix->transactionId . "\n";
    echo "   Status:         " . $pix->status->value . "\n";
    echo "   Valor:          R$ " . number_format($pix->amount, 2, ',', '.') . "\n";
    echo "   PIX Copia&Cola: " . ($pix->metadata['pixCopiaECola'] ?? 'N/A') . "\n";
    echo "   Location:       " . ($pix->metadata['location'] ?? 'N/A') . "\n\n";

    // Consultar QR Code
    $qrCode = $hub->getPixQrCode($pix->transactionId);
    echo "   QR Code: " . substr($qrCode, 0, 50) . "...\n\n";

    // Consultar status
    $status = $hub->getTransactionStatus($pix->transactionId);
    echo "   Status atual: " . $status->status->value . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro PIX: " . $e->getMessage() . " (HTTP " . $e->getCode() . ")\n\n";
}

// ─────────────────────────────────────────────────────────────
//  2. BOLETO — Registro de Boleto Bancário
// ─────────────────────────────────────────────────────────────

echo "=== 2. BOLETO BANCÁRIO ===\n\n";

try {
    $boletoRequest = BoletoPaymentRequest::create(
        amount:           299.90,
        description:      'Mensalidade Janeiro/2025',
        customerName:     'João Pereira',
        customerDocument: '987.654.321-00',
        customerEmail:    'joao@email.com',
        customerPhone:    '11987654321',
        dueDate:          date('Y-m-d', strtotime('+5 days')),
        metadata: [
            'address'      => 'Rua das Flores, 123',
            'neighborhood' => 'Centro',
            'city'         => 'São Paulo',
            'cityCode'     => 3550308,
            'state'        => 'SP',
            'zipCode'      => '01310-100',
            'fine'         => 2.0,      // 2% ao mês de juros mora
            'discount'     => 10.00,    // R$ 10,00 de desconto até o vencimento
        ],
    );

    $boleto = $hub->createBoleto($boletoRequest);

    echo "✅ Boleto registrado!\n";
    echo "   Número:          " . $boleto->boletoId . "\n";
    echo "   Linha Digitável: " . $boleto->linhaDigitavel . "\n";
    echo "   Código de Barras:" . $boleto->barCode . "\n";
    echo "   URL do Boleto:   " . $boleto->boletoUrl . "\n";
    echo "   Vencimento:      " . $boleto->dueDate . "\n";
    echo "   Valor:           R$ " . number_format($boleto->amount, 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Boleto: " . $e->getMessage() . " (HTTP " . $e->getCode() . ")\n\n";
}

// ─────────────────────────────────────────────────────────────
//  3. BOLIX (Boleto + PIX)
// ─────────────────────────────────────────────────────────────

echo "=== 3. BOLIX (BOLETO + PIX) ===\n\n";

try {
    $boletoHibridoRequest = BoletoPaymentRequest::create(
        amount:           500.00,
        description:      'Fatura #789 — Serviços',
        customerName:     'Ana Costa',
        customerDocument: '12.345.678/0001-99',
        dueDate:          date('Y-m-d', strtotime('+7 days')),
        metadata: [
            'address'      => 'Av. Paulista, 1000',
            'neighborhood' => 'Bela Vista',
            'city'         => 'São Paulo',
            'cityCode'     => 3550308,
            'state'        => 'SP',
            'zipCode'      => '01310-200',
            'hibrido'      => true,     // ← ATIVA BOLETO + PIX NO MESMO TÍTULO!
        ],
    );

    $boletoHibrido = $hub->createBoleto($boletoHibridoRequest);

    echo "✅ Boleto Híbrido registrado!\n";
    echo "   Número:        " . $boletoHibrido->boletoId . "\n";
    echo "   URL do Boleto: " . $boletoHibrido->boletoUrl . "\n";
    echo "   PIX Copia&Cola:" . ($boletoHibrido->metadata['pixCopiaECola'] ?? 'N/A') . "\n";
    echo "   QR Code PIX:   " . ($boletoHibrido->metadata['qrCodePix'] ?? 'N/A') . "\n";
    echo "   Mensagem:      " . $boletoHibrido->message . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Boleto Híbrido: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  4. TRANSFERÊNCIA VIA PIX
// ─────────────────────────────────────────────────────────────

echo "=== 4. TRANSFERÊNCIA VIA PIX ===\n\n";

try {
    $pixTransferRequest = new TransferRequest(
        amount:              200.00,
        beneficiaryName:     'Carlos Mendes',
        beneficiaryDocument: '111.222.333-44',
        description:         'Pagamento de fornecedor',
        metadata: [
            'pixKey' => 'carlos@email.com', // Chave PIX do destinatário
        ],
    );

    $pixTransfer = $gateway->transfer($pixTransferRequest);

    echo "✅ PIX enviado!\n";
    echo "   ID:      " . $pixTransfer->transferId . "\n";
    echo "   Status:  " . $pixTransfer->status . "\n";
    echo "   Valor:   R$ " . number_format($pixTransfer->amount, 2, ',', '.') . "\n";
    echo "   Mensagem:" . $pixTransfer->message . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Transferência PIX: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  5. TRANSFERÊNCIA VIA TED
// ─────────────────────────────────────────────────────────────

echo "=== 5. TRANSFERÊNCIA VIA TED ===\n\n";

try {
    $tedRequest = new TransferRequest(
        amount:              1500.00,
        beneficiaryName:     'Empresa XYZ Ltda',
        beneficiaryDocument: '12.345.678/0001-99',
        description:         'Pagamento de serviços - NF 001234',
        bankCode:            '237',    // Código do banco (237 = Bradesco)
        agency:              '1234',   // Agência do destinatário
        account:             '56789',  // Conta do destinatário
        accountDigit:        '0',      // Dígito da conta
        accountType:         'checking',
    );

    $ted = $gateway->transfer($tedRequest);

    echo "✅ TED enviada!\n";
    echo "   ID:      " . $ted->transferId . "\n";
    echo "   Status:  " . $ted->status . "\n";
    echo "   Valor:   R$ " . number_format($ted->amount, 2, ',', '.') . "\n";
    echo "   Mensagem:" . $ted->message . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro TED: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  6. TRANSFERÊNCIA AGENDADA
// ─────────────────────────────────────────────────────────────

echo "=== 6. TRANSFERÊNCIA AGENDADA ===\n\n";

try {
    $agendamentoRequest = new TransferRequest(
        amount:              500.00,
        beneficiaryName:     'Fornecedor ABC',
        beneficiaryDocument: '98.765.432/0001-11',
        description:         'Pagamento agendado - fatura 999',
        metadata: [
            'pixKey' => '98765432000111', // CNPJ como chave PIX
        ],
    );

    $dataAgendamento = date('Y-m-d', strtotime('+3 days'));
    $agendado = $gateway->scheduleTransfer($agendamentoRequest, $dataAgendamento);

    echo "✅ Transferência agendada!\n";
    echo "   ID:      " . $agendado->transferId . "\n";
    echo "   Data:    " . $dataAgendamento . "\n";
    echo "   Valor:   R$ " . number_format($agendado->amount, 2, ',', '.') . "\n";
    echo "   Mensagem:" . $agendado->message . "\n\n";

    // Cancelar o agendamento
    $cancelado = $gateway->cancelScheduledTransfer($agendado->transferId);
    echo "   ✅ Agendamento cancelado: " . ($cancelado ? 'Sim' : 'Não') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Agendamento: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  7. CONSULTA DE SALDO E EXTRATO
// ─────────────────────────────────────────────────────────────

echo "=== 7. SALDO E EXTRATO ===\n\n";

try {
    // Saldo da conta
    $saldo = $hub->getBalance();

    echo "✅ Saldo da conta:\n";
    echo "   Disponível: R$ " . number_format($saldo->availableBalance, 2, ',', '.') . "\n";
    echo "   Total:      R$ " . number_format($saldo->totalBalance, 2, ',', '.') . "\n";
    echo "   Bloqueado Judicial:       R$ " . number_format($saldo->metadata['bloqueado_judicial'] ?? 0, 2, ',', '.') . "\n";
    echo "   Bloqueado Administrativo: R$ " . number_format($saldo->metadata['bloqueado_administrativo'] ?? 0, 2, ',', '.') . "\n\n";

    // Extrato do último mês
	$extrato = $gateway->getStatement(new DateTime('-30 days'), new DateTime());
	
    echo "✅ Extrato dos últimos 30 dias (" . count($extrato) . " lançamentos):\n";

	foreach (array_slice($extrato['lancamentos'], 0, 5) as $lancamento) { 
        $valor = isset($lancamento['creditoDebito'])
            ? ($lancamento['creditoDebito'] === 'C' ? '+ ' : '- ')
            : '';
        echo "   [{$lancamento['data']}] {$valor}R$ " .
             number_format($lancamento['valor'] ?? 0, 2, ',', '.') .
             " — " . ($lancamento['descricao'] ?? 'Sem descrição') . "\n";
    }
    echo "\n";

} catch (GatewayException $e) {
    echo "❌ Erro Saldo/Extrato: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  8. WEBHOOKS
// ─────────────────────────────────────────────────────────────

echo "=== 8. WEBHOOKS ===\n\n";

try {
    // Registrar webhook para PIX e Boleto
    $webhook = $gateway->registerWebhook(
        'https://seusite.com/webhooks/bb',
        ['pix', 'boleto']
    );

    echo "✅ Webhook registrado!\n";
    echo "   ID:  " . $webhook->webhookId . "\n";
    echo "   URL: " . $webhook->url . "\n\n";

    // Listar webhooks
    $webhooks = $gateway->listWebhooks();
    echo "✅ Webhooks registrados: " . count($webhooks) . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Webhook: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  9. ESTORNO PIX
// ─────────────────────────────────────────────────────────────

echo "=== 9. ESTORNO PIX ===\n\n";

try {
    use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;

    $estornoRequest = new RefundRequest(
        transactionId: 'E00038166202501141052152649956', // E2EId da transação original
        amount:        50.00,
        reason:        'Produto devolvido pelo cliente',
        metadata: [
            'e2eId' => 'E00038166202501141052152649956',
        ],
    );

    $estorno = $hub->refund($estornoRequest);

    echo "✅ Estorno solicitado!\n";
    echo "   ID:     " . $estorno->refundId . "\n";
    echo "   Status: " . $estorno->status . "\n";
    echo "   Valor:  R$ " . number_format($estorno->amount, 2, ',', '.') . "\n\n";

} catch (GatewayException $e) {
    echo "❌ Erro Estorno: " . $e->getMessage() . "\n\n";
}

// ─────────────────────────────────────────────────────────────
//  10. LISTAR COBRANÇAS PIX
// ─────────────────────────────────────────────────────────────

echo "=== 10. LISTAR COBRANÇAS PIX ===\n\n";

try {
    $cobranças = $gateway->listTransactions([
        'inicio' => (new DateTime('-7 days'))->format(DateTime::RFC3339),
        'fim'    => (new DateTime())->format(DateTime::RFC3339),
        'status' => 'ATIVA',
    ]);

    echo "✅ Cobranças PIX ativas nos últimos 7 dias: " . count($cobranças) . "\n";
    foreach (array_slice($cobranças, 0, 3) as $cob) {
        echo "   TxID: " . ($cob['txid'] ?? 'N/A') .
             " | Valor: R$ " . number_format($cob['valor']['original'] ?? 0, 2, ',', '.') .
             " | Status: " . ($cob['status'] ?? 'N/A') . "\n";
    }
    echo "\n";

} catch (GatewayException $e) {
    echo "❌ Erro Listagem: " . $e->getMessage() . "\n\n";
}

echo "═══════════════════════════════════════════════\n";
echo "  Exemplos BB Gateway concluídos!\n";
echo "  Portal: https://app.developers.bb.com.br\n";
echo "  Docs:   https://developers.bb.com.br\n";
echo "═══════════════════════════════════════════════\n";
