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
        $standard = $this->video->hasWebCopy();
        $mail = (new MailMessage)->subject($standard ? 'Dein Video ist in Standardqualität bereit' : 'Dein Video ist bereit')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('«'.$this->video->title.'» ist jetzt '.($standard ? 'in Standardqualität' : 'in der Web-Version').' abspielbar.');
        if ($standard && in_array($this->video->optimization_status, ['queued', 'processing'], true)) {
            $mail->line('Die Standardversion ist fertig. HD wird noch im Hintergrund vorbereitet und kann weiterhin in der Warteschlange stehen. Du kannst das Video bereits ansehen.');
        }

        return $mail->action('Video ansehen', route('videos.show', $this->video));
    }
}
