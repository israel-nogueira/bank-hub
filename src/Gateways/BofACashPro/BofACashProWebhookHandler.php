<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Gateways\BofACashPro;

use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

/**
 * ============================================================
 *  Bank of America — CashPro Webhook Handler
 * ============================================================
 *
 *  Processa eventos Push Notification recebidos do BofA CashPro.
 *
 *  O BofA envia um HTTP POST para sua URL cadastrada sempre que
 *  um evento ocorre na conta (pagamento recebido, enviado, falhou, etc.).
 *  Esta classe é responsável por:
 *
 *    1. Validar a assinatura HMAC-SHA256 do payload
 *    2. Parsear e normalizar o evento
 *    3. Despachar para o handler correto via callbacks registrados
 *    4. Responder 200 OK ao BofA (necessário para evitar reenvios)
 *
 *  EVENTOS SUPORTADOS
 *  ------------------
 *  | Evento                  | Quando ocorre                          |
 *  |-------------------------|----------------------------------------|
 *  | PAYMENT_RECEIVED        | Zelle/ACH/Wire recebido na conta       |
 *  | PAYMENT_SENT            | Transferência enviada confirmada        |
 *  | PAYMENT_FAILED          | Transferência falhou                   |
 *  | PAYMENT_RETURNED        | ACH devolvido pelo banco receptor      |
 *  | PAYMENT_CANCELLED       | Transferência cancelada com sucesso    |
 *  | BALANCE_BELOW_THRESHOLD | Saldo abaixo do limite configurado     |
 *  | STATEMENT_AVAILABLE     | Extrato mensal disponível              |
 *
 *  SOBRE A ASSINATURA HMAC
 *  -----------------------
 *  O BofA assina cada payload com HMAC-SHA256 usando uma chave secreta
 *  que você configura no Developer Portal. A assinatura é enviada no
 *  header: X-BofA-Signature
 *
 *  Formato da assinatura: sha256={hash_hex}
 *  Exemplo: sha256=3ecb2e1a9f34...
 *
 *  NUNCA processe um webhook sem validar a assinatura em produção.
 *
 *  IDEMPOTÊNCIA
 *  ------------
 *  O BofA pode reenviar o mesmo evento em caso de timeout ou falha
 *  de rede. Use o campo eventId para deduplicar eventos já processados.
 *
 * @author  PaymentHub
 * @version 1.0.0
 */
class BofACashProWebhookHandler
{
    // ----------------------------------------------------------
    //  Constantes de eventos
    // ----------------------------------------------------------

    /** Zelle, ACH ou Wire creditado na conta corporativa. Evento mais importante para a fintech. */
    public const EVENT_PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';

    /** Transferência debitada e confirmada pelo BofA (Zelle instantâneo, ACH/Wire na liquidação). */
    public const EVENT_PAYMENT_SENT = 'PAYMENT_SENT';

    /** Transferência falhou por qualquer motivo (conta inválida, limite, etc.). */
    public const EVENT_PAYMENT_FAILED = 'PAYMENT_FAILED';

    /** ACH devolvido pelo banco receptor (conta encerrada, routing errado, etc.). */
    public const EVENT_PAYMENT_RETURNED = 'PAYMENT_RETURNED';

    /** Transferência agendada cancelada com sucesso via cancelScheduledTransfer(). */
    public const EVENT_PAYMENT_CANCELLED = 'PAYMENT_CANCELLED';

    /** Saldo da conta caiu abaixo do threshold configurado no portal do BofA. */
    public const EVENT_BALANCE_BELOW_THRESHOLD = 'BALANCE_BELOW_THRESHOLD';

    /** Novo extrato mensal disponível para download. */
    public const EVENT_STATEMENT_AVAILABLE = 'STATEMENT_AVAILABLE';

    // ----------------------------------------------------------
    //  Estado interno
    // ----------------------------------------------------------

    /**
     * Mapa de eventType → callable.
     * Populado via on() e onPaymentReceived() etc.
     *
     * @var array<string, callable>
     */
    private array $handlers = [];

    /**
     * Handler de fallback chamado para eventos sem handler registrado.
     * Útil para logging de eventos não tratados.
     *
     * @var callable|null
     */
    private $fallbackHandler = null;

    // ----------------------------------------------------------
    //  Construtor
    // ----------------------------------------------------------

