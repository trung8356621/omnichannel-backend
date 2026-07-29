# Site Sync V2 Security

## Callback requirements

- HMAC signature (or equivalent current bridge secret)
- Timestamp + tolerance (replay protection)
- Nonce / event_id uniqueness
- Connection / site binding
- Constant-time signature compare
- Payload size limit
- Rate limit
- HTTPS expectation
- Secret rotation support
- Audit / security event on failure (no secrets in logs)

## On signature fail

- Do **not** queue reconcile
- Do **not** mutate SeoArticle / catalog / keywords
- Record security event safely

## Flag

`SEO_SITE_SYNC_V2_REQUIRE_SIGNED=true` to enforce signatures in production.

## Never log

API tokens, signature secrets, full article body, unnecessary contact payloads.
