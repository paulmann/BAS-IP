<?php

declare(strict_types=1);

namespace BasIp\Notify\Channel;

use BasIp\Notify\Message;
use BasIp\Notify\MimeMessage;
use BasIp\Notify\SmtpClient;
use Throwable;

/**
 * E-mail delivery: `smtp` (own client, no dependencies), `mail` (PHP's mail())
 * or `log` (dry-run).
 */
final class MailChannel extends AbstractChannel
{
    public function name(): string  { return 'mail'; }
    public function label(): string { return 'E-mail (SMTP)'; }
    public function icon(): string  { return 'mail'; }

    public function enabled(): bool
    {
        return (bool) $this->option('enabled', $this->config->bool('notify.channels.mail.enabled', false));
    }

    public function supports(string $contactType): bool
    {
        return $contactType === 'email';
    }

    public function send(string $to, Message $message): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Некорректный e-mail: ' . $to];
        }

        $driver = (string) $this->option('driver', $this->config->string('notify.mail.driver', 'smtp'));
        $fromAddress = (string) ($this->option('from')['address'] ?? $this->config->string('notify.mail.from.address', 'no-reply@localhost'));
        $fromName    = (string) ($this->option('from')['name'] ?? $this->config->string('notify.mail.from.name', 'BAS-IP'));
        $replyTo     = $this->option('reply_to', $this->config->get('notify.mail.reply_to'));

        $mime = MimeMessage::make()
            ->subject($message->getSubject() !== '' ? $message->getSubject() : 'Уведомление')
            ->text($message->getBody())
            ->html($this->toHtml($message->getBody(), $message->getSubject()))
            ->from($fromName !== '' ? $fromName . ' <' . $fromAddress . '>' : $fromAddress)
            ->replyTo(is_string($replyTo) && $replyTo !== '' ? $replyTo : null)
            ->to([$to])
            ->header('X-BAS-IP-Template', $message->getTemplate())
            ->header('X-BAS-IP-Resident', (string) ($message->getResidentId() ?? ''));

        try {
            if ($driver === 'log') {
                $this->log('info', 'E-mail (dry-run)', ['to' => $to, 'subject' => $message->getSubject()]);

                return ['ok' => true, 'provider_id' => 'mail:log'];
            }

            if ($driver === 'mail') {
                $raw = $mime->raw();
                [$headers, $body] = explode("\r\n\r\n", $raw, 2);
                $headers = preg_replace('/^(To|Subject):.*\r?\n/mi', '', $headers) ?? $headers;
                $ok = @mail($to, $mime->encodeHeader($message->getSubject()), $body, trim($headers));

                return $ok
                    ? ['ok' => true, 'provider_id' => 'mail:php']
                    : ['ok' => false, 'error' => 'Функция mail() вернула ошибку (проверьте sendmail_path).'];
            }

            $smtp = new SmtpClient(
                host: (string) $this->option('host', $this->config->string('notify.mail.host', 'localhost')),
                port: (int) $this->option('port', $this->config->int('notify.mail.port', 587)),
                encryption: (string) $this->option('encryption', $this->config->string('notify.mail.encryption', 'tls')),
                username: $this->option('username', $this->config->get('notify.mail.username')) ?: null,
                password: $this->secret('password', $this->config->string('notify.mail.password', '')) ?: null,
                timeout: (int) $this->option('timeout', $this->config->int('notify.mail.timeout', 20)),
                hello: $this->option('hello', $this->config->get('notify.mail.hello')) ?: null,
                caFile: $this->option('ca_file', $this->config->get('notify.mail.ca_file')) ?: null,
                verifyPeer: (bool) $this->option('verify_peer', $this->config->bool('notify.mail.verify_peer', true)),
            );

            $smtp->connect();
            $smtp->send($fromAddress, [$to], $mime->raw());
            $providerId = 'smtp:' . substr(hash('sha256', $to . microtime()), 0, 16);
            $smtp->quit();

            return ['ok' => true, 'provider_id' => $providerId];
        } catch (Throwable $e) {
            $this->log('error', 'Ошибка отправки e-mail', ['to' => $to, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function test(string $to): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => 'Канал e-mail отключён в настройках.'];
        }

        return parent::test($to);
    }

    public function configFields(): array
    {
        return [
            ['key' => 'enabled', 'label' => 'Канал включён', 'type' => 'bool'],
            ['key' => 'driver', 'label' => 'Драйвер', 'type' => 'select', 'options' => ['smtp' => 'SMTP', 'mail' => 'PHP mail()', 'log' => 'Только журнал']],
            ['key' => 'host', 'label' => 'SMTP-сервер', 'type' => 'text', 'placeholder' => 'smtp.yandex.ru'],
            ['key' => 'port', 'label' => 'Порт', 'type' => 'number', 'placeholder' => '465'],
            ['key' => 'encryption', 'label' => 'Шифрование', 'type' => 'select', 'options' => ['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'Без шифрования']],
            ['key' => 'username', 'label' => 'Логин', 'type' => 'text'],
            ['key' => 'password', 'label' => 'Пароль', 'type' => 'secret'],
            ['key' => 'from_address', 'label' => 'Адрес отправителя', 'type' => 'text'],
            ['key' => 'from_name', 'label' => 'Имя отправителя', 'type' => 'text'],
            ['key' => 'reply_to', 'label' => 'Reply-To', 'type' => 'text'],
        ];
    }

    /** Very light text → HTML conversion used for the multipart/alternative part. */
    private function toHtml(string $text, string $subject): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // Turn bare links into anchors.
        $escaped = (string) preg_replace(
            '~(https?://[^\s<]+)~i',
            '<a href="$1" style="color:#2563eb;word-break:break-all">$1</a>',
            $escaped
        );
        $body = nl2br($escaped);

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($subject, ENT_QUOTES) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f4f4f5;'
            . 'font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
            . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e4e4e7;'
            . 'border-radius:12px;padding:24px;color:#18181b;line-height:1.65;font-size:15px">'
            . ($subject !== '' ? '<h1 style="font-size:18px;margin:0 0 16px;color:#09090b">' . htmlspecialchars($subject, ENT_QUOTES) . '</h1>' : '')
            . '<div>' . $body . '</div>'
            . '<hr style="border:0;border-top:1px solid #e4e4e7;margin:24px 0 12px">'
            . '<p style="margin:0;font-size:12px;color:#71717a">Письмо отправлено автоматически системой управления шлагбаумом BAS-IP.</p>'
            . '</div></body></html>';
    }
}
