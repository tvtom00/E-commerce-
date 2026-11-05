# Nira Builder persistent storage

The Builder writes generated ZIP files beneath `NIRA_STORAGE_PATH`. The default
container path is ephemeral and files can disappear after a Render restart or
redeploy.

For persistent ZIP retention, attach a persistent disk to the Render Builder
service and set:

- `NIRA_STORAGE_PATH=/var/data/nira-generated`
- `NIRA_STORAGE_PERSISTENT=true`

The mount path must match the disk mount path configured in Render. The service
process must have write permission to the directory. Do not set the persistent
flag unless the disk is actually attached and mounted.

The `/health` endpoint reports only booleans:
- `storage_writable`
- `persistent_storage_configured`
- `gemini_configured`
- `github_publishing_configured`

It never returns secret values. A true `persistent_storage_configured` means
the configured path/flag indicates persistence; operators must still confirm
the Render disk is attached in the Dashboard.
