import { useState } from 'react';
import {
    Alert,
    Badge,
    Button,
    formatDate,
    httpErrorToHuman,
    useExtensionAction,
    type FormExtension,
} from '@pterodactyl/sdk';
import { describeSource, markVerified, resendForUser, statusFromValues, type AdminVerificationStatus } from './api';

export type VerificationFieldValues = {
    signup: boolean;
    source: string | null;
    verified_at: string | null;
    required: boolean;
    pending: boolean;
};

type Notice = { type: 'success' | 'danger'; text: string };

/**
 * The status badge: verified, waiting for the link, or not held back.
 *
 * @param {{ status: AdminVerificationStatus }} props
 * @returns {JSX.Element}
 */
export function StatusBadge({ status }: { status: AdminVerificationStatus }) {
    if (!status.signup) return <Badge tone={'neutral'}>Not required</Badge>;
    if (status.verified_at !== null) return <Badge tone={'success'}>Verified</Badge>;
    if (status.pending) return <Badge tone={'warning'}>Not verified</Badge>;
    return <Badge tone={'neutral'}>Not verified</Badge>;
}

/**
 * The email verification of an account on the admin user page, with buttons to mark it
 * verified or send the link again. Nothing here is saved with the form.
 */
const VerificationFields: FormExtension<VerificationFieldValues, 'admin.user'> = ({ mode, resource, values }) => {
    const [status, setStatus] = useState<AdminVerificationStatus | null>(null);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [busy, setBusy] = useState<'verify' | 'resend' | null>(null);
    const current = status ?? statusFromValues(values);
    const userId = resource?.attributes.id;

    const run = async (kind: 'verify' | 'resend') => {
        if (userId === undefined) return;
        setBusy(kind);
        setNotice(null);
        try {
            const next = kind === 'verify' ? await markVerified(userId) : await resendForUser(userId);
            setStatus(next);
            setNotice({
                type: 'success',
                text: kind === 'verify' ? 'The email address is marked as verified.' : 'A new verification link is on its way.',
            });
        } catch (cause) {
            setNotice({ type: 'danger', text: httpErrorToHuman(cause) });
        } finally {
            setBusy(null);
        }
    };
    const verify = useExtensionAction('admin-verify', () => run('verify'));
    const resend = useExtensionAction('admin-resend', () => run('resend'));

    if (mode === 'create' || current === null) {
        return (
            <p className={'rxrg:text-sm rxrg:text-muted-foreground'}>
                Accounts created by an admin never have to verify their email.
            </p>
        );
    }

    const unverified = current.signup && current.verified_at === null;

    return (
        <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4'} data-rxrg-admin-verification>
            <dl className={'rxrg:grid rxrg:grid-cols-[auto_1fr] rxrg:items-center rxrg:gap-x-6 rxrg:gap-y-2 rxrg:text-sm'}>
                <dt className={'rxrg:text-muted-foreground'}>Email</dt>
                <dd>
                    <StatusBadge status={current} />
                </dd>
                <dt className={'rxrg:text-muted-foreground'}>Account</dt>
                <dd>{describeSource(current.source)}</dd>
                {current.verified_at !== null && (
                    <>
                        <dt className={'rxrg:text-muted-foreground'}>Verified</dt>
                        <dd>
                            <time dateTime={current.verified_at}>
                                {formatDate(current.verified_at, 'MMM D, YYYY [at] h:mm A')}
                            </time>
                        </dd>
                    </>
                )}
            </dl>
            {unverified && current.pending && (
                <p className={'rxrg:text-sm rxrg:text-muted-foreground'}>
                    This account only sees the &quot;Verify your email&quot; screen until the emailed link is opened.
                </p>
            )}
            {unverified && !current.pending && (
                <p className={'rxrg:text-sm rxrg:text-muted-foreground'}>
                    Email verification is turned off, so this account is not held back.
                </p>
            )}
            {notice && <Alert type={notice.type}>{notice.text}</Alert>}
            {unverified && (
                <div className={'rxrg:flex rxrg:flex-wrap rxrg:gap-3'}>
                    <Button
                        type={'button'}
                        size={'small'}
                        isLoading={busy === 'verify'}
                        disabled={busy !== null}
                        onClick={() => void verify()}
                    >
                        Mark as verified
                    </Button>
                    {current.pending && (
                        <Button
                            type={'button'}
                            size={'small'}
                            isSecondary
                            isLoading={busy === 'resend'}
                            disabled={busy !== null}
                            onClick={() => void resend()}
                        >
                            Resend verification email
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
};

export default VerificationFields;
