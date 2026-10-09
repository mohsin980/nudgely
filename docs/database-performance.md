# Database performance and indexing (Task 16D)

Scope: the existing schema and the queries the application runs today. No product features were added.
No production database was accessed. Every number below was measured on a synthetic dataset in a
local PostgreSQL instance, or is marked as an estimate.

## 1. Audit summary

- **Indexing:** the schema already has composite indexes for the tenant-scoped lists (`organization_id` with
  status, timestamps and `last_message_at`), for the scheduler scans (`status, due_at`; `status, resume_at`
  partial), and unique constraints for every idempotency key (webhook events, follow-up keys, automation runs,
  messages by provider ID, reply-route token hashes, public estimate token hashes).
- **Redundancy:** six single-column indexes were covered by a composite that starts with the same column. They
  were removed (section 5).
- **Lists:** every user-facing list is paginated or has an explicit limit. Page sizes are whitelisted.
- **Unbounded reads:** one was found and fixed: the follow-up page loaded every open follow-up in the organization.
- **Scheduler correctness:** the due-follow-up and resume scans used `IN (subquery ... LIMIT ... FOR UPDATE SKIP
  LOCKED)`. Under the planner's nested-loop plan, the batch limit was not enforced (a batch of 2 flipped 5 rows).
  Both now lock a bounded set first and update exactly those rows by primary key.
- **Hourly scans:** the estimate-expiry update had no index for its predicate and held locks for the whole
  update. It now works in locked batches, backed by a partial index.

## 2. Baseline

Dataset (synthetic, ANALYZEd): 100 organizations, 15,000 customers, 15,000 conversations, 45,000 messages,
15,000 estimates, 15,000 follow-ups, 15,000 automation runs, 30,000 webhook events, 100 email connections.

Query counts per page (`tests/Feature/Performance/QueryScalingProfileTest.php`, and
`php artisan` profile run against the same dataset):

| Page | Queries (5 → 30 customers) | SQL time after ANALYZE |
|---|---|---|
| Customers list | 6 → 6 | 13 ms |
| Conversations inbox | 8 → 8 | 11 ms |
| Estimates list | 7 → 7 | 11 ms |
| Follow-ups | 6 → 6 | 13 ms |
| Dashboard | 3 → 3 | 3 ms |
| Automations | 5 → 5 | 8 ms |
| Customer detail | 14 → 14 | 29 ms |
| Conversation detail | 18 → 18 | 23 ms |
| Estimate detail | 13 → 13 | 21 ms |

No page issues a query per row. Counts stay flat as the tenant grows. Total SQL time on these pages is small.
This does not show memory or application CPU, which the next section covers.

Measured limitations: the dataset is synthetic (uniform, one conversation and one estimate per customer); there
is no production traffic, no production size, and no pg_stat_statements history. Single-request times vary by
roughly ±20 ms between runs.

## 3. Measured findings and changes

### 3.1 Follow-up page loaded every open follow-up (fixed)
A tenant with 850 open follow-ups: `/follow-ups` took **1,424 ms** over HTTP with only 23 ms of SQL, and peaked
at 46 MB. The page loaded all open follow-ups to group them into three sections.

After: each section is one index-backed query (`follow_ups_organization_id_status_due_at_index`), counted in SQL
and limited to 100 rows. The same tenant: **185 ms** over HTTP, peak **34 MB**. The page says "Showing the 100
soonest of N" when a section is truncated, and the heading counts stay the true totals.

### 3.2 Due-follow-up and resume scans did not enforce their batch limit (fixed)
`markDue()` and `resumeDue()` selected a batch with `FOR UPDATE SKIP LOCKED` inside an `IN` subquery. In the test
database (no statistics) the planner used a nested loop that re-ran the subquery per outer row: a batch size of 2
flipped **5** rows. Plans on an analyzed database chose a different join and returned 2, which is why the problem
was hard to see.

