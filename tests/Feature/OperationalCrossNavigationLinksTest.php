<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperationalCrossNavigationLinksTest extends TestCase
{
    public function test_subscription_index_vue_links_installation_and_client(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Index.vue'));

        $this->assertStringContainsString('`/installations/${subscription.installation.id}`', $contents);
        $this->assertStringContainsString('`/clients/${subscription.installation.client.id}`', $contents);
        $this->assertStringContainsString('v-if="subscription.installation?.id"', $contents);
        $this->assertStringContainsString('v-if="subscription.installation?.client?.id"', $contents);
    }

    public function test_subscription_show_vue_links_installation_client_and_payment(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Show.vue'));

        $this->assertStringContainsString('`/installations/${subscription.installation.id}`', $contents);
        $this->assertStringContainsString('`/clients/${subscription.installation.client.id}`', $contents);
        $this->assertStringContainsString('`/payments/${payment.id}`', $contents);
    }

    public function test_payment_index_vue_links_installation_client_and_subscription(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Index.vue'));

        $this->assertStringContainsString('`/installations/${payment.subscription.installation.id}`', $contents);
        $this->assertStringContainsString('`/clients/${payment.subscription.installation.client.id}`', $contents);
        $this->assertStringContainsString('`/subscriptions/${payment.subscription.id}`', $contents);
    }

    public function test_payment_show_vue_links_subscription_installation_and_client(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Subscriptions/Payments/Show.vue'));

        $this->assertStringContainsString('`/subscriptions/${payment.subscription.id}`', $contents);
        $this->assertStringContainsString('`/installations/${payment.subscription.installation.id}`', $contents);
        $this->assertStringContainsString('`/clients/${payment.subscription.installation.client.id}`', $contents);
    }

    public function test_installation_show_vue_retains_payments_list_link_and_last_payment_deep_link(): void
    {
        $contents = file_get_contents(base_path('resources/js/Pages/Installations/Show.vue'));

        $this->assertStringContainsString('href="/payments"', $contents);
        $this->assertStringContainsString('`/payments/${paymentsSummary.last_payment.id}`', $contents);
        $this->assertStringContainsString('v-if="paymentsSummary?.last_payment?.id"', $contents);
    }
}
