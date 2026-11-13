<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Services\Registration\TurnstileVerifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class TurnstileVerifierTest extends TestCase
{
    private function enableTurnstile(): void
    {
        config([
            'registration.turnstile.enabled' => true,
            'registration.turnstile.site_key' => 'site-key',
            'registration.turnstile.secret_key' => 'secret-key',
        ]);
    }

    #[Test]
    public function siteverify_requests_are_sent_through_the_safe_outbound_gateway(): void
    {
        $this->enableTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->assertTrue(app(TurnstileVerifier::class)->verify('turnstile-token', '203.0.113.9'));

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'secret-key'
            && $request['response'] === 'turnstile-token'
            && $request['remoteip'] === '203.0.113.9');
    }

    #[Test]
    public function a_failed_siteverify_response_is_rejected(): void
    {
        $this->enableTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->assertFalse(app(TurnstileVerifier::class)->verify('turnstile-token'));
    }

    #[Test]
    public function the_safe_gateway_blocks_a_private_verification_target_and_fails_closed(): void
    {
        $this->enableTurnstile();
        $resolver = app(HostResolver::class);
        $this->assertInstanceOf(FakeHostResolver::class, $resolver);
        $resolver->set('challenges.cloudflare.com', ['10.0.0.8']);
        Http::fake();

        $this->assertFalse(app(TurnstileVerifier::class)->verify('turnstile-token'));
        Http::assertNothingSent();
    }
}
