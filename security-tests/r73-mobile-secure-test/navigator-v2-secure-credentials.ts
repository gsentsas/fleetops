import * as Keychain from 'react-native-keychain';
import type { NavigatorPairingCredential } from './navigator-pairing-v2';

const SERVICE = 'io.fleetbase.navigator.pairing.v2.credential';
const ACCOUNT = 'navigator-v2-device';

/**
 * Persist only to a dedicated OS-protected Keychain service.
 * Never MMKV, AsyncStorage, JS logs, analytics, clipboard, QR, or URL.
 *
 * On Android, require hardware-backed credential encryption; if this
 * handset lacks it, fail closed instead of silently falling back.
 * The separate P-256 signing key remains in native AndroidKeystore.
 */
function origin(url: string): string {
    if (typeof url !== 'string') {
        throw new Error('Trusted Navigator origin required');
    }
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:' || parsed.username || parsed.password
        || parsed.search || parsed.hash || parsed.pathname !== '/'
        || !parsed.hostname) {
        throw new Error('Navigator requires a trusted HTTPS origin');
    }
    return parsed.origin;
}

function validated(
    value: NavigatorPairingCredential,
    trustedOrigin: string,
    trustedSocketHostname: string
): NavigatorPairingCredential {
    const expected = origin(trustedOrigin);
    if (!value || typeof value.credential !== 'string'
        || value.credential.length < 16 || value.credential.length > 4096
        || /[\r\n]/.test(value.credential)
        || typeof value.host !== 'string' || origin(value.host) !== expected
        || typeof value.socketcluster_host !== 'string'
        || value.socketcluster_host !== trustedSocketHostname
        || value.socketcluster_secure !== true
        || !Number.isSafeInteger(Number(value.socketcluster_port))
        || Number(value.socketcluster_port) < 1
        || Number(value.socketcluster_port) > 65535
    ) {
        throw new Error('Navigator credential does not match trusted configuration');
    }
    return {
        credential: value.credential,
        host: expected,
        socketcluster_host: trustedSocketHostname,
        socketcluster_port: Number(value.socketcluster_port),
        socketcluster_secure: true,
    };
}

/** Credential is not returned from this method to avoid accidental logging. */
export async function saveNavigatorV2Credential(
    value: NavigatorPairingCredential,
    trustedOrigin: string,
    trustedSocketHostname: string
): Promise<void> {
    const payload = validated(value, trustedOrigin, trustedSocketHostname);
    const stored = await Keychain.setGenericPassword(ACCOUNT, JSON.stringify(payload), {
        service: SERVICE,
        accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
        securityLevel: Keychain.SECURITY_LEVEL.SECURE_HARDWARE,
    });
    if (!stored) {
        throw new Error('Secure device credential storage unavailable');
    }
}

/**
 * Read only after the application has its trusted host and WSS hostname.
 * Never accept a trusted hostname supplied by QR or by returned credential.
 */
export async function loadNavigatorV2Credential(
    trustedOrigin: string,
    trustedSocketHostname: string
): Promise<NavigatorPairingCredential | null> {
    const entry = await Keychain.getGenericPassword({ service: SERVICE });
    if (!entry) {
        return null;
    }
    if (entry.username !== ACCOUNT) {
        throw new Error('Unexpected Navigator credential owner');
    }
    try {
        return validated(
            JSON.parse(entry.password) as NavigatorPairingCredential,
            trustedOrigin,
            trustedSocketHostname
        );
    } catch {
        throw new Error('Secure Navigator credential invalid');
    }
}

export async function clearNavigatorV2Credential(): Promise<void> {
    const result = await Keychain.resetGenericPassword({ service: SERVICE });
    if (!result) {
        const remaining = await Keychain.getGenericPassword({ service: SERVICE });
        if (remaining) {
            throw new Error('Cannot clear Navigator credential');
        }
    }
}
