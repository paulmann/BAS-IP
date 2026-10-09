<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Api;

use BasIp\Auth\ApiPrincipal;
use BasIp\Core\App;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\Invoice;
use BasIp\Domain\Model\Resident;
use BasIp\Http\Controller;
use BasIp\Notify\Message;
use BasIp\Notify\NotificationService;
use BasIp\Services\BillingService;
use BasIp\Services\DataService;
use BasIp\Services\StatisticsService;
use BasIp\Support\Money;

/**
 * Read/meta API plus a Telegram bot webhook.
 */
final class MetaApiController extends Controller
{
    /** Public capability document — safe without a token. */
    public function meta(Request $request): Response
    {
        return $this->json([
            'name' => $this->config()->string('app.name'),
            'version' => App::VERSION,
            'codename' => App::CODENAME,
            'api_version' => $this->config()->string('api.version', 'v1'),
            'docs' => $this->config()->bool('api.docs', true) ? $request->url('/api/v1/docs') : null,
            'capabilities' => [
                'gate.open', 'gate.close', 'gate.status', 'gate.log', 'gate.snapshot',
                'guest.create', 'guest.revoke', 'guest.view',
                'residents.view', 'billing.view', 'billing.payment.record',
                'notifications.send', 'system.health',
            ],
            'authentication' => 'Authorization: Bearer <token>',
            'rate_limits' => (array) $this->config()->get('security.rate_limits', []),
            'time' => date('c'),
            'timezone' => $this->config()->string('app.timezone'),
        ])->cache(300, true);
    }

    public function health(Request $request): Response
    {
        /** @var DataService $data */
        $data = $this->svc(DataService::class);
        $health = $data->health($request->bool('deep'));

        return $this->json($health, $health['status'] === 'ok' ? 200 : 503)->noCache();
    }

