import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    PanelLink,
    Spinner,
    httpErrorToHuman,
    useExtensionAction,
    useOpenLoginCheckpoint,
    type RouteSlotData,
} from '@pterodactyl/sdk';
import ProviderButton, { Divider } from './ProviderButton';
import { optionsQueryOptions, providerName, searchParam, ssoCheckpoint, ssoMessage, startSso } from './api';

const MESSAGES: Record<string, { type: 'success' | 'danger'; text: string }> = {
    verified: { type: 'success', text: 'Your email address is verified. Sign in to continue.' },
    expired: { type: 'danger', text: 'That verification link has expired or is not valid. Sign in to get a new one.' },
};

/**
 * Under the login form: "Continue with ..." for each provider, "Create an account" while
 * sign-ups are open, and the outcome of a verification link or an external sign-in. An
 * external sign-in of a two-factor account comes back here and opens the panel's checkpoint.
 *
 * @param {{ data: RouteSlotData }} props
 * @returns {JSX.Element | null}
 */
export default function LoginLink({ data }: { data: RouteSlotData }) {
    const options = useQuery(optionsQueryOptions());
    const reason = searchParam(data.search, 'rxrg_sso');
    const providers = options.data?.login_providers ?? [];
    const outcome =
        MESSAGES[searchParam(data.search, 'registration') ?? ''] ??
        ssoMessage(reason, providerName(searchParam(data.search, 'rxrg_provider'), providers));
    const open = options.data?.enabled === true;
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [checkpointFailed, setCheckpointFailed] = useState(false);
    const openCheckpoint = useOpenLoginCheckpoint();
    const awaitingCheckpoint = reason === 'checkpoint' && !checkpointFailed;

    useEffect(() => {
        if (!awaitingCheckpoint) return;
        ssoCheckpoint()
            .then((token) => openCheckpoint(token))
            .catch(() => setCheckpointFailed(true));
    }, [awaitingCheckpoint, openCheckpoint]);

    const start = useExtensionAction('sso-login', async (provider: string) => {
        setError(null);
        setBusy(provider);
        try {
            window.location.assign(await startSso(provider, 'login'));
        } catch (cause) {
            setError(httpErrorToHuman(cause));
            setBusy(null);
        }
    });

    if (awaitingCheckpoint) {
        return (
            <div className={'rxrg:mt-6 rxrg:flex rxrg:justify-center'} role={'status'} aria-label={'Opening two-factor sign-in'}>
                <Spinner size={'small'} />
            </div>
        );
    }

    const message = checkpointFailed ? ssoMessage('state') : (outcome ?? null);

    if (!message && !open && providers.length === 0) return null;

    return (
        <div className={'rxrg:mt-6 rxrg:flex rxrg:flex-col rxrg:gap-3'} data-rxrg-login>
            {message && <Alert type={message.type}>{message.text}</Alert>}
            {error && <Alert type={'danger'}>{error}</Alert>}
            {providers.length > 0 && (
                <>
                    <Divider label={'or'} />
                    {providers.map((provider) => (
                        <ProviderButton
                            key={provider.id}
                            provider={provider}
                            label={'Continue with'}
                            busy={busy === provider.id}
                            disabled={busy !== null}
                            onClick={() => void start(provider.id)}
                        />
                    ))}
                </>
            )}
            {open && (
                <p className={'rxrg:text-center rxrg:text-sm rxrg:text-muted-foreground'}>
                    Don&apos;t have an account?{' '}
                    <PanelLink
                        destination={{ to: '/auth/login', search: { signup: '1' } }}
                        className={'rxrg:font-medium rxrg:text-foreground rxrg:underline rxrg:underline-offset-4'}
                    >
                        Create one
                    </PanelLink>
                </p>
            )}
        </div>
    );
}
