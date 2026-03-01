<?php

declare(strict_types=1);

namespace IsraelNogueira\PaymentHub\Gateways\BancoDoBrasil;

use IsraelNogueira\PaymentHub\Exceptions\GatewayException;

/**
 * ============================================================
 *  Banco do Brasil — Webhook Handler
 * ============================================================
 *
 *  Processa notificações recebidas das APIs PIX e Cobrança do BB.
 *
 *  O BB envia um HTTP POST para a URL cadastrada via registerWebhook()
 *  sempre que um evento ocorre (PIX recebido, boleto liquidado, etc.).
 *  Esta classe é responsável por:
 *
 *    1. Validar o token de segurança (header x-webhook-token)
 *    2. Parsear e normalizar o payload do evento
 *    3. Despachar para o handler correto via callbacks registrados
 *    4. Garantir idempotência via campo txid/endToEndId
 *
 *  EVENTOS SUPORTADOS
 *  ------------------
 *  | Evento                    | Quando ocorre                        |
 *  |---------------------------|--------------------------------------|
 *  | PIX_RECEBIDO              | PIX creditado na conta               |
 *  | PIX_DEVOLVIDO             | Estorno PIX confirmado               |
 *  | BOLETO_LIQUIDADO          | Boleto pago e liquidado              |
 *  | BOLETO_VENCIDO            | Boleto venceu sem pagamento          |
 *  | BOLETO_BAIXADO            | Boleto cancelado (baixa solicitada)  |
 *
 *  SOBRE O TOKEN DE SEGURANÇA
 *  --------------------------
 *  O BB não usa HMAC-SHA256 nativamente como o BofA. A autenticação
 *  do webhook é feita via um token fixo que você define ao registrar
 *  a URL no portal. O BB envia esse token no header:
 *
 *    x-webhook-token: {seu_token}   (PIX)
 *    Authorization: Bearer {token}  (Cobrança/Boleto)
 *
 *  Configure o mesmo token em ambos os casos.
 *  Use hash_equals() para comparação — nunca === — para evitar
 *  timing attacks.
 *
 *  IDEMPOTÊNCIA
 *  ------------
 *  O BB pode reenviar o mesmo evento em caso de timeout ou falha de rede.
 *  Use txid (PIX) ou nossoNumero (Boleto) para deduplicar eventos.
 *  Registre os IDs processados em banco de dados ou cache (Redis, etc.).
 *
 *  RESPOSTA ESPERADA
 *  -----------------
 *  O BB aguarda HTTP 200 em até 10 segundos. Se não receber, reenvia
 *  com backoff exponencial por até 48 horas.
 *  Responda 200 imediatamente e processe de forma assíncrona se necessário.
 *
 * @author  PaymentHub
 * @version 1.0.0
 */
class BancoDoBrasilWebhookHandler
{
    // ----------------------------------------------------------
    //  Constantes de eventos — PIX
    // ----------------------------------------------------------

    /** PIX creditado na conta. Evento mais crítico para a aplicação. */
    public const EVENT_PIX_RECEBIDO = 'PIX_RECEBIDO';

    /** Estorno (devolução) de PIX confirmado pelo BB. */
    public const EVENT_PIX_DEVOLVIDO = 'PIX_DEVOLVIDO';

    // ----------------------------------------------------------
    //  Constantes de eventos — Boleto
    // ----------------------------------------------------------

    /** Boleto pago e liquidado na câmara. */
    public const EVENT_BOLETO_LIQUIDADO = 'BOLETO_LIQUIDADO';

    /** Boleto venceu sem pagamento. */
    public const EVENT_BOLETO_VENCIDO = 'BOLETO_VENCIDO';

    /** Boleto cancelado via baixa. */
    public const EVENT_BOLETO_BAIXADO = 'BOLETO_BAIXADO';

    // ----------------------------------------------------------
    //  Propriedades internas
    // ----------------------------------------------------------

    /** @var array<string, callable> Handlers registrados por tipo de evento. */
    private array $handlers = [];

    /** @var callable|null Handler fallback para eventos sem handler específico. */
    private mixed $fallbackHandler = null;

    // ----------------------------------------------------------
    //  Construtor
    // ----------------------------------------------------------

