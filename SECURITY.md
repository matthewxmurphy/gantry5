# Security reports and API boundaries

Do not post tokens, private keys, customer/site data or exploitable deployment
details in public issues. Use the repository's private vulnerability reporting
facility where available; otherwise contact the fork maintainer through the
private support contact at https://builtnotbegged.com. Developer:
Matthew X Murphy, https://MatthewxMurphy.com. No response SLA is promised.

Gantry 6.0.1 admits PHP 8.5 and exposes its narrow API only through Joomla 6.2 core
Web Services. It uses Joomla core token authentication and native Gantry settings
administrator permissions. Basic authentication is not enabled. Gantry creates
no token or account and offers no alternate authentication bypass.

Treat an API token as the existing user's credential: store it privately, never
put it in a URL, and use Joomla's own account/token controls to revoke it.
Swagger authorizations remain in page memory and are not persisted. The bundled
UI and same-origin request policy avoid sending a token to a remote API server.

The API accepts installed-theme outline/layout/settings data only. Writes need
current revisions; protected outlines cannot be deleted. It is not a file,
code, user or extension administration API. Keep backups private and verify
restore before installation. Follow [API contracts](docs/core-api.md) and
[the operational runbook](docs/6.0.1-runbook.md).
