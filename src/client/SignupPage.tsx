import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Form,
    PanelLink,
    Spinner,
    httpErrorToHuman,
    useAppForm,
    useExtensionAction,
} from '@pterodactyl/sdk';
import {
    fieldErrors,
    optionsQueryOptions,
    resendForEmail,
    signup,
    startSso,
    type SignupOptions,
    type SignupProvider,
    type SignupValues,
} from './api';

const FIELDS = ['username', 'email', 'name_first', 'name_last', 'password', 'password_confirmation'] as const;

const linkClass = 'rxrg:font-medium rxrg:text-foreground rxrg:underline rxrg:underline-offset-4';

/**
 * @param {string} message
 * @returns {(field: { value: string }) => string | undefined}
 */
const required =
    (message: string) =>
    ({ value }: { value: string }): string | undefined =>
        value.trim() === '' ? message : undefined;

/**
 * @param {{ value: string }} field
 * @returns {string | undefined}
 */
export function validateEmail({ value }: { value: string }): string | undefined {
    if (value.trim() === '') return 'Enter your email address.';
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim()) ? undefined : 'Enter a valid email address.';
}

/**
 * @param {{ value: string }} field
 * @returns {string | undefined}
 */
export function validateUsername({ value }: { value: string }): string | undefined {
    if (value.trim() === '') return 'Choose a username.';
    return /^[a-z0-9]([\w.-]+)[a-z0-9]$/.test(value.toLowerCase())
        ? undefined
        : 'Use at least 3 letters, numbers, dashes, underscores or periods, starting and ending with a letter or number.';
}

/**
 * The card the sign-up page is drawn in, shaped like the panel's sign-in card.
 *
 * @param {{ title: string; children: React.ReactNode }} props
 * @returns {JSX.Element}
 */
function Card({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className={'rxrg:mx-auto rxrg:w-full rxrg:max-w-xl rxrg:px-1 rxrg:md:p-10'}>
            <h2 className={'rxrg:py-4 rxrg:text-center rxrg:text-3xl rxrg:font-medium rxrg:text-foreground'}>
                {title}
            </h2>
            <div className={'rxrg:rounded-lg rxrg:bg-card rxrg:p-6 rxrg:text-card-foreground rxrg:shadow-lg'}>
                {children}
            </div>
        </div>
    );
}

/**
 * @param {{ email: string }} props
 * @returns {JSX.Element}
 */
export function CheckEmail({ email }: { email: string }) {
    const [state, setState] = useState<{ sent: boolean; error: string | null }>({ sent: false, error: null });
    const resend = useExtensionAction('resend', async () => {
        try {
            await resendForEmail(email);
            setState({ sent: true, error: null });
        } catch (cause) {
            setState({ sent: false, error: httpErrorToHuman(cause) });
        }
    });

    return (
        <Card title={'Check your email'}>
            <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4 rxrg:text-sm'}>
                <p>
                    We sent a link to <strong className={'rxrg:break-all'}>{email || 'your email address'}</strong>. Open
                    it to verify your address, then sign in.
                </p>
                <p className={'rxrg:text-muted-foreground'}>
                    It can take a few minutes to arrive. Check your spam folder too.
                </p>
                {state.sent && <Alert type={'success'}>If that address is waiting for a link, a new one is on its way.</Alert>}
                {state.error && <Alert type={'danger'}>{state.error}</Alert>}
                <div className={'rxrg:flex rxrg:flex-col rxrg:gap-3 rxrg:sm:flex-row rxrg:sm:items-center rxrg:sm:justify-between'}>
                    <Button type={'button'} isSecondary onClick={() => void resend()} disabled={email === ''}>
                        Send the link again
                    </Button>
                    <PanelLink destination={{ to: '/auth/login' }} className={linkClass}>
                        Back to sign in
                    </PanelLink>
                </div>
            </div>
        </Card>
    );
}

/**
 * @param {{ providers: SignupProvider[]; terms: boolean; needsTerms: boolean; onTermsMissing: () => void }} props
 * @returns {JSX.Element}
 */