    /**
     * @param string|null $webhookToken  Token configurado ao registrar o webhook no portal BB.
     *                                   Use null apenas em testes/sandbox sem validação.
     *                                   NUNCA use string vazia — seria equivalente a sem validação.
     * @param bool        $validateToken Se true, valida o header x-webhook-token em toda requisição.
     *                                   Recomendado: true em produção, pode ser false em sandbox.
     *
     * @throws \InvalidArgumentException Se $webhookToken for uma string vazia.
     */
    public function __construct(
        private readonly ?string $webhookToken  = null,
        private readonly bool    $validateToken  = true,
    ) {
        // String vazia é tão insegura quanto null, mas pode enganar ao passar isset()
        if ($this->webhookToken !== null && $this->webhookToken === '') {
            throw new \InvalidArgumentException(
                'BB WebhookHandler: webhookToken não pode ser uma string vazia. ' .
                'Use null para desabilitar explicitamente a validação (somente sandbox).'
            );
        }
    }

    // ----------------------------------------------------------
    //  Registro de handlers
    // ----------------------------------------------------------

    /**
     * Registra um handler genérico para qualquer tipo de evento.
     *
     * @param string   $eventType  Constante EVENT_* desta classe.
     * @param callable $handler    function(array $event): void
     * @return static Retorna $this para encadeamento fluente.
     *
     * Exemplo:
     *   $handler->on(BancoDoBrasilWebhookHandler::EVENT_PIX_RECEBIDO, function(array $event) {
     *       Orders::confirm($event['txid'], $event['valor']);
     *   });
     */
    public function on(string $eventType, callable $handler): static
    {
        $this->handlers[$eventType] = $handler;
        return $this;
    }

    /**
     * Atalho semântico para PIX_RECEBIDO.
     *
     * ESTRUTURA DO $event RECEBIDO:
     * -------------------------------------------
     *   $event['eventType']    string   'PIX_RECEBIDO'
     *   $event['txid']         string   ID único da transação PIX (use para deduplicar)
     *   $event['endToEndId']   string   ID E2E da transação (rastreabilidade BACEN)
     *   $event['valor']        float    Valor recebido em reais
     *   $event['horario']      string   ISO 8601 do recebimento (ex: '2025-01-14T10:52:15Z')
     *   $event['pagador']      array    ['nome' => ..., 'cpf'|'cnpj' => ...]
     *   $event['infoPagador']  string   Mensagem livre do pagador (pode estar vazia)
     *   $event['rawPayload']   array    Payload bruto original do BB
     *
     * @param callable $handler function(array $event): void
     * @return static
     */
    public function onPixRecebido(callable $handler): static
    {
        return $this->on(self::EVENT_PIX_RECEBIDO, $handler);
    }

    /**
     * Atalho semântico para PIX_DEVOLVIDO.
     *
     * ESTRUTURA DO $event RECEBIDO:
     * -------------------------------------------
     *   $event['eventType']      string   'PIX_DEVOLVIDO'
     *   $event['txid']           string   ID da transação original
     *   $event['devolucaoId']    string   ID único da devolução
     *   $event['valor']          float    Valor devolvido em reais
     *   $event['horario']        string   ISO 8601 da devolução
     *   $event['status']         string   'DEVOLVIDO' | 'EM_PROCESSAMENTO'
     *   $event['rawPayload']     array    Payload bruto original do BB
     *
     * @param callable $handler function(array $event): void
     * @return static
     */
    public function onPixDevolvido(callable $handler): static
    {
        return $this->on(self::EVENT_PIX_DEVOLVIDO, $handler);
    }

    /**
     * Atalho semântico para BOLETO_LIQUIDADO.
     *
     * ESTRUTURA DO $event RECEBIDO:
     * -------------------------------------------
     *   $event['eventType']    string   'BOLETO_LIQUIDADO'
     *   $event['nossoNumero']  string   Número do título (use para deduplicar)
     *   $event['valor']        float    Valor pago em reais
     *   $event['dataPagamento'] string  Data do pagamento (Y-m-d)
     *   $event['convenio']     int      Número do convênio
     *   $event['rawPayload']   array    Payload bruto original do BB
     *
     * @param callable $handler function(array $event): void
     * @return static
     */
    public function onBoletoLiquidado(callable $handler): static
    {
        return $this->on(self::EVENT_BOLETO_LIQUIDADO, $handler);
    }

    /**
     * Atalho semântico para BOLETO_VENCIDO.
     *
     * @param callable $handler function(array $event): void
     * @return static
     */
    public function onBoletoVencido(callable $handler): static
    {
        return $this->on(self::EVENT_BOLETO_VENCIDO, $handler);
    }

    /**
     * Registra um handler fallback chamado quando nenhum handler específico for encontrado.
     *
     * Útil para logar eventos desconhecidos ou novos tipos introduzidos pelo BB.
     *
     * @param callable $handler function(array $event): void
     * @return static
     */
    public function onUnknownEvent(callable $handler): static
    {
        $this->fallbackHandler = $handler;
        return $this;
    }

    // ----------------------------------------------------------
    //  Processamento principal
    // ----------------------------------------------------------

