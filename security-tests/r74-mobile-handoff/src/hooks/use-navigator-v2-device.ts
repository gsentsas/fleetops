import { useEffect, useState } from 'react';
import { config } from '../utils';
import {
    loadNavigatorV2Credential,
} from '../services/navigator-v2-secure-credentials';
import type { NavigatorPairingCredential } from '../services/navigator-pairing-v2';

export type NavigatorV2DeviceState =
    | { status: 'off'; credential: null }
    | { status: 'loading'; credential: null }
    | { status: 'absent'; credential: null }
    | { status: 'invalid'; credential: null }
    | { status: 'ready'; credential: NavigatorPairingCredential };

/**
 * Strictly opt-in: no fallback to old MMKV or a QR-provided host.
 * On a hardware Keychain read failure, no credential is available.
 */
export function useNavigatorV2Device(enabled: boolean): NavigatorV2DeviceState {
    const [state, setState] = useState<NavigatorV2DeviceState>(
        { status: enabled ? 'loading' : 'off', credential: null }
    );

    useEffect(() => {
        let active = true;
        if (!enabled) {
            setState({ status: 'off', credential: null });
            return () => { active = false; };
        }
        setState({ status: 'loading', credential: null });
        const trustedHost = String(config('FLEETBASE_HOST', ''));
        const trustedSocket = String(config('SOCKETCLUSTER_HOST', ''));
        loadNavigatorV2Credential(trustedHost, trustedSocket)
            .then((credential) => {
                if (!active) return;
                setState(credential
                    ? { status: 'ready', credential }
                    : { status: 'absent', credential: null });
            })
            .catch(() => {
                if (!active) return;
                setState({ status: 'invalid', credential: null });
            });
        return () => { active = false; };
    }, [enabled]);

    return state;
}
