import { storage } from '../hooks/use-storage';
import { loadNavigatorV2Credential, clearNavigatorV2Credential } from './navigator-v2-secure-credentials';

/**
 * Never import an old unrestricted Fleetbase API key into Navigator V2.
 * Only the server may issue a scoped Navigator credential after the
 * admin-approved device-signature challenge exchange.
 *
 * Old MMKV keys are deleted only AFTER reading back a previously saved,
 * host-validated device credential from OS secure storage. This avoids
 * stranding legitimate legacy installations when secure V2 is unavailable.
 */
export async function purgeLegacyKeysAfterVerifiedV2Pairing(
    trustedOrigin: string,
    trustedSocketHostname: string
): Promise<void> {
    const credential = await loadNavigatorV2Credential(trustedOrigin, trustedSocketHostname);
    if (!credential) {
        throw new Error('No secure Navigator pairing confirmed');
    }

    storage.removeItem('INSTANCE_LINK_FLEETBASE_KEY');
    storage.removeItem('_FLEETBASE_KEY');
}

/**
 * Local unlink: erase the V2 vault entry and legacy API key.
 * Does NOT revoke the credential on the server. Remote revocation
 * must be handled by a separate authorized server-side call.
 */
export async function clearNavigatorLocalPairing(): Promise<void> {
    await clearNavigatorV2Credential();
    storage.removeItem('INSTANCE_LINK_FLEETBASE_KEY');
    storage.removeItem('_FLEETBASE_KEY');
}
