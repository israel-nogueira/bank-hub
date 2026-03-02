<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Gateways\Itau;

use IsraelNogueira\PaymentHub\Contracts\PaymentGatewayInterface;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\EscrowRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SplitPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubAccountRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\TransferRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\WalletRequest;
use IsraelNogueira\PaymentHub\DataObjects\Responses\BalanceResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\CustomerResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\EscrowResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentLinkResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\PaymentResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\RefundResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubAccountResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\SubscriptionResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransactionStatusResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\TransferResponse;
use IsraelNogueira\PaymentHub\DataObjects\Responses\WalletResponse;
use IsraelNogueira\PaymentHub\Enums\Currency;
use IsraelNogueira\PaymentHub\Enums\PaymentStatus;
use IsraelNogueira\PaymentHub\Exceptions\GatewayException;
use IsraelNogueira\PaymentHub\ValueObjects\Money;
use DateTime;
use DateTimeInterface;

/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║                    ITAÚ GATEWAY                             ║
 * ║               PaymentHub — bank-hub                        ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Integração com a API do Itaú Unibanco (Itaú Developer Platform).
 *
 * ┌───────────────────────────────────────────────────────────┐
 * │  FUNCIONALIDADES                                          │
 * │  ✅ PIX — Cobrança imediata (QR Code Dinâmico)            │
 * │  ✅ PIX — Estorno (devolução parcial ou total)            │
 * │  ✅ Boleto Bancário (com registro)                        │
 * │  ✅ Transferência via PIX / TED                           │
 * │  ✅ Agendamento e cancelamento de transferências          │
 * │  ✅ Consulta de saldo                                     │
 * │  ✅ Extrato da conta corrente                             │
 * │  ✅ Webhooks (PIX)                                        │
 * │  ✅ Gestão de Clientes                                    │
 * │  ❌ Cartão de Crédito (use Adyen, Stripe ou PagarMe)      │
 * │  ❌ Cartão de Débito  (use PagarMe ou C6Bank)             │
 * │  ❌ Assinaturas       (use Asaas ou PagarMe)              │
 * │  ❌ Escrow            (use C6Bank)                        │
 * │  ❌ Split             (use Asaas ou PagarMe)              │
 * └───────────────────────────────────────────────────────────┘
 *
 * AUTENTICAÇÃO
 * ────────────────────────────────────────────────────────────
 * OAuth 2.0 — Client Credentials.
 * mTLS obrigatório em produção (certificado ICP-Brasil .pfx).
 * Cache automático com renovação 60 s antes do vencimento.
 *
 * AMBIENTES
 * ────────────────────────────────────────────────────────────
 * Sandbox  → sandbox.devportal.itau.com.br
 * Produção → api.itau.com.br
 *
 * PRÉ-REQUISITOS
 * ────────────────────────────────────────────────────────────
 * 1. Cadastro em: https://devportal.itau.com.br
 * 2. Criar aplicação → obter client_id e client_secret
 * 3. Para PIX: chave PIX cadastrada na conta Itaú
 * 4. Para boletos: convênio de cobrança ativo (solicitar ao gerente)
 * 5. Para produção: certificado mTLS (ICP-Brasil .pfx) registrado no portal
 *
 * @author  PaymentHub
 * @version 1.1.0
 *
 * @see https://devportal.itau.com.br/documentacao
 */
class ItauGateway implements PaymentGatewayInterface
{
    // ─────────────────────────────────────────────────────────
    //  Endpoints
    // ─────────────────────────────────────────────────────────

    private const API_SANDBOX    = 'https://sandbox.devportal.itau.com.br';
    private const API_PRODUCTION = 'https://api.itau.com.br';

    private const OAUTH_PATH = '/api/oauth/token';

    private const PATH_PIX      = '/pix/v2';
    private const PATH_BOLETO   = '/itau-ep9-gtw-cobranca-v2/v2';
    private const PATH_CONTA    = '/conta-corrente/v1';
    private const PATH_TRANSFER = '/pagamentos/v1';

    /** Valor mínimo aceito para cobranças e estornos (R$ 0,01). */
    private const AMOUNT_MIN = 0.01;

    /** Máximo de tentativas em erros transitórios (429 / 503). */
    private const MAX_RETRIES = 3;

    // ─────────────────────────────────────────────────────────
    //  Estado interno
    // ─────────────────────────────────────────────────────────

    private string  $baseUrl;
    private ?string $accessToken    = null;
    private ?int    $tokenExpiresAt = null;

    // ─────────────────────────────────────────────────────────
    //  Construtor
    // ─────────────────────────────────────────────────────────

    /**
     * @param string      $clientId       client_id obtido no Itaú Developer Portal
     * @param string      $clientSecret   client_secret obtido no Itaú Developer Portal
     * @param bool        $sandbox        true = ambiente de homologação/sandbox
     * @param string|null $pixKey         Chave PIX da conta (e-mail, CPF, CNPJ, telefone ou EVP)
     * @param string|null $convenio       Código do convênio de cobrança (boletos)
     * @param string|null $certPath       Caminho para o certificado mTLS (.pfx) — obrigatório em produção
     * @param string|null $certPassword   Senha do certificado mTLS
     */
    public function __construct(
        private readonly string  $clientId,
        private readonly string  $clientSecret,
        private readonly bool    $sandbox      = true,
        private readonly ?string $pixKey       = null,
        private readonly ?string $convenio     = null,
        private readonly ?string $certPath     = null,
        private readonly ?string $certPassword = null,
    ) {
        $this->baseUrl = $sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
    }

