# Nira Builder storage and Render Free setup

Render Free web services cannot attach persistent disks. Their local filesystem is
ephemeral: generated ZIP files can disappear when the service spins down, restarts,
or redeploys.

## Recommended setup on Render Free

For durable project source without a paid disk:

- Configure `NIRA_GITHUB_TOKEN` on the **Builder Render service itself**. It must be
  able to create private repositories and write workflow files (classic PAT scopes:
  `repo` and `workflow`; for fine-grained tokens, grant repository contents and
  workflows write access).
- Configure `GEMINI_API_KEY` or `GOOGLE_API_KEY` on the **Builder Render service**.
  Optionally set `GEMINI_MODEL` there; otherwise the Builder uses `gemini-3.6-flash`, matching Nira's configured model family. Gemini 2.5 access may be restricted for projects without prior active usage.
- Set the same `NIRA_BUILDER_TOKEN` value on both the Nira service and Builder service.
- Keep the Nira service's `NIRA_ECOMMERCE_BUILDER_URL` pointed at the Builder URL.

With GitHub publishing configured, generated project source is stored in a private
GitHub repository. The Builder can return a download archive based on that durable
source. If GitHub publishing is not configured, the response uses local ephemeral
storage and the ZIP is not guaranteed to survive a spin-down or redeploy.

## Paid-plan disk setup

If the Builder is moved to a Render plan that supports persistent disks, attach a
disk and set:

- `NIRA_STORAGE_PATH=/var/data/nira-generated`
- `NIRA_STORAGE_PERSISTENT=true`

The mount path must match the disk mount path configured in Render, and the service
process must have write permission. Do not set the persistent flag unless the disk
is actually attached and mounted.

The `/health` endpoint reports only booleans such as `storage_writable`,
`persistent_storage_configured`, `gemini_configured`, and
`github_publishing_configured`; it never returns secret values. A true persistent
flag still requires confirming the actual disk in the Render Dashboard.
