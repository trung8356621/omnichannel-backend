# SERP Page Fetch Security

Fetcher: `SerpPageEvidenceFetcher`

## Modes

- `metadata_only` (default) — validate URL only, skip HTTP
- `http` — curl with redirect re-validation

## Blocked targets (`validateUrlForFetch`)

| Category | Examples |
|----------|----------|
| Schemes | `file://`, `javascript:`, `data:`, `ftp:`, `gopher` |
| Hosts | `localhost`, `127.0.0.1`, `169.254.169.254`, `::1` |
| Private IP | `10.x`, `172.16-31.x`, `192.168.x`, link-local |
| Credentials in URL | user/pass in authority |

Public HTTPS URLs allowed when scheme ∈ `fetch.allowed_schemes` (default http/https).

## Redirect safety

Each redirect target re-validated; limit `fetch.redirect_limit` (default 3).

Response capped `fetch.max_bytes` (default 1 MiB).

## Own-domain detection

`SerpOwnDomainDetector` — suffix-safe match; rejects `example.com.evil.com` masquerading as subdomain.

## Error codes

- `serp.fetch_invalid_scheme`
- `serp.fetch_blocked_private_address`
- `serp.fetch_redirect_limit`
- `serp.fetch_response_too_large`

HTTP path uses `RuntimeLogger` on web routes per project logging rules.
