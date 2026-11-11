# Navigator pairing candidate (S92)

This is an unmerged security candidate. Mobile, complete business QA, FCM and
production acceptance remain required. Do not deploy from this document.

## Identity and permissions

An active administrator issues a 30-minute opaque capability for their company.
Only its SHA-256 fingerprint is stored. Redemption locks the token and issuer on
MySQL and creates a distinct `navigator-device` principal and API credential.
The principal has no password, console session, IAM membership or administrator
role. Credential purpose and issuer are server-owned attributes, not assignable
through ordinary API credential payloads. Core and FleetOps patches must be
installed together, including the scope and link-token migrations.

The bootstrap key allows only driver login, SMS login, login-code verification,
and the matching company's onboarding settings. It cannot create accounts,
read orders, dispatch, administer IAM or use other extensions. After a driver
logs in, their Sanctum token is linked to that pairing. Each request checks the
driver's tenant/status, pairing/device/issuer status, expiry and revocation.
The request identity is explicitly replaced even if an administrator cookie is
also present. Requests through other API controllers fail closed.

Paired sessions currently allow only own-driver lookup/location/online/device/
current-organization/password operations and assigned-order lists, lookup,
start, activity update, completion, next activity and comments. Lists bypass
shared query caching and always filter by assigned driver. Other operations,
including uploads, proofs, manifests, organization switching, general driver
editing and self-registration, are deliberately unauthorized pending their
resource-ownership review and real client acceptance. This restriction is an
open business acceptance blocker, not a claim of full mobile compatibility.

## Lifetime, revocation and renewal

The key expires seven days after pairing. Descendant driver tokens inherit that
deadline. Disabling the issuing administrator or device, changing their tenant,
revoking/deleting the key, or expiring either credential immediately denies the
next authenticated request. No physical device attestation is claimed: these
remain bearer credentials representing one pairing.

Company administrators can DELETE
`/int/v1/fleet-ops/navigator/pairings/{credential-uuid}` or POST the same path
with `/rotate`. Rotation revokes the selected key and its driver sessions and
returns a fresh single-use link; the operator must pair the app again and the
driver must log in again. Other pairings remain usable. No silent automatic
renewal is implemented or promised by the reviewed official client.

## Transactions and auditing

Credential creation, device creation and token consumption share a transaction.
A database/observer failure rolls back all of them and leaves an unexpired link
retryable. A committed successful redemption cannot be replayed. Concurrent
redemptions serialize on the token row. Retries never reuse an old API key.

The API records identifiers, action, outcome and expiry, never headers, query
strings, passwords, keys, raw response bodies or driver tokens for paired
traffic. Credential activity records use an explicit safe field list. Issuance
and revocation audit events are emitted after commit. The mobile source patch
must also be built and distributed: server changes cannot remove logging in an
already installed application. Reverse-proxy access logs must redact pairing
query strings; this requires a separate configuration review before exposure.

## Rollback

Never remove the `purpose` discriminator while scoped keys remain, or a scoped
key could become an unrestricted legacy key. The scope migration refuses that
rollback. Revoke and remove scoped keys and descendant tokens first, then test
the backward migration on a copy. A production rollback needs a current backup
and an explicit decision on newly created data. No production rollback has been
executed as part of S92.
