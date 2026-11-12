/** Canonical UTF-8 message shared with PHP NavigatorPairingV2Service::message. */
export type NavigatorPairingPhase = 'proof' | 'claim';

export function makeNavigatorPairingMessage(
    phase: NavigatorPairingPhase,
    sessionId: string,
    companyUuid: string,
    publicKeyFingerprint: string,
    challenge: string,
    expiresUnix: number
): string {
    if (phase !== 'proof' && phase !== 'claim') {
        throw new Error('Unsupported Navigator signing phase');
    }
    if (!/^[a-f0-9]{64}$/.test(sessionId)
        || !/^[a-f0-9]{64}$/.test(publicKeyFingerprint)
        || !/^[a-f0-9]{64}$/.test(challenge)
        || !companyUuid || /[\r\n]/.test(companyUuid)
        || !Number.isSafeInteger(expiresUnix) || expiresUnix <= 0
    ) {
        throw new Error('Invalid Navigator signing payload');
    }
    return [
        'fleetbase.navigator.pairing.v2/' + phase,
        sessionId, companyUuid, publicKeyFingerprint, challenge,
        String(expiresUnix),
    ].join('\n');
}
