# PromptEdit

AI image generation platform built with **Laravel 12 + Vue 3 + Inertia**.

PromptEdit uses [Pixelforge](https://pixelforge.promptedit.dev/docs) for image generation. Users are charged the Pixelforge provider cost plus a **25% markup**.

## How It Works

1. The user submits an image-generation prompt.
2. The application validates the request against Pixelforge's live pricing table, including supported sizes and qualities.
3. The generation is stored with a worst-case cost estimate.
4. A `SubmitGeneration` job is queued.
5. `SubmitGeneration` sends `POST /v1/generations` to Pixelforge using the generation UUID as both the `Idempotency-Key` and metadata identifier.
6. Temporary errors such as `429`, `503`, concurrency limits, and timeouts are retried with backoff. The retry delay respects the `Retry-After` header when provided. A maximum of 5 attempts is allowed.
7. Permanent rejections such as content-policy violations, validation errors, and daily quota errors fail the generation with a provider cost of `$0`.
8. Pixelforge sends status changes through webhooks. PromptEdit does not poll Pixelforge.
9. The scheduled `generations:resubmit-stuck` command re-queues submissions that Pixelforge did not accept within 2 minutes.
10. Both the create response and webhook events are processed through `GenerationStatusService::apply()`.

### Generation Status Rules

`GenerationStatusService::apply()`:

- Locks the generation row before updating it.
- Never moves a generation to an earlier status.
- Never modifies a finished generation.
- Charges the user only when the generation transitions to `succeeded`.
- Handles duplicate webhook deliveries safely.

### Image Storage

After a successful generation, the image is copied to `storage/app/public/generations`.

This is required because Pixelforge image URLs expire after **24 hours**.

---

# Pricing

`PixelforgePricingService` performs all monetary calculations using **BCMath decimal strings** with 12 decimal places.

No floating-point calculations or rounding are used.

### Cost Estimate

The estimate is calculated from Pixelforge's `GET /v1/pricing` response.

The calculation includes:

- Text tokens: `ceil(characters / 4)` with a ±15% range.
- Reference images: 765 tokens per image.
- Output tokens: exact value for the selected size and quality.
- Reasoning tokens: documented minimum and maximum for the selected quality.

The **maximum calculated cost** is stored as the generation's estimate.

### Actual Provider Cost

The actual cost is calculated from the usage reported for that generation:

`usage × generation rates / 1,000,000`

The rates returned by Pixelforge when the generation is created are stored and used for the final calculation.

### User Price

The user's price is:

`provider cost × (1 + markup / 100)`

The markup is stored on each generation. Changing the global markup later does not change historical charges.

### Failed Generations

Failed generations always have:

- Provider cost: `0`
- User cost: `0`

---

# Webhooks

Pixelforge sends generation status updates through `POST /webhooks/pixelforge`.

### Signature Verification

The `Pixelforge-Signature` header has the format `t=…,v1=…`.

The signature is an HMAC-SHA256 value calculated from `t.rawBody`.

`VerifyPixelforgeSignature` validates the signature **before parsing the request body**.

The default replay tolerance is **300 seconds**.

Invalid signatures:

- Return `400`.
- Do not modify application data.

### Webhook Processing

Valid events are first stored in `webhook_events`.

The table has a unique constraint on:

`provider + provider_event_id`

After the event is stored, the webhook is acknowledged with `204`.

Processing is handled asynchronously by `ProcessPixelforgeWebhook`.

Duplicate webhook deliveries are acknowledged and skipped.

If a webhook arrives before the create request completes, PromptEdit matches the event using the generation UUID stored in Pixelforge metadata.

---

# Local Setup

## Requirements

- PHP 8.2+
- BCMath PHP extension
- PDO PostgreSQL PHP extension
- PostgreSQL
- Node.js 20+

## Installation

Run the following setup commands:

- `composer install`
- `npm install`
- Copy `.env.example` to `.env`
- `php artisan key:generate`
- Configure the database and Pixelforge environment variables
- `php artisan migrate`
- `php artisan storage:link`

Start the application with `composer run dev`.

Start the scheduler in a second terminal with `php artisan schedule:work`.

The development command starts:

- Laravel application
- Queue listener
- Vite development server

---

# Test Credentials

These credentials are intended for **local/demo testing only**.

### Admin

- Name: `PromptEdit Admin`
- Email: `admin@promptedit.test`
- Password: `Password@123#`

### Test Client

- Name: `Test Client`
- Email: `client@promptedit.test`
- Password: `Password@123#`

### Test Accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@promptedit.test` | `Password@123#` |
| Client | `client@promptedit.test` | `Password@123#` |

> Do not use these credentials in production.

---

# Pixelforge Configuration

| Variable | Value / Purpose |
|---|---|
| `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` |
| `PIXELFORGE_API_KEY` | Pixelforge server-side API key |
| `PIXELFORGE_WEBHOOK_SECRET` | Pixelforge webhook signing secret |
| `PIXELFORGE_WEBHOOK_TOLERANCE` | `300` seconds |
| `PIXELFORGE_MARKUP_PERCENTAGE` | `25` |
| `PIXELFORGE_TIMEOUT` | `60` seconds |
| `PIXELFORGE_PRICING_CACHE_TTL` | `3600` seconds |

### Testing Configuration

For local testing, configure the following values in `.env`:

- `PIXELFORGE_BASE_URL`: `https://pixelforge.promptedit.dev/v1`
- `PIXELFORGE_API_KEY`: provided Pixelforge test API key
- `PIXELFORGE_WEBHOOK_SECRET`: provided Pixelforge webhook secret
- `PIXELFORGE_WEBHOOK_TOLERANCE`: `300`
- `PIXELFORGE_MARKUP_PERCENTAGE`: `25`
- `PIXELFORGE_TIMEOUT`: `60`
- `PIXELFORGE_PRICING_CACHE_TTL`: `3600`

**Important:** `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` are server-side secrets. Do not expose them in Vue, browser code, Git, or public documentation.

---

# Pixelforge Webhook Setup

Each Pixelforge API key has its own webhook URL and signing secret. Use a separate key for each environment.

## 1. Create an API Key

Create a key in the Pixelforge dashboard.

Copy the API key into `PIXELFORGE_API_KEY`.

## 2. Configure the Webhook

Set the webhook URL to:

`https://<public-host>/webhooks/pixelforge`

Copy the signing secret into `PIXELFORGE_WEBHOOK_SECRET`.

## 3. Local Development

Pixelforge does not accept `localhost` or `*.test` as webhook destinations.

Expose the local application through a public tunnel such as:

- ngrok
- Expose
- Cloudflare Tunnel

Then configure the tunnel URL as the Pixelforge webhook URL.

Without a reachable webhook endpoint, generations can remain in `queued` or `processing`.

## 4. Test the Webhook

Use **Send test event** in the Pixelforge dashboard.

A successful `ping` event should appear in `webhook_events` with a status of `ignored`.

---

# Testing

Tests use a PostgreSQL database named `promptedit_testing`.

The test database configuration is defined in `phpunit.xml`.

All Pixelforge API requests are faked during tests.

Run the test suite with `php artisan test`.

---

# Deployment

## 1. PostgreSQL

Configure production PostgreSQL using:

- `DB_CONNECTION=pgsql`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

For managed PostgreSQL services that require SSL, use `DB_SSLMODE=require`.

## 2. Production Environment

Use:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://your-domain`

Configure a production Pixelforge API key and its matching webhook secret.

Recommended production infrastructure:

- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis`

`database` can also be used for queues and cache when Redis is not available.

The cache is used for job uniqueness locks and rate-limit cooldowns.

## 3. Build and Cache

Run the following deployment tasks:

- `composer install --no-dev --optimize-autoloader`
- `npm ci`
- `npm run build`
- `php artisan migrate --force`
- `php artisan storage:link`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`
- `php artisan event:cache`

## 4. Queue Worker

Run the queue worker using Supervisor or an equivalent process manager.

Recommended worker settings:

- Attempts: `10`
- Timeout: `90` seconds
- Sleep: `1` second

After every deployment, run `php artisan queue:restart`.

## 5. Scheduler

Configure a cron job to run `php artisan schedule:run` every minute.

The scheduler runs `generations:resubmit-stuck` every minute.

## 6. Production Webhook

Configure the production Pixelforge key with:

`https://your-domain/webhooks/pixelforge`
