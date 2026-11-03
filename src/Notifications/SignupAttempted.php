<?php

namespace RustixDev\Registration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Pterodactyl\Models\User;

/**
 * Sent instead of a verification link when someone signs up with the address of an
 * existing account, so the sign-up page never tells whether an address is registered.
 */
class SignupAttempted extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('You already have an account')
            ->greeting('Hello '.$notifiable->name_first.'!')
            ->line('Someone tried to create an account on '.config()->string('app.name').' with this email address, which already belongs to your account (username '.$notifiable->username.').')
            ->action('Sign In', url('/auth/login'))
            ->line('If you forgot your password, you can reset it from the sign-in page. If this was not you, no action is needed.');
    }
}
