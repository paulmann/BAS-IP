<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;
use RuntimeException;

/**
 * Uploaded-file wrapper with extension / MIME / size validation.
 */
final class UploadedFile
{
    private const MIME_MAP = [
        'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'json' => ['application/json', 'text/plain'],
        'txt'  => ['text/plain'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml'],
        'sql'  => ['text/plain', 'application/sql', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        public readonly string $tmpPath,
        public readonly string $originalName,
        public readonly int $size,
        public readonly string $clientMime,
        public readonly int $error,
    ) {
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpPath);
    }

    /** True when the file came from the test-suite / CLI instead of an HTTP upload. */
    public function isTestFile(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->tmpPath) && !is_uploaded_file($this->tmpPath);
    }

    public function extension(): string
    {
        $ext = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));

        return preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
    }

    public function errorMessage(): ?string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK         => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => 'Файл превышает максимально допустимый размер.',
            UPLOAD_ERR_PARTIAL    => 'Файл загружен частично.',
            UPLOAD_ERR_NO_FILE    => 'Файл не выбран.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере нет временного каталога.',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
            UPLOAD_ERR_EXTENSION  => 'Загрузка заблокирована расширением PHP.',
            default               => 'Неизвестная ошибка загрузки.',
        };
    }

    public function realMime(): string
    {
        if (!function_exists('finfo_open') || !is_file($this->tmpPath)) {
            return $this->clientMime;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return $this->clientMime;
        }
        $mime = finfo_file($finfo, $this->tmpPath);
        finfo_close($finfo);

        return is_string($mime) ? $mime : $this->clientMime;
    }

    /**
     * @param array<int, string> $allowedExtensions
     * @return array{0: bool, 1: ?string}
     */
    public function validate(array $allowedExtensions, int $maxBytes): array
    {
        if (!$this->isValid() && !$this->isTestFile()) {
            return [false, $this->errorMessage() ?? 'Некорректная загрузка файла.'];
        }
        if ($this->size > $maxBytes) {
            return [false, sprintf('Файл больше %s.', \BasIp\Support\Str::bytes($maxBytes))];
        }
        $ext = $this->extension();
        if (!in_array($ext, $allowedExtensions, true)) {
            return [false, 'Недопустимый тип файла: .' . $ext . '. Разрешено: ' . implode(', ', $allowedExtensions)];
        }
        $expected = self::MIME_MAP[$ext] ?? null;
        if ($expected !== null) {
            $real = $this->realMime();
            if (!in_array($real, $expected, true) && !str_starts_with($real, 'text/')) {
                return [false, sprintf('Содержимое файла не соответствует расширению (%s).', $real)];
            }
        }

        return [true, null];
    }

    public function contents(): string
    {
        $data = @file_get_contents($this->tmpPath);
        if ($data === false) {
            throw new RuntimeException('Unable to read the uploaded file.');
        }

        return $data;
    }

    public function moveTo(string $destination): string
    {
        Fs::ensureDir(dirname($destination));
        $ok = $this->isValid()
            ? @move_uploaded_file($this->tmpPath, $destination)
            : @rename($this->tmpPath, $destination) || Fs::copy($this->tmpPath, $destination);
        if (!$ok) {
            throw new RuntimeException('Unable to store the uploaded file.');
        }
        @chmod($destination, 0640);

        return $destination;
    }

    public function hash(string $algo = 'sha256'): string
    {
        return hash_file($algo, $this->tmpPath) ?: '';
    }
}
