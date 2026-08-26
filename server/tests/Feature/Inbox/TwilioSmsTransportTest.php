<?php

namespace Tests\Feature\Inbox;

use App\Inbox\Sms\Transport\TwilioSmsTransport;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

class TwilioSmsTransportTest extends TestCase
{
    public function test_a_refusal_from_the_provider_is_something_the_writer_can_read()
    {
        $transport = new TwilioSmsTransport($this->clientRefusing(
            new RestException("'From' phone number routing configuration is incorrect", 21663, 400),
        ));

        try {
            $transport->send('+4533221100', '+4520123456', 'On our way');
            $this->fail('Expected the refusal to surface as a validation error.');
        } catch (ValidationException $e) {
            // It lands on the composer, not as a 500, and says what went wrong.
            $message = $e->errors()['body'][0];
            $this->assertStringContainsString('routing configuration is incorrect', $message);
        }
    }

    public function test_a_sent_text_returns_the_provider_id()
    {
        $messages = Mockery::mock();
        $messages->shouldReceive('create')
            ->once()
            ->with('+4520123456', ['from' => '+4533221100', 'body' => 'On our way'])
            ->andReturn((object) ['sid' => 'SM123']);

        $client = Mockery::mock(Client::class);
        $client->messages = $messages;

        $this->assertSame('SM123', (new TwilioSmsTransport($client))->send('+4533221100', '+4520123456', 'On our way'));
    }

    private function clientRefusing(RestException $e): Client
    {
        $messages = Mockery::mock();
        $messages->shouldReceive('create')->andThrow($e);

        $client = Mockery::mock(Client::class);
        $client->messages = $messages;

        return $client;
    }
}