    /**
     * Instancia o handler de webhooks do BofA CashPro.
     *
     * @param string|null $webhookSecret  Chave secreta configurada no Developer Portal
     *                                    para validação HMAC-SHA256.
     *                                    null = validação desativada (apenas para sandbox/testes).
     * @param bool        $validateIp     Se true, valida que o IP do request pertence ao BofA.
     *                                    Recomendado em produção combinado com HMAC.
     * @param array       $allowedIps     Lista de IPs do BofA autorizados a enviar webhooks.
     *                                    Consulte a documentação do BofA para a lista atualizada.
     *
     * Exemplo:
     *   $handler = new BofACashProWebhookHandler(
     *       webhookSecret: $_ENV['BOFA_WEBHOOK_SECRET'],
     *   );
     */
    public function __construct(
        private readonly ?string $webhookSecret = null,
        private readonly bool    $validateIp    = false,
        private readonly array   $allowedIps    = [],
    ) {}

    // ==========================================================
    //  REGISTRO DE HANDLERS
    // ==========================================================

    /**
     * Registra um handler genérico para qualquer tipo de evento.
     *
     * A função callback recebe um array normalizado com os dados do evento.
     * Veja a estrutura em parsePayload().
     *
     * @param string   $eventType  Constante EVENT_* desta classe.
     * @param callable $handler    function(array $event): void
     *
     * @return static  Retorna $this para encadeamento fluente.
     *
     * Exemplo:
     *   $handler->on(BofACashProWebhookHandler::EVENT_PAYMENT_FAILED, function(array $event) {
     *       Log::warning('Payment failed', ['id' => $event['paymentId']]);
     *   });
     */
    public function on(string $eventType, callable $handler): static
    {
        $this->handlers[$eventType] = $handler;
        return $this;
    }