function SsoButtons({
    providers,
    terms,
    needsTerms,
    onTermsMissing,
}: {
    providers: SignupProvider[];
    terms: boolean;
    needsTerms: boolean;
    onTermsMissing: () => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const start = useExtensionAction('sso', async (provider: string) => {
        if (needsTerms && !terms) {
            onTermsMissing();
            return;
        }
        setError(null);
        try {
            window.location.assign(await startSso(provider, terms));
        } catch (cause) {
            setError(httpErrorToHuman(cause));
        }
    });

    return (
        <div className={'rxrg:mt-6 rxrg:flex rxrg:flex-col rxrg:gap-3'}>
            <div
                className={
                    'rxrg:flex rxrg:items-center rxrg:gap-3 rxrg:text-xs rxrg:uppercase rxrg:tracking-wide rxrg:text-muted-foreground'
                }
            >
                <span className={'rxrg:h-px rxrg:flex-1 rxrg:bg-border'} />
                or sign up with
                <span className={'rxrg:h-px rxrg:flex-1 rxrg:bg-border'} />
            </div>
            {error && <Alert type={'danger'}>{error}</Alert>}
            <div className={'rxrg:grid rxrg:grid-cols-1 rxrg:gap-3 rxrg:sm:grid-cols-2'}>
                {providers.map((provider) => (
                    <button
                        key={provider.id}
                        type={'button'}
                        data-provider={provider.id}
                        onClick={() => void start(provider.id)}
                        className={
                            'rxrg:w-full rxrg:cursor-pointer rxrg:rounded-sm rxrg:border rxrg:border-border rxrg:bg-transparent rxrg:p-3 rxrg:text-sm rxrg:uppercase rxrg:tracking-wide rxrg:text-foreground rxrg:transition-colors rxrg:hover:bg-accent rxrg:hover:text-accent-foreground'
                        }
                    >
                        Sign up with {provider.name}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * @param {{ options: SignupOptions; onVerify: (email: string) => void }} props
 * @returns {JSX.Element}
 */
function SignupForm({ options, onVerify }: { options: SignupOptions; onVerify: (email: string) => void }) {
    const [error, setError] = useState<string | null>(null);
    const [terms, setTerms] = useState(false);
    const [termsError, setTermsError] = useState<string | null>(null);
    const needsTerms = options.terms_url !== null;

    const submit = useExtensionAction('signup', async (values: SignupValues) => {
        setError(null);
        if (needsTerms && !terms) {
            setTermsError('You must agree to the terms of service.');
            return;
        }
        try {
            const result = await signup({ ...values, terms });
            if (result.status === 'verify') {
                onVerify(result.email);
            } else {
                window.location.assign(result.redirect || '/');
            }
        } catch (cause) {
            const fields = fieldErrors(cause);
            let placed = false;
            for (const name of FIELDS) {
                if (fields[name]) {
                    placed = true;
                    form.setFieldMeta(name, (meta) => ({ ...meta, isTouched: true, errorMap: { ...meta.errorMap, onServer: fields[name] } }));
                }
            }
            if (fields.terms) {
                placed = true;
                setTermsError(fields.terms);
            }
            if (!placed) setError(httpErrorToHuman(cause));
        }
    });

    const form = useAppForm({
        defaultValues: {
            username: '',
            email: '',
            name_first: '',
            name_last: '',
            password: '',
            password_confirmation: '',
            terms: false,
            website: '',
        } satisfies SignupValues,
        onSubmit: ({ value }) => submit(value),
    });

    return (
        <Form form={form}>
            <div className={'rxrg:flex rxrg:flex-col rxrg:gap-5'}>
                {error && <Alert type={'danger'}>{error}</Alert>}
                <form.AppField name={'username'} validators={{ onChange: validateUsername }}>
                    {(field) => <field.TextField light label={'Username'} autoComplete={'username'} />}
                </form.AppField>
                <form.AppField name={'email'} validators={{ onChange: validateEmail }}>
                    {(field) => <field.TextField light type={'email'} label={'Email address'} autoComplete={'email'} />}
                </form.AppField>
                <div className={'rxrg:grid rxrg:grid-cols-1 rxrg:gap-5 rxrg:sm:grid-cols-2'}>
                    <form.AppField name={'name_first'} validators={{ onChange: required('Enter your first name.') }}>
                        {(field) => <field.TextField light label={'First name'} autoComplete={'given-name'} />}
                    </form.AppField>
                    <form.AppField name={'name_last'} validators={{ onChange: required('Enter your last name.') }}>
                        {(field) => <field.TextField light label={'Last name'} autoComplete={'family-name'} />}
                    </form.AppField>
                </div>
                <form.AppField name={'password'} validators={{ onChange: required('Choose a password.') }}>
                    {(field) => (
                        <field.TextField light type={'password'} label={'Password'} autoComplete={'new-password'} />
                    )}
                </form.AppField>
                <form.AppField
                    name={'password_confirmation'}
                    validators={{
                        onChangeListenTo: ['password'],
                        onChange: ({ value, fieldApi }) =>
                            value !== fieldApi.form.getFieldValue('password') ? 'The passwords do not match.' : undefined,
                    }}
                >
                    {(field) => (
                        <field.TextField light type={'password'} label={'Confirm password'} autoComplete={'new-password'} />
                    )}
                </form.AppField>
                <div className={'rxrg-trap'} aria-hidden={'true'}>
                    <label>
                        Website
                        <form.Field name={'website'}>
                            {(field) => (
                                <input
                                    type={'text'}
                                    name={'website'}
                                    tabIndex={-1}
                                    autoComplete={'off'}
                                    value={field.state.value}
                                    onChange={(event) => field.handleChange(event.target.value)}
                                />
                            )}
                        </form.Field>
                    </label>
                </div>
                {needsTerms && (
                    <div>
                        <label className={'rxrg:flex rxrg:items-start rxrg:gap-3 rxrg:text-sm'}>
                            <Checkbox
                                checked={terms}
                                onChange={(checked) => {
                                    setTerms(checked);
                                    if (checked) setTermsError(null);
                                }}
                                aria-label={'I agree to the terms of service'}
                            />
                            <span>
                                I agree to the{' '}
                                <a href={options.terms_url ?? '#'} target={'_blank'} rel={'noopener noreferrer'} className={linkClass}>
                                    terms of service
                                </a>
                            </span>
                        </label>
                        {termsError && <p className={'input-help error'}>{termsError}</p>}
                    </div>
                )}
                {/* A captcha extension mounts its widget here; see Contracts/VerifiesHumans.php. */}
                <div data-registration-captcha={''} />
                <form.AppForm>
                    <form.SubmitButton size={'xlarge'} className={'rxrg:w-full'}>
                        Create Account
                    </form.SubmitButton>
                </form.AppForm>
            </div>
            {options.providers.length > 0 && (
                <SsoButtons
                    providers={options.providers}
                    terms={terms}
                    needsTerms={needsTerms}
                    onTermsMissing={() => setTermsError('You must agree to the terms of service.')}
                />
            )}
            <p className={'rxrg:mt-6 rxrg:text-center rxrg:text-sm rxrg:text-muted-foreground'}>
                Already have an account?{' '}
                <PanelLink destination={{ to: '/auth/login' }} className={linkClass}>
                    Sign in
                </PanelLink>
            </p>
        </Form>
    );
}

/**
 * The sign-up page, at /auth/login?signup=1 (or /register).
 *
 * @returns {JSX.Element}
 */
export default function SignupPage() {
    const options = useQuery(optionsQueryOptions());
    const [pending, setPending] = useState<string | null>(null);

    if (pending !== null) {
        return <CheckEmail email={pending} />;
    }

    if (!options.data) {
        return (
            <Card title={'Create an Account'}>
                {options.error ? (
                    <Alert type={'danger'}>{httpErrorToHuman(options.error)}</Alert>
                ) : (
                    <div className={'rxrg:flex rxrg:justify-center rxrg:p-6'}>
                        <Spinner size={'small'} />
                    </div>
                )}
            </Card>
        );
    }

    if (!options.data.enabled) {
        return (
            <Card title={'Create an Account'}>
                <div className={'rxrg:flex rxrg:flex-col rxrg:gap-4 rxrg:text-sm'}>
                    <p>Sign-ups are closed on this panel. Ask an administrator for an account.</p>
                    <PanelLink destination={{ to: '/auth/login' }} className={linkClass}>
                        Back to sign in
                    </PanelLink>
                </div>
            </Card>
        );
    }

    return (
        <Card title={'Create an Account'}>
            <SignupForm options={options.data} onVerify={setPending} />
        </Card>
    );
}
