# Pixel Studio

A small image-generation app on top of Pixelforge: users write a prompt, see what it
will cost, and pay Pixelforge's cost plus 25% once the image is ready.

Laravel 12, Vue 3, Inertia 2, Tailwind. Tests: `php artisan test`.

## Running it

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # test@example.com / password
composer dev                        # server, queue worker, logs, Vite
php artisan schedule:work           # polls generations whose webhooks are late
```

`.env` needs `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` (the key's `whsec_…`)
and `ADMIN_EMAILS` (comma-separated, who may open the operator report). Point the
key's webhook URL at `https://<host>/webhooks/pixelforge`.

## How a generation flows

1. **Submit** (`StartGeneration`). The form's price range comes from the same
   calculator that charges, via `/studio/estimate`. On submit the row is saved as
   `pending` and the **maximum** possible price is held from the balance, under a
   row lock on the user so two tabs can't both spend the same credits.
2. **Send** (`SubmitGeneration` job). `POST /v1/generations` with the row's own
   `Idempotency-Key` and `metadata.generation_id`; since the key never changes,
   a retry can't create a second image. Pixelforge's three-at-once limit is a
   queue, so that wait lasts up to 30 minutes. Overloads, rate limits and
   network errors are faults: five tries after `Retry-After` (or exponential
   backoff), then the user is told. A blocked prompt, a validation error or the
   daily quota fail at once. Every failure releases the hold.
3. **Settle** (`ApplyProviderState`). The POST response, every webhook and the
   poller all go through this one action. It locks the row, only ever moves the
   status forward, and books the charge and the release of the hold in the same
   transaction as the status that justifies them.

## Decisions worth knowing

**Money is integer nanodollars.** Pixelforge prices tokens in millionths of a dollar
and reports nine decimals, so a nanodollar is the smallest amount it can bill. Sums
stay exact; multiplication goes through `brick/math`. No floats anywhere, including
the frontend, which only formats.

**The markup rounds up to the next nanodollar.** 25% of an odd number of
nanodollars is a fraction of one. Rounding up costs a user at most $0.000000001 and
never sells below cost. Display rounds to 4 decimals; the exact figure is in the
tooltip.

**Actual cost uses the generation's own `rates`**, which Pixelforge fixes at
creation, not the current price list. The estimate uses the current price list.

**The estimate range rounds outwards.** Text tokens are within ±15% of
`ceil(chars / 4)` (Unicode characters, not bytes): floor the low end, ceil the high
end, so the real charge always falls inside what the user was shown.

**Holding the maximum, not the estimate**, is what makes "block generating when the
user can't afford it" hold under concurrency: a final price can never exceed its
hold, so the balance can't go negative. The balance shown already excludes holds.

**Credits are a ledger** (`credit_transactions`): purchase, hold, release, charge.
The balance is their sum. A unique index on `(generation_id, type)` means a
generation can be charged at most once even if the application code were wrong.

**Webhooks** are verified (HMAC over `t.body`, constant-time compare, 5-minute
replay window), deduplicated by event id, and processed inline, inside one
transaction with the dedupe insert. The work is a few row updates, well within the
10-second limit, and the 2xx goes out only after it is committed, so a failure
means Pixelforge retries rather than an event marked seen but never applied. A
webhook that arrives before the POST response is matched by `metadata.generation_id`.

**Out of order:** statuses are ranked (`pending < queued < processing < final`) and
never move back. A late `processing` after `succeeded` changes nothing.

**Lost webhooks:** `generations:sync` runs every minute and polls anything queued or
processing that hasn't changed for 2 minutes, at most 20 per run to stay inside the
rate limit.

**Giving up.** A failure we conclude without ever hearing from Pixelforge
(retries exhausted) is our own guess. If Pixelforge did take the request after
all (a webhook or poll later says so), its word wins and the user is charged for
the image they got. That is the one path where a balance could dip below zero,
by one image.

**The operator report groups by the UTC day an image completed**, because that is
the day Pixelforge bills it. It reads `GET /v1/usage` for the same days and marks
each day as matching or not, to the nanodollar.

## Reviewing the operator report

No shared password. `php artisan report:share-link --until=2026-12-16` prints a
link signed with the app key: it opens the operator report and nothing else,
read-only, without an account, until the end of that day (UTC). Editing the
link breaks its signature; rotating `APP_KEY` revokes every link at once. The
signature covers path and query, not host, so the same link works on any of
the app's domains. Operators listed in `ADMIN_EMAILS` see the report signed in.

## Not done, on purpose

- Images aren't copied to our own storage, so they stop loading after Pixelforge's
  24-hour link expires (the brief says that's fine).
- The fake "Buy credits" has no limits.
- Pixelforge's 3-concurrent and 500-a-day limits are per account; with many users
  you would queue fairly per user rather than let one user fill the slots.