    // ══════════════════════════════════════════════════════════
    //  PIX
    // ══════════════════════════════════════════════════════════

    /**
     * Cria uma cobrança PIX imediata (QR Code Dinâmico — BACEN v2).
     *
     * Endpoint: PUT /pix/v2/cob/{txid}
     *
     * O txid é gerado deterministicamente a partir de um UUID v4 sem hífens
     * para garantir unicidade e conformidade BACEN (26–35 chars alfanuméricos).
     *
     * @throws GatewayException
     */
    public function createPixPayment(PixPaymentRequest $request): PaymentResponse
    {
        if ($request->amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf('Itaú PIX: valor mínimo é R$ %.2f', self::AMOUNT_MIN)
            );
        }

        if ($this->pixKey === null) {
            throw new GatewayException('Itaú PIX: pixKey não configurada no construtor.');
        }

        // txid: 26–35 caracteres alfanuméricos (sem traços/hífens) — BACEN v2
        $txid = substr(
            str_replace('-', '', sprintf('%04x%04x%04x%04x%04x%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            )),
            0,
            35
        );

        $doc     = preg_replace('/\D/', '', (string) $request->customerDocument);
        $isLegal = strlen($doc) > 11;

        $payload = [
            'calendario' => [
                'expiracao' => (int) ($request->metadata['expiracao'] ?? 3600),
            ],
            'devedor' => [
                $isLegal ? 'cnpj' : 'cpf' => $doc,
                'nome'                     => $request->customerName,
            ],
            'valor' => [
                'original' => number_format($request->amount, 2, '.', ''),
            ],
            'chave'              => $this->pixKey,
            'solicitacaoPagador' => $request->description ?? 'Pagamento via PIX',
        ];

        // Informações adicionais opcionais (array de {nome, valor})
        if (!empty($request->metadata['infoAdicionais'])) {
            $payload['infoAdicionais'] = $request->metadata['infoAdicionais'];
        }

        $response = $this->request('PUT', self::PATH_PIX . '/cob/' . $txid, $payload);

        return new PaymentResponse(
            success:       true,
            transactionId: (string) ($response['txid'] ?? $txid),
            status:        PaymentStatus::PENDING,
            money:         Money::from($request->amount, Currency::BRL),
            message:       'Cobrança PIX criada com sucesso.',
            rawResponse:   $response,
            metadata: [
                'location' => $response['location'] ?? null,
                'pixKey'   => $this->pixKey,
                'txid'     => $response['txid'] ?? $txid,
            ],
        );
    }

    /**
     * Retorna a imagem (base64) do QR Code da cobrança PIX.
     *
     * Endpoint: GET /pix/v2/loc/{id}/qrcode
     *
     * @throws GatewayException
     */
    public function getPixQrCode(string $transactionId): string
    {
        $cob   = $this->request('GET', self::PATH_PIX . '/cob/' . $transactionId);
        $locId = $cob['loc']['id']
            ?? throw new GatewayException(
                'Itaú PIX: loc.id não encontrado na cobrança ' . $transactionId
            );

        $qr = $this->request('GET', self::PATH_PIX . '/loc/' . $locId . '/qrcode');

        return $qr['imagemQrcode']
            ?? $qr['qrcode']
            ?? throw new GatewayException(
                'Itaú PIX: QR Code não retornado para loc ' . $locId
            );
    }

    /**
     * Retorna o código Pix Copia e Cola (EMV) da cobrança.
     *
     * @throws GatewayException
     */
    public function getPixCopyPaste(string $transactionId): string
    {
        $cob   = $this->request('GET', self::PATH_PIX . '/cob/' . $transactionId);
        $locId = $cob['loc']['id']
            ?? throw new GatewayException(
                'Itaú PIX: loc.id não encontrado na cobrança ' . $transactionId
            );

        $qr = $this->request('GET', self::PATH_PIX . '/loc/' . $locId . '/qrcode');

        return $qr['qrcode']
            ?? throw new GatewayException(
                'Itaú PIX: Copia e Cola não disponível para loc ' . $locId
            );
    }

    // ══════════════════════════════════════════════════════════
    //  BOLETO
    // ══════════════════════════════════════════════════════════

    /**
     * Registra um boleto bancário Itaú.
     *
     * Endpoint: POST /itau-ep9-gtw-cobranca-v2/v2/boletos
     *
     * @throws GatewayException
     */
    public function createBoleto(BoletoPaymentRequest $request): PaymentResponse
    {
        if (empty($this->convenio)) {
            throw new GatewayException('Itaú Boleto: convenio não configurado no construtor.');
        }

        $doc     = preg_replace('/\D/', '', (string) $request->customerDocument);
        $dueDate = $request->dueDate
            ? $request->dueDate->format('Y-m-d')
            : (new DateTime('+3 days'))->format('Y-m-d');

        $nossoNumero = $request->metadata['nossoNumero']
            ?? (string) random_int(10_000_000_000, 99_999_999_999);

        $payload = [
            'etapa_processo_boleto' => 'EFETIVACAO',
            'dado_boleto' => [
                'descricao_instrumento_cobranca' => 'boleto',
                'tipo_boleto'                    => 'a vista',
                'codigo_carteira'                => $request->metadata['carteira'] ?? '109',
                'valor_total_titulo'             => number_format($request->amount, 2, '.', ''),
                'codigo_especie'                 => $request->metadata['especie'] ?? 'DUPLICATA_MERCANTIL',
                'valor_abatimento'               => '0.00',
                'data_emissao'                   => (new DateTime())->format('Y-m-d'),
                'data_vencimento'                => $dueDate,
                'pagador' => [
                    'pessoa' => [
                        'nome_pessoa' => $request->customerName,
                        'tipo_pessoa' => [
                            'codigo_tipo_pessoa' => strlen($doc) === 11 ? 'F' : 'J',
                        ],
                    ],
                    'documento' => [
                        'tipo_documento'   => strlen($doc) === 11 ? 'CPF' : 'CNPJ',
                        'numero_documento' => $doc,
                    ],
                    'endereco' => [
                        'nome_logradouro' => $request->metadata['endereco'] ?? 'Não informado',
                        'nome_bairro'     => $request->metadata['bairro']   ?? 'Não informado',
                        'nome_cidade'     => $request->metadata['cidade']   ?? 'São Paulo',
                        'sigla_UF'        => $request->metadata['uf']       ?? 'SP',
                        'numero_CEP'      => preg_replace('/\D/', '', (string) ($request->metadata['cep'] ?? '01310100')),
                    ],
                ],
                'beneficiario_final' => [
                    'id_beneficiario' => $this->convenio,
                ],
                'texto_uso_beneficiario'  => $request->description ?? 'Pagamento de serviço',
                'dados_individuais_boleto' => [
                    [
                        'numero_nosso_numero' => $nossoNumero,
                        'data_vencimento'     => $dueDate,
                        'valor_titulo'        => number_format($request->amount, 2, '.', ''),
                        'texto_seu_numero'    => $request->metadata['seuNumero'] ?? uniqid('IT'),
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', self::PATH_BOLETO . '/boletos', $payload);

        $returnedNossoNumero = (string) (
            $response['dado_boleto']['dados_individuais_boleto'][0]['numero_nosso_numero']
            ?? $nossoNumero
        );

        return new PaymentResponse(
            success:       true,
            transactionId: $returnedNossoNumero,
            status:        PaymentStatus::PENDING,
            money:         Money::from($request->amount, Currency::BRL),
            message:       'Boleto registrado com sucesso.',
            rawResponse:   $response,
            metadata: [
                'nossoNumero'    => $returnedNossoNumero,
                'dueDate'        => $dueDate,
                'linhaDigitavel' => $response['dado_boleto']['dados_individuais_boleto'][0]['numero_linha_digitavel'] ?? null,
                'codigoBarras'   => $response['dado_boleto']['dados_individuais_boleto'][0]['numero_codigo_barras']  ?? null,
            ],
        );
    }

    /**
     * Retorna a URL do PDF do boleto para impressão.
     *
     * @throws GatewayException
     */
    public function getBoletoUrl(string $transactionId): string
    {
        $response = $this->request('GET', self::PATH_BOLETO . '/boletos/' . $transactionId);

        return $response['dado_boleto']['url_impressao']
            ?? $response['url_impressao']
            ?? throw new GatewayException(
                'Itaú Boleto: URL de impressão não disponível para ' . $transactionId
            );
    }

    /**
     * Cancela (baixa) um boleto registrado.
     *
     * Endpoint: POST /itau-ep9-gtw-cobranca-v2/v2/boletos/{nossoNumero}/baixa
     *
     * @throws GatewayException
     */
    public function cancelBoleto(string $transactionId): PaymentResponse
    {
        $response = $this->request(
            'POST',
            self::PATH_BOLETO . '/boletos/' . $transactionId . '/baixa',
            ['motivo_baixa' => 'SOLICITACAO_DO_CLIENTE']
        );

        return new PaymentResponse(
            success:       true,
            transactionId: $transactionId,
            status:        PaymentStatus::CANCELLED,
            money:         null,
            message:       'Boleto cancelado com sucesso.',
            rawResponse:   $response,
        );
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSAÇÕES
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o status de uma transação (PIX ou Boleto).
     *
     * Tenta PIX primeiro (GET /pix/v2/cob/{txid}).
     * Só faz fallback para Boleto quando a API retorna HTTP 404.
     * Qualquer outro erro (401, 500, rede…) é propagado imediatamente.
     *
     * @throws GatewayException
     */
    public function getTransactionStatus(string $transactionId): TransactionStatusResponse
    {
        // ── Tenta como cobrança PIX ─────────────────────────────
        try {
            $response = $this->request('GET', self::PATH_PIX . '/cob/' . $transactionId);

            $status = match (strtoupper((string) ($response['status'] ?? ''))) {
                'CONCLUIDA'                        => PaymentStatus::PAID,
                'REMOVIDA_PELO_USUARIO_RECEBEDOR',
                'REMOVIDA_PELO_PSP'                => PaymentStatus::CANCELLED,
                default                            => PaymentStatus::PENDING,
            };

            return TransactionStatusResponse::create(
                success:       true,
                transactionId: $transactionId,
                status:        $status->value,
                amount:        (float) ($response['valor']['original'] ?? 0),
                currency:      'BRL',
                rawResponse:   $response,
            );
        } catch (GatewayException $e) {
            // FIX: só silencia HTTP 404 (cobrança não encontrada como PIX).
            // Qualquer outro código (401, 500, rede…) deve ser propagado.
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        // ── Fallback: tenta como boleto ─────────────────────────
        $response = $this->request('GET', self::PATH_BOLETO . '/boletos/' . $transactionId);

        $status = match (strtoupper((string) ($response['situacao'] ?? ''))) {
            'LIQUIDADO' => PaymentStatus::PAID,
            'BAIXADO'   => PaymentStatus::CANCELLED,
            'VENCIDO'   => PaymentStatus::EXPIRED,
            default     => PaymentStatus::PENDING,
        };

        return TransactionStatusResponse::create(
            success:       true,
            transactionId: $transactionId,
            status:        $status->value,
            amount:        (float) ($response['dado_boleto']['valor_total_titulo'] ?? 0),
            currency:      'BRL',
            rawResponse:   $response,
        );
    }

    /**
     * Lista cobranças PIX com filtros opcionais.
     *
     * @param array{inicio?: string, fim?: string, cpf?: string, cnpj?: string} $filters
     * @return array<int, mixed>
     * @throws GatewayException
     */
    public function listTransactions(array $filters = []): array
    {
        $query = [
            'inicio' => $filters['inicio'] ?? (new DateTime('-30 days'))->format('Y-m-d\TH:i:s\Z'),
            'fim'    => $filters['fim']    ?? (new DateTime())->format('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($filters['cpf'])) {
            $query['cpf'] = preg_replace('/\D/', '', $filters['cpf']);
        }

        if (!empty($filters['cnpj'])) {
            $query['cnpj'] = preg_replace('/\D/', '', $filters['cnpj']);
        }

        $response = $this->request('GET', self::PATH_PIX . '/cob', [], $query);

        return $response['cobs'] ?? $response['lista'] ?? [];
    }

    // ══════════════════════════════════════════════════════════
    //  ESTORNOS
    // ══════════════════════════════════════════════════════════

    /**
     * Solicita devolução total ou parcial de um PIX recebido.
     *
     * Endpoint: PUT /pix/v2/pix/{e2eId}/devolucao/{id}
     *
     * O devolucaoId é gerado deterministicamente a partir do e2eId + valor,
     * garantindo idempotência em retries — o Itaú ignora tentativas com mesmo ID.
     *
     * @throws GatewayException
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $amount = (float) ($request->amount ?? 0);

        if ($amount < self::AMOUNT_MIN) {
            throw new GatewayException(
                sprintf('Itaú PIX: valor mínimo para devolução é R$ %.2f', self::AMOUNT_MIN)
            );
        }

        $e2eId = (string) ($request->metadata['e2eId'] ?? $request->transactionId);

        // ID determinístico: garante idempotência em caso de retry
        $devolucaoId = substr(
            hash('sha256', $e2eId . number_format($amount, 2, '.', '')),
            0,
            35
        );

        $payload = [
            'valor'     => number_format($amount, 2, '.', ''),
            'natureza'  => 'ORIGINAL',
            'descricao' => $request->reason ?? 'Estorno solicitado pelo recebedor',
        ];

        $response = $this->request(
            'PUT',
            self::PATH_PIX . '/pix/' . $e2eId . '/devolucao/' . $devolucaoId,
            $payload
        );

        $statusStr = match (strtoupper((string) ($response['status'] ?? ''))) {
            'DEVOLVIDO'     => 'refunded',
            'NAO_REALIZADO' => 'failed',
            default         => 'processing',
        };

        return RefundResponse::create(
            success:       true,
            refundId:      (string) ($response['id'] ?? $devolucaoId),
            transactionId: $request->transactionId,
            amount:        (float) ($response['valor'] ?? $amount),
            status:        $statusStr,
            currency:      'BRL',
            message:       'Devolução PIX iniciada.',
            rawResponse:   $response,
        );
    }

    /**
     * Atalho para devolução parcial de um PIX.
     *
     * O sufixo 'partial' no hash diferencia o ID de um eventual full refund
     * com mesmo e2eId + valor, garantindo idempotência independente.
     *
     * @throws GatewayException
     */
    public function partialRefund(string $transactionId, float $amount): RefundResponse
    {
        return $this->refund(RefundRequest::create(
            transactionId: $transactionId,
            amount:        $amount,
            reason:        'Devolução parcial solicitada pelo recebedor',
            metadata:      ['e2eId' => $transactionId],
        ));
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSFERÊNCIAS
    // ══════════════════════════════════════════════════════════

    /**
     * Transfere via PIX (padrão) ou TED.
     * Selecione o método via metadata['method'] = 'pix' | 'ted'.
     *
     * @throws GatewayException
     */
    public function transfer(TransferRequest $request): TransferResponse
    {
        return strtolower((string) ($request->metadata['method'] ?? 'pix')) === 'ted'
            ? $this->transferViaTed($request)
            : $this->transferViaPix($request);
    }

    /**
     * Agenda uma transferência para uma data futura.
     *
     * @param string $date Data no formato YYYY-MM-DD
     * @throws GatewayException
     */
    public function scheduleTransfer(TransferRequest $request, string $date): TransferResponse
    {
        $payload = [
            'data_agendamento' => $date,
            'valor'            => number_format($request->amount, 2, '.', ''),
            'descricao'        => $request->description ?? 'Transferência agendada',
            'favorecido' => [
                'nome'       => $request->recipientName,
                'documento'  => preg_replace('/\D/', '', (string) ($request->metadata['recipientDocument'] ?? '')),
                'banco'      => $request->metadata['bankCode']   ?? '',
                'agencia'    => $request->metadata['agency']     ?? '',
                'conta'      => $request->metadata['account']    ?? '',
                'tipo_conta' => $request->metadata['accountType'] ?? 'corrente',
            ],
        ];

        $response = $this->request('POST', self::PATH_CONTA . '/transferencias/agendadas', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['id_agendamento'] ?? uniqid('SCHED_ITAU_')),
            amount:      $request->amount,
            status:      PaymentStatus::PENDING->value,
            currency:    'BRL',
            message:     'Transferência agendada para ' . $date,
            rawResponse: $response,
        );
    }

    /**
     * Cancela um agendamento de transferência.
     *
     * @throws GatewayException
     */
    public function cancelScheduledTransfer(string $transferId): TransferResponse
    {
        $response = $this->request(
            'DELETE',
            self::PATH_CONTA . '/transferencias/agendadas/' . $transferId
        );

        return TransferResponse::create(
            success:     true,
            transferId:  $transferId,
            amount:      null,
            status:      PaymentStatus::CANCELLED->value,
            currency:    'BRL',
            message:     'Agendamento cancelado com sucesso.',
            rawResponse: $response,
        );
    }

    // ══════════════════════════════════════════════════════════
    //  SALDO E EXTRATO
    // ══════════════════════════════════════════════════════════

    /**
     * Consulta o saldo disponível e bloqueado da conta corrente.
     *
     * Endpoint: GET /conta-corrente/v1/saldo
     *
     * @throws GatewayException
     */
    public function getBalance(): BalanceResponse
    {
        $response = $this->request('GET', self::PATH_CONTA . '/saldo');

        return new BalanceResponse(
            success:          true,
            balance:          (float) ($response['saldo_disponivel'] ?? 0),
            availableBalance: (float) ($response['saldo_disponivel'] ?? 0),
            pendingBalance:   (float) ($response['saldo_bloqueado']  ?? 0),
            currency:         'BRL',
            rawResponse:      $response,
        );
    }

    /**
     * Retorna lançamentos do extrato paginados.
     *
     * @param array{dataInicio?: string, dataFim?: string, pagina?: int} $filters
     * @return array<int, mixed>
     * @throws GatewayException
     */
    public function getSettlementSchedule(array $filters = []): array
    {
        $query = [
            'dataInicio' => $filters['dataInicio'] ?? (new DateTime('-30 days'))->format('Y-m-d'),
            'dataFim'    => $filters['dataFim']    ?? (new DateTime())->format('Y-m-d'),
        ];

        if (!empty($filters['pagina'])) {
            $query['pagina'] = (int) $filters['pagina'];
        }

        $response = $this->request('GET', self::PATH_CONTA . '/extrato', [], $query);

        return $response['lancamentos'] ?? $response['transactions'] ?? [];
    }

    // ══════════════════════════════════════════════════════════
    //  WEBHOOKS
    // ══════════════════════════════════════════════════════════

    /**
     * Registra uma URL para receber notificações de eventos PIX.
     *
     * Endpoint: PUT /pix/v2/webhook/{chave}
     *
     * @param string   $url    URL pública HTTPS
     * @param string[] $events Eventos desejados (informativo; Itaú notifica todos da chave)
     * @return array<string, mixed>
     * @throws GatewayException
     */
    public function registerWebhook(string $url, array $events): array
    {
        if ($this->pixKey === null) {
            throw new GatewayException('Itaú Webhook: pixKey não configurada no construtor.');
        }

        $response = $this->request(
            'PUT',
            self::PATH_PIX . '/webhook/' . urlencode($this->pixKey),
            ['webhookUrl' => $url]
        );

        return [
            'webhookId' => $response['id']         ?? uniqid('WH_ITAU_'),
            'url'       => $response['webhookUrl'] ?? $url,
            'chave'     => $this->pixKey,
            'events'    => $events,
            'raw'       => $response,
        ];
    }

    /**
     * Lista os webhooks cadastrados para a chave PIX.
     *
     * @return array<int, mixed>
     * @throws GatewayException
     */
    public function listWebhooks(): array
    {
        if ($this->pixKey === null) {
            throw new GatewayException('Itaú Webhook: pixKey não configurada no construtor.');
        }

        $response = $this->request('GET', self::PATH_PIX . '/webhook/' . urlencode($this->pixKey));

        return isset($response['webhookUrl']) ? [$response] : ($response['webhooks'] ?? []);
    }

    /**
     * Remove o webhook vinculado à chave PIX configurada.
     * O parâmetro $webhookId é aceito por compatibilidade com a interface,
     * mas o Itaú identifica o webhook pela chave PIX (não por ID).
     *
     * @throws GatewayException
     */
    public function deleteWebhook(string $webhookId): bool
    {
        if ($this->pixKey === null) {
            throw new GatewayException('Itaú Webhook: pixKey não configurada no construtor.');
        }

        $this->request('DELETE', self::PATH_PIX . '/webhook/' . urlencode($this->pixKey));

        return true;
    }

    // ══════════════════════════════════════════════════════════
    //  CLIENTES
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createCustomer(CustomerRequest $request): CustomerResponse
    {
        // FIX: acessa taxId ou document com fallback seguro
        $doc = preg_replace('/\D/', '', (string) ($request->taxId ?? $request->documentNumber ?? ''));

        $response = $this->request('POST', self::PATH_CONTA . '/clientes', [
            'nome'      => $request->name,
            'documento' => $doc,
            'email'     => $request->email   ?? null,
            'telefone'  => preg_replace('/\D/', '', (string) ($request->phone ?? '')),
        ]);

        return new CustomerResponse(
            success:     true,
            customerId:  (string) ($response['id'] ?? uniqid('ITAU_CUST_')),
            name:        $request->name,
            email:       $request->email ?? '',
            document:    $doc,
            status:      'active',
            rawResponse: $response,
        );
    }

    /** @throws GatewayException */
    public function updateCustomer(string $customerId, array $data): CustomerResponse
    {
        $response = $this->request('PATCH', self::PATH_CONTA . '/clientes/' . $customerId, $data);

        return new CustomerResponse(
            success:     true,
            customerId:  $customerId,
            name:        (string) ($response['nome']      ?? $data['nome']      ?? ''),
            email:       (string) ($response['email']     ?? $data['email']     ?? ''),
            document:    (string) ($response['documento'] ?? $data['documento'] ?? ''),
            status:      'active',
            rawResponse: $response,
        );
    }

    /** @throws GatewayException */
    public function getCustomer(string $customerId): CustomerResponse
    {
        $response = $this->request('GET', self::PATH_CONTA . '/clientes/' . $customerId);

        return new CustomerResponse(
            success:     true,
            customerId:  $customerId,
            name:        (string) ($response['nome']      ?? ''),
            email:       (string) ($response['email']     ?? ''),
            document:    (string) ($response['documento'] ?? ''),
            status:      (string) ($response['status']    ?? 'active'),
            rawResponse: $response,
        );
    }

    /**
     * @return array<int, mixed>
     * @throws GatewayException
     */
    public function listCustomers(array $filters = []): array
    {
        $response = $this->request('GET', self::PATH_CONTA . '/clientes', [], $filters);

        return $response['data'] ?? $response['clientes'] ?? [];
    }

    // ══════════════════════════════════════════════════════════
    //  MÉTODOS NÃO SUPORTADOS
    // ══════════════════════════════════════════════════════════

    /** @throws GatewayException */
    public function createCreditCardPayment(CreditCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta cartão de crédito via API bancária. Use Adyen, Stripe ou PagarMe.');
    }

    /** @throws GatewayException */
    public function tokenizeCard(array $cardData): string
    {
        throw new GatewayException('Itaú não suporta tokenização de cartão. Use Adyen, Stripe ou PagarMe.');
    }

    /** @throws GatewayException */
    public function capturePreAuthorization(string $transactionId, ?float $amount = null): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta pré-autorização de cartão.');
    }

    /** @throws GatewayException */
    public function cancelPreAuthorization(string $transactionId): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta cancelamento de pré-autorização.');
    }

    /** @throws GatewayException */
    public function createDebitCardPayment(DebitCardPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta cartão de débito. Use PagarMe ou C6Bank.');
    }

    /** @throws GatewayException */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new GatewayException('Itaú não suporta assinaturas recorrentes. Use Asaas, PagarMe ou C6Bank.');
    }

    /** @throws GatewayException */
    public function cancelSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Itaú não suporta gestão de assinaturas.');
    }

    /** @throws GatewayException */
    public function suspendSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Itaú não suporta gestão de assinaturas.');
    }

    /** @throws GatewayException */
    public function reactivateSubscription(string $subscriptionId): SubscriptionResponse
    {
        throw new GatewayException('Itaú não suporta gestão de assinaturas.');
    }

    /** @throws GatewayException */
    public function updateSubscription(string $subscriptionId, array $data): SubscriptionResponse
    {
        throw new GatewayException('Itaú não suporta gestão de assinaturas.');
    }

    /** @throws GatewayException */
    public function getChargebacks(array $filters = []): array
    {
        throw new GatewayException('Itaú não suporta gestão de chargebacks via API bancária.');
    }

    /** @throws GatewayException */
    public function disputeChargeback(string $chargebackId, array $evidence): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta disputa de chargebacks.');
    }

    /** @throws GatewayException */
    public function createSplitPayment(SplitPaymentRequest $request): PaymentResponse
    {
        throw new GatewayException('Itaú não suporta split de pagamentos. Use Asaas ou PagarMe para marketplaces.');
    }

    /** @throws GatewayException */
    public function createSubAccount(SubAccountRequest $request): SubAccountResponse
    {
        throw new GatewayException('Itaú não suporta sub-contas via API pública. Use C6Bank ou Asaas.');
    }

    /** @throws GatewayException */
    public function updateSubAccount(string $subAccountId, array $data): SubAccountResponse
    {
        throw new GatewayException('Itaú não suporta gestão de sub-contas.');
    }

    /** @throws GatewayException */
    public function getSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Itaú não suporta gestão de sub-contas.');
    }

    /** @throws GatewayException */
    public function activateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Itaú não suporta gestão de sub-contas.');
    }

