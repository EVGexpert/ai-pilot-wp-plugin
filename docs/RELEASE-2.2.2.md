# AI Pilot — Remote Site API 2.2.2

## Purpose

Version 2.2.2 is a stabilization release based on the complete working 2.2.0 source tree.

It fixes category and tag assignment for AI-created posts without changing the approved proposal flow, the WordPress admin interface or the connection token.

## Supported taxonomy input

Categories and tags accept:

- numeric IDs;
- names;
- mixed arrays;
- comma-, semicolon- or newline-separated strings;
- singular fields `category` and `tag`;
- plural fields `categories` and `tags`.

Missing category names are created through `wp_insert_term()`. Missing tag names are created by WordPress when `wp_set_post_tags()` is called.

## Safe installation

1. Keep the current working plugin active.
2. Open **Plugins → Add Plugin → Upload Plugin**.
3. Upload `ai-pilot-wp-plugin-2.2.2.zip`.
4. WordPress must show that the destination plugin already exists.
5. Choose **Replace current with uploaded**.
6. Do not activate a second copy under another folder name.
7. Confirm that **AI Pilot** remains present in the main admin menu.
8. Confirm `/wp-json/aipilot/v1/ping` reports version `2.2.2`.

## Rollback

Keep a copy of the previous 2.2.0 ZIP. If activation or API checks fail, replace 2.2.2 with the 2.2.0 archive.
