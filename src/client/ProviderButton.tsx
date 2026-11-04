import { Spinner } from '@pterodactyl/sdk';
import ProviderIcon from './ProviderIcon';
import type { SignupProvider } from './api';

export const providerButtonClass =
    'rxrg:flex rxrg:w-full rxrg:min-h-11 rxrg:cursor-pointer rxrg:items-center rxrg:justify-center rxrg:gap-2.5 rxrg:rounded-sm rxrg:border rxrg:border-border rxrg:bg-transparent rxrg:px-3 rxrg:py-2.5 rxrg:text-sm rxrg:font-medium rxrg:text-foreground rxrg:transition-colors rxrg:hover:bg-accent rxrg:hover:text-accent-foreground rxrg:focus-visible:outline-2 rxrg:focus-visible:outline-offset-2 rxrg:focus-visible:outline-ring rxrg:disabled:cursor-wait rxrg:disabled:opacity-70 rxrg:motion-reduce:transition-none';

/**
 * "Continue with Discord", "Sign up with GitHub" and so on.
 *
 * @param {{ provider: SignupProvider; label: string; busy?: boolean; disabled?: boolean; onClick: () => void }} props
 * @returns {JSX.Element}
 */
export default function ProviderButton({
    provider,
    label,
    busy = false,
    disabled = false,
    onClick,
}: {
    provider: SignupProvider;
    label: string;
    busy?: boolean;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type={'button'}
            data-provider={provider.id}
            className={providerButtonClass}
            disabled={disabled || busy}
            aria-busy={busy || undefined}
            onClick={onClick}
        >
            {busy ? <Spinner size={'small'} /> : <ProviderIcon icon={provider.icon} />}
            <span>{label ? `${label} ${provider.name}` : provider.name}</span>
        </button>
    );
}

/**
 * The "or" line between a form and the provider buttons.
 *
 * @param {{ label: string }} props
 * @returns {JSX.Element}
 */
export function Divider({ label }: { label: string }) {
    return (
        <div
            className={
                'rxrg:flex rxrg:items-center rxrg:gap-3 rxrg:text-xs rxrg:uppercase rxrg:tracking-wide rxrg:text-muted-foreground'
            }
        >
            <span className={'rxrg:h-px rxrg:flex-1 rxrg:bg-border'} />
            {label}
            <span className={'rxrg:h-px rxrg:flex-1 rxrg:bg-border'} />
        </div>
    );
}
