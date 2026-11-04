import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { http, type ExtensionSetupContext, type RouteSlotData } from '@pterodactyl/sdk';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import extension from './index';
import LoginLink from './LoginLink';
import SignupPage from './SignupPage';
import ConnectionsScreen from './ConnectionsScreen';
import VerificationFields from './VerificationFields';
import ProviderIcon from './ProviderIcon';
import { describeSource, providerName, ssoMessage, statusFromValues, type Connections, type SignupOptions } from './api';

let host: ExtensionTestHost | undefined;
beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    window.history.replaceState({}, '', '/');
});
afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.restoreAllMocks();
});

function renderInHost(children: ReactNode, path = '/auth/login') {
    host = createExtensionTestHost({ extensionId: 'registration', path, prefix: 'rxrg' });
    return render(children, { wrapper: host.Wrapper });
}

function respond(responses: Record<string, unknown>) {
    return vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (!(url in responses)) throw new Error(`Unexpected request to ${url}`);
        return { data: { data: responses[url] } };
    });
}

const route = (search: Record<string, string> = {}): RouteSlotData => ({ pathname: '/auth/login', search, params: {} });

const discord = { id: 'discord', name: 'Discord', icon: 'discord' };
const cloudflare = { id: 'oidc', name: 'Cloudflare', icon: 'cloud' };
const options: SignupOptions = {
    enabled: true,
    terms_url: 'https://example.com/terms',
    verification: true,
    providers: [discord, cloudflare],
    login_providers: [discord, cloudflare],
};

it('draws the admin user fields and the connected accounts screen', () => {
    const context: ExtensionSetupContext = {
        meta: { id: 'registration' },
        config: {},
        views: { override: vi.fn() },
        slots: { register: vi.fn() },
        screens: { register: vi.fn() },
        columns: { register: vi.fn() },
        forms: { extend: vi.fn() },
    };
    vi.spyOn(http.interceptors.response, 'use').mockReturnValue(0);
    extension.setup(context);
    expect(context.forms.extend).toHaveBeenCalledWith('admin.user', VerificationFields);
    expect(context.screens.register).toHaveBeenCalledWith('connections', expect.any(Function));
});

it('shows "Continue with" for each provider under the login form and starts a sign-in', async () => {
    respond({ '/extensions/registration/options': options, '/sanctum/csrf-cookie': null });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { redirect: 'https://discord.com/oauth2/authorize?x' } } });
    renderInHost(<LoginLink data={route()} />);

    expect(await screen.findByText('Continue with Discord')).toBeTruthy();
    expect(screen.getByText('Continue with Cloudflare')).toBeTruthy();
    fireEvent.click(screen.getByText('Continue with Discord'));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/extensions/registration/sso/discord/start', { intent: 'login', terms: false })
    );
});

it('leaves the login buttons out when the Single Sign-On extension draws its own', async () => {
    respond({ '/extensions/registration/options': { ...options, login_providers: [] } });
    renderInHost(<LoginLink data={route()} />);
    expect(await screen.findByText('Create one')).toBeTruthy();
    expect(screen.queryByText('Continue with Discord')).toBeNull();
});

it('explains an unconnected external account without saying whether its address has an account', async () => {
    respond({ '/extensions/registration/options': options });
    renderInHost(<LoginLink data={route({ rxrg_sso: 'unlinked', rxrg_provider: 'oidc' })} />);
    expect(
        await screen.findByText(
            'That Cloudflare account is not connected to an account here. Sign in another way, then connect it under Account > Connected accounts.'
        )
    ).toBeTruthy();
});

it('opens the panel\'s two-factor checkpoint after an external sign-in', async () => {
    const get = respond({
        '/extensions/registration/options': options,
        '/extensions/registration/sso/checkpoint': { confirmation_token: 'tok' },
    });
    renderInHost(<LoginLink data={route({ rxrg_sso: 'checkpoint' })} />);
    expect(await screen.findByRole('status', { name: 'Opening two-factor sign-in' })).toBeTruthy();
    await waitFor(() => expect(get).toHaveBeenCalledWith('/extensions/registration/sso/checkpoint'));
});

it('asks for the terms before signing up with a provider', async () => {
    respond({ '/extensions/registration/options': options, '/sanctum/csrf-cookie': null });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { redirect: 'https://idp.test/auth' } } });
    renderInHost(<SignupPage />);

    fireEvent.click(await screen.findByText('Sign up with Cloudflare'));
    expect(await screen.findByText('You must agree to the terms of service.')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByText('Sign up with Cloudflare'));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/extensions/registration/sso/oidc/start', { intent: 'signup', terms: true })
    );
});

it('shows why a sign-up with a provider came back refused', async () => {
    window.history.replaceState({}, '', '/auth/login?signup=1&rxrg_sso=exists&rxrg_provider=discord');
    respond({ '/extensions/registration/options': options });
    renderInHost(<SignupPage />);
    expect(
        await screen.findByText(
            'An account already uses this email address. Sign in to it, then connect Discord under Account > Connected accounts.'
        )
    ).toBeTruthy();
});

it('finishes a Steam sign-up with an email address', async () => {
    window.history.replaceState({}, '', '/auth/login?signup=1&rxrg_finish=1');
    respond({
        '/extensions/registration/options': options,
        '/extensions/registration/sso/pending': { provider: 'steam', name: 'Steam', email: null },
        '/sanctum/csrf-cookie': null,
    });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { status: 'verify', email: 'gabe@example.com' } } });
    renderInHost(<SignupPage />);

    expect(await screen.findByText(/Steam does not share an email address/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'gabe@example.com' } });
    fireEvent.click(screen.getByText('Create Account'));
    expect(await screen.findByText('Check your email')).toBeTruthy();
    expect(post).toHaveBeenCalledWith('/extensions/registration/sso/finish', { email: 'gabe@example.com' });
});

