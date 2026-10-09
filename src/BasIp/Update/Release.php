<?php

declare(strict_types=1);

namespace BasIp\Update;

/**
 * A published release fetched from the GitHub API.
 */
final class Release
{
    /** @param array<int, array<string, mixed>> $assets */
    public function __construct(
        public readonly string $tag,
        public readonly string $name,
        public readonly string $body,
        public readonly string $url,
        public readonly string $downloadUrl,
        public readonly string $publishedAt,
        public readonly bool $prerelease,
        public readonly array $assets = [],
        public readonly ?string $checksum = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromGitHub(array $data, string $repo): ?self
    {
        $tag = (string) ($data['tag_name'] ?? $data['name'] ?? '');
        if ($tag === '') {
            return null;
        }
        $assets = [];
        $checksum = null;
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $assets[] = ['name' => (string) ($asset['name'] ?? ''), 'url' => (string) ($asset['browser_download_url'] ?? ''), 'size' => (int) ($asset['size'] ?? 0)];
            if (str_ends_with((string) ($asset['name'] ?? ''), '.sha256')) {
                $checksum = (string) ($asset['browser_download_url'] ?? '');
            }
        }

        return new self(
            tag: $tag,
            name: (string) ($data['name'] ?? $tag),
            body: (string) ($data['body'] ?? ''),
            url: (string) ($data['html_url'] ?? ('https://github.com/' . $repo . '/releases/tag/' . $tag)),
            downloadUrl: (string) ($data['zipball_url'] ?? ('https://api.github.com/repos/' . $repo . '/zipball/' . $tag)),
            publishedAt: (string) ($data['published_at'] ?? $data['created_at'] ?? ''),
            prerelease: (bool) ($data['prerelease'] ?? false),
            assets: $assets,
            checksum: $checksum,
        );
    }

    public function version(): string
    {
        return ltrim($this->tag, 'vV');
    }

    /** First lines of the release notes, markdown stripped. */
    public function summary(int $limit = 600): string
    {
        $text = preg_replace('/```.*?```/s', '', $this->body) ?? $this->body;
        $text = preg_replace('/[#*_>`]/', '', $text) ?? $text;
        $text = trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tag' => $this->tag, 'version' => $this->version(), 'name' => $this->name,
            'url' => $this->url, 'download_url' => $this->downloadUrl,
            'published_at' => $this->publishedAt, 'prerelease' => $this->prerelease,
            'body' => $this->body, 'assets' => $this->assets,
        ];
    }
}
