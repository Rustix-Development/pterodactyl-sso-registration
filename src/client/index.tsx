import './styles.css';
import { definePterodactylExtension, http } from '@pterodactyl/sdk';
import LoginLink from './LoginLink';
import SignupSlot from './SignupSlot';
import { VERIFY_PATH, isVerificationRequired } from './api';

/**
 * Sends an account that has not verified its address to the "Verify your email" screen when
 * the panel refuses one of its requests.
 *
 * @param {unknown} error
 * @returns {Promise<never>}
 */
export function redirectToVerify(error: unknown): Promise<never> {
    if (isVerificationRequired(error) && window.location.pathname !== VERIFY_PATH) {
        window.location.assign(VERIFY_PATH);
    }

    return Promise.reject(error);
}

export default definePterodactylExtension({
    setup({ slots, screens }) {
        slots.register('auth.login.form.after', LoginLink);
        slots.register('auth.login.before', SignupSlot);
        screens.register('verify', () => import('./VerifyScreen'));
        http.interceptors.response.use(undefined, redirectToVerify);
    },
});
