/**
 * Minimal typing of the Facebook JS SDK surface used by the Embedded
 * Signup v4 launcher (docs/modules/m0-onboarding.md §1). Ambient on
 * purpose — the SDK attaches itself to `window`.
 */

type FacebookAuthResponse = {
    /** Present when response_type is 'code' — the one-time exchange code. */
    code?: string;
    accessToken?: string;
    expiresIn?: number;
    userID?: string;
};

type FacebookLoginResponse = {
    status: 'connected' | 'not_authorized' | 'unknown';
    authResponse: FacebookAuthResponse | null;
};

type FacebookLoginOptions = {
    config_id?: string;
    response_type?: 'code' | 'token';
    override_default_response_type?: boolean;
    scope?: string;
    extras?: {
        setup?: Record<string, unknown>;
        featureType?: string;
        sessionInfoVersion?: string;
    };
};

type FacebookSdk = {
    init(options: {
        appId: string;
        autoLogAppEvents?: boolean;
        xfbml?: boolean;
        version: string;
    }): void;
    login(
        callback: (response: FacebookLoginResponse) => void,
        options?: FacebookLoginOptions,
    ): void;
};

interface Window {
    FB?: FacebookSdk;
    fbAsyncInit?: () => void;
}
