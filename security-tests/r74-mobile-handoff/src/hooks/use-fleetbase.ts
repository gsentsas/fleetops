import { useMemo, useState, useEffect, useCallback } from 'react';
import Fleetbase from '@fleetbase/sdk';
import { useConfig } from '../contexts/ConfigContext';
import useStorage from './use-storage';
import { NAVIGATOR_V2_SDK_ENABLED } from '../services/navigator-v2-release-gate';
import { useNavigatorV2Device } from './use-navigator-v2-device';


const useFleetbase = () => {
    const { resolveConnectionConfig } = useConfig();
    const v2 = useNavigatorV2Device(NAVIGATOR_V2_SDK_ENABLED);
    const FLEETBASE_KEY = NAVIGATOR_V2_SDK_ENABLED
        ? (v2.status === 'ready' ? v2.credential.credential : null)
        : resolveConnectionConfig('FLEETBASE_KEY');
    const FLEETBASE_HOST = NAVIGATOR_V2_SDK_ENABLED
        ? (v2.status === 'ready' ? v2.credential.host : null)
        : resolveConnectionConfig('FLEETBASE_HOST');

    const [error, setError] = useState<Error | null>(null);
    const [authToken] = useStorage('_driver_token');
    const [fleetbase, setFleetbase] = useState<Fleetbase | null>(
        NAVIGATOR_V2_SDK_ENABLED
            ? null
            : new Fleetbase(authToken ?? FLEETBASE_KEY, { host: FLEETBASE_HOST })
    );

    const hasFleetbaseConfig = useCallback(() => {
        if (NAVIGATOR_V2_SDK_ENABLED) {
            return v2.status === 'ready' && typeof v2.credential.host === 'string';
        }
        const legacyKey = resolveConnectionConfig('FLEETBASE_KEY');
        const legacyHost = resolveConnectionConfig('FLEETBASE_HOST');
        return typeof legacyKey === 'string' && typeof legacyHost === 'string';
    }, [resolveConnectionConfig, v2]);

    useEffect(() => {
        // V2 must never bootstrap with a key read from old MMKV. The
        // scoped credential is loaded asynchronously from OS Keychain.
        if (NAVIGATOR_V2_SDK_ENABLED && v2.status !== 'ready') {
            setFleetbase(null);
            setError(v2.status === 'invalid' ? new Error('Secure Navigator credential unavailable') : null);
            return;
        }

        try {
            const host = NAVIGATOR_V2_SDK_ENABLED
                ? (v2.status === 'ready' ? v2.credential.host : null)
                : resolveConnectionConfig('FLEETBASE_HOST');
            const key = NAVIGATOR_V2_SDK_ENABLED
                ? (v2.status === 'ready' ? v2.credential.credential : null)
                : resolveConnectionConfig('FLEETBASE_KEY');

            if (typeof host !== 'string' || typeof key !== 'string') {
                setFleetbase(null);
                return;
            }
            // A signed-in driver token may override the device key for
            // driver-scoped requests, but not for device provisioning.
            setFleetbase(new Fleetbase(authToken ?? key, { host }));
            setError(null);
        } catch {
            setFleetbase(null);
            setError(new Error('Fleetbase connection unavailable'));
        }
    }, [authToken, resolveConnectionConfig, v2]);

    // Memoize the adapter so that its reference only changes when the fleetbase instance updates.
    const adapter = useMemo(() => {
        if (!fleetbase) return null;
        return fleetbase.getAdapter();
    }, [fleetbase, authToken]);

    // Memoize the returned object to prevent unnecessary re-renders.
    const api = useMemo(
        () => ({
            fleetbase,
            adapter,
            error,
            hasFleetbaseConfig,
        }),
        [fleetbase, adapter, error, authToken, hasFleetbaseConfig]
    );

    return api;
};

export default useFleetbase;
