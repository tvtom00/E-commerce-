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

## API
POST /api/generate
Content-Type: application/json
Optional header: X-Nira-Token

{"name":"my-store","prompt":"Build a responsive ecommerce storefront with product listing, cart, and admin UI..."}

Returns JSON with a download URL. Generated projects are ZIP files and are not executed on this service.

GET /health returns service health.

## Resource limits
Generation is limited to 80 text files, 200 KB per file, and 12 KB request payload. Generated ZIPs are stored on the container filesystem; configure a Render persistent disk if ZIPs must survive restarts/redeploys. This service does not yet deploy generated projects to separate production services.
