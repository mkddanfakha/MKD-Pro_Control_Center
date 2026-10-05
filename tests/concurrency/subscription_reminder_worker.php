<?php

use App\Models\Subscription;
use App\Services\SubscriptionReminderService;
use Tests\Support\MysqlTestingConnection;

require __DIR__.'/../../vendor/autoload.php';

$subscriptionId = (int) ($argv[1] ?? 0);
$thresholdDays = (int) ($argv[2] ?? -1);
$syncDir = $argv[3] ?? '';
$workerId = $argv[4] ?? '';

if ($subscriptionId <= 0 || $thresholdDays < 0 || $syncDir === '' || $workerId === '') {
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
    /** @var Subscription $subscription */
    $subscription = Subscription::query()->findOrFail($subscriptionId);

    $result = app(SubscriptionReminderService::class)->recordDetectedReminder(
        $subscription,
        $thresholdDays,
    );

    fwrite(STDOUT, json_encode([
        'status' => 'SUCCESS',
        'worker' => $workerId,
        'outcome' => $result['outcome'],
        'created' => $result['created'],
        'reminder_id' => $result['reminder']->id,
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
