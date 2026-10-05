<?php

use App\Models\SubscriptionReminder;
use App\Services\SubscriptionReminderNotificationSender;
use App\Notifications\SubscriptionReminderSendResult;
use Tests\Support\MysqlTestingConnection;

require __DIR__.'/../../vendor/autoload.php';

$reminderId = (int) ($argv[1] ?? 0);
$syncDir = $argv[2] ?? '';
$workerId = $argv[3] ?? '';

if ($reminderId <= 0 || $syncDir === '' || $workerId === '') {
    fwrite(STDERR, json_encode([
        'status' => 'UNEXPECTED_ERROR',
        'message' => 'Arguments worker invalides.',
    ]));
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! MysqlTestingConnection::applyFromProjectEnv()) {
    fwrite(STDERR, json_encode([
        'status' => 'UNEXPECTED_ERROR',
        'message' => 'Connexion MySQL indisponible pour le worker.',
    ]));
    exit(3);
}

Illuminate\Support\Facades\Config::set('subscriptions.subscription_reminder_notifications.enabled', true);
Illuminate\Support\Facades\Config::set('subscriptions.subscription_reminder_notifications.channel', 'prepared');

$readyFile = rtrim($syncDir, '\\/').DIRECTORY_SEPARATOR.'worker_'.$workerId.'.ready';
$goFile = rtrim($syncDir, '\\/').DIRECTORY_SEPARATOR.'go.signal';

file_put_contents($readyFile, (string) microtime(true));

$deadline = microtime(true) + 30.0;

while (! is_file($goFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, json_encode([
            'status' => 'UNEXPECTED_ERROR',
            'message' => 'Timeout en attente du signal de départ.',
        ]));
        exit(4);
    }

    usleep(2000);
}

try {
    /** @var SubscriptionReminder $reminder */
    $reminder = SubscriptionReminder::query()->findOrFail($reminderId);

    $result = app(SubscriptionReminderNotificationSender::class)->send($reminder);

    fwrite(STDOUT, json_encode([
        'status' => 'SUCCESS',
        'worker' => $workerId,
        'send_status' => $result->status,
        'is_sent' => in_array($result->status, [
            SubscriptionReminderSendResult::STATUS_SENT,
            SubscriptionReminderSendResult::STATUS_ALREADY_SENT,
        ], true),
    ]));

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'UNEXPECTED_ERROR',
        'worker' => $workerId,
        'message' => $exception->getMessage(),
    ]));

    exit(1);
}
