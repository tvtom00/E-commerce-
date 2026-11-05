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
- NIRA_STORAGE_PATH: optional writable persistent-disk directory for generated ZIPs. Mount a Render persistent disk and set this path to its mount location (for example `/var/data/generated`) if ZIPs must survive restarts/redeploys.

## API
POST /api/generate
Content-Type: application/json
Optional header: X-Nira-Token

{"name":"my-store","prompt":"Build a responsive ecommerce storefront with product listing, cart, and admin UI..."}

Returns JSON with a download URL. If NIRA_GITHUB_TOKEN is configured, it also creates a private GitHub repository and a review pull request. Generated projects are never executed on this service.

GET /health returns service health.

## Resource limits
Generation is limited to 80 text files, 200 KB per file, and 12 KB request payload. Generated ZIPs use NIRA_STORAGE_PATH when configured; otherwise they are stored on the container filesystem and can be lost on restart/redeploy. This service does not deploy generated projects to production. Review and merge the GitHub pull request, configure MySQL and provider credentials, then deploy separately.
