import { queryOptions } from '@tanstack/react-query';
import { http } from '@pterodactyl/sdk';

export interface SignupProvider {
    id: string;
    name: string;
}

export interface SignupOptions {
    enabled: boolean;
    terms_url: string | null;
    verification: boolean;
    providers: SignupProvider[];
}

export interface SignupValues {
    username: string;
    email: string;
    name_first: string;
    name_last: string;
    password: string;
    password_confirmation: string;
    terms: boolean;
    website: string;
}

export type SignupResult = { status: 'verify'; email: string } | { status: 'created'; redirect: string };

export interface VerificationState {
    pending: boolean;
    email: string;
}

const BASE = '/extensions/registration';

export const VERIFY_PATH = '/account/verify-email';

export const VERIFICATION_REQUIRED = 'RegistrationVerificationRequired';

export const optionsQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'registration', 'options'],
        queryFn: async () => (await http.get<{ data: SignupOptions }>(`${BASE}/options`)).data.data,
        staleTime: 60_000,
    });

export const verificationQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'registration', 'verification'],
        queryFn: async () => (await http.get<{ data: VerificationState }>(`${BASE}/verification`)).data.data,
        retry: false,
    });

/** Makes sure the CSRF cookie is set before a form posts, as the panel's own sign-in does. */
async function csrf(): Promise<void> {
    await http.get('/sanctum/csrf-cookie');
}

export async function signup(values: SignupValues): Promise<SignupResult> {
    await csrf();
    return (await http.post<{ data: SignupResult }>(`${BASE}/signup`, values)).data.data;
}

export async function resendForEmail(email: string): Promise<void> {
    await csrf();
    await http.post(`${BASE}/resend`, { email });
}

export async function resendMine(): Promise<void> {
    await http.post(`${BASE}/verification`);
}

/** Starts "Sign up with ..." and returns where Single Sign-On takes over. */
export async function startSso(provider: string, terms: boolean): Promise<string> {
    await csrf();
    return (await http.post<{ data: { redirect: string } }>(`${BASE}/sso/${encodeURIComponent(provider)}`, { terms }))
        .data.data.redirect;
}

/**
 * Field errors of a refused sign-up, by field name.
 *
 * @param {unknown} error
 * @returns {Record<string, string>}
 */
export function fieldErrors(error: unknown): Record<string, string> {
    const data = (error as { response?: { data?: { errors?: unknown } } })?.response?.data;
    const errors = Array.isArray(data?.errors) ? data.errors : [];
    const fields: Record<string, string> = {};
    for (const entry of errors) {
        const field = entry?.meta?.source_field;
        if (typeof field === 'string' && typeof entry?.detail === 'string' && !(field in fields)) {
            fields[field] = entry.detail;
        }
    }
    return fields;
}

/**
 * Whether a request was refused because the account has not verified its address yet.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
export function isVerificationRequired(error: unknown): boolean {
    const response = (error as { response?: { status?: unknown; data?: { errors?: unknown } } })?.response;
    if (response?.status !== 409) return false;
    const errors = response.data?.errors;
    return Array.isArray(errors) && errors.some((entry) => entry?.code === VERIFICATION_REQUIRED);
}

/**
 * A search parameter from the route's search, or from the address bar: the login route only
 * keeps the parameters it knows about.
 *
 * @param {unknown} search
 * @param {string} key
 * @returns {string | undefined}
 */
export function searchParam(search: unknown, key: string): string | undefined {
    if (typeof search === 'object' && search !== null) {
        const value = (search as Record<string, unknown>)[key];
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);
        if (typeof value === 'string' && value !== '') return value;
    }
    if (typeof window === 'undefined') return undefined;
    const value = new URLSearchParams(window.location.search).get(key);
    return value === null ? undefined : value.replace(/^"(.*)"$/, '$1');
}

/** An account's verification, as the admin user page shows it. */
export interface AdminVerificationStatus {
    /** Whether the account was created on the sign-up page (or with "Sign up with ..."). */
    signup: boolean;
    source: string | null;
    verified_at: string | null;
    /** Whether email verification is turned on in the settings. */
    required: boolean;
    /** Whether the account is held on the "Verify your email" screen. */
    pending: boolean;
}

const ADMIN_BASE = '/api/admin/extensions/registration';

/**
 * Reads a status from the admin user form's values, which the panel fills from the
 * extension's PHP fields.
 *
 * @param {Partial<Record<string, unknown>>} values
 * @returns {AdminVerificationStatus | null}
 */
export function statusFromValues(values: Partial<Record<string, unknown>>): AdminVerificationStatus | null {
    if (typeof values.signup !== 'boolean') return null;
    return {
        signup: values.signup,
        source: typeof values.source === 'string' ? values.source : null,
        verified_at: typeof values.verified_at === 'string' ? values.verified_at : null,
        required: values.required !== false,
        pending: values.pending === true,
    };
}

export async function markVerified(user: number): Promise<AdminVerificationStatus> {
    return (await http.post<{ data: AdminVerificationStatus }>(`${ADMIN_BASE}/users/${user}/verify`)).data.data;
}

export async function resendForUser(user: number): Promise<AdminVerificationStatus> {
    return (await http.post<{ data: AdminVerificationStatus }>(`${ADMIN_BASE}/users/${user}/resend`)).data.data;
}

/**
 * How an account was created, in words.
 *
 * @param {string | null} source
 * @returns {string}
 */
export function describeSource(source: string | null): string {
    if (source === null) return 'Created by an admin';
    if (source.startsWith('sso:')) {
        const provider = source.slice(4);
        return `Signed up with ${provider.charAt(0).toUpperCase()}${provider.slice(1)}`;
    }
    return 'Signed up on the sign-up page';
}
