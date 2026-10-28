# Дополнение 26.10.2026: новая policy preliminary-customer-group/2

Это дополнение заменяет правила одноимённых карточек из V3 policy /1.

* При отсутствии authoritative customer_identity/primary_client_id у всех рабочих
  карточек разрешён EXACT_NAME_SINGLE_MANAGER для единственного владельца 3 или 6.
* Полный client_key — normalize_client_name(version=1). Идентификатор группы:
  SHA256(UTF-8("clientsvr-name-group:v1:aviatorov:" + client_key)). Он не зависит
  от порядка, количества карточек или имени менеджера. Это предварительная
  бизнес-группировка по утверждённому правилу, не доказательство юридической личности.
* Прикрепляемая карточка — минимальный строковый ClientsVR ID в лексикографическом
  порядке. Это технический представитель, карточки ClientsVR не объединяются.
* Смена состава требует свежего signed group_hash. Если представитель меняется,
  существующая привязка не переписывается автоматически: PRIMARY_CHANGED_REQUIRES_ADMIN.
  История и закрытые задания остаются, бюджет и inbox не сбрасываются.
* Если authoritative identity/primary есть хотя бы у одной рабочей карточки,
  нужны согласованные значения у всех. Частичные/противоречивые сведения не
  заменяются name fallback. Существующий стабильный внешний ID имеет приоритет.
* Рабочая карточка требует CUSTOMER; TECHNICAL/AGGREGATE и архив исключаются.
  Отсутствие менеджера само по себе не доказывает техническую карточку:
  неизвестная классификация блокирует предварительное назначение.
* Разные менеджеры, неполные данные, неактивная/неподтверждённая manager binding,
  выход за пилот 3/6 блокируют задачу. Ручной VERIFIED не перезаписывается.
* Обе стороны требуют matching policy /2 и свежий подписанный receipt/manifest.
  Старые /1 receipt и manifest не повышаются автоматически. SHADOW не изменяется.
* Group slot, история заданий всех карточек и purchase anchor защищают от дублей;
  действующий глобальный лимит 5/день сохраняется вместе с lifetime budget.

Данные production не запрашивались. Число готовых/заблокированных покупателей
не измерено. Нельзя выдавать синтетический E2E за проверку реальных карточек.
Наличие CUSTOMER/TECHNICAL в действующей проекции ClientsVR ещё нужно подтвердить.

Доверие владельца к FTP — принятое бизнес-решение. Реализован отдельный
source_trust_mode=TRUSTED_IMPORT; по умолчанию остаётся INDEPENDENT. Для нового
режима нужны import_trust_approval_id в утверждённой PG policy и том же Calltrack
policy-файле. Draft снимает approval, approve требует явного решения и пишет аудит.
Неверный/неизвестный режим и отсутствие решения блокируют выпуск.

Подписанный manifest для TRUSTED_IMPORT явно содержит:
source_trust_mode=TRUSTED_IMPORT, import_trust_approval_id, complete=false,
declared_complete_through=as_of. Источник не выдается за независимо доказанную
полноту: method=OWNER_TRUSTED_IMPORT_SIGNED_V1, coverage_status=DECLARED,
business_freshness=DECLARED_CURRENT только в отдельной automation-publication.
Исходный STORED_SALES SHADOW сохраняет UNKNOWN и automation_allowed=false.
Не подписывать trusted-import manifest как INDEPENDENT complete=true.

Обе стороны проверяют подпись и привязку декларации к одобренному режиму.
Сохраняются checksums фактов, source revision, отсутствие активного импорта,
последний закрытый день, сроки свидетельств, подлинные receipts ClientsVR,
клиентская актуальность, manager bindings и все бизнес-лимиты.
Доверие к импорту не даёт права подделывать clients_current/clients_revision.

Publisher app.trusted_import_publisher реализован как отдельная явная команда.
Он требует актуальную одобренную декларацию владельца, сверяет frozen run/revision,
подлинные binding receipts и keyring; не создаёт tasks и не подписывает фиктивную
независимую полноту. По умолчанию dry-run, --write только явно.
Production signer и декларация data-through пока не настроены:
нет защищённого доступа к VPS и фактическому контракту обновления ClientsVR.
Локальный E2E использует только реальные подписи синтетических сервисных ролей;
его ключи/manifest не пригодны для production. Подстановка тестовых ключей запрещена.

