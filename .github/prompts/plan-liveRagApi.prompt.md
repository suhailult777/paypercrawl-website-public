## Plan: Hybrid Model — “Sync Content” (Static KB) + “Live Sync” (Real-Time) + “Live Tool” (RAG API)

> **Implementation Status**: ✅ V1 Implemented (January 2026)
> You want two separate products/pipelines:

1. **Sync Content (Static Knowledge Base)**

- Purpose: Index WordPress content that changes “sometimes” (posts/pages/docs). This is your durable KB and is great for grounding on-site facts.
- Characteristics: batch/periodic, cheap, cached, searchable, can be embedded.

2. **Live Sync (Real-Time Data Layer)**

- Purpose: Provide _fresh_ data that AI companies don’t already have (prices, inventory, availability, current stats). This is not a “scrape my posts” feature.
- Characteristics: streaming/webhooks/short-interval polling, TTL-based storage, strict rate limiting, auditability, SLAs.

3. **Live Tool (RAG API)**

- Purpose: The public-facing “tool” endpoint that AI agents call (“flight prices to Dubai right now”).
- Key design point: **The Tool API must query the Live layer first**, and optionally blend with the Static KB only when helpful for context.

---

### A) UI Plan — WordPress Plugin (Publisher/Admin)

**1) Navigation / Pages**

- Dashboard (existing): keep summary + revenue/analytics.
- Content Sync (Static KB): settings + “Sync now” + status.
- Live Sync (Real-Time): settings + connectors + health.
- Live Tool Test Console: query builder + response viewer + citations.
- Usage & Limits: quotas, last 24h calls, errors, rate limit hits.

**2) Content Sync (Static KB) screen**

- “Enable Content Sync” toggle.
- “Sync frequency” (manual / WP-Cron schedule).
- “Include types” (posts/pages/custom post types).
- “Last sync” timestamp + counts (pages indexed).
- Button: “Sync now”.

**3) Live Sync (Real-Time) screen**

- “Enable Live Sync” toggle (independent of Content Sync).
- Connector management (one-to-many):
  - Connector type: WooCommerce inventory, product pricing, custom REST endpoint, CSV/JSON feed URL, webhook receiver.
  - Auth for connector (API token, basic auth, header key/value).
  - Polling interval (if polling) OR webhook secret (if push).
  - Data schema preview (sample payload) + validation.
- Health: last event received, last successful refresh, error log.
- Controls: “Send test event” / “Test pull now”.

**4) Live Tool Test Console screen**

- Inputs:
  - Question
  - Mode: `live_only` | `hybrid` | `kb_only`
  - Optional filters: connector, entity type (product/flight/stock), locale/currency
  - Max age / freshness window (e.g. last 5 min)
- Output:
  - Answer (structured + plain text)
  - Citations with timestamps (live events) + sources (connector ID)
  - Latency breakdown + rate-limit headers

---

### B) Backend Plan — Next.js SaaS (Tool API + Storage)

**1) API Surface (separate namespaces)**

Static KB (already exists; keep it separate):

- `POST /api/plugin/sync-content` (static KB ingestion)
- Optional: `GET /api/plugin/sync-content/status`

Live Sync ingestion/control (new, separate):

- `POST /api/plugin/live/register` (register site + live capabilities; returns siteId + signing secret)
- `POST /api/plugin/live/ingest` (push ingestion from plugin; signed)
- `POST /api/plugin/live/pull` (server-triggered pull for a connector; optional)
- `GET /api/plugin/live/status` (health, last event times, connector status)

Live Tool (public tool endpoint; new):

- `POST /api/tool/live/query`
  - Auth: API key (header `x-api-key`) + per-site routing
  - Reads: Live store first; optionally falls back to Static KB
  - Response: structured JSON for agents + human-readable summary

**2) Storage Model (two stores)**

Static KB store (already present):

- `Site` + `ScrapedPage` (good for long-lived facts)

Live store (new tables; TTL semantics):

- `LiveConnector` (per site): type, config, polling schedule, status
- `LiveEvent` (append-only): connectorId, timestamp, payload (JSON), normalized fields
- `LiveEntitySnapshot` (latest state per entity): entityId, updatedAt, TTL/expiresAt, structured fields
- `ToolQueryLog` (audit): apiKeyId, siteId, mode, latencyMs, tokens/bytes, status

**3) Retrieval & RAG Behavior (hybrid logic)**

- Default tool mode: `hybrid`.
- Retrieval order:
  1.  Live snapshots/events within freshness window (e.g. last 5–15 minutes)
  2.  If insufficient, optionally query Static KB for background context (policies, descriptions)
