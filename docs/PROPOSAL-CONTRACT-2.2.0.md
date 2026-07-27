# Proposal contract — version 2.2.0

## Create proposal

`POST /wp-json/aipilot/v1/agent/propose`

```json
{
  "action": "create_post",
  "description": "Create a draft article",
  "params": {
    "title": "Article title",
    "content": "<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->",
    "status": "draft"
  }
}
```

The route also accepts the older nested form for `create_post`:

```json
{
  "action": "create_post",
  "params": {
    "target": {
      "title": "Article title",
      "content": "<p>Content</p>"
    },
    "patch": {
      "status": "draft"
    }
  }
}
```

Both forms are normalized and stored as flat proposal params.

Expected response:

```json
{
  "proposal": {
    "id": "uuid",
    "action": "create_post",
    "status": "pending",
    "params": {
      "title": "Article title",
      "content": "<p>Content</p>",
      "status": "draft"
    }
  }
}
```

Creating a proposal must not create a WordPress post.

## Approve proposal

`POST /wp-json/aipilot/v1/agent/approve/{proposalId}`

The request body is ignored for action execution. The plugin loads the action and params already stored in the proposal.

Expected successful response:

```json
{
  "approved": true,
  "status": "completed",
  "proposal": {
    "id": "uuid",
    "status": "completed",
    "result": {
      "success": true,
      "id": 125,
      "post_id": 125,
      "type": "post",
      "status": "draft",
      "url": "https://example.com/?p=125"
    }
  }
}
```

## Idempotency

Calling approve again for the same completed proposal returns the stored result. It must not execute the action a second time. A database-backed lock also rejects overlapping approval requests with HTTP 409 while the first request is processing.

## Status transitions

```text
pending → processing → completed
                    ↘ failed
pending → rejected
```

A failed, rejected, or processing proposal cannot be approved again automatically.
