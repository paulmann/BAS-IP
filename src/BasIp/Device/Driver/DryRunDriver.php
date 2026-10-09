<?php

declare(strict_types=1);

namespace BasIp\Device\Driver;

use BasIp\Core\Logger;
use BasIp\Device\GateDriver;
use BasIp\Device\GateResult;

/**
 * Hardware-free simulator.
 *
 * Used for demos, the test-suite, CI and for "I don't have the panel on my
 * desk yet" development. Every command succeeds after a small artificial delay
 * and is written to the gate log exactly like a real one, so the whole stack
 * (permissions, scheduling, audit, notifications) can be exercised.
 */
final class DryRunDriver implements GateDriver
{
    /** @var array<string, mixed> */
    private array $options = [];
    private int $opens = 0;

    public function __construct(private readonly Logger $logger, private readonly string $id = 'dryrun')
    {
    }

    public function name(): string  { return 'dryrun'; }
    public function label(): string { return 'Эмулятор (без железа)'; }

    public function configure(array $options): void
    {
        $this->options = $options;
    }

    public function supports(string $capability): bool
    {
        return true;
    }

    public function open(int $channel = 1): GateResult
    {
        $this->opens++;
        $this->sleep();
        $this->log('open', $channel);

        return GateResult::success('Шлагбаум открыт (эмулятор)', [
            'simulated' => true, 'channel' => $channel, 'opens_total' => $this->opens,
        ], [], 12.5);
    }

    public function close(int $channel = 1): GateResult
    {
        $this->sleep();
        $this->log('close', $channel);

        return GateResult::success('Шлагбаум закрыт (эмулятор)', ['simulated' => true, 'channel' => $channel]);
    }

    public function status(): GateResult
    {
        return GateResult::success('Эмулятор в порядке', [
            'simulated'  => true,
            'sip_status' => 'registered',
            'firmware'   => 'DRY-RUN 2.0',
            'uptime'     => time(),
            'opens'      => $this->opens,
            'doors'      => [['id' => 1, 'state' => 'closed']],
        ]);
    }

    public function ping(): GateResult
    {
        return GateResult::success('Эмулятор на связи', ['simulated' => true, 'driver' => $this->name()], [], 0.4);
    }

    public function time(): GateResult
    {
        return GateResult::success('Время эмулятора', [
            'device_time_unix' => time(),
            'device_timezone'  => date_default_timezone_get(),
            'drift_seconds'    => 0,
            'drift_ok'         => true,
        ]);
    }

    public function snapshot(): ?string
    {
        // 1×1 transparent PNG so the UI never shows a broken image.
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ) ?: null;
    }

    public function cameras(): GateResult
    {
        return GateResult::success('Камеры эмулятора', ['cameras' => [], 'count' => 0]);
    }

    public function reboot(): GateResult
    {
        $this->log('reboot', 1);

        return GateResult::success('Перезагрузка эмулятора (ничего не произошло)', ['simulated' => true]);
    }

    public function sipEnable(bool $enable): GateResult
    {
        return GateResult::success($enable ? 'SIP включён (эмулятор)' : 'SIP выключен (эмулятор)', ['sip_enable' => $enable, 'simulated' => true]);
    }

    public function raw(string $method, string $path, mixed $body = null): GateResult
    {
        return GateResult::success('RAW ' . $method . ' ' . $path, ['simulated' => true, 'echo' => $body]);
    }

    public function disconnect(): void
    {
    }

    private function sleep(): void
    {
        $ms = (int) ($this->options['latency_ms'] ?? 60);
        if ($ms > 0) {
            usleep(min(2000, $ms) * 1000);
        }
    }

    private function log(string $command, int $channel): void
    {
        $this->logger->channel('device')->info('DryRun: ' . $command, ['device' => $this->id, 'channel' => $channel]);
    }
}