    /**
     * Registra handler para o evento PAYMENT_RECEIVED.
     *
     * Atalho semântico para on(EVENT_PAYMENT_RECEIVED, ...).
     * Este é o evento mais crítico para a fintech:
     * dispara quando um Zelle, ACH ou Wire é creditado na conta do BofA.
     *
     * ESTRUTURA DO $event RECEBIDO:
     * ------------------------------
     *   $event['eventId']      string   ID único do evento (use para deduplicação).
     *   $event['eventType']    string   'PAYMENT_RECEIVED'
     *   $event['timestamp']    string   ISO 8601 (ex: '2025-02-27T15:30:00Z')
     *   $event['paymentId']    string   ID do pagamento no BofA.
     *   $event['paymentType']  string   'ZELLE' | 'ACH_SAME_DAY' | 'ACH_STANDARD' | 'WIRE'
     *   $event['amount']       float    Valor recebido em USD.
     *   $event['currency']     string   Sempre 'USD'.
     *   $event['accountId']    string   ID da conta BofA que recebeu o crédito.
     *   $event['senderEmail']  string   Email do remetente (Zelle). null para ACH/Wire.
     *   $event['senderPhone']  string   Telefone do remetente (Zelle). null para ACH/Wire.
     *   $event['senderName']   string   Nome do remetente (quando disponível).
     *   $event['memo']         string   Mensagem/memo enviada pelo remetente.
     *                                   IMPORTANTE: Para Zelle, este campo contém o
     *                                   identificador do cliente na sua plataforma
     *                                   (ex: 'REF-USER-7890') se o cliente o informar.
     *   $event['rawPayload']   array    Payload bruto original do BofA.
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onPaymentReceived(callable $handler): static
    {
        return $this->on(self::EVENT_PAYMENT_RECEIVED, $handler);
    }

    /**
     * Registra handler para o evento PAYMENT_SENT.
     *
     * Dispara quando o BofA confirma que uma transferência foi enviada.
     * Para Zelle: quase instantâneo após o envio.
     * Para ACH: na data de liquidação.
     * Para Wire: quando o Fedwire confirma a liquidação.
     *
     * ESTRUTURA DO $event:
     *   $event['paymentId']    string   ID do pagamento.
     *   $event['paymentType']  string   'ZELLE' | 'ACH_SAME_DAY' | 'ACH_STANDARD' | 'WIRE'
     *   $event['amount']       float    Valor enviado em USD.
     *   $event['memo']         string   Memo/referência original da transferência.
     *   + demais campos padrão (eventId, eventType, timestamp, currency, accountId)
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onPaymentSent(callable $handler): static
    {
        return $this->on(self::EVENT_PAYMENT_SENT, $handler);
    }

    /**
     * Registra handler para o evento PAYMENT_FAILED.
     *
     * Dispara quando uma transferência falha por qualquer motivo.
     *
     * CAUSAS COMUNS DE FALHA:
     *   - Zelle: destinatário não tem Zelle ativo, limite excedido
     *   - ACH: routing number inválido, conta encerrada, saldo insuficiente
     *   - Wire: dados bancários incorretos, cutoff ultrapassado
     *
     * ESTRUTURA DO $event:
     *   $event['paymentId']    string   ID do pagamento que falhou.
     *   $event['paymentType']  string   Método de pagamento.
     *   $event['amount']       float    Valor que falhou.
     *   $event['failureCode']  string   Código de erro do BofA.
     *   $event['failureReason'] string  Descrição legível do motivo da falha.
     *   + demais campos padrão
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onPaymentFailed(callable $handler): static
    {
        return $this->on(self::EVENT_PAYMENT_FAILED, $handler);
    }

    /**
     * Registra handler para o evento PAYMENT_RETURNED.
     *
     * Dispara quando um ACH é devolvido pelo banco receptor.
     * Não se aplica a Zelle ou Wire (irreversíveis).
     *
     * CÓDIGOS DE RETORNO ACH COMUNS (campo returnCode):
     *   R01 → Saldo insuficiente
     *   R02 → Conta encerrada
     *   R03 → Conta inexistente
     *   R04 → Número de conta inválido
     *   R10 → Débito não autorizado pelo correntista
     *   R20 → Conta não aceita débito ACH
     *
     * ESTRUTURA DO $event:
     *   $event['paymentId']    string   ID do ACH original devolvido.
     *   $event['amount']       float    Valor devolvido.
     *   $event['returnCode']   string   Código ACH de retorno (ex: 'R02').
     *   $event['returnReason'] string   Descrição do código de retorno.
     *   + demais campos padrão
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onPaymentReturned(callable $handler): static
    {
        return $this->on(self::EVENT_PAYMENT_RETURNED, $handler);
    }

    /**
     * Registra handler para o evento BALANCE_BELOW_THRESHOLD.
     *
     * Dispara quando o saldo da conta cai abaixo do limite
     * configurado no painel do BofA CashPro.
     *
     * Use para acionar alertas operacionais e evitar falhas por
     * saldo insuficiente em transferências futuras.
     *
     * ESTRUTURA DO $event:
     *   $event['currentBalance']   float   Saldo atual em USD.
     *   $event['threshold']        float   Limite configurado que foi ultrapassado.
     *   $event['currency']         string  'USD'
     *   + demais campos padrão
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onBalanceBelowThreshold(callable $handler): static
    {
        return $this->on(self::EVENT_BALANCE_BELOW_THRESHOLD, $handler);
    }

    /**
     * Registra um handler de fallback para eventos sem handler específico.
     *
     * Útil para logging centralizado de todos os eventos recebidos,
     * incluindo novos tipos de eventos que o BofA possa adicionar no futuro.
     *
     * @param callable $handler  function(array $event): void
     * @return static
     */
    public function onUnhandled(callable $handler): static
    {
        $this->fallbackHandler = $handler;
        return $this;
    }

    // ==========================================================
    //  PROCESSAMENTO PRINCIPAL
    // ==========================================================

