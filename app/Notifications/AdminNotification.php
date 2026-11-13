<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\SendsFcmPush;
use App\Support\NotificationAudiencePayload;
use App\Support\UserNotificationAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

class AdminNotification extends Notification
{
    use Queueable;
    use SendsFcmPush;

    protected $title;
    protected $message;
    protected $meta;

    public function __construct($title, $message, array $meta = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->meta = $meta;
    }

    public function via($notifiable)
    {
        // Database inbox + FCM so alerts arrive when the mobile app is backgrounded or closed.
        return $this->channelsWithPush(['database']);
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->message)
            ->action('View Dashboard', url('/'));
    }

    public function toArray($notifiable)
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        if ($notifiable instanceof User) {
            $meta['audience_role'] = UserNotificationAudience::resolve($notifiable);
        }

        return NotificationAudiencePayload::merge($notifiable, [
            'title' => $this->title,
            'message' => $this->message,
            'type' => 'admin_notification',
            'meta' => $meta,
        ]);
    }
}
