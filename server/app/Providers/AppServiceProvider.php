<?php

namespace App\Providers;

use App\Enums\Channel;
use App\Inbox\ChannelRegistry;
use App\Inbox\Email\EmailChannelAdapter;
use App\Inbox\Email\Transport\EmailTransport;
use App\Inbox\Email\Transport\LogEmailTransport;
use App\Inbox\Email\Transport\MailEmailTransport;
use App\Inbox\Sms\SmsChannelAdapter;
use App\Inbox\Sms\Transport\LogSmsTransport;
use App\Inbox\Sms\Transport\SmsTransport;
use App\Inbox\Sms\Transport\TwilioSmsTransport;
use App\Models\UploadLink;
use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Twilio\Rest\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // How texts actually leave the building — swappable like a mail
        // driver so dev and test need no Twilio account.
        $this->app->singleton(SmsTransport::class, function (): SmsTransport {
            $transport = config('inbox.sms.transport');

            return match ($transport) {
                'log' => new LogSmsTransport,
                'twilio' => new TwilioSmsTransport($this->twilioClient()),
                default => throw new InvalidArgumentException("Unknown SMS transport [{$transport}]."),
            };
        });

        $this->app->singleton(EmailTransport::class, function (): EmailTransport {
            $transport = config('inbox.email.transport');

            return match ($transport) {
                'log' => new LogEmailTransport,
                'mail' => new MailEmailTransport($this->app->make('mailer')),
                default => throw new InvalidArgumentException("Unknown email transport [{$transport}]."),
            };
        });

        // The channel roster. A new channel is added here and nowhere else
        // in the core.
        $this->app->singleton(ChannelRegistry::class, fn ($app): ChannelRegistry => new ChannelRegistry([
            Channel::Sms->value => $app->make(SmsChannelAdapter::class),
            Channel::Email->value => $app->make(EmailChannelAdapter::class),
        ]));
    }

    /**
     * Twilio, pointed at the region the firm's number actually lives in.
     *
     * A number homed in one region is not reachable through another region's
     * endpoint: the send is refused rather than routed. Region and edge are
     * set together, as Twilio's client only rewrites the host when it knows
     * both halves.
     */
    private function twilioClient(): Client
    {
        $key = config('services.twilio.key');
        $secret = config('services.twilio.secret');
        $usingKey = filled($key) && filled($secret);

        $client = new Client(
            $usingKey ? $key : config('services.twilio.sid'),
            $usingKey ? $secret : config('services.twilio.token'),
            // An API key authenticates as itself, so the account it acts for
            // has to be named separately.
            $usingKey ? config('services.twilio.sid') : null,
            config('services.twilio.region'),
        );

        $client->setEdge(config('services.twilio.edge'));

        return $client;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Optional external reference (X2): host-linked or standalone.
        // Pair with the App\Models\Concerns\HasExternalReference trait.
        Blueprint::macro('externalRef', function () {
            /** @var Blueprint $this */
            return $this->string('external_ref')->nullable()->index();
        });

        // The reset-password page is now client-rendered in web/, not a
        // Laravel route — point the notification email at it directly.
        // An upload token resolves to its link or to nothing. Unknown,
        // expired and used-up all answer the same 404 on purpose: telling
        // them apart would turn this into a way to test tokens for validity.
        Route::bind('uploadLink', fn (string $token) => UploadLink::findUsable($token) ?? abort(404));

        ResetPassword::createUrlUsing(
            fn ($notifiable, string $token) => FrontendUrl::to("/reset-password/{$token}")
                .'?email='.urlencode($notifiable->getEmailForPasswordReset()),
        );
    }
}
