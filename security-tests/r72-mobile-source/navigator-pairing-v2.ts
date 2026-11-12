import { NativeModules } from 'react-native';
import { makeNavigatorPairingMessage } from './navigator-pairing-v2-message';

type PairingBridge = {
    publicKeyPem(): Promise<string>;
    signPairingMessage(message: string): Promise<string>;
};

export type PendingNavigatorPairing = {
    pairingSessionId: string;
    companyUuid: string;
    publicKeyFingerprint: string;
    challenge: string;
    challengeExpiresUnix: number;
    expiresUnix: number;
};

type PairingStatus = {
    verified: boolean;
    approved: boolean;
    claimChallenge: string | null;
    claimExpiresUnix: number | null;
};

export type NavigatorPairingCredential = {
    credential: string;
    host: string;
    socketcluster_host: string;
    socketcluster_port: string | number;
    socketcluster_secure: boolean;
};

const getBridge = (): PairingBridge => {
    const bridge = NativeModules.NavigatorPairingKeystore as PairingBridge | undefined;
    if (!bridge?.publicKeyPem || !bridge?.signPairingMessage) {
        throw new Error('Navigator pairing requires updated Android application');
    }
    return bridge;
};

/**
 * Isolated V2 client, NOT wired to any screen or build.
 * The trustedBaseUrl must be a pre-approved HTTPS origin from app configuration,
 * NEVER from QR data or a deep link. No secret is ever used in an URL.
 */
export class NavigatorPairingV2Client {
    private readonly apiRoot: string;

    constructor(trustedBaseUrl: string) {
        const base = new URL(trustedBaseUrl);
        if (base.protocol !== 'https:' || base.username || base.password
            || base.search || base.hash || base.pathname !== '/'
        ) {
            throw new Error('Navigator pairing requires a trusted HTTPS origin');
        }
        this.apiRoot = base.origin + '/int/v1/fleet-ops/navigator';
    }

    private async post<T>(route: string, payload: object): Promise<T> {
        const response = await fetch(this.apiRoot + route, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            // Do not print response details, pairing QR secret or URL.
            throw new Error('Navigator pairing request refused');
        }
        return await response.json() as T;
    }

    async beginFromQr(transferSecret: string): Promise<PendingNavigatorPairing> {
        if (!/^[a-f0-9]{64}$/.test(transferSecret)) {
            throw new Error('Invalid Navigator transfer code');
        }
        const bridge = getBridge();
        const publicKeyPem = await bridge.publicKeyPem();
        const session = await this.post<PendingNavigatorPairing>('/pairings/redeem', {
            pairingSecret: transferSecret, publicKeyPem,
        });
        const message = makeNavigatorPairingMessage(
            'proof', session.pairingSessionId, session.companyUuid,
            session.publicKeyFingerprint, session.challenge,
            session.challengeExpiresUnix
        );
        const proof = await bridge.signPairingMessage(message);
        const result = await this.post<{ verified: boolean }>('/pairings/proof', {
            pairingSessionId: session.pairingSessionId, signature: proof,
        });
        if (!result.verified) {
            throw new Error('Navigator pairing device proof rejected');
        }
        // Only a pending session exists. Admin approval must happen separately.
        return session;
    }

    async status(pairingSessionId: string): Promise<PairingStatus> {
        if (!/^[a-f0-9]{64}$/.test(pairingSessionId)) {
            throw new Error('Invalid Navigator pairing session');
        }
        return await this.post<PairingStatus>('/pairings/status', { pairingSessionId });
    }

    /**
     * Call only after UI confirms that the admin has approved the displayed
     * company and device fingerprint. Secure credential persistence must be
     * implemented separately; never store in plain ConfigContext state.
     */
    async claimAfterAdminApproval(session: PendingNavigatorPairing): Promise<NavigatorPairingCredential> {
        const status = await this.status(session.pairingSessionId);
        if (!status.verified || !status.approved
            || !status.claimChallenge || !status.claimExpiresUnix
            || status.claimExpiresUnix <= Math.floor(Date.now() / 1000)
        ) {
            throw new Error('Navigator pairing awaiting administrator confirmation');
        }
        const message = makeNavigatorPairingMessage(
            'claim', session.pairingSessionId, session.companyUuid,
            session.publicKeyFingerprint, status.claimChallenge,
            status.claimExpiresUnix
        );
        const signature = await getBridge().signPairingMessage(message);
        const result = await this.post<NavigatorPairingCredential>('/pairings/claim', {
            pairingSessionId: session.pairingSessionId, signature,
        });
        if (!result.credential || !result.host) {
            throw new Error('Navigator pairing response incomplete');
        }
        return result;
    }
}
