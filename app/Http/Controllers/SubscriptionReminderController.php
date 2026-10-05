<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Notifications\SubscriptionReminderRecipient;
use App\Services\SubscriptionReminderNotificationComposer;
use App\Support\AuditLogAdminPresentation;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionReminderController extends Controller
{
    public function __construct(
        private readonly SubscriptionReminderNotificationComposer $notificationComposer,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'installation_id' => 'nullable|integer|min:1',
            'subscription_id' => 'nullable|integer|min:1',
            'status' => 'nullable|in:detected,sent,failed',
            'threshold_days' => 'nullable|integer|in:0,1,3,7',
            'search' => 'nullable|string|max:255',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
        ]);

        $query = $this->filteredQuery($validated);

        $stats = [
            'total' => (clone $query)->count(),
            'detected' => (clone $query)->where('status', SubscriptionReminder::STATUS_DETECTED)->count(),
            'sent' => (clone $query)->where('status', SubscriptionReminder::STATUS_SENT)->count(),
            'failed' => (clone $query)->where('status', SubscriptionReminder::STATUS_FAILED)->count(),
        ];

        $reminders = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SubscriptionReminder $reminder): array => $this->serializeReminder($reminder));

        return Inertia::render('SubscriptionReminders/Index', [
            'reminders' => $reminders,
            'stats' => $stats,
            'filters' => [
                'installation_id' => isset($validated['installation_id']) ? (int) $validated['installation_id'] : null,
                'subscription_id' => isset($validated['subscription_id']) ? (int) $validated['subscription_id'] : null,
                'status' => $validated['status'] ?? null,
                'threshold_days' => isset($validated['threshold_days']) ? (int) $validated['threshold_days'] : null,
                'search' => $validated['search'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ],
        ]);
    }

    public function show(Request $request, SubscriptionReminder $subscriptionReminder): Response
    {
        $subscriptionReminder->load([
            'subscription.installation.client',
        ]);

        $subscription = $subscriptionReminder->subscription;
        $installation = $subscription?->installation;
        $client = $installation?->client;

        $payments = $subscription !== null
            ? Payment::query()
                ->where('subscription_id', $subscription->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'payments_page')
                ->withQueryString()
                ->through(fn (Payment $payment): array => $this->serializePaymentForReminderShow($payment))
            : null;

        return Inertia::render('SubscriptionReminders/Show', [
            'reminder' => $this->serializeReminderForAdminShow($subscriptionReminder),
            'notification' => $this->composeNotificationPreview($subscriptionReminder),
            'subscription' => $subscription !== null
                ? $this->serializeSubscriptionForReminderShow($subscription)
                : null,
            'installation' => $installation !== null
                ? $this->serializeInstallationForReminderShow($installation)
                : null,
            'client' => $client !== null
                ? $this->serializeClientForReminderShow($client)
                : null,
            'payments' => $payments,
            'audit_history' => $this->reminderAuditHistoryForAdminShow($subscriptionReminder),
            'navigation' => [
                'reminders_index' => route('subscription-reminders.index'),
                'subscription_show' => $subscription !== null
                    ? route('subscriptions.show', $subscription)
                    : null,
                'installation_show' => $installation !== null
                    ? route('installations.show', $installation)
                    : null,
                'client_show' => $client !== null
                    ? route('clients.show', $client)
                    : null,
                'audit_logs_index' => route('audit-logs.index', [
                    'auditable_type' => $subscriptionReminder->getMorphClass(),
                    'auditable_id' => $subscriptionReminder->id,
                ]),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<SubscriptionReminder>
     */
    private function filteredQuery(array $validated): Builder
    {
        $query = SubscriptionReminder::query()
            ->with([
                'subscription:id,installation_id,current_period_end',
                'subscription.installation:id,client_id,name,subdomain',
                'subscription.installation.client:id,company_name',
            ]);

        if (filled($validated['installation_id'] ?? null)) {
            $installationId = (int) $validated['installation_id'];
            $query->whereHas('subscription', fn (Builder $subscriptionQuery) => $subscriptionQuery
                ->where('installation_id', $installationId));
        }

        if (filled($validated['subscription_id'] ?? null)) {
            $query->where('subscription_id', (int) $validated['subscription_id']);
        }

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (filled($validated['threshold_days'] ?? null)) {
            $query->where('threshold_days', (int) $validated['threshold_days']);
        }

        if (filled($validated['search'] ?? null)) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->whereHas('subscription.installation', fn (Builder $installationQuery) => $installationQuery
                ->where('name', 'like', $term)
                ->orWhere('subdomain', 'like', $term));
        }

        if (filled($validated['date_from'] ?? null)) {
            $query->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('Y-m-d', $validated['date_from'])->startOfDay(),
            );
        }

        if (filled($validated['date_to'] ?? null)) {
            $query->where(
                'created_at',
                '<=',
                Carbon::createFromFormat('Y-m-d', $validated['date_to'])->endOfDay(),
            );
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReminder(SubscriptionReminder $reminder): array
    {
        $subscription = $reminder->subscription;
        $installation = $subscription?->installation;
        $client = $installation?->client;

        return [
            'id' => $reminder->id,
            'subscription_id' => $reminder->subscription_id,
            'installation_id' => $subscription?->installation_id,
            'installation_name' => $installation?->name,
            'installation_subdomain' => $installation?->subdomain,
            'client_id' => $client?->id,
            'client_name' => $client?->company_name,
            'subscription_show_url' => $subscription !== null
                ? route('subscriptions.show', $subscription)
                : null,
            'installation_show_url' => $installation !== null
                ? route('installations.show', $installation)
                : null,
            'client_show_url' => $client !== null
                ? route('clients.show', $client)
                : null,
            'show_url' => route('subscription-reminders.show', $reminder),
            'reminder_type' => $reminder->reminder_type,
            'threshold_days' => $reminder->threshold_days,
            'period_end' => $subscription?->current_period_end?->toIso8601String(),
            'scheduled_for' => $reminder->scheduled_for?->toIso8601String(),
            'detected_at' => $reminder->detected_at?->toIso8601String(),
            'sent_at' => $reminder->sent_at?->toIso8601String(),
            'status' => $reminder->status,
            'created_at' => $reminder->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReminderForAdminShow(SubscriptionReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'subscription_id' => $reminder->subscription_id,
            'reminder_type' => (string) $reminder->reminder_type,
            'threshold_days' => (int) $reminder->threshold_days,
            'scheduled_for' => $this->formatAdminDateTime($reminder->scheduled_for),
            'detected_at' => $this->formatAdminDateTime($reminder->detected_at),
            'sent_at' => $this->formatAdminDateTime($reminder->sent_at),
            'status' => (string) $reminder->status,
            'created_at' => $this->formatAdminDateTime($reminder->created_at),
            'updated_at' => $this->formatAdminDateTime($reminder->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function composeNotificationPreview(SubscriptionReminder $reminder): ?array
    {
        try {
            $data = $this->notificationComposer->compose($reminder);
        } catch (SubscriptionReminderNotificationException) {
            return null;
        }

        $recipientLabel = $data->recipient->type === SubscriptionReminderRecipient::TYPE_CENTRAL_ADMIN
            ? 'Administrateur central MKD-Pro'
            : $data->recipient->type;

        return [
            'recipient_label' => $recipientLabel,
            'recipient' => $data->recipient->toArray(),
            'channel' => 'email',
            'title' => $data->title,
            'body' => $data->body,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'current_period_end' => $data->current_period_end,
            'installation_name' => $data->installation_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubscriptionForReminderShow(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => (string) $subscription->status,
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'starts_at' => $this->formatAdminDateTime($subscription->starts_at),
            'current_period_start' => $this->formatAdminDateTime($subscription->current_period_start),
            'current_period_end' => $this->formatAdminDateTime($subscription->current_period_end),
            'grace_period_ends_at' => $this->formatAdminDateTime($subscription->grace_period_ends_at),
            'suspended_at' => $this->formatAdminDateTime($subscription->suspended_at),
            'terminated_at' => $this->formatAdminDateTime($subscription->terminated_at),
            'show_url' => route('subscriptions.show', $subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForReminderShow(\App\Models\Installation $installation): array
    {
        return [
            'id' => $installation->id,
            'name' => (string) $installation->name,
            'subdomain' => (string) $installation->subdomain,
            'domain' => $installation->domain,
            'status' => (string) $installation->status,
            'version' => $installation->version,
            'show_url' => route('installations.show', $installation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClientForReminderShow(\App\Models\Client $client): array
    {
        return [
            'id' => $client->id,
            'company_name' => (string) $client->company_name,
            'contact_name' => (string) $client->contact_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => (string) $client->status,
            'show_url' => route('clients.show', $client),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentForReminderShow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => (string) $payment->status,
            'paid_at' => $this->formatAdminDateTime($payment->paid_at),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'credit_months_purchased' => $payment->credit_months_purchased !== null
                ? (int) $payment->credit_months_purchased
                : null,
            'period_start' => $this->formatAdminDateTime($payment->period_start),
            'period_end' => $this->formatAdminDateTime($payment->period_end),
            'show_url' => route('payments.show', $payment),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reminderAuditHistoryForAdminShow(SubscriptionReminder $reminder): array
    {
        $reminderMorph = $reminder->getMorphClass();

        $logs = AuditLog::query()
            ->with('user')
            ->where('auditable_type', $reminderMorph)
            ->where('auditable_id', $reminder->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        $subjectContext = AuditLogAdminPresentation::buildSubjectContext($logs);

        return $logs
            ->map(
                fn (AuditLog $log): array => AuditLogAdminPresentation::serializeEntry($log, $subjectContext),
            )
            ->values()
            ->all();
    }

    private function formatAdminDateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }
}
