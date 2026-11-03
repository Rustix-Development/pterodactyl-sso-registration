<?php

namespace RustixDev\Registration\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\User;
use Pterodactyl\Notifications\AccountCreated;
use RustixDev\Registration\RegistrationSettings;
use RustixDev\Registration\Services\Verification;

/**
 * The panel emails every new account an "account created" message. Someone who has to
 * verify their address gets the verification link instead, so it is the only email they
 * receive. Accounts that need no verification still get the panel's message as a welcome.
 */
class SkipAccountCreatedMail
{
    public function __construct(private readonly Verification $verification) {}

    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notification instanceof AccountCreated || ! $event->notifiable instanceof User || ! Extensions::isAvailable(RegistrationSettings::EXTENSION)) {
            return null;
        }

        return $this->verification->pending($event->notifiable) ? false : null;
    }
}