    /**
     * Ponto de entrada principal: processa um request HTTP do BofA.
     *
     * Deve ser chamado no seu endpoint de webhook antes de qualquer
     * lógica de negócio. Valida, parseia e despacha o evento.
     *
     * FLUXO INTERNO:
     *   1. Valida método HTTP (deve ser POST)
     *   2. Valida IP de origem (se validateIp = true)
     *   3. Lê o body bruto (php://input)
     *   4. Valida assinatura HMAC-SHA256 (se webhookSecret configurado)
     *   5. Parseia e normaliza o payload JSON
     *   6. Despacha para o handler registrado via on()
     *   7. Retorna array com resultado do processamento
     *
     * RESPOSTA HTTP ESPERADA PELO BOFA:
     *   O BofA espera HTTP 200 em até 10 segundos.
     *   Se não receber, reenvia o evento (até 3 tentativas com backoff).
     *   Use respondOk() logo após o handle() para responder rápido,
     *   e processe em background se a lógica for demorada.
     *
     * @param string|null $rawBody   Body bruto do request. null = lê de php://input.
     * @param array|null  $headers   Headers do request. null = lê de getallheaders().
     *
     * @return array Resultado do processamento:
     *               ['success' => bool, 'eventType' => string, 'eventId' => string, 'message' => string]
     *
     * @throws GatewayException Se a assinatura for inválida (em produção).
     * @throws GatewayException Se o payload não for JSON válido.
     * @throws GatewayException Se o IP não estiver na whitelist (se validateIp = true).
     * @throws \Throwable       Qualquer exceção lançada pelo handler registrado.
     */
    public function handle(?string $rawBody = null, ?array $headers = null): array
    {
        // Lê body e headers do request se não fornecidos explicitamente
        $rawBody ??= file_get_contents('php://input');
        $headers ??= (function_exists('getallheaders') ? getallheaders() : []);

        // Normaliza nomes de header para case-insensitive
        $headers = array_change_key_case($headers, CASE_LOWER);

        // Validação de IP (opcional)
        if ($this->validateIp) {
            $this->assertValidIp($_SERVER['REMOTE_ADDR'] ?? '');
        }

        // Validação de assinatura HMAC
        if ($this->webhookSecret !== null) {
            $signature = $headers['x-bofa-signature'] ?? '';
            $this->assertValidSignature($rawBody, $signature);
        }

        // Parse do payload
        $payload = $this->parsePayload($rawBody);

        // Despacha para o handler registrado
        $eventType = $payload['eventType'];
        $handler   = $this->handlers[$eventType] ?? $this->fallbackHandler;

        if ($handler !== null) {
            ($handler)($payload);
        }

        return [
            'success'   => true,
            'eventType' => $eventType,
            'eventId'   => $payload['eventId'],
            'message'   => "Event {$eventType} processed successfully",
        ];
    }

