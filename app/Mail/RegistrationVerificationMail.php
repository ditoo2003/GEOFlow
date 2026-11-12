<?php

namespace App\Mail;

use App\Models\RegistrationSignup;
use App\Support\Branding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 公开注册的邮箱验证邮件。
 *
 * 仅替换发件人名称与模板品牌变量（平台统一 SMTP）；邮件模板为平台预置，不支持自由编辑 HTML。
 */
final class RegistrationVerificationMail extends Mailable
{
    public function __construct(
        public readonly RegistrationSignup $signup,
        public readonly string $verificationToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address', 'no-reply@localhost'),
                Branding::name(),
            ),
            subject: __('registration.mail.subject', ['brand' => Branding::name()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration.verify',
            with: [
                'name' => (string) $this->signup->name,
                'tenantName' => (string) $this->signup->tenant_name,
                'verificationUrl' => route('register.verify', ['token' => $this->verificationToken]),
                'ttlMinutes' => max(1, (int) config('registration.verification_ttl_minutes', 60)),
                'brand' => Branding::all(),
            ],
        );
    }
}