    /**
     * Processa um webhook recebido do Banco do Brasil.
     *
     * Fluxo:
     *   1. Valida token de segurança (se configurado)
     *   2. Faz parse do JSON
     *   3. Detecta o tipo de evento (PIX ou Boleto)
     *   4. Normaliza o payload
     *   5. Despacha para o handler registrado
     *
     * @param string|null $rawBody  Body bruto do request. Se null, lê de php://input.
     * @param array|null  $headers  Headers do request. Se null, lê via getallheaders().
     *
     * @return array{success: bool, eventType: string, eventId: string, message: string}
     *
     * @throws GatewayException Se o token for inválido, o JSON for malformado, etc.
     * @throws \Throwable       Qualquer exceção lançada pelo handler registrado.
     */
    public function handle(?string $rawBody = null, ?array $headers = null): array
    {
        $rawBody ??= (string) file_get_contents('php://input');
        $headers ??= (function_exists('getallheaders') ? (array) getallheaders() : []);

        // Normaliza headers para lowercase (HTTP headers são case-insensitive)
        $headers = array_change_key_case($headers, CASE_LOWER);

        // 1. Valida token de segurança
        if ($this->validateToken && $this->webhookToken !== null) {
            $this->assertValidToken($headers);
        }

        // 2. Parse do JSON
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new GatewayException(
                'BB Webhook: payload JSON inválido — ' . json_last_error_msg()
            );
        }

        // 3. Detecta tipo e normaliza
        $event = $this->normalizePayload($payload);

        // 4. Despacha para o handler
        $eventType = $event['eventType'];
        $handler   = $this->handlers[$eventType] ?? $this->fallbackHandler;

        if ($handler !== null) {
            ($handler)($event);
        }