The fix locks a bounded set of ids (`select … limit N for update skip locked`) and updates exactly those ids by
primary key (`where id = any(?)`), in one transaction. Verified with a regression test (batch of 2 across three
calls: 2, 2, 1, then 0) and a lease test (a waiting run is leased once).

### 3.3 Estimate expiry: unbounded statement, no supporting index (changed; trade-off measured)
The hourly `estimates:expire` ran one `UPDATE … FROM organizations` over every sent estimate in the system, with no
index on `valid_until`.

Changes:
- Partial index `estimates_expiry_due_index (valid_until) WHERE status IN ('sent','viewed')`. The single statement
  uses it (planner output confirmed).
- Batches of 500 (`RELIABILITY_ESTIMATE_EXPIRY_BATCH`), at most 200 batches per run, each locking only its batch.

Measured on 15,000 estimates with 2,500 due, same rows reset between runs:

| Form | Time per run |
|---|---|
| Original single statement | 102 – 121 ms |
| Batched (6 batches, one transaction each) | 148 – 204 ms |

**At this size the batched form is slower.** It trades total time for bounded lock duration and bounded work per
statement. The original's lock time grows with the number of estimates in the table; the batched form's does not.
This was not benchmarked at production size. `afterExpiry()` (an audit event and a queued listener job per
estimate) costs about 4 ms per estimate and is unchanged, because it is business behaviour.

### 3.4 Reply-route token lookup: investigated, no change
`EXPLAIN` with a `text`-cast literal showed a sequential scan. The application binds an untyped parameter, and with
server-side prepares the planner uses `email_reply_routes_token_hash_unique`. The scan was an artifact of my test
cast; no change was needed.

### 3.5 Conversation latest-message query: stale statistics, not an index problem
Before ANALYZE, the inbox's latest-message-per-conversation query took 10.7 ms and used the status index. After
ANALYZE it takes 0.37 ms through `messages_conversation_id_index`. No index was added. Bulk loads must be followed
by ANALYZE before plans are judged.

## 4. Indexes added

| Index | Shape | Why |
|---|---|---|
| `estimates_expiry_due_index` | `estimates (valid_until) WHERE status IN ('sent','viewed')` | Hourly expiry scan. Measured: the expiry statement uses it. |
| `organizations_trial_expiry_index` | `organizations (trial_ends_at) WHERE trial_expired_at IS NULL` | Hourly trial expiry. Tables are small here; added for scale. Not benchmarked. |
| `subscriptions_past_due_grace_index` | `subscriptions (past_due_since) WHERE status = 'past_due' AND restricted_at IS NULL` | Hourly grace check. Same reasoning. Not benchmarked. |

Partial indexes keep these small: they cover only the rows the jobs look at.

## 5. Indexes removed

Each was covered by a composite index whose leading column is the same, so every query the single-column index
served is served by the composite. Removing them saves write work and space.

| Removed | Covered by | Size in the synthetic sample |
|---|---|---|
| `conversations_organization_id_index` | `conversations_organization_id_last_message_at_index` | 168 kB |
| `conversations_customer_id_index` | `conversations_customer_id_last_message_at_index` | 344 kB |
| `customers_organization_id_index` | `customers_organization_id_status_index` (and the unique `(organization_id, email)`) | 312 kB |
| `messages_organization_id_index` | `messages_organization_id_direction_sent_at_index` | 528 kB |
| `messages_status_index` | `messages_status_created_at_index` | 304 kB |
| `users_organization_id_index` | `users_organization_id_status_index` | 16 kB |

The sample sizes are small; the saving grows with the tables. Kept deliberately: the email-connection
`domain`, `sender_email` and `verification_status` indexes (small, and `domain` is used by a lookup).

## 6. Expensive queries reviewed and left alone

- **Customer list:** three correlated subqueries per row (latest conversation status and intent, next follow-up).
  Each is served by an index. At 25 rows per page the cost is about 1 ms; a `LATERAL` join would not change the
  page's query count. Left as is.