    /**
     * Emite resposta HTTP 200 OK para o BofA e encerra o output buffer.
     *
     * Chame este método logo após handle() para responder ao BofA
     * rapidamente e evitar reenvios, especialmente se o processamento
     * real for feito de forma assíncrona (fila, job, etc.).
     *
     * PADRÃO RECOMENDADO:
     *   $result = $handler->handle();
     *   $handler->respondOk($result);
     *   // Aqui você pode despachar jobs para fila, por exemplo
     *
     * @param array $data Dados opcionais para incluir na resposta JSON.
     */
    public function respondOk(array $data = []): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['status' => 'ok'], $data));

        // Força envio imediato da resposta sem encerrar o processo
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    // ==========================================================
    //  VALIDAÇÕES
    // ==========================================================

    /**
     * Valida a assinatura HMAC-SHA256 do payload recebido.
     *
     * O BofA assina o body bruto (bytes exatos como recebido) com
     * HMAC-SHA256 e a chave secreta configurada no portal.
     *
     * NUNCA recalcule a assinatura sobre o JSON parseado e re-serializado
     * — pequenas diferenças de espaçamento ou ordem de chaves quebrariam
     * a comparação. Sempre use o $rawBody original.
     *
     * Formato esperado do header X-BofA-Signature: sha256={hash_hex}
     *
     * @param string $rawBody   Body bruto do request (bytes originais).
     * @param string $signature Valor do header X-BofA-Signature.
     *
     * @throws GatewayException Se a assinatura estiver ausente ou inválida.
     */
    private function assertValidSignature(string $rawBody, string $signature): void
    {
        if (empty($signature)) {
            throw new GatewayException(
                'BofA Webhook: missing X-BofA-Signature header. ' .
                'Ensure the webhook secret is configured in the BofA Developer Portal.'
            );
        }

        // Remove prefixo "sha256=" se presente
        $receivedHash = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        // Recalcula o hash esperado
        $expectedHash = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        // Comparação segura contra timing attacks
        if (!hash_equals($expectedHash, $receivedHash)) {
            throw new GatewayException(
                'BofA Webhook: invalid signature. ' .
                'Possible causes: wrong secret, payload tampered, or replay attack.'
            );
        }
    }

    /**
     * Valida que o IP de origem pertence ao BofA.
     *
     * Camada adicional de segurança. Combine sempre com HMAC.
     * A lista de IPs do BofA pode mudar — consulte a documentação
     * do CashPro Developer Portal para a lista atual.
     *
     * @param string $ip IP remoto do request.
     * @throws GatewayException Se o IP não estiver na whitelist.
     */
    private function assertValidIp(string $ip): void
    {
        if (empty($this->allowedIps)) {
            return; // Sem lista configurada, pula validação
        }

        if (!in_array($ip, $this->allowedIps, true)) {
            throw new GatewayException(
                "BofA Webhook: unauthorized IP address '{$ip}'. " .
                'Add this IP to allowedIps or disable IP validation.'
            );
        }
    }

    // ==========================================================
    //  PARSING
    // ==========================================================

    /**
     * Parseia e normaliza o payload JSON do BofA em array estruturado.
     *
     * A CashPro API pode retornar diferentes estruturas dependendo do
     * tipo de evento. Este método normaliza tudo em um formato consistente
     * para os handlers registrados.
     *
     * CAMPOS SEMPRE PRESENTES NA SAÍDA:
     *   eventId      string   ID único do evento (use para deduplicação no banco).
     *   eventType    string   Tipo do evento (constante EVENT_*).
     *   timestamp    string   ISO 8601 do momento do evento.
     *   accountId    string   ID da conta BofA afetada.
     *   currency     string   Moeda (geralmente 'USD').
     *   rawPayload   array    Payload original completo do BofA.
     *
     * CAMPOS ADICIONAIS POR TIPO DE EVENTO:
     *   PAYMENT_RECEIVED / SENT / FAILED / RETURNED / CANCELLED:
     *     paymentId, paymentType, amount, memo, senderEmail, senderPhone,
     *     senderName, recipientName, failureCode, failureReason,
     *     returnCode, returnReason
     *
     *   BALANCE_BELOW_THRESHOLD:
     *     currentBalance, threshold
     *
     *   STATEMENT_AVAILABLE:
     *     statementId, statementDate, downloadUrl
     *
     * @param string $rawBody Body JSON bruto do request.
     * @return array Evento normalizado.
     *
     * @throws GatewayException Se o body não for JSON válido ou campos obrigatórios estiverem ausentes.
     */
    private function parsePayload(string $rawBody): array
    {
        $data = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GatewayException(
                'BofA Webhook: invalid JSON payload — ' . json_last_error_msg()
            );
        }

        // Campos obrigatórios em qualquer evento BofA
        foreach (['eventId', 'eventType'] as $required) {
            if (empty($data[$required])) {
                throw new GatewayException(
                    "BofA Webhook: missing required field '{$required}' in payload."
                );
            }
        }

        // Base normalizada presente em todos os eventos
        $normalized = [
            'eventId'   => $data['eventId'],
            'eventType' => $data['eventType'],
            'timestamp' => $data['timestamp']  ?? null,
            'accountId' => $data['accountId']  ?? null,
            'currency'  => $data['currency']   ?? 'USD',
            'rawPayload'=> $data,
        ];

        // Campos específicos por tipo de evento
        $eventType = $data['eventType'];

        if (in_array($eventType, [
            self::EVENT_PAYMENT_RECEIVED,
            self::EVENT_PAYMENT_SENT,
            self::EVENT_PAYMENT_FAILED,
            self::EVENT_PAYMENT_RETURNED,
            self::EVENT_PAYMENT_CANCELLED,
        ], true)) {
            $normalized = array_merge($normalized, [
                'paymentId'     => $data['paymentId']     ?? null,
                'paymentType'   => $data['paymentType']   ?? null, // ZELLE|ACH_SAME_DAY|ACH_STANDARD|WIRE
                'amount'        => (float) ($data['amount'] ?? 0.0),
                'memo'          => $data['memo']          ?? null,
                'senderEmail'   => $data['senderEmail']   ?? null,
                'senderPhone'   => $data['senderPhone']   ?? null,
                'senderName'    => $data['senderName']    ?? null,
                'recipientName' => $data['recipientName'] ?? null,
                // Específicos de PAYMENT_FAILED
                'failureCode'   => $data['failureCode']   ?? null,
                'failureReason' => $data['failureReason'] ?? null,
                // Específicos de PAYMENT_RETURNED (ACH Return codes)
                'returnCode'    => $data['returnCode']    ?? null,
                'returnReason'  => $data['returnReason']  ?? null,
            ]);
        }

        if ($eventType === self::EVENT_BALANCE_BELOW_THRESHOLD) {
            $normalized = array_merge($normalized, [
                'currentBalance' => (float) ($data['currentBalance'] ?? 0.0),
                'threshold'      => (float) ($data['threshold']      ?? 0.0),
            ]);
        }

        if ($eventType === self::EVENT_STATEMENT_AVAILABLE) {
            $normalized = array_merge($normalized, [
                'statementId'   => $data['statementId']   ?? null,
                'statementDate' => $data['statementDate'] ?? null,
                'downloadUrl'   => $data['downloadUrl']   ?? null,
            ]);
        }

        return $normalized;
    }
}
