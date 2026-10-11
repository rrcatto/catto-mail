<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Renders the Markdown of the installation's own documents (the acceptable use policy) as HTML.
 * Deliberately small: headings, paragraphs, ordered and unordered lists (nested by indentation),
 * tables, and inline code, strong, emphasis and links. Everything else is text. All text is
 * escaped first, raw HTML is never passed through, and a link is made only for an http(s) or
 * mailto address or a path of this site - so the result is safe to print unescaped.
 */
final class Markdown
{
    private const ITEM = '/^( *)([-*+]|\d{1,9}[.)])\s+(.*)$/';

    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/', str_replace("\t", '    ', $markdown)) ?: [];
        $count = \count($lines);
        $html = [];
        for ($i = 0; $i < $count;) {
            $line = $lines[$i];
            if ('' === trim($line)) {
                ++$i;
            } elseif (1 === preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
                $level = \strlen($m[1]);
                $html[] = "<h$level>".self::inline($m[2])."</h$level>";
                ++$i;
            } elseif (self::isTable($lines, $i)) {
                $html[] = self::table($lines, $i);
            } elseif (1 === preg_match(self::ITEM, $line)) {
                $html[] = self::listing($lines, $i);
            } else {
                $text = [];
                while ($i < $count && '' !== trim($lines[$i]) && 1 !== preg_match('/^#{1,6}\s/', $lines[$i])
                    && 1 !== preg_match(self::ITEM, $lines[$i]) && !self::isTable($lines, $i)) {
                    $text[] = trim($lines[$i++]);
                }
                $html[] = '<p>'.self::inline(implode(' ', $text)).'</p>';
            }
        }

        return implode("\n", $html)."\n";
    }

    /** The first heading's text, for a page title. */
    public static function title(string $markdown): ?string
    {
        return 1 === preg_match('/^#\s+(.*?)\s*#*$/m', $markdown, $m) ? trim($m[1]) : null;
    }

    /** @param list<string> $lines */
    private static function isTable(array $lines, int $i): bool
    {
        return str_contains($lines[$i], '|') && isset($lines[$i + 1])
            && 1 === preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]);
    }

    /** @param list<string> $lines */
    private static function table(array $lines, int &$i): string
    {
        $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
        $head = $cells($lines[$i]);
        $i += 2;
        $html = '<div class="scroll"><table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th scope="col">'.self::inline($cell).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($count = \count($lines); $i < $count && '' !== trim($lines[$i]) && str_contains($lines[$i], '|'); ++$i) {
            $html .= '<tr>';
            foreach (array_pad(\array_slice($cells($lines[$i]), 0, \count($head)), \count($head), '') as $cell) {
                $html .= '<td>'.self::inline($cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    /**
     * A list and the lists nested in it. An item's level is its indentation; a list that does not
     * start at 1 keeps its number (a list continued after a table).
     *
     * @param list<string> $lines
     */
    private static function listing(array $lines, int &$i): string
    {
        $items = [];
        for ($count = \count($lines); $i < $count; ++$i) {
            if ('' === trim($lines[$i])) {
                // A blank line ends the list unless another item follows it.
                $next = $i;
                while ($next < $count && '' === trim($lines[$next])) {
                    ++$next;
                }
                if ($next >= $count || 1 !== preg_match(self::ITEM, $lines[$next])) {
                    break;
                }
                $i = $next - 1;
            } elseif (1 === preg_match(self::ITEM, $lines[$i], $m)) {
                $ordered = ctype_digit($m[2][0]);
                $items[] = ['indent' => \strlen($m[1]), 'tag' => $ordered ? 'ol' : 'ul', 'start' => $ordered ? (int) $m[2] : 1, 'text' => $m[3]];
            } elseif ([] !== $items && str_starts_with($lines[$i], ' ') && !self::isTable($lines, $i)) {
                $items[array_key_last($items)]['text'] .= ' '.trim($lines[$i]);   // a wrapped line of the item
            } else {
                break;
            }
        }
        $html = '';
        $open = [];
        foreach ($items as $item) {
            while ([] !== $open && $item['indent'] < end($open)['indent']) {
                $html .= '</li></'.array_pop($open)['tag'].'>';
            }
            if ([] === $open || $item['indent'] > end($open)['indent']) {
                $html .= '<'.$item['tag'].(1 !== $item['start'] ? ' start="'.$item['start'].'"' : '').'>';
                $open[] = $item;
            } else {
                $html .= '</li>';
            }
            $html .= '<li>'.self::inline($item['text']);
        }
        while ([] !== $open) {
            $html .= '</li></'.array_pop($open)['tag'].'>';
        }

        return $html;
    }

    private static function inline(string $text): string
    {
        $html = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![*\w])/', '<em>$1</em>', $html);

        return (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            return 1 === preg_match('#^(https?://|mailto:|/(?!/))#i', $m[2]) ? '<a href="'.$m[2].'">'.$m[1].'</a>' : $m[0];
        }, $html);
    }
}
