<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Ledger::class);
        $this->app->scoped(Audit::class);
        $this->app->scoped(Settings::class);
        $this->app->bind(\App\Sync\CompanyClient::class, \App\Sync\FtthApiClient::class);
        // The phone linked by QR code (default) or Meta's Cloud API, as chosen in the WhatsApp settings.
        $this->app->bind(\App\WhatsApp\Gateway::class, fn ($app) => $app->make(Settings::class)->get('whatsapp.driver') === 'meta'
            ? $app->make(\App\WhatsApp\CloudApiGateway::class)
            : $app->make(\App\WhatsApp\WahaGateway::class));
        // A paid OpenAI key wins when set; otherwise the free transcription service on the server.
        $this->app->bind(\App\WhatsApp\Transcriber::class, fn ($app) => \App\WhatsApp\HttpTranscriber::configured() || ! \App\WhatsApp\LocalWhisperTranscriber::configured()
            ? $app->make(\App\WhatsApp\HttpTranscriber::class)
            : $app->make(\App\WhatsApp\LocalWhisperTranscriber::class));
        $this->app->bind(\App\WhatsApp\Interpreter::class, \App\WhatsApp\CommandInterpreter::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Admins hold every permission, including ones added later.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);

        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            ? $event->user->forceFill(['last_login_at' => now()])->saveQuietly()
            : null);
    }
}
