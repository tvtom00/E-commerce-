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
- GEMINI_MODEL: optional; defaults to gemini-3.8-flash, with a Gemini 3.6 Flash fallback.
- GEMINI_FALLBACK_MODEL: optional; defaults to gemini-3.6-flash.
- ENABLE_OPENAI_FALLBACK: optional; defaults to false. Set true only if you intentionally want to allow paid OpenAI API usage. Gemini is tried first; Groq is secondary.
- NIRA_BUILDER_TOKEN: optional shared secret used only for protected existing-draft revision and approval endpoints. Normal website ZIP generation is prompt-only and does not ask the visitor for this token.
- NIRA_GITHUB_TOKEN: required for durable free project storage. Use a fine-grained GitHub token with permission to create private repositories and read/write repository contents and pull requests. Each validated project is committed to a new private repository on a review branch. The response download URL points to the private repository branch ZIP, which remains available in GitHub after Render restarts.
- NIRA_STORAGE_PATH: optional local path for temporary ZIP creation. Render Free local files are ephemeral; GitHub private repositories are used as the durable free archive when NIRA_GITHUB_TOKEN is configured.

### Free ZIP storage
1. Configure NIRA_GITHUB_TOKEN privately in Render. Do not paste it into source code or chat.
2. Give the token access only to create private repositories and read/write repository contents and pull requests.
3. Generated projects are committed to a private GitHub repository on the `nira-generated` branch. The returned download URL downloads that branch as a ZIP from GitHub; the user must be signed in to an account with access to the private repository.
4. If GitHub publishing is not configured or fails, the service clearly reports that the local ZIP is temporary. Do not rely on Render Free local files for long-term storage.
5. No Cloudflare R2, paid object storage, or Persistent Disk is required for the GitHub archive workflow.

## API
POST /api/generate
Content-Type: application/json
No Builder token is required for normal generation. The browser must submit from the same website origin; generation is limited to five requests per source IP per hour.

{"name":"my-store","prompt":"Build a responsive ecommerce storefront with product listing, cart, and admin UI..."}

Returns JSON with a download URL. If NIRA_GITHUB_TOKEN is configured, it also creates a private GitHub repository and a review pull request. NIRA_BUILDER_TOKEN is not required for generation; it remains required for the separate /api/revise and /api/approve advanced draft actions. Generated projects are never executed on this service.

GET /health returns service health.

## Resource limits
Generation is limited to 2,000 text files, 512 KB per file, 50 MB total source, and 12 KB request payload. Gemini is the primary free-tier provider (Gemini 3.8 Flash, then 3.6 Flash fallback), Groq is secondary, and OpenAI is disabled unless explicitly enabled. The Laravel project is generated in thirteen sequential bounded batches. With `NIRA_GITHUB_TOKEN` configured, each completed batch is committed to a private `nira-resume-<id>` checkpoint repository. A retry resumes only when both the project name and exact same prompt match; the browser stores a request fingerprint and the server verifies the exact prompt hash before resuming. A changed brief starts a new checkpoint instead of mixing files from different requests. Each batch is checked for unsafe paths, case-insensitive duplicate paths, possible secrets, unsupported/binary files, per-file size and total source limits before its checkpoint is committed. Completed checkpoint repositories can be deleted from GitHub when no longer needed. For final project storage, a verified private GitHub project archive is used when `NIRA_GITHUB_TOKEN` is configured; otherwise a configured S3-compatible bucket is used, and if neither is configured the local ZIP is temporary and can be lost on restart/redeploy. This service does not deploy generated projects to production. Review and merge the GitHub pull request, configure MySQL and provider credentials, then deploy separately.


## Generated-project completeness gates

Before a generated ZIP is marked complete, the builder validates the full saved file inventory and cross-file contracts, including PHP syntax, Laravel imports/routes/controllers, referenced Blade views, required customer login/registration pages, the complete runtime/configuration inventory, model-to-migration columns, duplicate paths that would collide on case-insensitive filesystems, state-changing form CSRF/method spoofing, placeholder links/actions, feature-test depth, Vite/package dependency and entrypoint consistency, CI frontend asset compilation, and ZIP entry/content integrity. Missing required files or failed checks keep the project out of the final-download success path and trigger the existing checkpoint-preserving repair flow. A generation batch is only considered saved after its checkpoint write succeeds; retries resume from the last committed batch.

These are static/source-level gates, not a claim that every third-party integration is live or that the generated application has been deployed. Generated applications still require their own dependency installation, database-backed test run, credentials, and provider sandbox configuration before production use.
