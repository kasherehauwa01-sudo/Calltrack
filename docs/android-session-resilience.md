# Android session resilience

## Observed defects in source (not a confirmed production incident timeline)

- LoginActivity called logout after any failed validation, including network failures,
  5xx and malformed responses. The logout also revoked the server token.
- AuthInterceptor erased credentials after any endpoint returned 401, including proxy
  responses and responses to requests started before a subsequent login.
- AuthStore deleted encrypted preferences and the Keystore master key after broadly
  classified cryptographic errors, including potentially temporary failures.
- Recovery treated unreadable credentials as no account and cancelled background work.

## Changes

Session verification is read-only. Retryable failures retain credentials and permit
offline use; only the auth endpoint's explicit invalid-session response asks for
sign-in. Neither path calls logout. A delayed successful verification may update only
the token it actually verified. Explicit logout continues to revoke the session.
Credential writes are committed before reporting successful login.

Encrypted storage retries without deleting preferences or keys. A persistent failure
is reported on the login screen with a retry action. Sync throws a retryable I/O
failure instead of sending anonymously when credentials cannot be read. Recovery
retains/retries work during temporary storage unavailability. A 401 sync response
shows an attention notification when Android notification permission is available.

The server's sliding 30-day expiry, PIN/role changes, account disabling and intentional
revocation remain effective. No automatic reauthentication with stored PINs or
bypass of server authentication is introduced. Already erased/revoked credentials
cannot be restored by this patch; affected users must sign in again.

## Validation

GitHub Actions compiles the debug application and runs auth JVM regression tests:
HTTP status/payload handling, timeout then recovery, preservation after 401,
no anonymous request on unreadable storage, non-destructive crypto retry, explicit
logout and stale validation versus a new session.

Real-device checks still required before distributing a signed release:
1. Login, disable networking, relaunch, create activity, then reconnect and verify sync.
2. Upgrade with the same signing key without clearing application data.
3. Reboot, unlock, and verify background sync resumes.
4. Revoke a test account's token intentionally: requests stay rejected; open app and
   sign in again; verify pending records are retained.
5. Simulate transient Keystore unavailability on a test device and verify retry.
   Persistent key loss is not a recoverable network failure.
6. Confirm the notification when sync receives 401, including denied notification permission.

The managed workspace failed to start during preparation. Use the linked Actions
results to distinguish executed automated checks from the device checks above.
