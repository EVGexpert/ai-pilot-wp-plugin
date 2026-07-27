# AI Pilot — Remote Site API 2.2.0

Version 2.2.0 combines a safer proposal execution contract with a redesigned WordPress administration experience.

## User experience

AI Pilot is now available as a separate top-level WordPress admin section instead of being hidden under **Settings**.

The section contains four pages:

- **Connection** — one-click setup and current connection status;
- **Assistant** — site description, tone of voice and working rules;
- **Permissions** — clear capability switches;
- **System** — plugin version, connection state and endpoint diagnostics.

When the administrator clicks **Connect AI Pilot**, WordPress creates a one-time code and opens the authorization window. The page polls only the status of that exact code. After authorization succeeds, the popup closes automatically and the onboarding view is replaced by the connected-site dashboard.

## Connection safety

- The one-time code expires after five minutes.
- Creating a reconnect code does not revoke the current working token.
- The new token is activated only when the code is verified.
- Connection-code creation and status polling require `manage_options` and a WordPress REST nonce.
- Disconnecting the site revokes the token and removes outstanding codes.

## Proposal execution

The old 2.1.1 approval endpoint only changed a proposal status. Version 2.2.0 executes the action saved in the proposal:

1. `POST /agent/propose` stores the action and normalized params as `pending`.
2. `POST /agent/approve/{id}` moves the proposal to `processing`.
3. The plugin executes the stored action.
4. On success, it saves `completed` and a canonical result.
5. On failure, it saves `failed` and a safe error.
6. Repeated approval returns the original stored result without executing again.
7. A database-backed per-proposal lock prevents simultaneous requests from executing the same action twice.

The legacy `/agent/action` route remains available for existing clients.

## Create-post result

Successful `create_post` execution returns:

```json
{
  "success": true,
  "id": 125,
  "post_id": 125,
  "type": "post",
  "status": "draft",
  "url": "https://example.com/?p=125"
}
```

A post is not created when its title or content is empty, or when the requested status is outside `draft`, `publish`, `pending`, and `private`.