Обновлять producer и consumer согласованно при выключенной автоматизации;
миграций для /2 нет. Перед любым включением нужны настоящие service keys,
правила confidence/priority/due/lifetime budget и отдельное разрешение владельца.

---

# PRELIMINARY_MATCH — business policy update, 2026-10-26

This supersedes the previous mandatory per-customer manual VERIFIED condition.
**No production activation performed or authorized by this document.** Existing
server advisory UI/modules, verified bindings, limits, signed-source gates and history
are retained. No new migration is required beyond the already documented prerequisites.

## Assignment and uncertainty

An eligible automatic match is stored in Calltrack as `status=REVIEW`, with
`evidence.stage=PRELIMINARY_MATCH`, a versioned matching policy, actual method/basis,
all related cards and stable group key. System `reviewer_id=0` and audit actor0 mean
no human reviewer; there is no fabricated VERIFIED. PostgreSQL identity status is
`PRELIMINARY_MATCH`; its six manual-review flags remain false. Signed receipts carry
that exact status. Existing VERIFIED/admin decisions are never automatically replaced.
Admin begin/confirm/revoke and manual tasks remain independent and tested.

Rules, using all cards in the exact normalization-v1 name group:

- One active working card, unique resolved owner3/6, explicit customer classification:
  `UNIQUE_EXACT_NAME`, not verified identity. Stable key SHA256(`clientsvr-card:`+cardID).
- Multiple active working cards: same unique owner is necessary but not sufficient.
  Every working card must carry the same authoritative customer identity and explicit
  primary card ID. The primary must be a working member. The task uses that designated
  card, never the first/sorted/phone-matched arbitrary card; all members remain evidence.
  Stable group key SHA256(`clientsvr-customer:`+authoritativeIdentity).
- Different owners: MANAGER_CONFLICT. Different/missing customer identity, missing
  primary, unknown classification, missing owner/card, aggregates and technical groups:
  BLOCKED. Name/phone-only similarities cannot merge several buyers into one customer.
- Technical/no-owner cards are not working candidates. Archived cards cannot be selected.
  Multiple same-owner cards without primary identity metadata remain blocked.

### Required upstream metadata (not present in the old candidate-report contract)

The raw ClientsVR card `fields` projection needs:

| Field | Contract |
| --- | --- |
| `analytics_customer_kind` | `CUSTOMER` only for an actual nontechnical, nonaggregate buyer |
| `analytics_customer_identity` | Shared, namespaced stable primary customer ID for a multicard group |
| `analytics_primary_client_id` | Explicit existing working ClientsVR ID, the same on every working group card |

Classification/identity must come from authoritative source data, independently
reconciled by the upstream manifest publisher. Never populate them by name/phone,
assume all281 matches are customers or generate a group ID from a similar name.
These are an explicit adapter contract, not a claim the current ClientsVR export already
provides them. If unavailable, report CUSTOMER_CLASSIFICATION_UNCONFIRMED /
GROUP_IDENTITY_UNCONFIRMED / PRIMARY_CARD_UNCONFIRMED. No current eligible coverage
can be claimed without real data. The281 advisory report is unchanged and grants nothing.

## Preparation, signatures and gate

`tools/analytics_preliminary.php` is an explicit bounded CLI preparation job, not a
new web write route or installed cron. It reads up to50 inbox rows/page and full local
card groups, fetches the current full-history hash from the authenticated Sales API,
and emits cursor/reasons. Dry-run does not write binding rows/tasks. Apply persists
REVIEW+PRELIMINARY_MATCH bindings/audits only, requires explicit acknowledgement,
never creates tasks, never overrides manual/revoked decisions. Identical semantic
snapshots are idempotent despite irrelevant cache refreshes. A changed primary requires
admin reconciliation; historical sales are never rewritten by fuzzy matching.