    public function residents(Request $request): Response
    {
        $this->requirePrincipal();
        $filters = $this->filters($request, ['q', 'status', 'building', 'apartment_from', 'apartment_to', 'tag']);
        $filters['status'] = $request->str('status', 'active');
        $paginator = $this->residentRepo()->search($filters, $this->perPage($request, 50), $this->page($request));
        /** @var array<int, Resident> $items */
        $items = $paginator->items();
        $this->residentRepo()->loadRelations($items);

        return $this->paginateJson($paginator, fn (Resident $r): array => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'full_name' => $r->fullName,
            'short_name' => $r->shortName(),
            'apartment' => $r->apartment,
            'building' => $r->building,
            'entrance' => $r->entrance,
            'phone' => $r->phone,
            'email' => $r->email,
            'is_active' => $r->isActive,
            'access_granted' => $r->accessGranted,
            'balance' => $r->balance,
            'debt' => $r->debt,
            'cars' => array_map(static fn (array $c): array => [
                'plate' => $c['plate'], 'brand' => $c['brand'], 'model' => $c['model'], 'active' => (bool) $c['is_active'],
            ], $r->cars),
            'contacts' => array_map(static fn (array $c): array => [
                'type' => $c['type'], 'value' => $c['value'], 'label' => $c['label'] ?? null,
            ], $r->contacts),
            'can_open_gate' => $r->canOpenGate(),
        ]);
    }

    public function resident(Request $request, string $id = ''): Response
    {
        $this->requirePrincipal();
        $resident = $this->residentRepo()->findById((int) $id, true);
        if ($resident === null) {
            return $this->jsonError('Житель не найден.', 404);
        }

        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);
        /** @var \BasIp\Services\GateService $gate */
        $gate = $this->svc(\BasIp\Services\GateService::class);

        return $this->json([
            'resident' => $resident->toArray(),
            'statement' => $billing->residentStatement($resident->id),
            'invoices' => array_map(static fn (Invoice $i): array => [
                'id' => $i->id, 'number' => $i->number, 'amount' => $i->amount,
                'paid' => $i->paidAmount, 'balance' => $i->balance, 'status' => $i->status,
                'due_date' => $i->dueDate, 'period' => $i->periodLabel, 'kind' => $i->kind,
            ], $billing->invoices(['resident_id' => $resident->id], 100, 1)->items()),
            'payments' => array_map(static fn ($p): array => [
                'id' => $p->id, 'amount' => $p->amount, 'date' => $p->paidAt,
                'method' => $p->method, 'reference' => $p->reference,
            ], $billing->paymentsForResident($resident->id, 50)),
            'recent_events' => array_map(static fn ($e): array => [
                'at' => $e->occurredAt, 'access_type' => $e->accessType,
                'result' => $e->result, 'source' => $e->source,
            ], array_slice($gate->eventsForResident($resident->id, 20), 0, 20)),
        ]);
    }

    public function recordPayment(Request $request): Response
    {
        $principal = $this->requirePrincipal();
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);

        $result = $billing->recordPayment([
            'resident_id' => $request->int('resident_id'),
            'amount'      => $request->float('amount'),
            'invoice_id'  => $request->filled('invoice_id') ? $request->int('invoice_id') : null,
            'paid_at'     => $request->str('paid_at') ?: null,
            'method'      => $request->str('method', 'online'),
            'reference'   => $request->str('reference'),
            'external_id' => $request->str('external_id'),
            'note'        => $request->str('note') ?: ('API: ' . $principal->name),
            'payer_name'  => $request->str('payer_name'),
        ], $principal->user, $request);

        return $this->json($result, $result['ok'] ? 201 : 422);
    }

    public function stats(Request $request): Response
    {
        $this->requirePrincipal();
        /** @var StatisticsService $stats */
        $stats = $this->svc(StatisticsService::class);

        return $this->json([
            'overview' => $stats->overview(30),
            'gate_by_day' => $stats->gateByDay(min(90, max(7, $request->int('days', 30)))),
            'payments_by_month' => $stats->paymentsByMonth(12),
            'collection_rate' => $stats->collectionRate(12),
            'top_residents' => $stats->topResidentsByOpens(30, 10),
        ]);
    }

    public function sendNotification(Request $request): Response
    {
        $principal = $this->requirePrincipal();
        /** @var NotificationService $notify */
        $notify = $this->svc(NotificationService::class);

        $residentId = $request->int('resident_id');
        $resident = $residentId > 0 ? $this->residentRepo()->findById($residentId) : null;
        if ($residentId > 0 && $resident === null) {
            return $this->jsonError('Житель не найден.', 404);
        }

        $message = ($resident !== null ? Message::forResident($resident) : Message::make())
            ->subject($request->str('subject', 'Уведомление'))
            ->body((string) $request->input('body', ''))
            ->template($request->str('template', 'api'))
            ->channels($request->array('channels') ?: null);

        if ($resident === null && $request->filled('to')) {
            $message = $message->to($request->str('to'));
            if ($request->filled('channel')) {
                $message = $message->channel($request->str('channel'));
            }
        }

        $queued = $notify->queue($message, $resident);

        return $this->json(['ok' => true, 'queued' => $queued, 'sent_by' => $principal->name]);
    }

    /** Machine-readable API documentation served from the app itself. */
    public function docs(Request $request): Response
    {
        $docs = [
            'title' => 'BAS-IP Gate Manager — REST API v1',
            'base_url' => rtrim($this->config()->string('app.url', ''), '/') . '/api/v1',
            'authentication' => [
                'type' => 'bearer',
                'header' => 'Authorization: Bearer <token>',
                'where_to_get' => 'Админка → Система → API-токены',
                'scopes' => \BasIp\Services\ApiTokenService::PRESET_SCOPES,
            ],
            'rate_limits' => (array) $this->config()->get('security.rate_limits', []),
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/v1/meta', 'auth' => false, 'description' => 'Возможности и версия'],
                ['method' => 'GET', 'path' => '/api/v1/health', 'auth' => false, 'description' => 'Состояние системы'],
                ['method' => 'GET', 'path' => '/api/v1/gate/status', 'scopes' => ['gate.status'], 'description' => 'Статус панели'],
                ['method' => 'POST', 'path' => '/api/v1/gate/open', 'scopes' => ['gate.open'], 'description' => 'Открыть шлагбаум', 'body' => ['resident_id' => 'int (или apartment/plate)', 'device' => 'string?', 'channel' => 'int?', 'note' => 'string?']],
                ['method' => 'POST', 'path' => '/api/v1/gate/close', 'scopes' => ['gate.close'], 'description' => 'Закрыть шлагбаум'],
                ['method' => 'GET', 'path' => '/api/v1/gate/events', 'scopes' => ['gate.log'], 'description' => 'Журнал открываний', 'query' => ['from', 'to', 'access_type', 'result', 'resident_id', 'page', 'per_page']],
                ['method' => 'GET', 'path' => '/api/v1/gate/snapshot', 'scopes' => ['gate.status'], 'description' => 'Кадр с камеры (image/jpeg)'],
                ['method' => 'POST', 'path' => '/api/v1/guest/passes', 'scopes' => ['guest.create'], 'description' => 'Создать гостевой доступ (12 ч по умолчанию)', 'body' => ['resident_id' => 'int', 'ttl_hours' => 'int?', 'guest_name' => 'string?', 'guest_phone' => 'string?', 'car_plate' => 'string?', 'purpose' => 'string?']],
                ['method' => 'GET', 'path' => '/api/v1/guest/passes', 'scopes' => ['guest.view'], 'description' => 'Список гостевых доступов'],
                ['method' => 'DELETE', 'path' => '/api/v1/guest/passes/{id}', 'scopes' => ['guest.revoke'], 'description' => 'Отозвать гостевой доступ'],
                ['method' => 'GET', 'path' => '/api/v1/residents', 'scopes' => ['residents.view'], 'description' => 'Список жителей', 'query' => ['q', 'status', 'building', 'page', 'per_page']],
                ['method' => 'GET', 'path' => '/api/v1/residents/{id}', 'scopes' => ['residents.view'], 'description' => 'Карточка жителя со счетами и оплатами'],
                ['method' => 'POST', 'path' => '/api/v1/payments', 'scopes' => ['billing.payment.record'], 'description' => 'Провести оплату', 'body' => ['resident_id' => 'int', 'amount' => 'float', 'invoice_id' => 'int?', 'paid_at' => 'Y-m-d?', 'method' => 'cash|card|transfer|online|sbp|other', 'reference' => 'string?', 'external_id' => 'string?']],
                ['method' => 'GET', 'path' => '/api/v1/stats', 'scopes' => ['billing.view'], 'description' => 'Сводная статистика'],
                ['method' => 'POST', 'path' => '/api/v1/notifications', 'scopes' => ['notifications.send'], 'description' => 'Отправить уведомление', 'body' => ['resident_id' => 'int?', 'to' => 'string?', 'channel' => 'string?', 'subject' => 'string', 'body' => 'string']],
            ],
            'errors' => [
                '401' => 'Нет токена или токен недействителен',
                '403' => 'Токену не хватает области доступа (scope)',
                '404' => 'Ресурс не найден',
                '422' => 'Ошибка валидации или команда отклонена политикой',
                '429' => 'Превышен лимит запросов',
                '502' => 'Панель не отвечает',
            ],
            'examples' => [
                'curl -H "Authorization: Bearer $TOKEN" https://gate.example.com/api/v1/gate/status',
                'curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" '
                . '-d \'{"apartment":"12","note":"курьер"}\' https://gate.example.com/api/v1/gate/open',
                'curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" '
                . '-d \'{"resident_id":42,"ttl_hours":12,"guest_name":"Курьер"}\' https://gate.example.com/api/v1/guest/passes',
            ],
        ];

        if ($request->expectsJson() || $request->str('format') === 'json') {
            return $this->json($docs);
        }

        return $this->view('help/api', ['title' => 'API', 'docs' => $docs, 'layout' => 'app']);
    }

    /**
     * Telegram bot webhook: `/open`, `/status`, `/guest`, `/balance`, `/help`.
     *
     * Protected by `integrations.telegram_bot.secret` compared in constant time.
     */
    public function telegramWebhook(Request $request): Response
    {
        $expected = $this->config()->string('integrations.telegram_bot.secret', '');
        $provided = (string) ($request->header('X-Telegram-Bot-Api-Secret-Token') ?? $request->str('secret'));
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return $this->jsonError('Forbidden', 403);
        }

        $update = json_decode($request->body, true);
        if (!is_array($update)) {
            return $this->jsonError('Bad payload', 400);
        }
        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
        if (!is_array($message)) {
            return $this->json(['ok' => true, 'ignored' => true]);
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));
        $adminChat = (string) $this->config()->string('integrations.telegram_bot.admin_chat_id', '');

        // Only the configured admin chat may drive the gate.
        if ($adminChat !== '' && (string) $chatId !== $adminChat) {
            return $this->json(['ok' => false, 'error' => 'chat not authorised']);
        }

        $reply = $this->handleTelegramCommand($text, $chatId);

        $token = $this->config()->string('integrations.telegram_bot.token', '');
        if ($token !== '' && $reply !== '') {
            $this->svc(\BasIp\Core\HttpClient::class)->postJson(
                rtrim($this->config()->string('notify.channels.telegram.api', 'https://api.telegram.org'), '/') . '/bot' . $token . '/sendMessage',
                ['chat_id' => $chatId, 'text' => $reply, 'parse_mode' => 'HTML'],
                ['timeout' => 10]
            );
        }

        return $this->json(['ok' => true]);
    }

    private function handleTelegramCommand(string $text, int $chatId): string
    {
        $command = strtolower(strtok($text, ' ') ?: '');
        $arg = trim(substr($text, strlen($command)));

        return match ($command) {
            '/open', 'open' => $this->telegramOpen($arg),
            '/status', 'status' => $this->telegramStatus(),
            '/guest', 'guest' => $this->telegramGuest($arg),
            '/balance', 'balance' => $this->telegramBalance($arg),
            '/log', 'log' => $this->telegramLog(),
            default => "Доступные команды:\n/open [квартира] — открыть\n/status — состояние\n/guest [квартира] [часы] — гостевой доступ\n/balance [квартира] — долги\n/log — последние события\n/help — эта справка",
        };
    }

    private function telegramOpen(string $arg): string
    {
        /** @var \BasIp\Services\GateService $gate */
        $gate = $this->svc(\BasIp\Services\GateService::class);
        $resident = null;
        if ($arg !== '') {
            $row = $this->db()->table('residents')->where('apartment', '=', $arg)->where('is_active', '=', 1)->whereNull('deleted_at')->first();
            $resident = $row === null ? null : $this->residentRepo()->hydrate($row, true);
        }

        $result = $gate->open([
            'resident' => $resident,
            'access_type' => GateEvent::ACCESS_REMOTE,
            'source' => 'telegram',
            'note' => 'Команда из Telegram',
        ]);

        return $result->ok ? '✅ Шлагбаум открыт.' : '⛔ ' . $result->message;
    }

    private function telegramStatus(): string
    {
        /** @var \BasIp\Services\GateService $gate */
        $gate = $this->svc(\BasIp\Services\GateService::class);
        $result = $gate->status();
        $stats = $gate->stats(1);

        return sprintf(
            "%s Панель: %s\nСегодня открытий: %d (успешно %d, отказов %d)\nГостевых доступов активно: %d",
            $result->ok ? '🟢' : '🔴',
            $result->ok ? ($result->get('sip_status') ?? 'на связи') : $result->message,
            $stats['today'], $stats['success'], $stats['denied'],
            $this->db()->table('guest_passes')->where('status', '=', 'active')->where('valid_until', '>', date('Y-m-d H:i:s'))->count()
        );
    }

    private function telegramGuest(string $arg): string
    {
        $parts = preg_split('/\s+/', trim($arg)) ?: [];
        $apartment = $parts[0] ?? '';
        $hours = (int) ($parts[1] ?? $this->settingsService()->int('guest.default_ttl_hours', 12));
        if ($apartment === '') {
            return 'Укажите квартиру: /guest 12 12';
        }
        $row = $this->db()->table('residents')->where('apartment', '=', $apartment)->where('is_active', '=', 1)->whereNull('deleted_at')->first();
        if ($row === null) {
            return 'Житель с квартирой «' . htmlspecialchars($apartment) . '» не найден.';
        }

        /** @var \BasIp\Services\GuestPassService $passes */
        $passes = $this->svc(\BasIp\Services\GuestPassService::class);
        $result = $passes->create([
            'resident_id' => (int) $row['id'], 'ttl_hours' => $hours,
            'purpose' => 'Создан из Telegram', 'notify' => false,
            'link_base' => rtrim($this->config()->string('app.url', ''), '/'),
        ]);

        if (!$result['ok']) {
            return '⛔ ' . htmlspecialchars((string) ($result['error'] ?? 'Ошибка'));
        }

        return sprintf(
            "🎫 Гостевой доступ\nКод: <b>%s</b>\nДо: %s\nСсылка: %s",
            htmlspecialchars((string) $result['code']),
            htmlspecialchars((string) ($result['pass']?->validUntil ?? '')),
            htmlspecialchars((string) $result['link'])
        );
    }

    private function telegramBalance(string $arg): string
    {
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);
        if ($arg !== '') {
            $row = $this->db()->table('residents')->where('apartment', '=', $arg)->whereNull('deleted_at')->first();
            if ($row === null) {
                return 'Житель не найден.';
            }
            $statement = $billing->residentStatement((int) $row['id']);

            return sprintf('Кв. %s: долг %s, переплата %s, открытых счетов %d', htmlspecialchars($arg), Money::format($statement['debt']), Money::format($statement['overpay']), $statement['invoices']);
        }

        $totals = $billing->invoiceTotals();

        return sprintf('Всего к сбору: %s\nПросрочено: %s (%d счетов)', Money::format($totals['outstanding']), Money::format($totals['overdue']), $totals['overdue_count']);
    }

    private function telegramLog(): string
    {
        /** @var \BasIp\Services\GateService $gate */
        $gate = $this->svc(\BasIp\Services\GateService::class);
        $events = $gate->recentEvents(8);
        if ($events === []) {
            return 'Событий пока нет.';
        }
        $lines = [];
        foreach ($events as $event) {
            $lines[] = sprintf('%s %s · %s · %s', $event->when('d.m H:i'), $event->isSuccess() ? '✅' : '⛔', htmlspecialchars($event->actorLabel()), htmlspecialchars($event->accessLabel()));
        }

        return implode("\n", $lines);
    }

    private function requirePrincipal(): ApiPrincipal
    {
        $principal = $this->c->get('api.principal');
        if (!$principal instanceof ApiPrincipal) {
            $this->forbidden('Требуется API-токен.');
        }

        return $principal;
    }
}