- Output should include explicit freshness timestamps and source attribution.

**Phase 1 (ship fast):**

- Live retrieval via structured filtering + simple keyword search over recent `LiveEvent.payload`.
- KB retrieval via Postgres text search over `ScrapedPage.content`.

**Phase 2 (true RAG):**

- Add embeddings for Static KB chunks and (optionally) Live snapshots.
- Use vector similarity + freshness ranking.

**4) Security, Rate Limits, and Abuse Control**

- Separate secrets:
  - API key (`x-api-key`) for Tool API consumers.
  - Per-site signing secret for Live ingestion from plugin (HMAC signature header).
- Rate limits:
  - Tool API: per apiKey + per site (hard caps + burst)
  - Live ingest: per connector (protects DB)
- Payload validation: strict `zod` schemas per connector type.

---

### C) WordPress Plugin Backend Implementation (how it talks to SaaS)

**1) Settings & state**

- Keep `crawlguard_options` but split clearly:
  - `content_sync.*` (static KB)
  - `live_sync.*` (real-time)
- Store per-connector configs in a separate option or custom table if needed.

**2) Live Sync mechanics (separate from content sync)**

- Webhook mode: WP pushes events to `POST /api/plugin/live/ingest` when changes happen (e.g. order status change, stock change).
- Polling mode: WP schedules pulls (WP-Cron) to fetch from configured endpoints and pushes to ingest.
- Include idempotency key + timestamp to prevent duplicates.

**3) Live Tool**

- Plugin mainly provides:
  - Onboarding + connector configuration
  - A test console for admins
- The actual “tool” for AI companies lives in Next.js at `POST /api/tool/live/query`.

---

### D) Milestones (suggested)

**Milestone 1: Foundations**

- Separate Live tables + minimal endpoints (`/api/plugin/live/ingest`, `/api/tool/live/query`)
- Plugin UI pages + test console (no fancy connectors yet)

**Milestone 2: First real connector**

- WooCommerce inventory/pricing live sync (webhook-based)
- Tool answers with freshness + citations

**Milestone 3: Scale & hardening**

- Rate limiting + query logging UI
- Multi-site + multi-connector management

---

### Open Decisions (you should choose early)

1. What is the first “live” domain you want to ship?

- Confirmed for v1: **WooCommerce inventory + pricing**.

2. Who calls the Tool API?

- Confirmed for v1: **anyone with the API key** (header `x-api-key`).

3. Freshness guarantees

- Defer to later: return timestamps + sources now; add explicit SLAs once you see real event/query volume.

---

## V1 Contracts (Concrete Specs)

These are the minimum contracts to build and demo end-to-end.

### 1) Live Sync Ingest (Publisher → PayPerCrawl)

**Endpoint**

- `POST /api/plugin/live/ingest`

**Auth (v1)**

- `x-api-key: <api_key>`
- Note: v1 uses the same key for ingest + tool calls; later you can split to a separate signing secret / ingest token.

**Request body (WooCommerce event)**

```json
{
  "siteUrl": "https://example.com",
  "connector": "woocommerce",
  "eventType": "product.price_or_stock.updated",
  "eventId": "uuid-or-deterministic-idempotency-key",
  "occurredAt": "2026-01-15T12:34:56.000Z",
  "entity": {
    "type": "product",
    "productId": 123,
    "sku": "SKU-123",
    "name": "T-Shirt",
    "currency": "USD",
    "price": {
      "regular": 29.99,
      "sale": 19.99,
      "effective": 19.99
    },
    "stock": {
      "status": "instock",
      "quantity": 7,
      "backorders": "no"
    },
    "permalink": "https://example.com/product/t-shirt/"
  }
}
```

**Response**

```json
{
  "success": true,
  "stored": true,
  "deduped": false,
  "receivedAt": "2026-01-15T12:34:56.789Z"
}
```

**Server behavior (v1)**

- Validate with `zod`.
- Deduplicate by `eventId` (idempotency).
- Upsert a “latest snapshot” row for `(site, sku/productId)` for fast tool reads.
- Append an event row for audit/debug.

### 2) Live Tool Query (AI Agent → PayPerCrawl)

**Endpoint**

- `POST /api/tool/live/query`

**Auth**

- `x-api-key: <api_key>`

**Request body**

```json
{
  "siteUrl": "https://example.com",
  "question": "What is the current price and stock for SKU-123?",
  "mode": "hybrid",
  "freshnessSeconds": 900,
  "filters": {
    "connector": "woocommerce",
    "sku": "SKU-123"
  }
}
```

