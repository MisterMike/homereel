<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LibraryInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('You’re invited to HomeReel')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You’ve been invited to your family’s private video library.')
            ->action('Accept invitation', $this->url)
            ->line('Choose your password to get started. This invitation expires in seven days.');
    }
}