Preparation is not automation permission. The existing read-only signed binding export
renews eligible preliminary or verified receipts only when card/history snapshots still
match. Sales imports both states monotonically and emits a separate external_id approved
stream only under the unchanged source/policy/revision/freshness gates. Signed primary
manifest must additionally declare `matching_policy=preliminary-customer-group/1` when
covering preliminary assignments. Both sides recompute matching from signed full card
projections. The event's matching metadata must equal its signed receipt; tampering fails.
Manual flow remains supported without the new preliminary fields or policy declaration.

Legacy `reviewed_count` in the primary protocol counts signed assignable identities,
not human approvals. Diagnostic evidence-request now separately exposes
manually_verified_count/preliminary_count. Every preliminary record/task/receipt keeps
manual_verified=false and no manual-review evidence flags. The independent publisher
must explicitly implement the new matching-policy contract; old manifests cannot
silently authorize this new path.

## Dedupe and task UX

Use a domain-separated clientsvr-group open-task slot for preliminary matches, plus
existing source-signal uniqueness and complete purchase-anchor task history across
related card IDs. Concurrent different signal IDs for the same group create one task;
after DONE the same purchase episode does not reopen. Existing work on another related
card blocks/reviews rather than reassigns. Slots/budget reservations remain transactional.
Stable group identity and explicit primary must be conserved: a changed primary/card
composition/source/history must be reconciled and re-prepared; it cannot reset budgets.

Task evidence contains method, basis, card IDs, group key and preliminary status.
Manager task UI explicitly labels preliminary matching and related cards. It does not
expose other clients' source-manifest bundles. Admin can inspect provenance and correct
bindings; task reassign/close/delete is never automatic.

## Operational sequence (not executed on production)

1. Review source/adapter classification+group+primary contracts. Obtain actual coverage
   and conflict statistics from safe diagnostic preparation, not the advisory count.
2. Strict exact-preimage package checks on old production snapshots, backups/verified
   restore/rollback artifact and isolated candidate tests. Preserve Calltrack dirty code.
3. Install with producer false, consumer disabled, SHADOW UNKNOWN unchanged. No new job
   or feature flag is turned on by this code. PG24 already reported: do not rerun migration24.
4. Separately authorize bounded preparation. CLI defaults to dry-run; apply is a DB write:

```sh
php tools/analytics_preliminary.php --dry-run
# Separate permission: REVIEW bindings/audits only; no tasks or VERIFIED.
php tools/analytics_preliminary.php --apply --confirm-preliminary-policy
# Continue with the exact returned 64hex cursor; no reset of delivery cursors.
php tools/analytics_preliminary.php --dry-run --after=RETURNED_CURSOR
```

5. Genuine independent manifests, signed receipts, explicit source/consumer policy approval,
   confidence/priority/due/lifetime/day limits, pilot owners3/6, monitoring and separate
   permission remain required. No need for per-customer human VERIFIED on eligible
   preliminary groups. A manual/revoked/ambiguous binding remains an admin decision.
6. Dry-run the actual approved feed before separately enabling existing scheduled jobs.
   Unknown source completeness is never converted to true. Stop on conflict/source drift,
   new purchase, wrong assignment, exhausted/corrupt quota, invalid signature or schema.

Rollback: disable/quiesce actual jobs, revoke authorization, retain all tasks/budgets/
bindings/audits. Old verified-only code must stay OFF: it cannot interpret preliminary
receipts. Reverse package restores supplied server preimage, not clean HEAD. Preserve
schema24 and external checked rollback launcher; never downgrade/drop history/reset quotas.
No real-data restore or production startup compatibility is proved by synthetic E2E.

If an authoritative customer identity is present even on a singleton, it determines
the stable group key and an explicit working primary is required. Do not fall back
to a card key on that path. Cross-normalized-name groups/renames must be reconciled
against the entire primary card group by the independent publisher; this importer
does not merge historical names. A missing/out-of-scope primary blocks preparation.
It is not legitimate for a publisher to sign a partial name-group hash as a complete
customer group. Such aliases remain a coverage blocker until a full primary-group
contract/export is supplied and reconciled.
