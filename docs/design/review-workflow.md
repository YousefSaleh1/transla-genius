# Review / Approval Workflow — Design

Status: **decided** (see [Decisions](#decisions)); implemented in Phase 7. Where the body and the Decisions section ever disagree, the Decisions win.

## Goal

AI translations can land in a *pending* state and only go live in the model's translatable column after a human approves them. The package provides primitives (storage, lifecycle, events, a service API, a couple of Artisan commands); the review UI and the authorization rules belong to the consuming app.

Constraints carried over from the existing package:

- Zero-code first: switching it on is a `.env` change, and choosing what's reviewed is config only.
- Portable across MySQL, MariaDB, PostgreSQL, SQLite and SQL Server: plain columns, no partial indexes, no JSON-path queries in the new table.
- Concurrency rules already in place stay authoritative: `TranslationWriter`'s row lock, and the source-snapshot rule from the stale-translation fix (a write only lands if the stored source still equals the source the translation was made from).
- Usage tracking stays exact: cost is incurred when a translation is produced, whatever happens to it in review.
- With review off, nothing changes. See §8.

---

## 1. Activation

**Global switch**: `TRANSLATION_REVIEW=false` by default (`translaGenius.review.enabled`).

**What is reviewed** (config only, in a published `config/translaGenius.php`):

```php
'review' => [
    'enabled' => env('TRANSLATION_REVIEW', false),

    // Which model/field pairs go through review when enabled. With both
    // `models` and `fields` empty, every translatable field of every model is
    // reviewed (Decision 1).
    'models' => [
        \App\Models\Product::class => ['description', 'marketing_copy'],
        \App\Models\Page::class => ['*'],               // all translatable fields
    ],

    // Field names reviewed on every model (in addition to `models`).
    'fields' => [],

    // Only these target locales are reviewed; others publish directly (Decision 7).
    'locales' => [],                                     // empty = all

    // What stays visible while a re-translation is pending (Decision 2).
    'while_pending' => env('TRANSLATION_REVIEW_WHILE_PENDING', 'keep'),  // 'keep' | 'clear'
    'while_pending_overrides' => [
        // \App\Models\Product::class => 'clear',                       // whole model
        // \App\Models\Product::class => ['price_note' => 'clear'],      // per field
    ],

    // After a rejection (Decision 4).
    'on_reject' => env('TRANSLATION_REVIEW_ON_REJECT', 'leave'),        // 'leave' | 'retranslate'
],
```

Per-field selection is the useful granularity: a product's `name` is low-risk and high-volume, while its `description` or legal text is what people want to read before it goes live. A per-locale filter is cheap to add and covers "we only have Arabic reviewers".

**Model-level property** (`protected array $translationReview = ['description'];` on the model): **not in v1** (Decision 6); configuration is config/env only. The reasoning that led there:

- For: the setting sits next to `$translatable`, and it moves with the model across apps that share it.
- Against: it's a code change, which is against the zero-code goal; two sources of truth need a precedence rule (config should win, so ops can switch review off without a deploy); and it's one more thing to test.
- Recommendation: config only in v1. If added later, config overrides the property.

## 2. Storage

### Recommended: a dedicated table

`translation_reviews`, as a publishable migration stub (separate tag, so apps that never enable review never create it):

| Column | Type | Notes |
|---|---|---|
| `id` | big integer | |
| `translatable_type` | string | Owner morph type (class or morph alias). |
| `translatable_id` | string(64) | String, so integer, UUID and ULID keys all fit. |
| `field` | string | Translatable attribute. |
| `locale` | string(16) | Target locale. |
| `text` | text | The AI translation as produced. |
| `approved_text` | text, nullable | What was published if a reviewer edited it (null = published as produced). |
| `status` | string(20) | `pending`, `approved`, `rejected`, `superseded`. |
| `source_locale` | string(16) | |
| `source_text` | text | Snapshot of the source the translation was made from (shown to reviewers; avoids loading the model). |
| `source_hash` | string(64) | sha256 of `source_text`, for cheap equality checks under the lock. |
| `provider` | string, nullable | Config key that produced it. |
| `request_id` | string(64), nullable | Links to `translation_usage_logs.request_id`. |
| `cached` | boolean | The text came from the translation cache. |
| `reviewer_type` / `reviewer_id` | string, nullable | Nullable morph to whoever decided. |
| `reviewed_at` | timestamp, nullable | |
| `note` | text, nullable | Optional reviewer note or rejection reason. |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(translatable_type, translatable_id, field, locale)`, `(status)`, `(status, created_at)`. "At most one pending row per owner/field/locale" is enforced in code under the owner's row lock, not by a partial unique index (not portable, notably to MySQL/MariaDB).

Rows are kept after a decision, as an audit trail: who approved what, and what the AI originally produced versus what was published. Pruning old decided rows is left to the app (or a later `--prune` option).

### Alternative: a parallel JSON structure on the user's table

For example a `translations_pending` JSON column next to each translatable column, or one JSON column holding `{field: {locale: {...}}}`.

| | Dedicated table | Parallel JSON on user tables |
|---|---|---|
| App migration work | One package migration | A migration on **every** reviewed table |
| "List everything pending" across models | One indexed query | A query per table; JSON-path filtering differs per driver |
| History / audit trail | Natural (rows persist) | Would need its own structure |
| Reviewer, status, timestamps per pair | Plain columns | Nested JSON, hard to index |
| Lock / concurrency | Owner row lock + own rows | Same row as the published value (simpler locking) |
| Portability | Plain columns everywhere | JSON functions vary; SQLite needs JSON1 |

**Recommendation: the dedicated table.** The JSON approach's one advantage (same-row locking) is small, because approval already takes the owner's row lock through `TranslationWriter`. Everything a review tool needs (cross-model queues, filters, history) is much easier with a table.

## 3. Lifecycle

```
             ┌──────────────► approved           (published as produced)
             │
  pending ───┼──────────────► approved + edit    (published approved_text)
             │
             ├──────────────► rejected           (nothing published)
             │
             └──────────────► superseded         (source changed / newer pending)
```

`approved-with-edit` is `status = approved` with a non-null `approved_text`. One status value keeps queries simple ("everything approved"), and the column tells edited from unedited.

**Producing a pending translation.** Where the job would call `TranslationWriter::apply()` for a reviewed field/locale, it instead writes a `pending` row, under the owner's row lock, with the same source-snapshot check as today: if the stored source no longer equals the snapshot, the result is discarded (and logged as `discarded`, as now). Any existing `pending` row for the same owner/field/locale becomes `superseded` in the same transaction. Locales the user provided in the same save (the stale-translation fix's `keep` list) are human input and are never routed to review.

**Approval** writes through `TranslationWriter` with the snapshot rule:

1. Lock the owner row.
2. If the stored source text's hash ≠ `source_hash`, the translation is stale. Mark the row `superseded` and refuse with a clear exception (`StaleReviewException`), so the UI can tell the reviewer the source changed.
3. Otherwise write `approved_text ?? text` to the field/locale, **overwriting** what's there (this is the "publish" step), set `approved`, the reviewer and `reviewed_at`.

**Rejection** sets `rejected` (reviewer, note) and publishes nothing. The locale keeps whatever it had: missing for a new record, or the old published text after a source change (see §4). Re-requesting is **not** automatic by default, since the same setup would likely produce the same text (and with the cache on, would *exactly* reproduce it). The API offers an explicit `retranslate()` that evicts the cache entry and dispatches a fresh job for that pair; `review.on_reject = 'retranslate'` makes that automatic. The default is `leave` (Decision 4).

**Source changes while pending.** The update hook already plans a re-translation for the changed field. In addition, every `pending` row for that field is marked `superseded` (its `source_hash` no longer matches), and the re-translation produces a new `pending` row. Approving a superseded row is refused, as in step 2 above.

**Model deleted.** Pending rows for a deleted owner are left in place but filtered out of `pending()` by default; a `translagenius:review:prune` (or the app's own `deleting` listener) can clean them up. A soft-deleted owner keeps its rows.

## 4. What's visible while a translation is pending

New records are simple: there's no published text, so the locale falls back (Spatie's fallback locale, or empty) until approval.

**The open question is the source-changed case**: a record has a published, approved translation of the *old* source, the source changes, and a new translation is pending.

| Option | Shows | Pros | Cons |
|---|---|---|---|
| **A. Keep the stale published text** until the new one is approved | Old translation of old source | Nothing disappears; no visible regression for users of that locale | The page can be factually wrong (old price, old spec, old legal wording) for as long as review takes |
| **B. Clear the locale** when the source changes, so it falls back until approval | Fallback locale (usually the source language) | Never shows a translation that contradicts the source | Users of that locale suddenly see another language; a slow review queue means long gaps |
| **C. Per model/field config** (`review.while_pending = 'keep' | 'clear'`) | Either | Fits both marketing copy (keep) and legal/pricing text (clear) | One more setting to understand; needs both paths tested |

**Decided: option C, with A as the default** (Decision 2): `review.while_pending` (`keep` by default, or `clear`), overridable per model or per field with `review.while_pending_overrides`. With `clear`, the stale published text is removed when the pending translation is produced, so the fallback locale shows until approval. A useful data point: with review **off**, the current behavior (since the stale-translation fix) is effectively A for the duration of the job (seconds), so A's downside only becomes real with a human in the loop.

## 5. Interaction with existing features

**Scopes.**
- `fullyTranslated` / `notFullyTranslated` keep looking only at the published column, so they mean "what users can see". A pending pair is *not* translated yet.
- Add `pendingReview()`: models with at least one `pending` row (a `whereExists` on `translation_reviews`, portable). Optionally `pendingReview('description', 'ar')`.

**Backfill.** `translagenius:backfill` selects `notFullyTranslated` records, so it would see pairs awaiting review as missing and pay for them again. With review on, the job (and backfill's pre-count) must skip any field/locale that already has a `pending` row. Backfill output reports skipped-pending counts. Rejected pairs are *not* skipped: a rejected pair is missing, and backfilling it is a deliberate re-request (Decision 5). A job dispatched by a model save, by contrast, does skip a pair rejected for the current source text, so a rejection really does leave the locale missing until an explicit `retranslate()` or backfill (Decision 4).

**Cache.**
- The cache keeps storing **machine output at translation time**, exactly as today. Review doesn't change what the model produced.
- **On reject**: evict that entry. Otherwise the next record with identical source text gets the rejected text served from cache, at zero cost, straight into the review queue again. (Recommended, low risk.)
- **On approve-with-edit**: **replace the cache entry with the edited text, by default** (Decision 3), with a per-approval opt-out (`cacheEdit: false`). Reasons: the human-corrected version is what you want for the next identical text, and without it every repeat of a boilerplate sentence needs the same correction again. Risk: an edit that only makes sense in one record's context (it mentions that product) would spread. Mitigations: only identical source text shares the entry anyway, and the API's `approve()` takes a `cacheEdit: false` flag for record-specific edits.
- On approve-as-is: no change (the entry already holds that text).

**Batching.** No change to requests. A batch's valid locales each become a `pending` row, all sharing its `request_id`. The fallback and truncation rules are unchanged.

**Usage tracking.** No change. Tokens and cost are recorded when the translation is produced (`outcome = translated`, or the billed-but-unused outcomes), regardless of approval. A review status is *not* a usage outcome. To report "spend on rejected translations", join `translation_usage_logs.request_id` to `translation_reviews.request_id`.

**Events.**
- `TranslationCompleted` keeps firing when a translation is *produced*, review or not. It's what usage tracking and existing listeners rely on, and the cost is real at that point. It gains a `pendingReview` boolean so listeners can tell "produced and published" from "produced, awaiting review".
- New:
  - `TranslationPendingReview`: a pending row was created (carries the review model).
  - `TranslationApproved`: published; carries the review, reviewer, and whether it was edited.
  - `TranslationRejected`: carries the review, reviewer and note.
  - `TranslationSuperseded`: a pending row was made obsolete by a source change or a newer pending row.
- Ordering for a reviewed pair: `TranslationStarted` → `TranslationCompleted` (`pendingReview = true`) → `TranslationPendingReview` → later `TranslationApproved` / `Rejected` / `Superseded`.

## 6. Primitives API

A `Models\TranslationReview` Eloquent model (public API, like `TranslationUsageLog`) plus a `Services\ReviewManager`, reachable via the facade (`TranslaGenius::reviews()`) or the container:

```php
$reviews = TranslaGenius::reviews();

// Query: returns a TranslationReview query builder, so apps paginate/filter freely.
$reviews->pending()
    ->forModel(Product::class)          // or ->for($product)
    ->field('description')
    ->locale('ar')
    ->provider('openai')
    ->olderThan(now()->subDay())
    ->paginate(50);

$reviews->approve($review, editedText: null, reviewer: $user, note: null, cacheEdit: true);
$reviews->reject($review, reviewer: $user, note: 'Wrong tone');
$reviews->retranslate($review);         // evict cache entry + dispatch a fresh job for that pair

// Bulk: everything pending for a model instance, a model class, optionally narrowed.
$reviews->approveAll($product, field: 'description', locale: null, reviewer: $user);
$reviews->approveAll(Product::class, field: 'name', reviewer: $user);
```

Each decision method locks, applies the §3 rules, and returns the updated `TranslationReview`; a stale approval throws `StaleReviewException`. `approveAll` approves row by row, so stale rows are skipped and reported rather than failing the batch, and returns a summary (`approved`, `skipped_stale`).

**Authorization stays in the app.** The package never checks permissions. To make that easy:
- Every decision takes the acting `reviewer` explicitly (a nullable morph), so the audit trail records whoever the app says acted.
- `TranslationReview` is a normal model, so apps register a policy (`Gate::policy(TranslationReview::class, ...)`) and call `$this->authorize('approve', $review)` in their controller before calling the API.
- Reviews expose `translatable` (the owner morph) so policies can check ownership ("can this user edit this product?").

**Artisan.**
- `translagenius:review:list {model?} {--field=} {--locale=}`: counts per model/field/locale (for ops and cron alerts).
- `translagenius:review:approve-all {model} {--field=} {--locale=} {--force}`: bulk approval with the same confirmation guard as backfill (for migrating a backlog, or switching review off cleanly).
- `translagenius:review:prune {--days=}`: delete decided (non-pending) rows older than N days.

## 7. Auto-approval options

| Option | What it does | Risk | Recommendation |
|---|---|---|---|
| User-provided target locales | Locales set in the same save as the source (the `keep` list) bypass review | None: they're human input, not AI output | **Always on** (not a setting) |
| Approve cache hits of *reviewed* entries | If the cache entry was human-approved (§5, `reviewed: true`), publish directly | An approval in one record's context reused in another, though only for identical source text | **Offer, off by default** (`review.auto_approve_reviewed_cache`) |
| Approve any cache hit | Cache hit → publish | Unreviewed machine text published, which defeats review | No |
| Approve after N days | Pending older than N days publishes itself | Unreviewed text goes live silently; becomes the default path if the queue is neglected | No (apps can do it with `approveAll` from their own scheduler if they want it) |
| Per-provider / per-locale trust | e.g. auto-approve `name` from provider X | Encourages fragile config; `review.models` / `review.locales` already express "don't review this" | No; use the scope settings |

## 8. Migration impact with review off

**Target: none, and achievable.**
- No new required table: the `translation_reviews` migration is a separate publish tag, and the code never touches it when `review.enabled` is false.
- No change to job behavior, events, scopes, backfill, cache or usage when off. `TranslationCompleted` gains `pendingReview` with a default of `false` (additive; constructor arguments stay compatible by adding it last, with a default).
- No new required config keys: `review` defaults to disabled.
- Additive public API only: new model, service, events and commands.

Breaking-change surface if someone *enables* it later: published text for reviewed fields stops updating until approval. That's the point, and it's documented.

## 9. Test plan outline

- **Activation:** off → behavior identical to today (rerun the existing suite with review config absent and with `enabled=false`). On with `models` selection → only selected fields/locales create pending rows; others publish.
- **Producing:** a pending row is created instead of a write; events in order; `TranslationCompleted.pendingReview` is true; usage rows unchanged; a batch creates N pending rows sharing a `request_id`; user-provided `keep` locales publish directly.
- **Approve:** writes `text`; approve-with-edit writes `approved_text`; overwrites a stale published value; refuses (`StaleReviewException`) and supersedes when the source changed; reviewer and `reviewed_at` recorded.
- **Reject:** nothing published; cache entry evicted; `retranslate()` evicts and dispatches exactly one job for that pair; `on_reject = retranslate` does it automatically.
- **Supersede:** source change supersedes pending rows and creates new ones; a newer pending row supersedes an older one for the same pair; approving a superseded row is refused.
- **Visibility (§4):** whichever option is chosen, for new records and for source changes.
- **Scopes:** `fullyTranslated` ignores pending; `pendingReview()` finds the right records on all five databases.
- **Backfill:** skips pending pairs (0 requests), re-requests rejected pairs, reports counts.
- **Cache:** reject evicts; approve-with-edit replaces (or not, with `cacheEdit: false`); `auto_approve_reviewed_cache` publishes a reviewed hit and nothing else.
- **Concurrency:** two approvals of the same row (one wins); approve racing a source change (refused); two jobs producing pending rows for the same pair (one pending survives).
- **Bulk/commands:** `approveAll` skips stale rows and reports them; `review:list` counts; `approve-all` needs `--force` or confirmation; `prune` deletes only decided rows.
- **Portability:** string `translatable_id` with integer and UUID owners; morph aliases; full suite on SQLite, MySQL, MariaDB, PostgreSQL, SQL Server.

## 10. Open decisions for Yousef (all resolved; see [Decisions](#decisions))

1. **What does `TRANSLATION_REVIEW=true` cover when `review.models` is empty?**
   Options: (a) everything translatable; (b) nothing until models are listed.
   *Recommendation: (a).* It's the zero-code expectation ("turn on review"); narrowing is config.

2. **Visibility while a re-translation is pending (§4).**
   Options: (A) keep the stale published text; (B) clear it to the fallback locale; (C) configurable per model/field, default A.
   *Recommendation: (C) with default A.* Most content is better slightly stale than suddenly in another language, but legal/pricing fields need B. **Your call.**

3. **Should approve-with-edit update the cache entry?**
   Options: (a) yes, by default, with a per-call opt-out; (b) no, by default, with an opt-in; (c) never.
   *Recommendation: (a)*, plus always evicting on reject. Repeated boilerplate then only needs correcting once.

4. **Rejection follow-up.**
   Options: (a) leave the pair missing, with an explicit `retranslate()`; (b) automatically re-request once; (c) configurable, default (a).
   *Recommendation: (c), default (a).* An automatic re-request with the same setup usually returns the same text.

5. **Backfill with review on.**
   Options: (a) skip pairs with a pending row, re-request rejected ones; (b) skip both; (c) treat pending as missing (pays again).
   *Recommendation: (a).*

6. **Model-level `$translationReview` property.**
   Options: (a) not in v1; (b) in v1, with config overriding it.
   *Recommendation: (a)*, revisit if apps ask.

7. **Per-locale review filter (`review.locales`).**
   Options: (a) include in v1; (b) later.
   *Recommendation: (a).* It's small, and "we only review Arabic" is a common first setup.

8. **Retention of decided review rows.**
   Options: (a) keep forever, with a `prune` command; (b) auto-prune after N days.
   *Recommendation: (a).* It's an audit trail, so let the app choose.

9. **`TranslationCompleted` semantics under review.**
   Options: (a) keep firing when produced, with `pendingReview` added; (b) fire only on publish (approval), with a new `TranslationProduced` event for the production step.
   *Recommendation: (a).* Usage tracking and existing listeners keep working; (b) would silently change what existing listeners see.

---

## Decisions

Recorded after review; these are final, and the body above has been updated to match.

1. **Activation scope** (resolves Open decision 1). `TRANSLATION_REVIEW=true` with no models (and no fields) listed covers every translatable field.
2. **Visibility while pending** (resolves Open decision 2). Configurable per model and per field. The default keeps the previously published, now stale, text visible until approval (`keep`); the alternative, `clear`, removes it so the fallback locale shows.
3. **Cache** (resolves Open decision 3). Approval with a reviewer edit updates the cache entry by default, with a per-approval opt-out. Rejection always removes that cache entry.
4. **Rejection** (resolves Open decision 4). Configurable (`review.on_reject`). The default, `leave`, leaves the locale missing until an explicit `retranslate()`.
5. **Backfill with review on** (resolves Open decision 5). Skip pairs that are pending; re-request rejected ones.
6. **Model property** (resolves Open decision 6). No model-level property in v1; configuration is config/env only.
7. **Per-locale filter** (resolves Open decision 7). `review.locales` is in v1.
8. **Retention** (resolves Open decision 8). Decided review rows are kept, with a `prune` Artisan command; there is no automatic deletion.
9. **Events** (resolves Open decision 9). `TranslationCompleted` keeps firing when a translation is produced, with a `pendingReview` flag; new events cover approval and rejection.

