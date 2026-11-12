import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import QRCode from 'qrcode';

/**
 * Navigator V2 issuer-only approval surface.
 * No device is approved without a code typed by the administrator
 * after comparing it with the code DISPLAYED ON THAT DEVICE.
 *
 * Backend still enforces: protected route, exact issuer+company,
 * verified proof, expiry, and constant-time fingerprint comparison.
 */
export default class NavigatorPairingApprovalsComponent extends Component {
    @service fetch;
    @service abilities;
    @service notifications;
    @service modalsManager;

    @tracked pending = [];
    @tracked selected = null;
    @tracked confirmation = '';
    @tracked unavailable = false;
    @tracked qrImage = null;
    @tracked qrVisible = false;
    @tracked qrExpiresAt = null;
    qrExpiryTimer = null;

    permission = 'fleet-ops update navigator-settings';

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.clearQr();
    }

    clearQr() {
        if (this.qrExpiryTimer !== null) {
            clearTimeout(this.qrExpiryTimer);
            this.qrExpiryTimer = null;
        }
        this.qrImage = null;
        this.qrVisible = false;
        this.qrExpiresAt = null;
    }

    @action dismissQr() {
        this.clearQr();
    }

    /**
     * Build the QR entirely in this browser tab with the local qrcode
     * library. QR contains just a random 64-hex transfer secret.
     * No external QR service, query string, image URL, analytics or log.
     */
    @task *issueQr() {
        if (!this.canApprove) return;
        this.clearQr();
        try {
            const response = yield this.fetch.post('fleet-ops/navigator/pairings', {});
            const token = response?.pairingSecret;
            const ttl = response?.expiresInSeconds;
            if (typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token)
                || !Number.isInteger(ttl) || ttl < 1 || ttl > 300) {
                throw new Error('Invalid QR response');
            }
            const image = yield QRCode.toDataURL(token, {
                errorCorrectionLevel: 'M',
                margin: 4,
                width: 280,
                color: { dark: '#000000', light: '#ffffff' },
            });
            if (!image.startsWith('data:image/png;base64,')) {
                throw new Error('QR encoding unavailable');
            }
            this.qrImage = image;
            this.qrVisible = true;
            this.qrExpiresAt = new Date(Date.now() + ttl * 1000);
            this.qrExpiryTimer = setTimeout(() => this.clearQr(), ttl * 1000);
        } catch {
            this.clearQr();
            // Generic failure: never print QR bearer or API response.
            this.notifications.warning('Génération de QR indisponible ou désactivée.');
        }
    }

    get canApprove() {
        return this.abilities.can(this.permission);
    }

    get confirmationMatches() {
        return this.canApprove
            && this.selected
            && /^[a-f0-9]{12}$/.test(this.confirmation)
            && this.confirmation === this.selected.deviceConfirmationCode;
    }

    @task *load() {
        if (!this.canApprove) {
            this.pending = [];
            return;
        }
        try {
            const result = yield this.fetch.post('fleet-ops/navigator/pairings/admin-pending', {});
            this.pending = Array.isArray(result?.pendingPairings) ? result.pendingPairings : [];
            this.unavailable = false;
        } catch {
            // V2 is intentionally feature-gated OFF; no detailed exception/logs.
            this.unavailable = true;
            this.pending = [];
        }
    }

    @action refresh() {
        this.load.perform();
    }

    @action select(session) {
        if (!this.canApprove || !session?.verified) return;
        this.selected = session;
        this.confirmation = '';
    }

    @action cancelSelection() {
        this.selected = null;
        this.confirmation = '';
    }

    @action typeConfirmation(event) {
        this.confirmation = String(event?.target?.value || '').trim().toLowerCase();
    }

    @action requestApproval() {
        if (!this.confirmationMatches) return;
        this.modalsManager.confirm({
            title: 'Autoriser cet appareil Navigator ?',
            body: "Confirmez uniquement si le code a été lu directement sur le téléphone que vous souhaitez associer. N'approuvez jamais une demande inconnue.",
            acceptButtonText: "Confirmer l'appairage",
            acceptButtonType: 'primary',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.approve.perform();
                } finally {
                    modal.done();
                }
            },
        });
    }

    @task *approve() {
        if (!this.confirmationMatches || !this.selected) return;
        try {
            const result = yield this.fetch.post('fleet-ops/navigator/pairings/approve', {
                pairingSessionId: this.selected.pairingSessionId,
                deviceConfirmationCode: this.confirmation,
            });
            if (!result?.approved) {
                throw new Error('Approval rejected');
            }
            this.notifications.success('Appareil approuvé. Terminez la connexion sur le téléphone.');
            this.cancelSelection();
            yield this.load.perform();
        } catch {
            this.notifications.warning("Approbation refusée ou session expirée.");
        }
    }
}