it('says when the finishing step has expired', async () => {
    window.history.replaceState({}, '', '/auth/login?signup=1&rxrg_finish=1');
    respond({ '/extensions/registration/options': options, '/extensions/registration/sso/pending': null });
    renderInHost(<SignupPage />);
    expect(await screen.findByText('This sign-up has expired. Start again from the sign-up page.')).toBeTruthy();
});

const connections: Connections = {
    has_password: false,
    providers: [
        { ...discord, enabled: true, connected: true, account: 'Nelly', connected_at: '2026-11-01T10:00:00+00:00' },
        { id: 'github', name: 'GitHub', icon: 'github', enabled: true, connected: false, account: null, connected_at: null },
    ],
};

it('lists connected accounts, connects one and keeps the last way in', async () => {
    respond({ '/extensions/registration/connections': connections });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { redirect: 'https://github.com/login/oauth/authorize' } } });
    renderInHost(<ConnectionsScreen data={{ pathname: '/account/connected-accounts', search: {}, params: {} }} />, '/account/connected-accounts');

    expect(await screen.findByText(/Connected as Nelly/)).toBeTruthy();
    const disconnect = screen.getByText('Disconnect').closest('button');
    expect(disconnect?.disabled).toBe(true);
    expect(screen.getByText(/its last connection cannot be removed/)).toBeTruthy();

    fireEvent.click(screen.getByText('Connect'));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/extensions/registration/connections/github'));
});

it('disconnects a provider and shows the panel\'s refusal', async () => {
    respond({ '/extensions/registration/connections': { ...connections, has_password: true } });
    const remove = vi.spyOn(http, 'delete').mockRejectedValueOnce({
        response: { status: 422, data: { errors: [{ detail: 'This is the only way into your account.', meta: { source_field: 'provider' } }] } },
    });
    renderInHost(<ConnectionsScreen data={{ pathname: '/account/connected-accounts', search: {}, params: {} }} />, '/account/connected-accounts');

    fireEvent.click(await screen.findByText('Disconnect'));
    expect(await screen.findByText('This is the only way into your account.')).toBeTruthy();
    expect(remove).toHaveBeenCalledWith('/extensions/registration/connections/discord');
});

it('shows the verification status on the admin user page and marks it verified', async () => {
    const post = vi.spyOn(http, 'post').mockResolvedValue({
        data: { data: { signup: true, source: 'sso:steam', verified_at: '2026-11-03T12:00:00+00:00', required: true, pending: false } },
    });
    const resource = { object: 'user', attributes: { id: 17 } } as never;
    renderInHost(
        <VerificationFields
            form={'admin.user'}
            mode={'edit'}
            resource={resource}
            values={{ signup: true, source: 'sso:steam', verified_at: null, required: true, pending: true }}
            field={vi.fn() as never}
        />,
        '/panel/users/17'
    );

    expect(await screen.findByText('Not verified')).toBeTruthy();
    expect(screen.getByText('Signed up with Steam')).toBeTruthy();
    expect(screen.getByText('Resend verification email')).toBeTruthy();
    fireEvent.click(screen.getByText('Mark as verified'));
    expect(await screen.findByText('The email address is marked as verified.')).toBeTruthy();
    expect(post).toHaveBeenCalledWith('/api/admin/extensions/registration/users/17/verify');
    expect(screen.getAllByText('Verified').length).toBe(2);
    expect(screen.queryByText('Mark as verified')).toBeNull();
});

it('resends the verification email from the admin user page', async () => {
    const post = vi.spyOn(http, 'post').mockResolvedValue({
        data: { data: { signup: true, source: 'password', verified_at: null, required: true, pending: true } },
    });
    renderInHost(
        <VerificationFields
            form={'admin.user'}
            mode={'edit'}
            resource={{ object: 'user', attributes: { id: 5 } } as never}
            values={{ signup: true, source: 'password', verified_at: null, required: true, pending: true }}
            field={vi.fn() as never}
        />
    );
    fireEvent.click(await screen.findByText('Resend verification email'));
    expect(await screen.findByText('A new verification link is on its way.')).toBeTruthy();
    expect(post).toHaveBeenCalledWith('/api/admin/extensions/registration/users/5/resend');
});

it('says accounts an admin creates need no verification', async () => {
    renderInHost(
        <VerificationFields form={'admin.user'} mode={'create'} values={{}} field={vi.fn() as never} />
    );
    expect(await screen.findByText('Accounts created by an admin never have to verify their email.')).toBeTruthy();
});

it('reads statuses, sources, names and messages', () => {
    expect(statusFromValues({})).toBeNull();
    expect(statusFromValues({ signup: false })).toEqual({ signup: false, source: null, verified_at: null, required: true, pending: false });
    expect(describeSource(null)).toBe('Created by an admin');
    expect(describeSource('sso:github')).toBe('Signed up with Github');
    expect(providerName('steam')).toBe('Steam');
    expect(providerName('oidc', [cloudflare])).toBe('Cloudflare');
    expect(ssoMessage('checkpoint')).toBeNull();
    expect(ssoMessage('linked', 'GitHub')?.type).toBe('success');
    expect(ssoMessage('weird')?.text).toBe('That sign-in expired or was started in another tab. Try again.');
});

it('draws brand logos and the generic OpenID Connect icons', () => {
    const { container } = render(
        <>
            <ProviderIcon icon={'google'} />
            <ProviderIcon icon={'cloud'} />
        </>
    );
    expect(container.querySelectorAll('svg').length).toBe(2);
});
