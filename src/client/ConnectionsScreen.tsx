import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    ContentBox,
    PageContentBlock,
    ServerError,
    Spinner,
    formatDate,
    httpErrorToHuman,
    useExtensionAction,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import ProviderIcon from './ProviderIcon';
import {
    connect,
    connectionsQueryOptions,
    disconnect,
    fieldErrors,
    providerName,
    searchParam,
    ssoMessage,
    type Connection,
} from './api';

/**
 * Account > Connected accounts: the external accounts the user can sign in with.
 *
 * @param {ScreenComponentProps} props
 * @returns {JSX.Element}
 */
export default function ConnectionsScreen(_: ScreenComponentProps) {
    const queryClient = useQueryClient();
    const connections = useQuery(connectionsQueryOptions());
    const returned = ssoMessage(
        searchParam(undefined, 'rxrg_sso'),
        providerName(searchParam(undefined, 'rxrg_provider'), connections.data?.providers ?? [])
    );
    const [notice, setNotice] = useState<{ type: 'success' | 'danger'; text: string } | null>(returned);
    const [busy, setBusy] = useState<string | null>(null);

    const link = useExtensionAction('connect', async (provider: string) => {
        setNotice(null);
        setBusy(provider);
        try {
            window.location.assign(await connect(provider));
        } catch (cause) {
            setNotice({ type: 'danger', text: httpErrorToHuman(cause) });
            setBusy(null);
        }
    });
    const unlink = useExtensionAction('disconnect', async (connection: Connection) => {
        setNotice(null);
        setBusy(connection.id);
        try {
            await disconnect(connection.id);
            setNotice({ type: 'success', text: `${connection.name} is disconnected.` });
            await queryClient.invalidateQueries({ queryKey: connectionsQueryOptions().queryKey });
        } catch (cause) {
            setNotice({ type: 'danger', text: fieldErrors(cause).provider ?? httpErrorToHuman(cause) });
        } finally {
            setBusy(null);
        }
    });

    if (connections.error) {
        return <ServerError message={httpErrorToHuman(connections.error)} onRetry={() => connections.refetch()} />;
    }

    const list = connections.data?.providers ?? [];
    const linked = list.filter((connection) => connection.connected).length;

    return (
        <PageContentBlock title={'Connected accounts'}>
            <ContentBox title={'Connected accounts'}>
                {!connections.data ? (
                    <div className={'rxrg:flex rxrg:justify-center rxrg:p-6'}>
                        <Spinner size={'small'} />
                    </div>
                ) : (
                    <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4'}>
                        <p className={'rxrg:text-sm rxrg:text-muted-foreground'}>
                            Sign in with these accounts instead of your password. Two-factor authentication still applies.
                        </p>
                        {notice && <Alert type={notice.type}>{notice.text}</Alert>}
                        {list.length === 0 ? (
                            <p className={'rxrg:text-sm'}>No sign-in providers are turned on for this panel.</p>
                        ) : (
                            <ul className={'rxrg:m-0 rxrg:flex rxrg:list-none rxrg:flex-col rxrg:divide-y rxrg:divide-border rxrg:p-0'}>
                                {list.map((connection) => {
                                    const last = connection.connected && linked === 1 && !connections.data.has_password;
                                    return (
                                        <li
                                            key={connection.id}
                                            data-connection={connection.id}
                                            className={'rxrg:flex rxrg:flex-wrap rxrg:items-center rxrg:gap-3 rxrg:py-3'}
                                        >
                                            <ProviderIcon icon={connection.icon} />
                                            <div className={'rxrg:min-w-0 rxrg:flex-1'}>
                                                <p className={'rxrg:m-0 rxrg:text-sm rxrg:font-medium'}>{connection.name}</p>
                                                <p className={'rxrg:m-0 rxrg:truncate rxrg:text-xs rxrg:text-muted-foreground'}>
                                                    {connection.connected
                                                        ? `Connected${connection.account ? ` as ${connection.account}` : ''}${
                                                              connection.connected_at
                                                                  ? ` on ${formatDate(connection.connected_at, 'MMM D, YYYY')}`
                                                                  : ''
                                                          }`
                                                        : 'Not connected'}
                                                </p>
                                            </div>
                                            {connection.connected ? (
                                                <Button
                                                    type={'button'}
                                                    size={'small'}
                                                    isSecondary
                                                    color={'red'}
                                                    isLoading={busy === connection.id}
                                                    disabled={busy !== null || last}
                                                    title={last ? 'This is the only way into your account.' : undefined}
                                                    onClick={() => void unlink(connection)}
                                                >
                                                    Disconnect
                                                </Button>
                                            ) : (
                                                <Button
                                                    type={'button'}
                                                    size={'small'}
                                                    isLoading={busy === connection.id}
                                                    disabled={busy !== null || !connection.enabled}
                                                    onClick={() => void link(connection.id)}
                                                >
                                                    Connect
                                                </Button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {!connections.data.has_password && linked > 0 && (
                            <Alert type={'info'}>
                                Your account has no password yet, so its last connection cannot be removed. Use
                                &quot;Forgot password?&quot; on the login page to set one.
                            </Alert>
                        )}
                    </div>
                )}
            </ContentBox>
        </PageContentBlock>
    );
}
