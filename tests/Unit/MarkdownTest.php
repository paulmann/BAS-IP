<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Markdown;
use BasIp\Tests\Support\TestCase;

/**
 * The Markdown renderer processes untrusted content (GitHub release notes), so
 * escaping is a security property, not a nicety.
 */
final class MarkdownTest extends TestCase
{
    public function testHeadingsAndParagraphs(): void
    {
        $html = Markdown::toHtml("# Заголовок\n\nТекст абзаца.");
        $this->assertStringContains('<h1 class="md-h1" id="zagolovok">Заголовок</h1>', $html);
        $this->assertStringContains('<p>Текст абзаца.</p>', $html);
    }

    public function testBoldItalicAndInlineCode(): void
    {
        $html = Markdown::toHtml('**жирный** *курсив* `код`');
        $this->assertStringContains('<strong>жирный</strong>', $html);
        $this->assertStringContains('<em>курсив</em>', $html);
        $this->assertStringContains('<code class="inline-code">код</code>', $html);
    }

    public function testLists(): void
    {
        $html = Markdown::toHtml("- первый\n- второй");
        $this->assertStringContains('<ul class="md-list">', $html);
        $this->assertStringContains('<li>первый</li>', $html);

        $html = Markdown::toHtml("1. один\n2. два");
        $this->assertStringContains('<ol class="md-list">', $html);
    }

    public function testFencedCodeBlockIsNotInterpreted(): void
    {
        $html = Markdown::toHtml("```bash\nphp bin/bas-ip **not bold** <script>alert(1)</script>\n```");
        $this->assertStringContains('<pre class="code-block">', $html);
        $this->assertStringContains('**not bold**', $html);
        $this->assertStringNotContains('<script>alert(1)</script>', $html);
        $this->assertStringContains('&lt;script&gt;', $html);
    }

    public function testLinksAreSanitized(): void
    {
        $html = Markdown::toHtml('[ok](https://example.com) [bad](javascript:alert(1))');
        $this->assertStringContains('href="https://example.com"', $html);
        $this->assertStringNotContains('javascript:', $html);
    }

    public function testHtmlInSourceIsEscaped(): void
    {
        $html = Markdown::toHtml('<img src=x onerror=alert(1)>');
        $this->assertStringNotContains('<img src=x', $html);
        $this->assertStringContains('&lt;img', $html);
    }

    public function testTables(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        $html = Markdown::toHtml($md);
        $this->assertStringContains('<table class="md-table">', $html);
        $this->assertStringContains('<th>A</th>', $html);
        $this->assertStringContains('<td>1</td>', $html);
    }

    public function testHeadingsExtractionForToc(): void
    {
        $headings = Markdown::headings("# Один\n\n## Два\n\n### Три\n\n#### Четыре");
        $this->assertCount(3, $headings);   // only levels 1..3
        $this->assertSame('Один', $headings[0]['text']);
        $this->assertSame(2, $headings[1]['level']);
        $this->assertNotSame('', $headings[1]['id']);
    }

    public function testBlockquoteAndHr(): void
    {
        $html = Markdown::toHtml("> цитата\n\n---");
        $this->assertStringContains('<blockquote class="md-quote">цитата</blockquote>', $html);
        $this->assertStringContains('<hr class="md-hr">', $html);
    }
}
