<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Update\Release;
use BasIp\Update\UpdateService;
use BasIp\Tests\Support\TestCase;

/**
 * The self-updater talks to the GitHub API and compares versions; both are
 * pure logic and must never regress — a wrong comparison either hides a new
 * release forever or "updates" to an older one.
 */
final class UpdateReleaseTest extends TestCase
{
    public function testFromGitHubParsesAFullPayload(): void
    {
        $release = Release::fromGitHub([
            'tag_name'      => 'v2.1.0',
            'name'          => '2.1.0 — Guest passes v2',
            'body'          => "## Changes\n- faster gate\n```php\nsecret\n```",
            'html_url'      => 'https://github.com/paulmann/BAS-IP/releases/tag/v2.1.0',
            'zipball_url'   => 'https://api.github.com/repos/paulmann/BAS-IP/zipball/v2.1.0',
            'published_at'  => '2026-10-01T10:00:00Z',
            'prerelease'    => false,
            'assets'        => [
                ['name' => 'bas-ip-2.1.0.zip', 'browser_download_url' => 'https://example.test/a.zip', 'size' => 123],
                ['name' => 'bas-ip-2.1.0.zip.sha256', 'browser_download_url' => 'https://example.test/a.sha256', 'size' => 64],
            ],
        ], 'paulmann/BAS-IP');

        $this->assertTrue($release instanceof Release);
        $this->assertSame('v2.1.0', $release->tag);
        $this->assertSame('2.1.0', $release->version());
        $this->assertSame('https://example.test/a.sha256', (string) $release->checksum);
        $this->assertCount(2, $release->assets);
        $this->assertSame('2026-10-01T10:00:00Z', $release->publishedAt);
        $this->assertSame(false, $release->prerelease);

        $summary = $release->summary();
        $this->assertStringContains('faster gate', $summary);
        $this->assertStringNotContains('secret', $summary);   // code fences are stripped
        $this->assertStringNotContains('##', $summary);       // markdown markers are stripped
    }

    public function testFromGitHubFallsBackToSaneDefaults(): void
    {
        $release = Release::fromGitHub(['name' => 'v3.0.0'], 'paulmann/BAS-IP');
        $this->assertTrue($release instanceof Release);
        $this->assertSame('v3.0.0', $release->tag);
        $this->assertSame('https://github.com/paulmann/BAS-IP/releases/tag/v3.0.0', $release->url);
        $this->assertSame('https://api.github.com/repos/paulmann/BAS-IP/zipball/v3.0.0', $release->downloadUrl);

        // A tag entry (not a release) has no tag_name at all.
        $this->assertTrue(Release::fromGitHub(['zipball_url' => 'x'], 'paulmann/BAS-IP') === null);
    }

    public function testToArrayRoundTripsKeyFields(): void
    {
        $release = Release::fromGitHub(['tag_name' => 'v2.0.1', 'prerelease' => true], 'paulmann/BAS-IP');
        $array = $release->toArray();
        $this->assertSame('2.0.1', $array['version']);
        $this->assertSame(true, $array['prerelease']);
        $this->assertSame('v2.0.1', $array['tag']);
    }

    public function testIsNewerComparesSemverCorrectly(): void
    {
        $this->assertTrue(UpdateService::isNewer('2.0.1', '2.0.0'));
        $this->assertTrue(UpdateService::isNewer('v2.1.0', '2.0.9'));
        $this->assertTrue(UpdateService::isNewer('2.10.0', '2.9.9'));     // numeric, not lexicographic
        $this->assertFalse(UpdateService::isNewer('2.0.0', '2.0.0'));
        $this->assertFalse(UpdateService::isNewer('1.9.9', '2.0.0'));
        $this->assertFalse(UpdateService::isNewer('V2.0.0', 'v2.0.0'));    // case-insensitive prefix
    }

    public function testIsNewerIgnoresMetadataAndGarbage(): void
    {
        // -beta/+build metadata is dropped before comparing.
        $this->assertTrue(UpdateService::isNewer('2.1.0-beta', '2.0.0'));
        $this->assertFalse(UpdateService::isNewer('2.0.0+build5', '2.0.0'));
        // Garbage never triggers an update.
        $this->assertFalse(UpdateService::isNewer('main', '2.0.0'));
        $this->assertFalse(UpdateService::isNewer('', '2.0.0'));
        $this->assertFalse(UpdateService::isNewer('2.0.0', 'nonsense'));
    }

    public function testCompareOrdersVersionsForDisplay(): void
    {
        $this->assertSame(-1, UpdateService::compare('2.0.0', '2.0.1'));
        $this->assertSame(0, UpdateService::compare('v2.0.0', '2.0.0'));
        $this->assertSame(1, UpdateService::compare('2.1.0', 'v2.0.9'));
    }
}
