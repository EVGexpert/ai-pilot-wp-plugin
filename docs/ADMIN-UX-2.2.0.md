# WordPress admin interface — version 2.2.0

## Navigation

AI Pilot appears in the main WordPress administration menu. Administrators no longer need to search for the plugin under **Settings**.

The previous `options-general.php?page=ai-pilot-settings` URL redirects to the new section. The Plugins list also includes an **Open AI Pilot** shortcut.

## First connection

The connection page shows:

- a short explanation of what will happen;
- a single primary **Connect AI Pilot** button;
- three simple setup steps;
- a security note about the one-time code.

The button opens the authorization window immediately so browser popup blockers do not interfere. WordPress then creates a one-time connection code and sends the popup to the AI Pilot authorization page.

While authorization is in progress, the WordPress page displays:

- current status;
- one-time code;
- expiry countdown;
- a link to reopen the window if it was closed.

After the exact code is consumed, the popup closes automatically and the page reloads into the connected state.

## Connected state

The onboarding window is removed and replaced by:

- connection status;
- site hostname;
- connection date;
- plugin version;
- button to open AI Pilot;
- controlled reconnect action;
- explicit disconnect and token revocation action.

## Reconnect behavior

An already connected site remains operational while the administrator completes a new authorization. Polling is tied to the newly generated code, so the popup cannot close merely because an older token is active.

## Styling

The interface is responsive and uses native WordPress assets only. It does not load external fonts, images, trackers or frontend frameworks.
