<?php

namespace App\Inbox\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one place rich message bodies are made safe.
 *
 * Two directions run through here, and the allowlist has to cover both. What
 * we *emit* is small — the starting set the composer can produce: bold,
 * italic, underline, lists, links, paragraphs. What we *receive* is whatever
 * a real mail client felt like sending: nested tables, inline styles,
 * tracking pixels, script tags, Outlook's conditional comments. Both are
 * reduced to the same short list, so a message renders the same whoever
 * wrote it and nothing arrives that the browser will execute.
 *
 * Sanitizing happens on write, not on read: what is stored is already safe,
 * so a later bug in a template cannot resurrect something dangerous, and the
 * expensive parse happens once per message rather than once per view.
 */
class HtmlSanitizer
{
    /**
     * Elements whose *text content* is not content at all — a stylesheet, a
     * document title — and which the HTML5 parser hands over as raw text.
     * Symfony's `dropElement()` does not remove that text (it does for
     * ordinary elements like `iframe` and `script`, but these are parsed
     * differently), so they are cut out before it ever sees them. Verified
     * behaviour, not a guess: without this, a mail with a `<style>` block
     * renders its CSS as the message body.
     */
    private const RAW_TEXT_ELEMENTS = ['style', 'title', 'head'];

    /**
     * Where one block of text ends and the next begins. Used only to derive
     * the plain-text form; without them a message reads as
     * "Hi thereWe will come on Tuesday".
     */
    private const BLOCK_BOUNDARIES = [
        'br', '/p', '/div', '/li', '/ul', '/ol', '/tr', '/td', '/th',
        '/h1', '/h2', '/h3', '/h4', '/h5', '/h6', '/blockquote',
        '/section', '/article', '/pre', '/table',
    ];

    private readonly SymfonySanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            // Anything not named below has its tags stripped but its text
            // kept. This matters far more for what arrives than for what we
            // send: real mail clients wrap their content in tables, divs and
            // font tags, and the alternative default — dropping an unknown
            // element *including its children* — turns a perfectly ordinary
            // email into an empty message.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('a', ['href'])
            // A link may point at the web or at a mailbox and nowhere else:
            // javascript: and data: are how a link becomes an execution.
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            // Blocking these would keep their contents as text — a script's
            // source rendered as the message body. They go entirely.
            ->dropElement('script')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('applet')
            ->dropElement('noscript')
            ->dropElement('template')
            ->dropElement('meta')
            ->dropElement('link')
            // A mail thread can be long, and a client's quoted history makes
            // it longer; the cap guards against a hostile payload, not
            // against real messages.
            ->withMaxInputLength(500_000);

        $this->sanitizer = new SymfonySanitizer($config);
    }

    /**
     * The safe HTML for a rich body.
     */
    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($this->withoutRawTextElements($html)));
    }

    /**
     * The plain-text form of a rich body.
     *
     * `messages.body` is the canonical text on every channel — it is what
     * search reads and what the inbox list previews — so a rich message has
     * to produce one.
     *
     * Takes the **original** HTML, not the sanitized output: sanitizing has
     * already thrown away the `div`, `table` and `blockquote` tags that say
     * where one block ends and the next begins, and by then the boundaries
     * cannot be recovered.
     */
    public function toPlainText(string $html): string
    {
        $text = $this->withoutRawTextElements($html);
        $text = $this->stripWithContents($text, ['script', 'iframe', 'object', 'applet', 'noscript', 'template']);

        $boundaries = implode('|', array_map(
            fn (string $tag) => preg_quote($tag, '#'),
            self::BLOCK_BOUNDARIES,
        ));

        $text = preg_replace("#<({$boundaries})\b[^>]*>#i", "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the runs of blank lines that block-per-line leaves behind.
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function withoutRawTextElements(string $html): string
    {
        return $this->stripWithContents($html, self::RAW_TEXT_ELEMENTS);
    }

    /**
     * @param  list<string>  $elements
     */
    private function stripWithContents(string $html, array $elements): string
    {
        foreach ($elements as $element) {
            $tag = preg_quote($element, '#');
            $html = preg_replace("#<{$tag}\b[^>]*>.*?</{$tag}\s*>#is", '', $html) ?? $html;
            // An unclosed one takes the rest with it, which is what a
            // browser would do too.
            $html = preg_replace("#<{$tag}\b[^>]*>.*#is", '', $html) ?? $html;
        }

        return $html;
    }
}
