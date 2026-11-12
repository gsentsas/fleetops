import { NAVIGATOR_V2_SDK_ENABLED } from './navigator-v2-release-gate';
import { NavigatorPairingV2Client, type PendingNavigatorPairing } from './navigator-pairing-v2';
import {
    loadNavigatorV2Credential,
    saveNavigatorV2Credential,
} from './navigator-v2-secure-credentials';
import { purgeLegacyKeysAfterVerifiedV2Pairing } from './navigator-v2-migration';

export type NavigatorV2Completion = {
    status: 'ready';
    deviceConfirmationCode: string;
};

/**
 * Single-use V2 handoff, purposefully unconnected to the app UI until
 * the Keystore build, full authentication and revoke UX are approved.
 *
 * The server claim is irreversible. A local Keychain failure after claim
 * MUST NOT trigger a second server claim; the administrator must revoke
 * that device credential and generate a fresh pairing if unrecoverable.
 */
export async function claimNavigatorV2ToSecureVault(
    client: NavigatorPairingV2Client,
    session: PendingNavigatorPairing,
    trustedHttpsOrigin: string,
    trustedSocketHostname: string
): Promise<NavigatorV2Completion> {
    if (!NAVIGATOR_V2_SDK_ENABLED) {
        throw new Error('Navigator V2 cannot be activated by the application');
    }
    if (!session || !/^[a-f0-9]{64}$/.test(session.pairingSessionId)
        || !/^[a-f0-9]{64}$/.test(session.publicKeyFingerprint)
        || session.expiresUnix <= Math.floor(Date.now() / 1000)) {
        throw new Error('Navigator pairing session is invalid or expired');
    }

    // Never overwrite an existing valid scoped credential without explicit
    // server-side revocation and a distinct admin-approved rotation.
    const existing = await loadNavigatorV2Credential(trustedHttpsOrigin, trustedSocketHostname);
    if (existing) {
        throw new Error('A secure Navigator pairing already exists');
    }

    // Device signed two challenges, admin explicitly approved.
    // This action consumes the server-side claim exactly once.
    const claimed = await client.claimAfterAdminApproval(session);

    try {
        await saveNavigatorV2Credential(claimed, trustedHttpsOrigin, trustedSocketHostname);
        const verified = await loadNavigatorV2Credential(trustedHttpsOrigin, trustedSocketHostname);
        if (!verified || verified.credential !== claimed.credential) {
            throw new Error('Secure storage read-back failed');
        }
    } catch {
        // Do not clear partially saved data or retry an already consumed claim.
        // Also do not delete old local credential before confirmed read-back.
        throw new Error('Device credential issued but not securely confirmed; administrator revocation required before retry');
    }

    try {
        await purgeLegacyKeysAfterVerifiedV2Pairing(trustedHttpsOrigin, trustedSocketHostname);
    } catch {
        // The new scoped credential is already saved. Do not reclaim again.
        throw new Error('Secure device credential saved; legacy key cleanup still required');
    }

    return { status: 'ready', deviceConfirmationCode: session.publicKeyFingerprint.slice(0, 12) };
}
