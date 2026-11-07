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
- NIRA_BUILDER_TOKEN: required shared secret. The generation API stays disabled until this is set privately in Render; callers must send it as X-Nira-Token.
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
Optional header: X-Nira-Token

{"name":"my-store","prompt":"Build a responsive ecommerce storefront with product listing, cart, and admin UI..."}

Returns JSON with a download URL. If NIRA_GITHUB_TOKEN is configured, it also creates a private GitHub repository and a review pull request. Generated projects are never executed on this service.

GET /health returns service health.

## Resource limits
Generation is limited to 100 text files, 120 KB per file, 3 MB total source, and 12 KB request payload. Gemini is the primary free-tier provider (Gemini 3.8 Flash, then 3.6 Flash fallback), Groq is secondary, and OpenAI is disabled unless explicitly enabled. The Laravel project is generated in eleven sequential bounded batches. With `NIRA_GITHUB_TOKEN` configured, each completed batch is committed to a private `nira-resume-<id>` checkpoint repository. If a later provider fails, retry Generate with the same project name and prompt; the builder resumes from the last completed batch instead of regenerating earlier batches. The resume ID is retained in the browser for the same request. Completed checkpoint repositories can be deleted from GitHub when no longer needed. Generated ZIPs use S3-compatible external storage when configured; otherwise they are stored on the container filesystem and can be lost on restart/redeploy. This service does not deploy generated projects to production. Review and merge the GitHub pull request, configure MySQL and provider credentials, then deploy separately.
