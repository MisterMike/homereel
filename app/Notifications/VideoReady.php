<?php

namespace App\Notifications;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VideoReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Video $video)
    {
        $this->onConnection('database')->onQueue('default')->beforeCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ! $this->video->trashed() && $notifiable->is_active ? ['mail'] : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $this->video->trashed() && $notifiable->is_active;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Dein Video ist bereit')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('«'.$this->video->title.'» ist jetzt in der Web-Version abspielbar.')
            ->line('Eine höhere Qualität wird bei Bedarf noch im Hintergrund vorbereitet. Du kannst das Video bereits ansehen.')
            ->action('Video ansehen', route('videos.show', $this->video));
    }
}
