# Changelog

## 2.2.0 — 2026-07-27

### Added
- Top-level **AI Pilot** section in the WordPress admin menu.
- **Open AI Pilot** shortcut in the WordPress Plugins list.
- Modern onboarding dashboard with connection, assistant, permissions and system pages.
- One-click connect flow with a five-minute one-time code.
- Automatic popup close and dashboard transition after successful authorization.
- Admin-only connection status endpoint for safe polling.
- Executable proposal approval flow with stored results and idempotent replay.
- Canonical action result format for `create_post`.
- Validation for title, content and post status before WordPress writes.

### Changed
- Connection tokens remain provisional until the one-time code is verified.
- Reconnect no longer invalidates a working token before authorization completes.
- `/agent/approve/{id}` now executes the saved action instead of only changing status.
- `/agent/action` remains available for legacy clients and uses the same action executor.
- Legacy Settings URL redirects to the new top-level admin section.

### Security
- Connect-code creation and connection-status polling require a WordPress administrator and REST nonce.
- Approval executes only the action and params saved in the proposal.
- Repeated approval returns the stored result and does not create a duplicate post.
- A database-backed per-proposal lock blocks simultaneous approve requests.
- Disconnect revokes the active token and removes pending connection codes.
