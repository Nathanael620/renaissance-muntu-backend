<?php

namespace Tests\Unit;

use Tests\TestCase;

class DonationConfigTest extends TestCase
{
    public function test_organization_official_information_is_configured(): void
    {
        $this->assertSame('MENER AUTREMENT INC', config('donation.organization.legal_name'));
        $this->assertSame('86984 1601 RR0001', config('donation.organization.cra_registration_number'));
        $this->assertSame('11794, Avenue P.-M.-Favier', config('donation.organization.address.line1'));
        $this->assertSame('Montréal-Nord (Québec) H1G 5Z7', config('donation.organization.address.line2'));
        $this->assertSame('(514) 881-7216', config('donation.organization.phone'));
        $this->assertSame('info@menerautrement.org', config('donation.organization.email'));
        $this->assertSame('Montréal', config('donation.organization.issuance_place'));
        $this->assertSame('www.menerautrement.org', config('donation.organization.website'));
    }

    public function test_authorized_signatory_has_no_default_and_is_not_the_contact_person(): void
    {
        $this->assertSame('Oscar Elimby', config('donation.organization.contact_person'));
        $this->assertEmpty(config('donation.organization.authorized_signatory'));
    }

    public function test_currency_falls_back_to_cad_when_not_configured(): void
    {
        $this->assertSame('cad', config('donation.currency'));
    }

    public function test_frontend_url_is_taken_from_frontend_url_environment_variable(): void
    {
        $this->assertSame('http://localhost:5173', config('donation.frontend_url'));
    }

    public function test_stripe_keys_are_not_configured_in_this_environment(): void
    {
        $this->assertEmpty(config('services.stripe.secret_key'));
        $this->assertEmpty(config('services.stripe.publishable_key'));
        $this->assertEmpty(config('services.stripe.webhook_secret'));
    }
}
