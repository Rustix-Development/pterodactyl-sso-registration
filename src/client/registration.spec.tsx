import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { http, type ExtensionSetupContext, type RouteSlotData } from '@pterodactyl/sdk';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import extension, { redirectToVerify } from './index';
import LoginLink from './LoginLink';
import SignupSlot, { wantsSignup } from './SignupSlot';
import SignupPage, { validateEmail, validateUsername } from './SignupPage';
import VerifyScreen from './VerifyScreen';
import { fieldErrors, isVerificationRequired, type SignupOptions } from './api';

let host: ExtensionTestHost | undefined;
beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
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

const open: SignupOptions = { enabled: true, terms_url: null, verification: true, providers: [], login_providers: [] };

function respond(responses: Record<string, unknown>) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (!(url in responses)) throw new Error(`Unexpected request to ${url}`);
        return { data: { data: responses[url] } };
    });
}

function route(search: Record<string, string> = {}): RouteSlotData {
    return { pathname: '/auth/login', search, params: {} };
}

it('adds the link and the sign-up form to the login page, and a verify screen to the account area', () => {
    const context: ExtensionSetupContext = {
        meta: { id: 'registration' },
        config: {},
        views: { override: vi.fn() },
        slots: { register: vi.fn() },
        screens: { register: vi.fn() },
        columns: { register: vi.fn() },
        forms: { extend: vi.fn() },
    };
    const interceptors = vi.spyOn(http.interceptors.response, 'use').mockReturnValue(0);

    extension.setup(context);

    expect(context.slots.register).toHaveBeenCalledWith('auth.login.form.after', LoginLink);
    expect(context.slots.register).toHaveBeenCalledWith('auth.login.before', SignupSlot);
    expect(context.screens.register).toHaveBeenCalledWith('verify', expect.any(Function));
    expect(interceptors).toHaveBeenCalledWith(undefined, redirectToVerify);
});

it('shows "Create one" under the login form only while sign-ups are open', async () => {
    respond({ '/extensions/registration/options': open });
    renderInHost(<LoginLink data={route()} />);
    expect(await screen.findByText('Create one')).toBeTruthy();
    cleanup();
    host?.dispose();

    respond({ '/extensions/registration/options': { ...open, enabled: false } });
    const { container } = renderInHost(<LoginLink data={route({ registration: 'verified' })} />);
    expect(await screen.findByText('Your email address is verified. Sign in to continue.')).toBeTruthy();
    expect(container.textContent).not.toContain('Create one');
});

it('renders the sign-up form only when asked for', () => {
    expect(wantsSignup({ signup: '1' })).toBe(true);
    expect(wantsSignup({})).toBe(false);
    const { container } = renderInHost(<SignupSlot data={route()} />);
    expect(container.innerHTML).toBe('');
});

it('says sign-ups are closed when they are', async () => {
    respond({ '/extensions/registration/options': { ...open, enabled: false } });
    renderInHost(<SignupPage />);
    expect(await screen.findByText(/Sign-ups are closed on this panel/)).toBeTruthy();
});

it('asks for the terms, offers Single Sign-On and shows the check-your-email step', async () => {
    respond({
        '/extensions/registration/options': {
            ...open,
            terms_url: 'https://example.com/terms',
            providers: [{ id: 'discord', name: 'Discord', icon: 'discord' }],
        },
        '/sanctum/csrf-cookie': null,
    });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { status: 'verify', email: 'new@example.com' } } });
    renderInHost(<SignupPage />);

    expect(await screen.findByText('Sign up with Discord')).toBeTruthy();
    expect(screen.getByText('terms of service').getAttribute('href')).toBe('https://example.com/terms');

    const fill = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
    fill('Username', 'newperson');
    fill('Email address', 'new@example.com');
    fill('First name', 'New');
    fill('Last name', 'Person');
    fill('Password', 'Correct-horse-9');
    fill('Confirm password', 'Correct-horse-9');

    fireEvent.click(screen.getByText('Create Account'));
    expect(await screen.findByText('You must agree to the terms of service.')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByText('Create Account'));
    expect(await screen.findByText('Check your email')).toBeTruthy();
    expect(post).toHaveBeenCalledWith(
        '/extensions/registration/signup',
        expect.objectContaining({ username: 'newperson', email: 'new@example.com', terms: true, website: '' })
    );
});

it('puts the server\'s errors on their fields', async () => {
    respond({ '/extensions/registration/options': open, '/sanctum/csrf-cookie': null });
    vi.spyOn(http, 'post').mockRejectedValue({
        response: {
            status: 422,
            data: { errors: [{ detail: 'Sign-ups with this email address are not allowed.', meta: { source_field: 'email' } }] },
        },
    });
    renderInHost(<SignupPage />);
    await screen.findByText('Create Account');
    for (const [label, value] of [
        ['Username', 'newperson'],
        ['Email address', 'x@blocked.test'],
        ['First name', 'New'],
        ['Last name', 'Person'],
        ['Password', 'Correct-horse-9'],
        ['Confirm password', 'Correct-horse-9'],
    ]) {
        fireEvent.change(screen.getByLabelText(label), { target: { value } });
    }
    fireEvent.click(screen.getByText('Create Account'));
    expect(await screen.findByText('Sign-ups with this email address are not allowed.')).toBeTruthy();
});

it('lets an unverified account ask for a new link', async () => {
    respond({ '/extensions/registration/verification': { pending: true, email: 'new@example.com' } });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { data: { status: 'sent' } } });
    renderInHost(<VerifyScreen data={{ pathname: '/account/verify-email', search: {}, params: {} }} />, '/account/verify-email');

    expect(await screen.findByText('new@example.com')).toBeTruthy();
    fireEvent.click(screen.getByText('Send the link again'));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/extensions/registration/verification'));
    expect(await screen.findByText('A new link is on its way.')).toBeTruthy();
});

it('checks fields on the way in and reads the panel\'s refusals', () => {
    expect(validateUsername({ value: 'ok_name' })).toBeUndefined();
    expect(validateUsername({ value: '-no' })).toBeDefined();
    expect(validateEmail({ value: 'a@b.co' })).toBeUndefined();
    expect(validateEmail({ value: 'nope' })).toBe('Enter a valid email address.');
    expect(fieldErrors({ response: { data: { errors: [{ detail: 'Taken', meta: { source_field: 'username' } }] } } })).toEqual({ username: 'Taken' });
    expect(isVerificationRequired({ response: { status: 409, data: { errors: [{ code: 'RegistrationVerificationRequired' }] } } })).toBe(true);
    expect(isVerificationRequired({ response: { status: 409, data: { errors: [{ code: 'Other' }] } } })).toBe(false);
});
