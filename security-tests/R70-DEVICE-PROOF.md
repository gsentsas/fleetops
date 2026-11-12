# GST Fleetbase S98-R70 - Device proof design and offline verification

Mobile source on Mac:
/Users/gsent/Documents/ChatGPT/FLEETBASE/s92-navigator
Local SHA 0479fdb061955448832c1a8a523d96381f2c8f8b,
with two security log-removal commits over upstream
fleetbase/navigator-app 0da77ce050ad4621efc0ede115c6e2692cda2c8e.

Isolated mobile clone in
/Users/gsent/Documents/ChatGPT/FLEETBASE/s98-r70-navigator-v2-lab
branch feature/s98-r70-navigator-v2-local, contract in
R70-NAVIGATOR-V2-MOBILE-CONTRACT.md.

Device Android 16 model 25057RN09E,
package io.fleetbase.navigator.qa version rc1-qa installed.
No APK changes.

Source findings: InstanceLinkHandler.tsx stores a key from
configure?key=; legacy/App.js also accepts deep-link key.
InstanceLinkScreen.tsx accepts a manually entered API key.
react-native-keychain and vision-camera declared but no verified native
Android Keystore signing bridge or QR POST V2 handshake.

Proposed: Android Keystore non-exportable P-256 key, server challenge
bound to session/company/device/expiry, ECDSA SHA-256 signature, plus
explicit admin confirmation BEFORE any scoped credential is delivered.
Body-only HTTPS credential exchange. Signature alone proves possession
of a private key, not authorization of the device/user.

Offline PHP OpenSSL test r70-device-proof.php, purely synthetic
software keys: 7/7 PASS. Valid signature, wrong tenant rejected,
tampered challenge rejected, wrong key rejected, first use accepted,
replay refused, expired challenge rejected.
This is NOT Android hardware or real backend integration.

Critical gap: current API V2 draft returns scoped credential to anyone
redeeming a QR bearer when flag is enabled. Redesign to pending approval
and challenge proof before enabling. Flag OFF by default. NO-GO.
