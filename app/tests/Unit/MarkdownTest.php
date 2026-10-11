<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\Markdown;
use PHPUnit\Framework\TestCase;

/** The small Markdown renderer of the acceptable use policy: structure in, escaped HTML out. */
final class MarkdownTest extends TestCase
{
    public function testHeadingsParagraphsListsAndTables(): void
    {
        $html = Markdown::toHtml(<<<'MD'
            # Policy title

            Version 1. A paragraph
            on two lines.

            ## 1. Rules

            1. First rule:
               - a nested point;
               - another one.
            2. Second rule.

            | Measure | Warning |
            | --- | --- |
            | Hard bounces | 2% |

            3. A rule after the table.
            MD);
        self::assertStringContainsString('<h1>Policy title</h1>', $html);
        self::assertStringContainsString('<p>Version 1. A paragraph on two lines.</p>', $html);
        self::assertStringContainsString('<h2>1. Rules</h2>', $html);
        self::assertStringContainsString('<ol><li>First rule:<ul><li>a nested point;</li><li>another one.</li></ul></li><li>Second rule.</li></ol>', $html);
        self::assertStringContainsString('<table><thead><tr><th scope="col">Measure</th><th scope="col">Warning</th></tr></thead><tbody><tr><td>Hard bounces</td><td>2%</td></tr></tbody></table>', $html);
        self::assertStringContainsString('<ol start="3"><li>A rule after the table.</li></ol>', $html);
        self::assertSame('Policy title', Markdown::title("intro\n\n# Policy title\n"));
        self::assertNull(Markdown::title('no heading'));
    }

    public function testEverythingIsEscapedAndOnlySafeLinksAreMade(): void
    {
        $html = Markdown::toHtml("# <script>alert(1)</script>\n\nA **bold** and *plain* `code <b>` & [the site](https://example.org/?a=1&b=2), [mail](mailto:abuse@example.org),"
            ." [here](/docs/api), [bad](javascript:alert(1)), [worse](//evil.example) <img src=x onerror=alert(1)>\n\n- item <i>x</i>\n\n| a | <td> |\n|---|---|\n| \"q\" | 'r' |\n");
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringContainsString('<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong> and <em>plain</em> <code>code &lt;b&gt;</code> &amp; <a href="https://example.org/?a=1&amp;b=2">the site</a>', $html);
        self::assertStringContainsString('<a href="mailto:abuse@example.org">mail</a>', $html);
        self::assertStringContainsString('<a href="/docs/api">here</a>', $html);
        self::assertStringContainsString('[bad](javascript:alert(1))', $html, 'not a link');
        self::assertStringContainsString('[worse](//evil.example)', $html, 'not a link');
        self::assertStringContainsString('<li>item &lt;i&gt;x&lt;/i&gt;</li>', $html);
        self::assertStringContainsString('<th scope="col">&lt;td&gt;</th>', $html);
        self::assertStringContainsString('<td>&quot;q&quot;</td><td>&#039;r&#039;</td>', $html);
    }
}
