<?php

namespace RustixDev\Registration\Sso;

use RuntimeException;

/**
 * A sign-in, sign-up or link that cannot go on. The reason is one of the constants and is
 * passed to the page as `rxrg_sso`, which shows a fixed message for it.
 */
final class SsoException extends RuntimeException
{
    /** The flow expired, was started elsewhere or was tampered with. */
    public const string STATE = 'state';

    /** The person cancelled on the provider's page. */
    public const string DENIED = 'denied';

    /** The provider answered with something unusable, or could not be reached. */
    public const string PROVIDER = 'provider';

    /** The provider is turned off or not configured. */
    public const string UNAVAILABLE = 'unavailable';

    /** Signing in with an external account that is connected to no account here. */
    public const string UNLINKED = 'unlinked';

    /** Signing up with an address that already has an account. */
    public const string EXISTS = 'exists';

    /** Sign-ups are off, the terms were not accepted, or the domain rules refuse the address. */
    public const string REFUSED = 'refused';

    /** The external account is connected to another account here. */
    public const string TAKEN = 'taken';

    /** Too many attempts from this address. */
    public const string LIMITED = 'limited';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Single sign-on failed: '.$reason);
    }
}
