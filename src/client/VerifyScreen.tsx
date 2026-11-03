import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    Button,
    ContentBox,
    PageContentBlock,
    ServerError,
    Spinner,
    http,
    httpErrorToHuman,
    useExtensionAction,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { resendMine, verificationQueryOptions } from './api';

/**
 * Where an account that has not verified its address lands. The panel keeps the rest of the
 * account closed until the emailed link is opened.
 *
 * @param {ScreenComponentProps} props
 * @returns {JSX.Element}
 */
export default function VerifyScreen(_: ScreenComponentProps) {
    const state = useQuery(verificationQueryOptions());
    const [notice, setNotice] = useState<{ type: 'success' | 'danger'; text: string } | null>(null);
    const resend = useExtensionAction('resend', async () => {
        try {
            await resendMine();
            setNotice({ type: 'success', text: 'A new link is on its way.' });
        } catch (cause) {
            setNotice({ type: 'danger', text: httpErrorToHuman(cause) });
        }
    });
    const signOut = useExtensionAction('sign-out', async () => {
        await http.post('/auth/logout');
        window.location.assign('/auth/login');
    });

    if (state.error) {
        return <ServerError message={httpErrorToHuman(state.error)} onRetry={() => state.refetch()} />;
    }

    return (
        <PageContentBlock title={'Verify your email'}>
            <ContentBox title={'Verify your email'}>
                {!state.data ? (
                    <div className={'rxrg:flex rxrg:justify-center rxrg:p-6'}>
                        <Spinner size={'small'} />
                    </div>
                ) : !state.data.pending ? (
                    <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4'}>
                        <Alert type={'success'}>Your email address is verified.</Alert>
                        <div>
                            <Button type={'button'} onClick={() => window.location.assign('/')}>
                                Go to the dashboard
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4 rxrg:text-sm'}>
                        <p>
                            Before you can use the panel, open the link we sent to{' '}
                            <strong className={'rxrg:break-all'}>{state.data.email}</strong>.
                        </p>
                        <p className={'rxrg:text-muted-foreground'}>
                            The link works for 24 hours. Check your spam folder if it has not arrived.
                        </p>
                        {notice && <Alert type={notice.type}>{notice.text}</Alert>}
                        <div className={'rxrg:flex rxrg:flex-wrap rxrg:gap-3'}>
                            <Button type={'button'} onClick={() => void resend()}>
                                Send the link again
                            </Button>
                            <Button type={'button'} isSecondary onClick={() => void signOut()}>
                                Sign out
                            </Button>
                        </div>
                    </div>
                )}
            </ContentBox>
        </PageContentBlock>
    );
}
