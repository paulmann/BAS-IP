<?php

declare(strict_types=1);

namespace BasIp\Device;

/**
 * A barrier/panel driver.
 *
 * The application talks to *this* interface, never to a specific vendor API.
 * Three drivers ship out of the box:
 *   • `basip`      — BAS-IP Camdroid panels over their `/api/v0` HTTP API
 *   • `http-relay` — any dumb HTTP relay board (NetPing, Sonoff, ESP32, …)
 *   • `dryrun`     — no hardware: always succeeds, perfect for demos and tests
 *
 * Adding support for Came / Nice / DoorHan / Faac means implementing ~6 methods.
 */
interface GateDriver
{
    public function name(): string;

    public function label(): string;

    /** @param array<string, mixed> $options base_url, login, password, channel, timeout, verify_tls, … */
    public function configure(array $options): void;

    /** Is the panel reachable and authenticated? */
    public function ping(): GateResult;

    /**
     * Pulse the relay / open the barrier.
     *
     * @param int $channel 1-based lock/relay number
     */
    public function open(int $channel = 1): GateResult;

    public function close(int $channel = 1): GateResult;

    /** Structured status: SIP state, firmware, uptime, doors, network. @return array<string, mixed> */
    public function status(): GateResult;

    /** Device clock (used to detect panel time drift). */
    public function time(): GateResult;

    /** One JPEG frame, or null when the camera is off. */
    public function snapshot(): ?string;

    /** @return array<int, array<string, mixed>> configured camera/RTSP streams */
    public function cameras(): GateResult;

    public function reboot(): GateResult;

    /** Enable/disable the SIP stack (panel intercom registration). */
    public function sipEnable(bool $enable): GateResult;

    /** Arbitrary vendor call for things the UI does not model yet. */
    public function raw(string $method, string $path, mixed $body = null): GateResult;

    /** Free the session token / close sockets. */
    public function disconnect(): void;

    /** Whether this driver supports a given optional capability. */
    public function supports(string $capability): bool;
}
