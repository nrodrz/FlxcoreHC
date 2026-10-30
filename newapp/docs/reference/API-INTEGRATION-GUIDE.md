# ITOps — API & Webhook Integration Guide

**For developers integrating an external system with ITOps.**

Version: build 0621-F · Base URL: `https://ops.example.com/api`

ITOps supports integration in both directions:

| Direction | Mechanism | Use it to… |
|---|---|---|
| **Inbound** | REST API + API key | Let another system create or update records in ITOps (e.g. raise a ticket) |
| **Outbound** | Signed webhooks | Have ITOps notify your system when something changes in ITOps |

You can use either one on its own, or both together.

---

## Part 1 — Inbound: sending data to ITOps

### 1.1 Get an API key

An ITOps administrator creates one for you: **Administration → Integrations → API keys → + New key**.

When creating the key they choose:

- **A name** — identifies which system it belongs to.
- **Scopes** — which modules you may touch, and at what level (*Read only*, *Read + write*, or *Read + write + delete*). You will only be able to reach what you were granted.
- **An optional expiry date.**

The key looks like `opsk_live_1a2b3c4d…` and is **shown exactly once, at creation**. ITOps stores only a hash of it, so it cannot be recovered later — if it is lost, the administrator revokes it and issues a new one.

> **Treat the key like a password.** Store it in your system's secret store or environment variables — never in source control, client-side code, or a browser. Anyone holding the key can act with its permissions.

### 1.2 Authenticate

Send the key on **every request**, in either of these headers:

```http
X-API-Key: opsk_live_1a2b3c4d…
```

or

```http
Authorization: Bearer opsk_live_1a2b3c4d…
```

`X-API-Key` is recommended. All requests must use **HTTPS**.

API keys are for machine-to-machine use only — they are never treated as an administrator, and they cannot manage the integration settings themselves.

A key never has more access than the administrator who created it (the wildcard `*` scope is not allowed), and API keys can never reveal credential-vault secrets.

### 1.3 Make a request

The API is a conventional JSON REST API. Send `Content-Type: application/json` on requests with a body.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/{module}` | List records (paginated) |
| `GET` | `/{module}/{id}` | Fetch one record |
| `POST` | `/{module}` | Create a record |
| `PUT` | `/{module}/{id}` | Update a record |
| `DELETE` | `/{module}/{id}` | Delete a record |

**Create a ticket:**

```bash
curl -X POST https://ops.example.com/api/tickets \
  -H "X-API-Key: opsk_live_1a2b3c4d…" \
  -H "Content-Type: application/json" \
  -d '{
        "subject": "Printer offline — 3rd floor",
        "description": "Reported by reception. Model HP-4500.",
        "priority": "p2_high"
      }'
```

**Response — `200 OK`:**

```json
{ "id": 1482, "ticket_number": "TK-2026-1482", "subject": "Printer offline — 3rd floor",
  "priority": "p2_high", "status": "new", "created_at": "2026-07-14 09:21:04" }
