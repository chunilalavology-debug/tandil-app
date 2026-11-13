<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsFcmPush;
use App\Support\NotificationAudiencePayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Subscription;

class SubscriptionCreated extends Notification
{
    use Queueable;
    use SendsFcmPush;

    protected Subscription $subscription;

    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
    }

    public function via($notifiable)
    {
        return $this->channelsWithPush(['database', 'mail']);
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Subscription Confirmed')
                    ->greeting('Hello '.$notifiable->name)
                    ->line('Your subscription has been created and visits scheduled.')
                    ->line('Plan: '.$this->subscription->plan)
                    ->line('Start date: '.$this->subscription->start_date)
                    ->line('End date: '.$this->subscription->end_date)
                    ->action('View Subscriptions', url('/'))
                    ->line('Thank you for choosing Tandil!');
    }

    public function toArray($notifiable)
    {
        return NotificationAudiencePayload::merge($notifiable, [
            'title' => 'New Subscription Booking',
            'message' => 'Your '.$this->subscription->plan.' subscription is booked from '.$this->subscription->start_date.' to '.$this->subscription->end_date.'.',
            'type' => 'subscription_booking_created',
            'subscription_id' => $this->subscription->id,
            'plan' => $this->subscription->plan,
            'start_date' => $this->subscription->start_date,
            'end_date' => $this->subscription->end_date,
        ]);
    }
}