**Response body (agent-friendly)**

```json
{
  "success": true,
  "answer": {
    "text": "SKU-123 (T-Shirt) is $19.99 and in stock (qty 7).",
    "data": {
      "sku": "SKU-123",
      "productId": 123,
      "currency": "USD",
      "effectivePrice": 19.99,
      "stockStatus": "instock",
      "stockQuantity": 7,
      "permalink": "https://example.com/product/t-shirt/"
    }
  },
  "citations": [
    {
      "type": "live",
      "connector": "woocommerce",
      "occurredAt": "2026-01-15T12:34:56.000Z",
      "eventId": "uuid-or-deterministic-idempotency-key"
    }
  ],
  "meta": {
    "mode": "hybrid",
    "freshnessSeconds": 900,
    "latencyMs": 123
  }
}
```

**Tool behavior (v1)**

- Live-first: prefer latest snapshot/event within `freshnessSeconds`.
- If `mode=hybrid`, optionally add background context from the static KB (policies, descriptions), but do not let stale KB override live fields.
- Always return timestamps so the caller can verify freshness.

---

## WooCommerce Implementation Notes (Plugin-side)

**Requirement**: WooCommerce is optional. If it’s not active, the WooCommerce connector UI is disabled.

**How it works**

- Your plugin installs alongside WooCommerce and listens to WooCommerce/WordPress hooks.
- When a product price/stock changes, you emit a Live event and POST it to `/api/plugin/live/ingest`.

**V1 event triggers to implement**

- Product save/update (price changes)
- Stock quantity/status changes

**Testing without customers**

- Spin up a throwaway WP site (local Docker or cheap staging), install WooCommerce + your plugin, and change product price/stock to generate events.

---

## Implementation Summary (V1 Complete)

### Files Created/Modified

**Next.js Backend (Prisma + API)**

- `prisma/schema.prisma` — Added 4 new models: `LiveConnector`, `LiveEvent`, `LiveEntitySnapshot`, `ToolQueryLog`
- `src/app/api/plugin/live/ingest/route.ts` — Live event ingestion endpoint
- `src/app/api/plugin/live/status/route.ts` — Live sync status endpoint
- `src/app/api/tool/live/query/route.ts` — Live Tool query endpoint (public RAG API)

**WordPress Plugin**

- `crawlguard-wp-main/includes/class-live-sync-client.php` — API client for Live Sync
- `crawlguard-wp-main/includes/class-woocommerce-connector.php` — WooCommerce hooks + event builder
- `crawlguard-wp-main/includes/class-admin.php` — Added Live Sync page + Live Tool Console page
- `crawlguard-wp-main/crawlguard-wp.php` — Updated to load new classes + init WooCommerce connector

### How to Test

1. **Start the dev server**: `npm run dev`
2. **Push schema**: Already done (`npm run db:push`)
3. **Test ingest endpoint** (curl example):

```bash
curl -X POST http://localhost:3000/api/plugin/live/ingest \
  -H "Content-Type: application/json" \
  -H "x-api-key: YOUR_API_KEY" \
  -d '{
    "siteUrl": "https://example.com",
    "connector": "woocommerce",
    "eventType": "product.price_or_stock.updated",
    "eventId": "test-001",
    "occurredAt": "2026-01-15T12:00:00.000Z",
    "entity": {
      "type": "product",
      "productId": 1,
      "sku": "TEST-SKU",
      "name": "Test Product",
      "currency": "USD",
      "price": { "regular": 29.99, "sale": null, "effective": 29.99 },
      "stock": { "status": "instock", "quantity": 10, "backorders": "no" },
      "permalink": "https://example.com/product/test/"
    }
  }'
```

4. **Test query endpoint**:

```bash
curl -X POST http://localhost:3000/api/tool/live/query \
  -H "Content-Type: application/json" \
  -H "x-api-key: YOUR_API_KEY" \
  -d '{
    "siteUrl": "https://example.com",
    "question": "What is the price of TEST-SKU?",
    "mode": "live_only",
    "freshnessSeconds": 3600
  }'
```

5. **WordPress Plugin**: Install on a WP site with WooCommerce, enable Live Sync in the new "Live Sync" admin page, and use the "Live Tool Console" to test queries.

### Next Steps (V2)

- Add embeddings + vector search (pgvector) for true RAG
- Add more connectors (custom JSON feeds, webhook receiver)
- Add rate limiting per API key
- Add usage analytics dashboard in SaaS
