# MAX message phone enrichment

Incoming and outgoing messages are enriched in MaxMessageLocalStore.persist, in the existing
Dispatchers.IO scope, immediately before insertion. The detectors, header/group classification,
notification listener and sync worker are unchanged. No HTTP/client-directory lookup is added.

MaxContactResolver already reads ContactsContract and checks READ_CONTACTS. Its new message path
reuses the existing Contacts.CONTENT_FILTER_URI candidate query and MaxContactNameMatcher's
strict final comparison (trim, collapse whitespace, ignore case). The provider's candidate filter
is not accepted as proof: partial names, extra words or reordered words fail the final comparison.
Blank names are not queried. Contacts without phones still count towards name ambiguity.

Phone rows are read only for exactly matching contact IDs. Multiple different contact IDs are
ambiguous even if they have the same number. Repeated rows for one aggregated Android contact ID
are merged; formatted variants of the same normalized number are deduplicated. One contact with
multiple distinct numbers stays unresolved. A blank NORMALIZED_NUMBER falls back to NUMBER on
the message path; existing MAX call behavior is preserved.

ClientDirectory's existing phone lookup key implementation was extracted to ClientPhoneNumbers
and reused. MAX stores the same 10-digit lookup key: both +7 (999) 123-45-67 and 8 (999) 123-45-67
become 9991234567. It does not store a second, incompatible phone format. The message-specific
input guard accepts Russian 10-digit numbers and 11-digit numbers beginning with 7/8; short,
extension-bearing, malformed and unsupported international numbers stay unresolved instead of
being truncated into an unrelated Russian client key. A valid number plus an invalid number also
remains unresolved. No MAX IDs are generated or copied between contacts.

READ_CONTACTS already exists in the Manifest, AppPermissions, permissions onboarding,
MainActivity's RequestMultiplePermissions flow and PermissionsFragment's RequestPermission flow.
No new permission or prompt was added. Missing permission, null/empty cursor, provider exceptions,
no match and ambiguity leave phone empty and do not prevent saving/synchronizing the event.
Coroutine cancellation is propagated normally. Results are not cached, so later messages recheck
the current address book. Previously stored events are not backfilled or modified.

ClientDirectory can return empty results both for missing clients and for failed/incomplete
loads; its cached name index also does not establish unique client identity. Thus it cannot prove
which one of several contact numbers is the unique eligible number without more infrastructure.
The conservative implementation does not invoke it to guess. Multiple distinct numbers are a
known limitation, not a reason to choose the first/mobile/primary number arbitrarily.

Only the resolved phone for the specific event is written to the existing max_messages.phone.
The existing explicit sync DTO already includes phone and sends it unchanged. The address book
and candidate phone lists are never uploaded or logged. messageText stays empty; incoming
maxUserId/shortcutId and outgoing's empty IDs are unchanged. Fingerprints, sync cutoff, Room v11,
entity/DAO/migrations, server API/schema and UI are unchanged. CallRepository.kt is untouched.

## Device verification

Install the debug APK manually and use the existing permission screen to grant contacts access.
Create one contact with a unique exact MAX display name and one phone. Send/receive one personal
message: new outgoing/incoming server rows should carry the 10-digit phone. Verify incoming real
MAX IDs remain present and outgoing IDs remain empty. With two same-name contacts or two distinct
numbers, phone must be empty. Denying contacts permission must still save/upload both directions.
Group messages must still be excluded and messageText empty. No physical-device test is performed
by the implementation agent; no APK installation or production deployment is performed.

## Validation results

- 55 selected MAX JVM tests passed, including nine new phone-resolution/store tests.
- Full Gradle testDebugUnitTest: 74 tests, 71 passed, three failed. The same failures were
  reproduced using source files extracted from the starting commit 1dbd7e7: two Int-versus-Long
  assertions in MaxCallStateMachineTest (lines 14/33) and TextEncodingTest's
  preservesFormattingAroundRepairedText (line 30). None of these files was changed.
- The first offline test run lacked cached JUnit. After allowing normal Gradle dependency
  resolution, the full tests compiled and executed as above; no dependency/config edits made.
- assembleDebug completed; signed debug APK:
  app/build/outputs/apk/debug/calltrack_v1.0.18_29872539.apk (versionCode 29872539).
- git diff --check passed. No physical-device test, installation or production changes made.
