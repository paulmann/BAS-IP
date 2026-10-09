<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Notify\Message;
use BasIp\Notify\NotificationService;
use BasIp\Notify\QueueWorker;
use BasIp\Notify\TemplateRenderer;
use BasIp\Support\Money;

/**
 * Mass mailings: composing a broadcast, template preview with live
 * placeholders, channel self-tests, batch monitoring and queue control.
 */
final class NotificationsController extends Controller
{
    private function notify(): NotificationService
    {
        return $this->svc(NotificationService::class);
    }

    public function index(Request $request): Response
    {
        $this->requirePermission('notifications.view');
        $notify = $this->notify();

        $filters = $this->filters($request, ['q', 'status']);

        return $this->view('admin/notifications/index', [
            'title'    => 'Рассылки и уведомления',
            'batches'  => $notify->batches($this->perPage($request, 20), $this->page($request), $filters),
            'outbox'   => $notify->outbox($this->filters($request, ['status', 'channel', 'batch_id', 'q']), 25, $request->int('outbox_page', 1)),
            'stats'    => $notify->queueStats(),
            'channels' => $notify->channelStatus(),
            'templates' => (array) $this->config()->get('notify.templates', []),
            'placeholders' => $this->svc(TemplateRenderer::class)->placeholders(),
            'query'    => $request->query,
            'canSend'  => $this->requireUser()->can('notifications.send'),
            'canManage' => $this->requireUser()->can('system.manage'),
            'residentsCount' => [
                'active'   => $this->residentRepo()->countActive(),
                'notify'   => $this->db()->table('residents')->where('is_active', '=', 1)->where('notify_enabled', '=', 1)->whereNull('deleted_at')->count(),
                'with_email' => $this->db()->table('contacts')->where('type', '=', 'email')->distinct()->count('resident_id'),
                'with_phone' => $this->db()->table('contacts')->where('type', '=', 'phone')->distinct()->count('resident_id'),
                'with_telegram' => $this->db()->table('contacts')->where('type', '=', 'telegram')->distinct()->count('resident_id'),
            ],
            'audiences' => [
                'active'   => 'Все активные жители',
                'debtors'  => 'Только должники',
                'no_debt'  => 'Только без долгов',
                'no_contact' => 'Без контактов (для проверки)',
                'selected' => 'Выбранные вручную',
            ],
        ]);
    }

    public function batch(Request $request, string $id = ''): Response
    {
        $this->requirePermission('notifications.view');
        $notify = $this->notify();
        $batch = $notify->batch((int) $id);
        if ($batch === null) {
            $this->notFound('Рассылка не найдена.');
        }

        return $this->view('admin/notifications/batch', [
            'title'   => 'Рассылка: ' . ($batch['name'] ?? '#' . $id),
            'batch'   => $batch,
            'messages' => $notify->outbox(['batch_id' => (int) $id] + $this->filters($request, ['status', 'channel']), $this->perPage($request, 50), $this->page($request)),
            'query'   => $request->query,
            'canSend' => $this->requireUser()->can('notifications.send'),
        ]);
    }