```

The full created record is returned. Store the `id` if you need to update the record later.

### 1.4 Available modules

Use the **path** in the URL. The **entity name** matters only for webhooks (Part 2).

| Module (URL path) | Entity name | Notes |
|---|---|---|
| `/tickets` | `ticket` | Service desk tickets |
| `/sops` | `sop` | Standard Operating Procedures |
| `/job_descriptions` | `job_description` | Job Descriptions |
| `/kb` | `kb_article` | Knowledge Base articles |
| `/vendors` | `vendor` | Vendors |
| `/contracts` | `contract` | Contracts |
| `/credentials` | `credential` | Credential records |
| `/calendar` | `calendar_event` | Calendar events |
| `/documents` | `document` | Documents |

You can only reach modules included in your key's scopes; anything else returns `403`.

### 1.5 Common fields

Only these fields are accepted per module — unknown fields are **ignored**, not rejected.

**`/tickets`**
`subject`, `description`, `category_id`, `department_id`, `priority`, `status`, `requester_id`, `assignee_id`, `due_date`, `resolution_notes`, `tags`

- `priority`: `p4_low` · `p3_medium` · `p2_high` · `p1_critical`

**`/sops`**
`title`, `content`, `category_id`, `department_id`, `status`, `owner_id`, `review_frequency_days`, `next_review_date`, `tags`

**`/job_descriptions`**
`title`, `content`, `department_id`, `reports_to`, `employment_type`, `location`, `status`, `owner_id`, `review_frequency_days`, `next_review_date`

**`/vendors`**
`name`, `website`, `primary_contact_name`, `primary_contact_email`, `primary_contact_phone`, `support_url`, `support_email`, `support_phone`, `sla_response_hours`, `risk_tier`, `category`, `notes`, `department_id`, `status`

**`/contracts`**
`title`, `vendor_id`, `contract_number`, `contract_type`, `department_id`, `status`, `start_date`, `end_date`, `auto_renew`, `renewal_notice_days`, `total_value`, `payment_frequency`, `payment_amount`, `owner_id`, `notes`, `tags`

`content` fields accept HTML. Dates are `YYYY-MM-DD`.

### 1.6 Listing, searching, paging

```
GET /tickets?search=printer&page=2&limit=50&sort=created_at&order=DESC
```

| Parameter | Default | Notes |
|---|---|---|
| `page` | `1` | |
| `limit` | `25` | Maximum `100` |
| `search` | — | Free-text across that module's searchable fields |
| `sort` | `created_at` | |
| `order` | `DESC` | `ASC` or `DESC` |

**Response shape:**

```json
{
  "data": [ { "id": 1482, "subject": "…" } ],
  "pagination": { "page": 1, "limit": 25, "total": 137, "pages": 6 }
}
```

Always read the list from `data` and page using `pagination` — never assume everything came back in one response.

### 1.7 Errors

Errors use a consistent shape and a matching HTTP status:

```json
{ "error": { "code": 403, "message": "Insufficient permissions" } }
```

| Status | Meaning | What to do |
|---|---|---|
| `400` | Bad request — missing/invalid field | Fix the payload; do not retry unchanged |
| `401` | Invalid, revoked, or expired API key | Stop and alert; get a new key |
| `403` | Key lacks the required scope | Ask the administrator to widen the scope |
| `404` | Record or module not found | Check the id/path |
| `500` | Server error | Retry with backoff |

**Recommended client behaviour:** treat `4xx` as permanent (log it, do not hammer the endpoint) and `5xx` as transient (retry a few times with exponential backoff).

---

## Part 2 — Outbound: receiving events from ITOps

### 2.1 Subscribe

An administrator adds your endpoint in **Administration → Integrations → Webhooks**, choosing:

- **Your URL** — must be **`https://`**.
- **The events** you want (or *All events*).

ITOps generates a **signing secret** for that webhook. The administrator gives it to you — you need it to verify deliveries. Store it as a secret.

### 2.2 Events

Event names are `{entity}.{action}` where *action* is `created`, `updated`, or `deleted`, and *entity* is the entity name from the table in §1.4.

Examples: `ticket.created` · `ticket.updated` · `sop.updated` · `job_description.created` · `kb_article.updated` · `vendor.created` · `contract.updated`

Besides the record events, ITOps also sends these **process events**:

| Event | When |
|---|---|
| `onboarding.started` / `offboarding.started` | A person's onboarding / offboarding process was started (`data`: `run_id`, `subject`) |
| `onboarding.completed` / `offboarding.completed` | Every step of the process is done or skipped |
| `asset.loaned` / `asset.returned` | Equipment issued to / checked back in from a person |
| `signature_request.created` / `signature_request.completed` / `signature.signed` | E-signature requests and signatures |
| `m365.password_reset`, `m365.license_changed` … | Microsoft 365 admin actions taken in ITOps |
| `guardian.action_run` | A Server Guardian remediation action ran |

Payloads never contain password hashes, 2FA secrets or encrypted vault values.

Wildcards are supported when subscribing: `ticket.*` (all ticket events) or `*` (everything).

### 2.3 What a delivery looks like

ITOps sends an HTTP **`POST`** with `Content-Type: application/json` and these headers:

```http
X-ITOps-Event:     ticket.created
X-ITOps-Delivery:  9f2b7c1d4e6a8b30
X-ITOps-Timestamp: 1768392064
X-ITOps-Signature: sha256=6f1a…
User-Agent:         ITOps-Webhook/1.0
```

**Body:**

```json
{
  "event": "ticket.created",
  "delivered": "2026-07-14T09:21:04+00:00",
  "delivery_id": "9f2b7c1d4e6a8b30",
  "data": {
    "id": 1482,
    "ticket_number": "TK-2026-1482",
    "subject": "Printer offline — 3rd floor",
    "priority": "p2_high",
    "status": "new",
    "created_at": "2026-07-14 09:21:04"
  }
}
```

`data` is the full record as it exists **after** the change (for `deleted`, it is the record as it was immediately before deletion).

### 2.4 Verify the signature — do this before trusting anything

Anyone can POST to a public URL. The signature is what proves the request genuinely came from ITOps.

