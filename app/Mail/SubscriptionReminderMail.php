<?php

namespace App\Mail;

use App\Notifications\SubscriptionReminderNotificationData;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $formattedAmount;

    public string $periodEndLabel;

    /**
     * @var list<string>
     */
    public array $bodyLines;

    public function __construct(
        public SubscriptionReminderNotificationData $notification,
    ) {
        $this->formattedAmount = number_format($notification->amount, 0, ',', ' ').' '.$notification->currency;
        $this->periodEndLabel = Carbon::parse($notification->current_period_end)->utc()->format('d/m/Y');
        $this->bodyLines = array_values(array_filter(explode("\n", $notification->body)));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-reminder',
        );
    }
}