        return [
            'success'   => true,
            'eventType' => $eventType,
            'eventId'   => $event['eventId'],
            'message'   => "Evento {$eventType} processado com sucesso",
        ];
    }

    /**
     * Emite resposta HTTP 200 OK para o BB e libera a conexão.
     *
     * O BB aguarda 200 em até 10 segundos. Chame este método logo após handle()
     * para responder rapidamente e processar de forma assíncrona em seguida.
     *
     * PADRÃO RECOMENDADO:
     *   $result = $handler->handle();
     *   $handler->respondOk($result);
     *   // Despache jobs para fila aqui (SQS, Redis, etc.)
     *
     * @param array $data Dados opcionais para incluir na resposta JSON.
     */
    public function respondOk(array $data = []): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['status' => 'ok'], $data));

        // Libera a conexão sem encerrar o processo (PHP-FPM)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    // ----------------------------------------------------------
    //  Validação do token
    // ----------------------------------------------------------

    /**
     * Valida o token de segurança presente nos headers do request.
     *
     * O BB envia o token em:
     *   PIX API     → header: x-webhook-token
     *   Cobrança    → header: authorization (Bearer {token})
     *
     * Este método aceita ambos os formatos.
     *
     * Usa hash_equals() para comparação segura contra timing attacks.
     *
     * @param array<string, string> $headers Headers normalizados em lowercase.
     * @throws GatewayException Se o token for inválido ou ausente.
     */
    private function assertValidToken(array $headers): void
    {
        // Tenta x-webhook-token (API PIX)
        $receivedToken = $headers['x-webhook-token'] ?? '';

        // Fallback: Authorization: Bearer {token} (API Cobrança)
        if ($receivedToken === '') {
            $authHeader = $headers['authorization'] ?? '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $receivedToken = substr($authHeader, 7);
            }
        }

        if ($receivedToken === '') {
            throw new GatewayException(
                'BB Webhook: token de segurança ausente. ' .
                'Verifique se o header x-webhook-token ou Authorization está sendo enviado pelo BB.'
            );
        }

        // Comparação segura contra timing attacks
        if (!hash_equals($this->webhookToken, $receivedToken)) {
            throw new GatewayException(
                'BB Webhook: token de segurança inválido. ' .
                'Possíveis causas: token incorreto no portal BB ou payload adulterado.'
            );
        }
    }

    // ----------------------------------------------------------
    //  Normalização do payload
    // ----------------------------------------------------------

    /**
     * Detecta o tipo de evento e normaliza o payload em formato consistente.
     *
     * O BB usa estruturas diferentes para PIX e Boleto:
     *
     *   PIX recebido:
     *     { "pix": [{ "txid": "...", "endToEndId": "...", "valor": "10.00", ... }] }
     *
     *   PIX devolvido (dentro de um pix recebido com devolucoes):
     *     { "pix": [{ "txid": "...", "devolucoes": [{ ... }] }] }
     *
     *   Boleto liquidado:
     *     { "cobrancas": [{ "nossoNumero": "...", "situacao": "LIQUIDADA", ... }] }
     *     ou via evento de mudança de status:
     *     { "numeroTitulo": "...", "situacaoTitulo": "LIQUIDADA", ... }
     *
     * @param array<string, mixed> $payload Payload JSON decodificado.
     * @return array<string, mixed> Payload normalizado.
     */
    private function normalizePayload(array $payload): array
    {
        // ── PIX ──────────────────────────────────────────────────
        if (isset($payload['pix']) && is_array($payload['pix'])) {
            $pixEntry = $payload['pix'][0] ?? [];

            // Se tem devoluções, é um evento de devolução
            if (!empty($pixEntry['devolucoes'])) {
                $dev = $pixEntry['devolucoes'][0];
                return [
                    'eventType'   => self::EVENT_PIX_DEVOLVIDO,
                    'eventId'     => (string) ($dev['id'] ?? $pixEntry['txid'] ?? uniqid('BB-DEV-')),
                    'txid'        => (string) ($pixEntry['txid']       ?? ''),
                    'devolucaoId' => (string) ($dev['id']              ?? ''),
                    'valor'       => (float)  ($dev['valor']           ?? 0.0),
                    'horario'     => (string) ($dev['horario']['liquidacao'] ?? $dev['horario'] ?? ''),
                    'status'      => (string) ($dev['status']          ?? 'EM_PROCESSAMENTO'),
                    'rawPayload'  => $payload,
                ];
            }

            // PIX recebido normal
            return [
                'eventType'   => self::EVENT_PIX_RECEBIDO,
                'eventId'     => (string) ($pixEntry['endToEndId'] ?? $pixEntry['txid'] ?? uniqid('BB-PIX-')),
                'txid'        => (string) ($pixEntry['txid']       ?? ''),
                'endToEndId'  => (string) ($pixEntry['endToEndId'] ?? ''),
                'valor'       => (float)  ($pixEntry['valor']      ?? 0.0),
                'horario'     => (string) ($pixEntry['horario']    ?? ''),
                'pagador'     => (array)  ($pixEntry['pagador']    ?? []),
                'infoPagador' => (string) ($pixEntry['infoPagador'] ?? ''),
                'rawPayload'  => $payload,
            ];
        }

        // ── Boleto / Cobrança ─────────────────────────────────────
        if (isset($payload['cobrancas']) && is_array($payload['cobrancas'])) {
            $cob = $payload['cobrancas'][0] ?? $payload;
            return $this->normalizeBoleto($cob, $payload);
        }

        // Formato alternativo direto (sem wrapper 'cobrancas')
        if (isset($payload['nossoNumero']) || isset($payload['numeroTitulo'])) {
            return $this->normalizeBoleto($payload, $payload);
        }

        // ── Evento desconhecido ───────────────────────────────────
        return [
            'eventType'  => 'DESCONHECIDO',
            'eventId'    => (string) ($payload['id'] ?? uniqid('BB-UNK-')),
            'rawPayload' => $payload,
        ];
    }

    /**
     * Normaliza um payload de boleto/cobrança para formato consistente.
     *
     * @param array<string, mixed> $cob     Entrada da cobrança.
     * @param array<string, mixed> $raw     Payload original completo.
     * @return array<string, mixed>
     */
    private function normalizeBoleto(array $cob, array $raw): array
    {
        $situacao = strtoupper((string) ($cob['situacao'] ?? $cob['situacaoTitulo'] ?? ''));

        $eventType = match ($situacao) {
            'LIQUIDADA', 'PAGA'   => self::EVENT_BOLETO_LIQUIDADO,
            'VENCIDA'             => self::EVENT_BOLETO_VENCIDO,
            'BAIXADA', 'CANCELADA' => self::EVENT_BOLETO_BAIXADO,
            default               => 'BOLETO_' . ($situacao ?: 'ATUALIZADO'),
        };

        $nossoNumero = (string) (
            $cob['nossoNumero']  ??
            $cob['numeroTitulo'] ??
            $cob['id']           ??
            uniqid('BB-BOL-')
        );

        return [
            'eventType'     => $eventType,
            'eventId'       => $nossoNumero,
            'nossoNumero'   => $nossoNumero,
            'valor'         => (float) ($cob['valor']['original'] ?? $cob['valor'] ?? 0.0),
            'dataPagamento' => (string) ($cob['pix']['horario'] ?? $cob['dataPagamento'] ?? ''),
            'convenio'      => (int)   ($cob['convenio'] ?? 0),
            'situacao'      => $situacao,
            'rawPayload'    => $raw,
        ];
    }
}
