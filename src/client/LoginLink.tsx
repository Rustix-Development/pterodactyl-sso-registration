import { useQuery } from '@tanstack/react-query';
import { Alert, PanelLink, type RouteSlotData } from '@pterodactyl/sdk';
import { optionsQueryOptions, searchParam } from './api';

const MESSAGES: Record<string, { type: 'success' | 'danger'; text: string }> = {
    verified: { type: 'success', text: 'Your email address is verified. Sign in to continue.' },
    expired: { type: 'danger', text: 'That verification link has expired or is not valid. Sign in to get a new one.' },
};

/**
 * Under the login form: "Create an account" while sign-ups are open, and the outcome of a
 * verification link.
 *
 * @param {{ data: RouteSlotData }} props
 * @returns {JSX.Element | null}
 */
export default function LoginLink({ data }: { data: RouteSlotData }) {
    const options = useQuery(optionsQueryOptions());
    const outcome = MESSAGES[searchParam(data.search, 'registration') ?? ''];
    const open = options.data?.enabled === true;

    if (!outcome && !open) return null;

    return (
        <div className={'rxrg:mt-6 rxrg:flex rxrg:flex-col rxrg:gap-3'}>
            {outcome && <Alert type={outcome.type}>{outcome.text}</Alert>}
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