    /**
     * Compose and queue a broadcast.
     *
     * `audience`: active | debtors | no_debt | no_contact | selected
     */
    public function compose(Request $request): Response
    {
        $user = $this->requirePermission('notifications.send');
        $notify = $this->notify();
        /** @var TemplateRenderer $templates */
        $templates = $this->svc(TemplateRenderer::class);

        $subject = trim($request->str('subject', 'Информация'));
        $body = trim((string) $request->input('message', ''));
        if ($subject === '' || $body === '') {
            return $this->respond($request, false, 'Заполните тему и текст сообщения.', '/admin/notifications');
        }
        if (mb_strlen($body) > 20000) {
            return $this->respond($request, false, 'Сообщение слишком длинное (максимум 20 000 символов).', '/admin/notifications');
        }

        $channels = $request->array('channels') ?: $this->settingsService()->json('notify.channels_default', ['log']);
        $audience = $request->str('audience', 'active');
        $ids = array_map('intval', $request->array('resident_ids'));
        $template = $request->str('template', 'custom');
        $dryRun = $request->bool('dry_run');

        $residents = $this->audience($audience, $ids);
        if ($residents === []) {
            return $this->respond($request, false, 'Под выбранные условия не попал ни один активный житель.', '/admin/notifications');
        }

        $messages = [];
        $skipped = 0;
        foreach ($residents as $resident) {
            if (!$resident->notifyEnabled) {
                $skipped++;
                continue;
            }
            $statement = $this->svc(\BasIp\Services\BillingService::class)->residentStatement($resident->id);
            $context = [
                'name'      => $resident->shortName(),
                'full_name' => $resident->displayName(),
                'apartment' => $resident->apartment,
                'building'  => (string) $resident->building,
                'address'   => $resident->address(),
                'balance'   => Money::format($resident->balance, $this->config()->string('billing.currency_symbol', '₽')),
                'debt'      => Money::format($statement['debt'], $this->config()->string('billing.currency_symbol', '₽')),
                'amount'    => Money::format($statement['debt'], $this->config()->string('billing.currency_symbol', '₽')),
                'currency'  => $this->config()->string('billing.currency', 'RUB'),
                'period'    => date('F Y'),
                'due_date'  => (string) ($statement['oldest_due'] ?? ''),
                'pay_link'  => $this->settingsService()->string('billing.pay_link', ''),
                'organisation' => $this->settingsService()->string('ui.organisation_name', $this->config()->string('app.name', 'BAS-IP')),
                'support_phone' => $this->settingsService()->string('ui.support_phone', ''),
                'support_email' => $this->settingsService()->string('ui.support_email', ''),
                'cabinet_url' => rtrim($this->config()->string('app.url', ''), '/') . '/cabinet',
                'today'     => date('d.m.Y'),
            ];

            $messages[] = Message::forResident($resident)
                ->template($template)
                ->subject($templates->interpolate($subject, $context))
                ->body($templates->interpolate($body, $context))
                ->context($context)
                ->channels($channels);
        }

        if ($dryRun) {
            $sample = $messages[0] ?? null;

            return $this->json([
                'ok' => true, 'dry_run' => true, 'recipients' => count($messages), 'skipped' => $skipped,
                'channels' => $channels,
                'preview' => $sample === null ? null : [
                    'to' => $sample->getTo() ?? ($sample->getResidentId() !== null ? 'по контактам жителя' : null),
                    'subject' => $sample->getSubject(),
                    'body' => $sample->getBody(),
                ],
                'resolved' => $sample !== null ? $notify->resolve($sample) : [],
            ]);
        }

        $batch = $notify->queueBatch($messages, [
            'name'     => $request->str('name', 'Рассылка ' . date('d.m.Y H:i')),
            'template' => $template,
            'subject'  => $subject,
            'message'  => $body,
            'channels' => $channels,
            'created_by' => $user->id,
            'audience' => ['type' => $audience, 'ids' => $ids, 'count' => count($messages)],
            'scheduled_at' => $request->str('scheduled_at') ?: null,
        ]);

        $this->auditService()->record('notifications.compose', $user, [
            'subject_type' => 'batch', 'subject_id' => (string) $batch['id'], 'subject_label' => $subject,
            'meta' => ['queued' => $batch['queued'], 'skipped' => $skipped + $batch['skipped'], 'channels' => $channels, 'audience' => $audience],
        ], $request);

        return $this->respond(
            $request, true,
            sprintf('Рассылка создана: %d сообщений в очереди (пропущено %d).', $batch['queued'], $skipped + $batch['skipped']),
            '/admin/notifications/batches/' . (int) $batch['id']
        );
    }

    /** @return array<int, \BasIp\Domain\Model\Resident> */
    private function audience(string $type, array $ids): array
    {
        $filters = match ($type) {
            'debtors'    => ['status' => 'debtors'],
            'no_debt'    => ['status' => 'active'],
            'no_contact' => ['status' => 'active', 'no_contact' => 1],
            'selected'   => ['status' => 'all'],
            default      => ['status' => 'active'],
        };

        if ($type === 'selected') {
            return $ids === [] ? [] : $this->residentRepo()->findByIds($ids, true);
        }

        $paginator = $this->residentRepo()->search($filters, 5000, 1);
        /** @var array<int, \BasIp\Domain\Model\Resident> $residents */
        $residents = $paginator->items();
        $this->residentRepo()->loadRelations($residents);

        if ($type === 'no_debt') {
            $billing = $this->svc(\BasIp\Services\BillingService::class);
            $residents = array_values(array_filter($residents, static fn ($r): bool => $billing->residentStatement($r->id)['debt'] < 0.01));
        }

        return $residents;
    }

