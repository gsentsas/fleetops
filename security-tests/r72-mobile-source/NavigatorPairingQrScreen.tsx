import React, { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { Camera, useCameraDevice, useCodeScanner } from 'react-native-vision-camera';
import { YStack, Text, Button } from 'tamagui';
import { config } from '../utils';
import {
    NavigatorPairingV2Client,
    type PendingNavigatorPairing,
} from '../services/navigator-pairing-v2';

type PairingStep = 'permission' | 'scanning' | 'submitting' | 'pending' | 'approved' | 'error';

/**
 * S98-R72: Navigator V2 in-app QR scanning; no URL navigation.
 * DO NOT send the secret to external QR services, analytics or logging.
 * Do not claim the final credential here until secure storage, full app
 * authentication and native Android integration are acceptance-tested.
 */
export default function NavigatorPairingQrScreen() {
    const navigation = useNavigation();
    const device = useCameraDevice('back');
    const locked = useRef(false);
    const [permission, setPermission] = useState(false);
    const [step, setStep] = useState<PairingStep>('permission');
    const [session, setSession] = useState<PendingNavigatorPairing | null>(null);
    const [notice, setNotice] = useState('');

    useEffect(() => {
        let alive = true;
        (async () => {
            try {
                let status = await Camera.getCameraPermissionStatus();
                if (status !== 'granted') {
                    status = await Camera.requestCameraPermission();
                }
                if (!alive) return;
                setPermission(status === 'granted');
                setStep(status === 'granted' ? 'scanning' : 'error');
                if (status !== 'granted') setNotice('Permission caméra nécessaire.');
            } catch {
                if (!alive) return;
                setStep('error');
                setNotice('Caméra indisponible.');
            }
        })();
        return () => { alive = false; };
    }, []);

    const begin = async (raw: string) => {
        if (locked.current || step !== 'scanning') return;
        locked.current = true;
        setStep('submitting');
        try {
            // Only an opaque 64-hex token is allowed, never an http(s) QR URL.
            if (!/^[a-f0-9]{64}$/.test(raw)) {
                throw new Error('Format QR invalide');
            }
            // Host originates from trusted application config, NOT QR/deep link.
            const host = String(config('FLEETBASE_HOST', ''));
            const client = new NavigatorPairingV2Client(host);
            const pending = await client.beginFromQr(raw);
            if (pending.expiresUnix <= Math.floor(Date.now() / 1000)) {
                throw new Error('Appairage expiré');
            }
            setSession(pending);
            setNotice('Comparez le code ci-dessous avec la console administrateur.');
            setStep('pending');
        } catch {
            // Never expose QR or server response details in UI diagnostics.
            setStep('error');
            setNotice('Code invalide, expiré ou échange sécurisé impossible.');
        } finally {
            locked.current = false;
        }
    };

    const scan = useCodeScanner({
        codeTypes: ['qr'],
        onCodeScanned: (codes) => {
            const value = codes[0]?.value;
            if (typeof value === 'string' && step === 'scanning') {
                void begin(value);
            }
        },
    });

    const checkApproval = async () => {
        if (!session || locked.current) return;
        locked.current = true;
        try {
            const host = String(config('FLEETBASE_HOST', ''));
            const client = new NavigatorPairingV2Client(host);
            const status = await client.status(session.pairingSessionId);
            if (!status.verified) {
                setNotice("La preuve de l'appareil n'est pas encore validée.");
            } else if (!status.approved) {
                setNotice("En attente de l'approbation de l'administrateur.");
            } else {
                // Do not claim a credential without secure storage integration.
                setStep('approved');
                setNotice('Appareil approuvé. Activation finale indisponible dans cette version de test.');
            }
        } catch {
            setStep('error');
            setNotice('Session expirée ou vérification momentanément impossible.');
        } finally {
            locked.current = false;
        }
    };

    const retry = () => {
        locked.current = false;
        setSession(null);
        setNotice('');
        setStep(permission ? 'scanning' : 'permission');
    };

    return (
        <YStack flex={1} bg='$background' padding='$4' gap='$4'>
            <Text color='$textPrimary' fontWeight='bold' fontSize='$6'>
                Appairage Navigator sécurisé
            </Text>
            <Text color='$textPrimary' fontSize='$3'>
                Scannez uniquement le QR affiché dans votre console Fleetbase.
                Aucun lien Internet contenant une clé API n'est accepté.
            </Text>
            {step === 'scanning' && permission && device && (
                <View style={styles.camera} testID='navigator-v2-qr-camera'>
                    <Camera
                        style={StyleSheet.absoluteFill}
                        device={device}
                        isActive={step === 'scanning'}
                        codeScanner={scan}
                    />
                </View>
            )}
            {step === 'scanning' && !device && (
                <Text color='$textPrimary'>Caméra arrière indisponible.</Text>
            )}
            {step === 'submitting' && (
                <Text color='$textPrimary'>Vérification cryptographique en cours…</Text>
            )}
            {(step === 'pending' || step === 'approved') && session && (
                <YStack gap='$3' borderWidth={1} borderColor='$borderColor' borderRadius='$4' padding='$3'>
                    <Text color='$textPrimary'>Entreprise : {session.companyUuid}</Text>
                    <Text color='$textPrimary' fontWeight='bold'>
                        Code à comparer sur les deux écrans :
                    </Text>
                    <Text color='$textPrimary' fontFamily='$mono' fontSize='$6' testID='navigator-v2-device-code'>
                        {session.publicKeyFingerprint.slice(0, 12)}
                    </Text>
                    <Text color='$textPrimary' fontSize='$2'>
                        Ne validez pas si ce code ne correspond pas au téléphone présent.
                    </Text>
                    {step === 'pending' && (
                        <Button onPress={() => { void checkApproval(); }}>
                            Vérifier l'approbation
                        </Button>
                    )}
                </YStack>
            )}
            {notice !== '' && <Text color='$textPrimary'>{notice}</Text>}
            {step === 'error' && <Button onPress={retry}>Réessayer</Button>}
            <Button onPress={() => navigation.goBack()}>Retour</Button>
        </YStack>
    );
}

const styles = StyleSheet.create({
    camera: {
        height: 340,
        width: '100%',
        overflow: 'hidden',
        borderRadius: 14,
    },
});