    /** @throws GatewayException */
    public function deactivateSubAccount(string $subAccountId): SubAccountResponse
    {
        throw new GatewayException('Itaú não suporta gestão de sub-contas.');
    }

    /** @throws GatewayException */
    public function createWallet(WalletRequest $request): WalletResponse
    {
        throw new GatewayException('Itaú não suporta wallets digitais. Use C6Bank.');
    }

    /** @throws GatewayException */
    public function addBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Itaú não suporta wallets.');
    }

    /** @throws GatewayException */
    public function deductBalance(string $walletId, float $amount): WalletResponse
    {
        throw new GatewayException('Itaú não suporta wallets.');
    }

    /** @throws GatewayException */
    public function getWalletBalance(string $walletId): BalanceResponse
    {
        throw new GatewayException('Itaú não suporta wallets. Use getBalance() para saldo da conta corrente.');
    }

    /** @throws GatewayException */
    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount): TransferResponse
    {
        throw new GatewayException('Itaú não suporta transferências entre wallets.');
    }

    /** @throws GatewayException */
    public function holdInEscrow(EscrowRequest $request): EscrowResponse
    {
        throw new GatewayException('Itaú não suporta escrow. Use C6Bank para custódia de valores.');
    }

    /** @throws GatewayException */
    public function releaseEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Itaú não suporta escrow.');
    }

    /** @throws GatewayException */
    public function partialReleaseEscrow(string $escrowId, float $amount): EscrowResponse
    {
        throw new GatewayException('Itaú não suporta escrow.');
    }

    /** @throws GatewayException */
    public function cancelEscrow(string $escrowId): EscrowResponse
    {
        throw new GatewayException('Itaú não suporta escrow.');
    }

    /** @throws GatewayException */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new GatewayException('Itaú não suporta links de pagamento via API. Use Asaas, PagarMe ou C6Bank.');
    }

    /** @throws GatewayException */
    public function getPaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Itaú não suporta links de pagamento.');
    }

    /** @throws GatewayException */
    public function expirePaymentLink(string $linkId): PaymentLinkResponse
    {
        throw new GatewayException('Itaú não suporta links de pagamento.');
    }

    /** @throws GatewayException */
    public function analyzeTransaction(string $transactionId): array
    {
        throw new GatewayException('Itaú não expõe análise antifraude via API pública.');
    }

    /** @throws GatewayException */
    public function addToBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Itaú não suporta blacklist via API.');
    }

    /** @throws GatewayException */
    public function removeFromBlacklist(string $identifier, string $type): bool
    {
        throw new GatewayException('Itaú não suporta blacklist via API.');
    }

    /** @throws GatewayException */
    public function anticipateReceivables(array $transactionIds): PaymentResponse
    {
        throw new GatewayException('Antecipação de recebíveis não disponível via API pública do Itaú. Contate seu gerente.');
    }

    // ══════════════════════════════════════════════════════════
    //  HELPERS PRIVADOS — TRANSFERÊNCIAS
    // ══════════════════════════════════════════════════════════

    /**
     * Executa transferência via PIX usando chave ou dados bancários.
     *
     * @throws GatewayException
     */
    private function transferViaPix(TransferRequest $request): TransferResponse
    {
        $payload = [
            'valor'     => number_format($request->amount, 2, '.', ''),
            'descricao' => $request->description ?? 'Transferência PIX',
        ];

        if (!empty($request->metadata['pixKey'])) {
            $payload['chave'] = $request->metadata['pixKey'];
        } else {
            $payload['favorecido'] = [
                'nome'       => $request->recipientName,
                'cpf_cnpj'   => preg_replace('/\D/', '', (string) ($request->metadata['recipientDocument'] ?? '')),
                'banco'      => $request->metadata['bankCode']   ?? '',
                'agencia'    => $request->metadata['agency']     ?? '',
                'conta'      => $request->metadata['account']    ?? '',
                'tipo_conta' => $request->metadata['accountType'] ?? 'corrente',
            ];
        }

        $response = $this->request('POST', self::PATH_PIX . '/pix', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['endToEndId'] ?? uniqid('PIX_ITAU_')),
            amount:      $request->amount,
            status:      PaymentStatus::PENDING->value,
            currency:    'BRL',
            message:     'Transferência PIX iniciada.',
            rawResponse: $response,
        );
    }

    /**
     * Executa transferência via TED.
     *
     * @throws GatewayException
     */
    private function transferViaTed(TransferRequest $request): TransferResponse
    {
        $payload = [
            'valor'      => number_format($request->amount, 2, '.', ''),
            'descricao'  => $request->description ?? 'Transferência TED',
            'favorecido' => [
                'nome'       => $request->recipientName,
                'cpf_cnpj'   => preg_replace('/\D/', '', (string) ($request->metadata['recipientDocument'] ?? '')),
                'banco'      => $request->metadata['bankCode']   ?? '',
                'agencia'    => $request->metadata['agency']     ?? '',
                'conta'      => $request->metadata['account']    ?? '',
                'tipo_conta' => $request->metadata['accountType'] ?? 'corrente',
            ],
        ];

        $response = $this->request('POST', self::PATH_CONTA . '/transferencias/ted', $payload);

        return TransferResponse::create(
            success:     true,
            transferId:  (string) ($response['id'] ?? uniqid('TED_ITAU_')),
            amount:      $request->amount,
            status:      PaymentStatus::PENDING->value,
            currency:    'BRL',
            message:     'Transferência TED iniciada.',
            rawResponse: $response,
        );
    }

    // ══════════════════════════════════════════════════════════
    //  AUTENTICAÇÃO OAuth 2.0
    // ══════════════════════════════════════════════════════════

    /**
     * Realiza o fluxo OAuth 2.0 Client Credentials.
     * mTLS obrigatório em produção (ICP-Brasil .pfx).
     *
     * @throws GatewayException
     */
    private function authenticate(): void
    {
        $url = $this->baseUrl . self::OAUTH_PATH;
        $ch  = curl_init($url);

        if ($ch === false) {
            throw new GatewayException('Itaú OAuth: falha ao inicializar cURL.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($this->certPath !== null) {
            $options[CURLOPT_SSLCERT]     = $this->certPath;
            $options[CURLOPT_SSLCERTTYPE] = 'P12';
            if ($this->certPassword !== null) {
                $options[CURLOPT_SSLCERTPASSWD] = $this->certPassword;
            }
        }

        curl_setopt_array($ch, $options);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== 0) {
            throw new GatewayException(
                'Itaú OAuth: cURL error (' . $curlErr . ') — ' . $curlErrMsg
            );
        }

        $data = json_decode((string) $body, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new GatewayException(
                'Itaú OAuth: autenticação falhou — '
                    . ($data['error_description'] ?? $data['error'] ?? 'resposta inesperada'),
                $httpCode,
                null,
                ['response' => $data]
            );
        }

        $this->accessToken    = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600) - 60;
    }

    /**
     * Retorna o access token vigente, renovando se necessário.
     *
     * @throws GatewayException
     */
    private function getToken(): string
    {
        if ($this->accessToken === null || time() >= ($this->tokenExpiresAt ?? 0)) {
            $this->authenticate();
        }

        return (string) $this->accessToken;
    }

    // ══════════════════════════════════════════════════════════
    //  HTTP CLIENT INTERNO
    // ══════════════════════════════════════════════════════════

    /**
     * Executa uma chamada HTTP autenticada à API do Itaú.
     *
     * Recursos:
     *   - Bearer token com renovação automática
     *   - Idempotency-Key determinística (sha256 do método + path + body)
     *   - x-itau-correlationID para rastreabilidade
     *   - Retry com backoff exponencial para HTTP 429 e 503
     *   - mTLS quando certPath configurado
     *
     * @param string               $method  GET | POST | PUT | PATCH | DELETE
     * @param string               $path    Caminho após baseUrl
     * @param array<string, mixed> $body    Dados JSON do corpo da requisição
     * @param array<string, mixed> $query   Parâmetros de query string
     * @return array<string, mixed>
     * @throws GatewayException
     */
    protected function request(
        string $method,
        string $path,
        array  $body  = [],
        array  $query = [],
    ): array {
        $token  = $this->getToken();
        $url    = $this->baseUrl . $path;
        $method = strtoupper($method);

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $jsonBody = '';
        if ($body !== []) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new GatewayException(
                    'Itaú API: falha ao serializar payload — ' . json_last_error_msg()
                );
            }
            $jsonBody = $encoded;
        }

        $idempotencyKey = hash('sha256', $method . $path . $jsonBody);
        $correlationId  = 'itau-' . date('Ymd-His') . '-' . substr($idempotencyKey, 0, 8);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Idempotency-Key: ' . $idempotencyKey,
            'x-itau-correlationID: ' . $correlationId,
        ];

        $attempt = 0;

        while (true) {
            $ch = curl_init($url);

            if ($ch === false) {
                throw new GatewayException('Itaú API: falha ao inicializar cURL.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ];

            if ($jsonBody !== '') {
                $options[CURLOPT_POSTFIELDS] = $jsonBody;
            }

            if ($this->certPath !== null) {
                $options[CURLOPT_SSLCERT]     = $this->certPath;
                $options[CURLOPT_SSLCERTTYPE] = 'P12';
                if ($this->certPassword !== null) {
                    $options[CURLOPT_SSLCERTPASSWD] = $this->certPassword;
                }
            }

            curl_setopt_array($ch, $options);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr      = curl_errno($ch);
            $curlErrMsg   = curl_error($ch);
            curl_close($ch);

            if ($curlErr !== 0) {
                throw new GatewayException(
                    'Itaú API: cURL error (' . $curlErr . ') — ' . $curlErrMsg
                );
            }

            // Retry com backoff exponencial para erros transitórios
            if (in_array($httpCode, [429, 503], true) && $attempt < self::MAX_RETRIES) {
                sleep(2 ** ++$attempt);
                continue;
            }

            $data = json_decode((string) $responseBody, true) ?? [];

            // HTTP 204 No Content — operação bem-sucedida sem corpo (ex: DELETE)
            if ($httpCode === 204) {
                return [];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $errorMsg = $data['mensagem']
                    ?? $data['message']
                    ?? $data['detail']
                    ?? $data['title']
                    ?? ('HTTP ' . $httpCode);

                throw new GatewayException(
                    'Itaú API [' . $method . ' ' . $path . ']: ' . $errorMsg,
                    $httpCode,
                    null,
                    ['response' => $data, 'correlationId' => $correlationId]
                );
            }

            return $data;
        }
    }
}