# Nira AI Website Builder

A lightweight PHP 8.2 + Apache service for generating website project ZIPs from natural-language instructions using the Gemini API.

## Render deployment
- Runtime: Docker
- Build context: repository root
- Dockerfile: Dockerfile
- Health check path: /health
- Render supplies PORT automatically.

## Environment variables
- GEMINI_API_KEY: required; set privately in Render.
- GEMINI_MODEL: optional; defaults to gemini-3.6-flash. Change to a model available to your API key if needed.
- NIRA_BUILDER_TOKEN: required shared secret. The generation API stays disabled until this is set privately in Render; callers must send it as X-Nira-Token.
- NIRA_GITHUB_TOKEN: optional GitHub token with permission to create private repositories and pull requests. When configured, each validated project is committed to a new private repository on a review branch and a pull request is opened; the ZIP remains available too.
- NIRA_S3_ENDPOINT: optional HTTPS endpoint for S3-compatible object storage (Cloudflare R2 or AWS S3).
- NIRA_S3_BUCKET: bucket name for generated ZIP archives.
- NIRA_S3_ACCESS_KEY_ID and NIRA_S3_SECRET_ACCESS_KEY: private object-storage credentials. Grant access only to the configured bucket.
- NIRA_S3_REGION: optional signing region; use `auto` for Cloudflare R2 and the bucket region for AWS S3.
- NIRA_STORAGE_PATH: optional local path for temporary ZIP creation or a mounted persistent disk. On Render Free, local files are ephemeral. Configure all four required S3 variables (`NIRA_S3_ENDPOINT`, `NIRA_S3_BUCKET`, `NIRA_S3_ACCESS_KEY_ID`, `NIRA_S3_SECRET_ACCESS_KEY`) to keep ZIPs in external object storage. When S3 is configured, uploads and downloads use that bucket; the service proxies downloads without exposing storage credentials.

### Cloudflare R2 setup
1. Create an R2 bucket and an API token limited to Object Read & Write for that bucket.
2. In Render → `nira-ecommerce-builder` → Environment, set `NIRA_S3_ENDPOINT` to `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, `NIRA_S3_BUCKET` to the bucket name, `NIRA_S3_ACCESS_KEY_ID` and `NIRA_S3_SECRET_ACCESS_KEY` to the generated credentials, and `NIRA_S3_REGION` to `auto`.
3. Save changes and redeploy. `/health` reports `persistent_storage_configured: true` only when all required S3 settings are present. Never put these credentials in GitHub source files or the browser.

## API
POST /api/generate
Content-Type: application/json
Optional header: X-Nira-Token

{"name":"my-store","prompt":"Build a responsive ecommerce storefront with product listing, cart, and admin UI..."}

Returns JSON with a download URL. If NIRA_GITHUB_TOKEN is configured, it also creates a private GitHub repository and a review pull request. Generated projects are never executed on this service.

GET /health returns service health.

## Resource limits
Generation is limited to 100 text files, 120 KB per file, 3 MB total source, and 12 KB request payload. Gemini generates the Laravel project in three bounded batches; duplicate paths and incomplete outputs are rejected. Generated ZIPs use S3-compatible external storage when configured; otherwise they are stored on the container filesystem and can be lost on restart/redeploy. This service does not deploy generated projects to production. Review and merge the GitHub pull request, configure MySQL and provider credentials, then deploy separately.
