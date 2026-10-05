<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class SubscriptionReminderProcessingScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_reminders_command_is_scheduled_daily_with_without_overlapping(): void
    {
        $event = $this->findScheduleEvent('subscriptions:process-reminders');

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_scheduler_uses_application_timezone_utc(): void
    {
        Config::set('app.timezone', 'UTC');

        $event = $this->findScheduleEvent('subscriptions:process-reminders');

        $this->assertNotNull($event);
        $this->assertSame('UTC', $event->timezone);
    }

    public function test_process_reminders_coexists_with_lifecycle_and_renewal_commands(): void
    {
        $lifecycle = $this->findScheduleEvent('subscriptions:sync-lifecycle');
        $renewal = $this->findScheduleEvent('subscriptions:renew-with-credit');
        $reminders = $this->findScheduleEvent('subscriptions:process-reminders');

        $this->assertNotNull($lifecycle);
        $this->assertNotNull($renewal);
        $this->assertNotNull($reminders);
        $this->assertNotSame($lifecycle, $renewal);
        $this->assertNotSame($lifecycle, $reminders);
        $this->assertNotSame($renewal, $reminders);
    }

    private function findScheduleEvent(string $needle): ?Event
    {
        foreach (Schedule::events() as $event) {
            $command = (string) ($event->command ?? '');

            if (str_contains($command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