    /** Live preview of a template with a sample context. */
    public function preview(Request $request): Response
    {
        $this->requirePermission('notifications.view');
        /** @var TemplateRenderer $templates */
        $templates = $this->svc(TemplateRenderer::class);

        $template = $request->str('template', 'payment_new');
        $subject = $request->str('subject') ?: (string) ($this->config()->get('notify.templates.' . $template . '.subject', $template));
        $body = (string) $request->input('message') ?: (string) ($this->config()->get('notify.templates.' . $template . '.body', ''));

        $sample = $this->residentRepo()->scopeActive()->orderBy('apartment_no')->first();
        $resident = $sample !== null ? $this->residentRepo()->hydrate($sample, true) : null;

        $context = $resident !== null
            ? $this->svc(\BasIp\Services\BillingService::class)->messageContext($resident)
            : [
                'name' => 'Иванов И.И.', 'full_name' => 'Иванов Иван Иванович', 'apartment' => '12',
                'amount' => Money::format(5000, $this->config()->string('billing.currency_symbol', '₽')),
                'period' => date('F Y'), 'due_date' => date('d.m.Y', strtotime('+14 days')),
                'pay_link' => 'https://pay.example.com/?apt=12', 'balance' => '0 ₽', 'debt' => '5 000 ₽',
                'currency' => 'RUB', 'organisation' => $this->config()->string('app.name'),
                'today' => date('d.m.Y'), 'cabinet_url' => rtrim($this->config()->string('app.url', ''), '/') . '/cabinet',
            ];

        $context += [
            'code' => '7K4M-Q2XD', 'pin' => '482913', 'link' => rtrim($this->config()->string('app.url', ''), '/') . '/guest/TOKEN',
            'expires_at' => date('d.m.Y H:i', strtotime('+12 hours')), 'ttl_hours' => 12,
            'guest_name' => 'Курьер', 'used_at' => date('d.m.Y H:i'), 'used_count' => 1,
            'login' => 'ivanov', 'reason' => 'изменение суммы', 'actor' => 'Иванов И.И.',
            'access_type' => 'Гостевой', 'time' => date('d.m.Y H:i'), 'device' => 'Основной шлагбаум',
            'title' => 'Проверка панели', 'message' => 'Тестовое сообщение',
        ];

        return $this->json([
            'ok' => true,
            'subject' => $templates->interpolate($subject, $context),
            'body' => $templates->interpolate($body, $context),
            'context' => $context,
            'sample_resident' => $resident?->displayName(),
            'unknown' => $this->unknownPlaceholders($subject . "\n" . $body, $context),
        ]);
    }

    /** @param array<string, mixed> $context @return array<int, string> */
    private function unknownPlaceholders(string $text, array $context): array
    {
        $unknown = [];
        if (preg_match_all('/\{\s*([a-zA-Z0-9_.]+)\s*(?:\|[^}]*)?\}/', $text, $matches)) {
            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $context) && !\BasIp\Support\Arr::has($context, $key)) {
                    $unknown[] = $key;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /** Channel self-test («Проверить подключение»). */
    public function test(Request $request): Response
    {
        $this->requirePermission('settings.edit');
        $channelName = $request->str('channel', 'log');
        $to = trim($request->str('to', ''));

        $channel = $this->notify()->channel($channelName);
        if ($channel === null) {
            return $this->jsonError('Неизвестный канал: ' . $channelName, 404);
        }
        if ($to === '') {
            $user = $this->requireUser();
            $to = match ($channelName) {
                'mail'     => (string) ($user->email ?? ''),
                'telegram' => $this->settingsService()->string('notify.telegram_chat_id', ''),
                default    => '',
            };
        }
        if ($to === '') {
            return $this->jsonError('Укажите получателя для теста.', 422);
        }

        $result = $channel->test($to);
        $this->auditService()->info('notifications.test', $this->requireUser(), 'channel', $channelName, $to, $request, $result);

        return $this->json(['ok' => (bool) $result['ok'], 'message' => (string) $result['message'], 'channel' => $channelName], $result['ok'] ? 200 : 422);
    }

    public function retry(Request $request, string $id = ''): Response
    {
        $this->requirePermission('notifications.send');
        $count = $this->notify()->retryFailed((int) $id);

        return $this->respond($request, true, sprintf('Возобновлено сообщений: %d.', $count), '/admin/notifications/batches/' . (int) $id);
    }

    public function cancel(Request $request, string $id = ''): Response
    {
        $this->requirePermission('notifications.send');
        $count = $this->notify()->cancelBatch((int) $id);

        return $this->respond($request, true, sprintf('Отменено сообщений в очереди: %d.', $count), '/admin/notifications');
    }

    /** Process the queue synchronously (handy when cron is not set up yet). */
    public function runQueue(Request $request): Response
    {
        $this->requirePermission('system.manage');
        /** @var QueueWorker $worker */
        $worker = $this->svc(QueueWorker::class);
        $stats = $worker->run(min(500, max(1, $request->int('limit', 100))), min(120, max(5, $request->int('seconds', 50))));

        return $this->json(['ok' => true] + $stats + ['pending_left' => $worker->pendingCount()]);
    }

    public function templates(Request $request): Response
    {
        $this->requirePermission('settings.view');

        return $this->view('admin/notifications/templates', [
            'title' => 'Шаблоны уведомлений',
            'templates' => (array) $this->config()->get('notify.templates', []),
            'placeholders' => $this->svc(TemplateRenderer::class)->placeholders(),
            'names' => $this->svc(TemplateRenderer::class)->templateNames(),
        ]);
    }
}
