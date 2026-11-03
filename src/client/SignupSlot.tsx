import { lazy, Suspense } from 'react';
import { Spinner, type RouteSlotData } from '@pterodactyl/sdk';
import { searchParam } from './api';

const SignupPage = lazy(() => import('./SignupPage'));

/**
 * Whether the visit asks for the sign-up form.
 *
 * @param {unknown} search
 * @returns {boolean}
 */
export function wantsSignup(search: unknown): boolean {
    return searchParam(search, 'signup') !== undefined;
}

/**
 * The sign-up page, at /auth/login?signup=1. The panel gives visitors who are not signed in
 * slots but no pages or view overrides, so the form renders in the slot before the login page,
 * inside the panel's auth layout and the active theme, and styles.css hides the login form
 * while it is there.
 *
 * @param {{ data: RouteSlotData }} props
 * @returns {JSX.Element | null}
 */
export default function SignupSlot({ data }: { data: RouteSlotData }) {
    if (!wantsSignup(data.search)) return null;

    return (
        <div data-rxrg-signup={''}>
            <Suspense
                fallback={
                    <div className={'rxrg:flex rxrg:justify-center rxrg:p-10'}>
                        <Spinner size={'large'} />
                    </div>
                }
            >
                <SignupPage />
            </Suspense>
        </div>
    );
}