- **Inbox list:** the latest-message aggregate and the unread and follow-up counts per row are all index-backed
  (`messages_unread_index`, `follow_ups_conversation_id_status_index`). Page size is 25.
- **Automation lists:** bounded by the plan's automation limit. Not a risk at current limits.
- **Team and connection lists:** bounded by seat and connection limits.
- **Usage counts:** outbound email usage uses `messages_organization_id_direction_sent_at_index`; estimate
  usage uses the estimate indexes. Both are index-backed in the synthetic plans.

## 7. Caching

None added. Nothing measured justified it, and any cache would need invalidation on every write path.

## 8. Tests

`tests/Feature/Performance/`:
- `QueryScalingProfileTest`: list and detail pages do not issue more queries as the tenant grows (5 → 30 customers).
  Set `PROFILE_QUERIES=1` to print the table.
- `DatabasePerformanceTest` (10 tests): estimate expiry in batches of 2 (and a re-run returns 0); expiry leaves
  estimates not yet past their date; follow-up discovery queues exactly the batch size, then the rest; the follow-up
  page counts sections in SQL, lists at most 100, and only shows the organization's own follow-ups; page sizes are
  clamped; the expected indexes exist with their predicates; the removed indexes are gone and the kept ones remain;
  a waiting run is leased once.

Tenant scoping and the other reliability guarantees (webhook deduplication, idempotency, usage counts) are covered
by the existing suites, which run unchanged.

## 9. Deployment

- The new migration (`2026_10_28_000000_database_performance_indexes`) creates three indexes with plain
  `CREATE INDEX`, which takes a SHARE lock (blocks writes, allows reads) while it builds. Laravel runs migrations
  inside a transaction, where `CREATE INDEX CONCURRENTLY` is not allowed. On a large production table, build the
  three indexes beforehand, outside the migration, with:

  ```sql
  CREATE INDEX CONCURRENTLY estimates_expiry_due_index ON estimates (valid_until) WHERE status IN ('sent', 'viewed');
  CREATE INDEX CONCURRENTLY organizations_trial_expiry_index ON organizations (trial_ends_at) WHERE trial_expired_at IS NULL;
  CREATE INDEX CONCURRENTLY subscriptions_past_due_grace_index ON subscriptions (past_due_since) WHERE status = 'past_due' AND restricted_at IS NULL;
  ```
  If they already exist, the migration's `CREATE INDEX` fails; drop and recreate, or run the migration as-is on
  a small table. Verify each with `\d` after building.
- The removed indexes are dropped with a brief lock per table. The index drops are quick.
- After deploying, run `ANALYZE` on `estimates`, `follow_ups`, `messages`, `conversations`, `customers` if the
  tables had a large change in size since the last statistics run.
- Batch-size settings: `follow_ups.batch_size` (500), `reliability.estimates.expiry_batch_size` (500). Lower them
  if expiry or due-follow-up runs hold locks for too long at production volume.
- No production migration was run in this task.

## 10. Remaining risks

- **Production volume is unmeasured.** Every number above is from a synthetic dataset. The estimate-expiry batch
  is slower at 15k rows and was not measured at production size; its lock behaviour is the reason it exists.
- **Trial and grace scans** are sequential on tiny tables. Their partial indexes are for scale and are unmeasured.
- **Statistics:** plans depend on ANALYZE. A bulk load or a large deletion can make the planner choose badly until
  autovacuum catches up. Monitor for this.
- **`afterExpiry` writes** (two inserts per expired estimate) are proportional to the expiry count. Batching them
  would change business side effects and was left alone.
- **Application CPU** on large lists was only observed on the follow-up page. Other pages were measured for query
  count and SQL time, not for PHP time at large sizes.
- **No query-count budget in CI** other than the scaling test, which checks growth, not absolute counts.
