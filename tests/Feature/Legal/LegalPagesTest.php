<?php

namespace Tests\Feature\Legal;

use App\Enums\RoleName;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_policy_pages_are_public_server_rendered_html(): void
    {
        config(['legal.contact_email' => 'privacy@example.test', 'legal.company_name' => 'Acme Legal Ltd']);

        $this->get('/privacy')->assertOk()
            ->assertSee('Privacy Policy')->assertSee('Acme Legal Ltd')->assertSee('privacy@example.test')
            ->assertSee('WhatsApp Business Platform')->assertSee('Lead Ads')->assertSee('Facebook Login for Business');
        $this->get('/terms')->assertOk()->assertSee('Terms of Service')->assertSee('Meta Platform Terms');
        $this->get('/data-deletion')->assertOk()->assertSee('Data Deletion Instructions')->assertSee('Apps and websites');
        $this->get('/data-deletion/status')->assertOk()->assertSee('Confirmation code');
    }

    public function test_policy_pages_stay_reachable_when_signed_in(): void
    {
        [$tenant] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);

        $this->actingAs($agent)->get('/privacy')->assertOk();
        $this->actingAs($agent)->get('/terms')->assertOk();
    }

    public function test_pages_render_without_a_contact_email(): void
    {
        config(['legal.contact_email' => null]);

        $this->get('/privacy')->assertOk()->assertDontSee('mailto:');
    }

    public function test_unknown_or_malformed_status_codes_do_not_error_or_reflect_markup(): void
    {
        $this->get('/data-deletion/status?code=NOPE1234NOPE')->assertOk()->assertSee('could not find');
        $this->get('/data-deletion/status?code='.urlencode('<script>x</script>'))->assertOk()->assertDontSee('<script>x', false);
    }
}
