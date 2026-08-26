<?php

namespace Tests\Feature\Inbox;

use App\Inbox\Support\HtmlSanitizer;
use Tests\TestCase;

/**
 * The sanitizer has to survive two very different inputs: the small, tidy
 * HTML our own composer produces, and whatever a real mail client sends.
 */
class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_the_starting_set_survives_a_round_trip()
    {
        $html = '<p>Hej <strong>Anders</strong>, vi kommer <em>tirsdag</em>.</p>'
            .'<ul><li>Radiator</li><li>Termostat</li></ul>'
            .'<p><a href="https://example.dk">Se her</a></p>';

        $this->assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function test_a_script_is_removed_with_its_contents()
    {
        $clean = $this->sanitizer->sanitize('<script>alert(1)</script><p>Real body</p>');

        $this->assertSame('<p>Real body</p>', $clean);
        $this->assertStringNotContainsString('alert', $clean);
    }

    public function test_a_stylesheet_does_not_leak_as_message_text()
    {
        // Regression: dropElement() does not remove the contents of raw-text
        // elements, so a mail with a <style> block rendered its CSS as the
        // message body.
        $clean = $this->sanitizer->sanitize('<style>p{display:none}</style><p>Real body</p>');

        $this->assertSame('<p>Real body</p>', $clean);
        $this->assertStringNotContainsString('display:none', $clean);
    }

    public function test_a_document_title_does_not_leak_as_message_text()
    {
        $clean = $this->sanitizer->sanitize('<head><title>Kvittering</title></head><p>Real body</p>');

        $this->assertSame('<p>Real body</p>', $clean);
    }

    public function test_a_javascript_link_keeps_its_words_and_loses_its_teeth()
    {
        $this->assertSame('<a>klik</a>', $this->sanitizer->sanitize('<a href="javascript:alert(1)">klik</a>'));
    }

    public function test_an_image_with_an_event_handler_is_removed()
    {
        $this->assertSame('<p>after</p>', $this->sanitizer->sanitize('<img src=x onerror=alert(1)><p>after</p>'));
    }

    public function test_a_mail_clients_wrapper_markup_keeps_the_words_inside_it()
    {
        // The whole reason unknown elements are blocked rather than dropped:
        // Outlook and friends wrap everything in tables, divs and font tags,
        // and dropping those with their children would empty the message.
        $this->assertSame(
            'Hej fra Outlook',
            $this->sanitizer->sanitize('<div><table><tr><td><font face="Arial">Hej fra Outlook</font></td></tr></table></div>'),
        );
    }

    public function test_plain_text_keeps_the_boundaries_between_blocks()
    {
        $text = $this->sanitizer->toPlainText('<p>Hi there</p><p>We will come on Tuesday</p>');

        $this->assertSame("Hi there\nWe will come on Tuesday", $text);
    }

    public function test_plain_text_reads_boundaries_the_sanitizer_would_have_thrown_away()
    {
        // toPlainText takes the *original* html on purpose: by the time
        // sanitize() has run, the tags that said where one block ended are
        // gone and the words would run together.
        $html = '<blockquote>Den 1. maj skrev du:</blockquote><div>Svar</div>';

        $this->assertSame("Den 1. maj skrev du:\nSvar", $this->sanitizer->toPlainText($html));
    }

    public function test_plain_text_drops_script_and_style_contents()
    {
        $text = $this->sanitizer->toPlainText('<style>p{color:red}</style><script>alert(1)</script><p>Body</p>');

        $this->assertSame('Body', $text);
    }

    public function test_plain_text_decodes_entities()
    {
        $this->assertSame('café & bar', $this->sanitizer->toPlainText('<p>caf&eacute; &amp; bar</p>'));
    }

    public function test_an_unclosed_style_takes_the_rest_with_it_rather_than_leaking()
    {
        $this->assertSame('', $this->sanitizer->sanitize('<style>p{color:red}<p>never rendered'));
    }
}
