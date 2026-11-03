<?php

namespace RustixDev\Registration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Pterodactyl\Models\User;
use RustixDev\Registration\Services\Verification;

class VerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    /** @param User $notifiable */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello '.$notifiable->name_first.'!')
            ->line('Thanks for creating an account on '.config()->string('app.name').'. Confirm that this is your email address to start using it.')
            ->action('Verify Email Address', Verification::url($notifiable))
            ->line('The link works for '.Verification::LIFETIME_HOURS.' hours. If you did not create an account, you can ignore this email.');
    }
}