`X-ITOps-Signature` is `sha256=` followed by an **HMAC-SHA256**, computed over:

```
{timestamp}.{raw request body}
```

…keyed with your webhook's signing secret. Compute the same value and compare using a **constant-time** comparison.

**PHP**

```php
$raw    = file_get_contents('php://input');
$ts     = $_SERVER['HTTP_X_ITOPS_TIMESTAMP'] ?? '';
$sig    = $_SERVER['HTTP_X_ITOPS_SIGNATURE'] ?? '';
$secret = getenv('ITOPS_WEBHOOK_SECRET');

// Reject replays: ignore anything older than 5 minutes.
if (!$ts || abs(time() - (int)$ts) > 300) { http_response_code(401); exit; }

$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, $secret);
if (!hash_equals($expected, $sig)) { http_response_code(401); exit; }

$payload = json_decode($raw, true);   // trusted from here on
http_response_code(200);
echo 'ok';
```

**Node.js (Express)**

```js
const crypto = require('crypto');

// The raw body is required — parse it as text, not JSON, on this route.
app.post('/hooks/itops', express.text({ type: '*/*' }), (req, res) => {
  const ts  = req.get('X-ITOps-Timestamp') || '';
  const sig = req.get('X-ITOps-Signature') || '';
  const raw = req.body;

  if (!ts || Math.abs(Date.now() / 1000 - Number(ts)) > 300) return res.sendStatus(401);

  const expected = 'sha256=' + crypto
    .createHmac('sha256', process.env.ITOPS_WEBHOOK_SECRET)
    .update(`${ts}.${raw}`)
    .digest('hex');

  const a = Buffer.from(expected), b = Buffer.from(sig);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return res.sendStatus(401);

  const payload = JSON.parse(raw);   // trusted from here on
  res.sendStatus(200);
});
```

> **Signature verification must run on the raw body**, byte for byte. If your framework re-serialises the JSON before you hash it, the signature will not match.

### 2.5 How to respond

- Reply **`2xx`** to acknowledge. Anything else counts as a failure.
- **Reply quickly.** ITOps gives up after **5 seconds**. Acknowledge first, then do your slow work in a background job.
- Failures are **retried automatically — up to 5 attempts**, with an increasing delay (roughly 1 min, 3 min, 9 min, 27 min).

### 2.6 Build your handler to be idempotent

Retries mean **the same event can arrive more than once**. Use `delivery_id` (unique per delivery, stable across retries) as a deduplication key: record the ones you have processed and ignore repeats.

Ordering is not guaranteed either — if two changes happen in quick succession, they may arrive out of order. Where order matters, trust the record's own timestamps in `data` rather than arrival order.

### 2.7 Troubleshooting

Administrators can see every attempt in **Administration → Integrations → Delivery log** — the event, the HTTP status you returned, any error, and the attempt count. There is also a **Test** button on each webhook that sends a `ping` event, and a **Retry failed now** button.

If deliveries are failing, check in this order:

1. Is your URL **https** and reachable from the public internet (not behind a VPN or firewall)?
2. Is its **TLS certificate valid**? ITOps verifies certificates and will refuse to deliver otherwise.
3. Are you replying **within 5 seconds** with a `2xx`?
4. Are you rejecting the signature? Re-check that you hash `timestamp + "." + raw body`.

---

## Part 3 — Practical guidance

### Security checklist

- Store the API key and webhook secret in a **secret manager or environment variables** — never in code, repos, or browsers.
- Request the **narrowest scopes** that do the job. If you only file tickets, ask for write access to tickets and nothing else.
- **Always verify** the webhook signature and **reject stale timestamps** (> 5 minutes).
- Use a **separate key per system**, so one can be revoked without disrupting the others.
- Rotate keys periodically: create the new key, deploy it, then revoke the old one — revocation takes effect immediately.

### If you need to both send and receive

A common pattern: your system POSTs a ticket into ITOps, then subscribes to `ticket.updated` to learn when staff change its status — closing the loop without polling. Record the `id` returned at creation and match it to `data.id` on incoming events.

### Limits and behaviour worth knowing

- Webhooks are dispatched **as the change is saved**, so events arrive within seconds.
- List endpoints return a **maximum of 100 records** per page — page through the rest.
- Unknown fields in a create/update are **silently ignored**, so a typo in a field name fails quietly. Verify the response contains what you expect.
- Every API-key action is written to the ITOps **audit log**, attributed to the key's name.

### Getting help

Contact the ITOps administrator to issue or revoke keys, adjust scopes, register webhooks, retrieve a signing secret, or inspect the delivery log for your endpoint.
