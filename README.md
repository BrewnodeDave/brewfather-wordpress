# Brewfather Sync — WordPress Plugin

Automatically creates a WordPress blog post with full recipe details whenever you send a batch from [Brewfather](https://brewfather.app). Uses the **Brewfather API V2** to fetch complete batch data.

## How It Works

1. You trigger a send from Brewfather (or any HTTP client) with just the batch `_id`.
2. The plugin calls the **Brewfather REST API V2** (`GET /v2/batches/:id?complete=true`) using your stored credentials to retrieve the full batch data.
3. A formatted blog post is published automatically — no manual effort required.

**WordPress REST endpoint:**
```
POST /wp-json/bf-sync/v1/post-batch
```

**Request body:**
```json
{ "_id": "<brewfather_batch_id>" }
```

## Features

- **API V2 powered** — Fetches complete, authoritative batch data directly from Brewfather using authenticated API calls.
- **Automatic duplicate prevention** — Stores the Brewfather batch ID in a hidden custom field (`_bf_batch_id`). If the same batch is received again, it is silently ignored.
- **Full recipe detail** — Publishes grain bill, hop schedule, yeast, ABV, and brewer's notes.
- **Fermentation graph** — Interactive Chart.js temperature graph rendered below each post, showing Fermenter, Glycol (fridge), and Ambient (room) temperature series where data is available.
- **Zoom & pan** — Scroll wheel zoom, pinch-to-zoom (touch), and drag-to-pan along the time axis, with a Reset Zoom button.
- **Refresh readings** — Admin tool to re-fetch fermentation data for any existing post by WordPress post ID.
- **Auto-tagging** — Post is tagged with the beer style and style category from Brewfather.
- **Beer colour swatch** — EBC colour rendered as an inline swatch next to the style name and in the stats table.
- **Gutenberg compatible** — Post content uses standard HTML that renders correctly in the block editor.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- A Brewfather account with API access (free accounts have API access)

## Installation

### Option A — FTP / SSH File Transfer

1. Connect to your server via FTP (e.g. FileZilla) or SSH/SFTP.
2. Create the folder `/wp-content/plugins/brewfather/` on your server.
3. Upload `brewfather.php` into that folder.
4. In your WordPress Admin, go to **Plugins → Installed Plugins**.
5. Find **Brewfather Sync** in the list and click **Activate**.


### Option B — WP-CLI

```bash
wp plugin activate brewfather
```

## Configuration

### 1. Generate a Brewfather API Key

1. In Brewfather, open **Settings → Power-ups → API**.
2. Click **Generate** to create an API key.
3. Enable the **Read Batches** scope.
4. Note your **User ID** (shown above the key).

### 2. Enter Credentials in WordPress

1. In WordPress Admin, go to **Settings → Brewfather Sync**.
2. Enter your **Brewfather User ID** and **API Key** (see below for where to find these).
3. Click **Save Changes**.

#### Finding your User ID and API Key

| Field | Where to find it |
|---|---|
| **User ID** | Brewfather **Settings → Account** — shown as *User ID* near the top of the page |
| **API Key** | Brewfather **Settings → Power-ups → API** — click **Generate** if you haven't already, then copy the key shown |

> The API key is only displayed once at generation time. Copy it immediately and store it somewhere safe. If you lose it, generate a new one (this invalidates the old key).

Make sure the key has the **Read Batches** scope enabled — no other scopes are required for this plugin.

### 3. Configure Brewfather Custom Endpoint

Brewfather's **Custom Endpoint** power-up lets you push a batch to your WordPress site directly from the batch view.

#### Enable it in Brewfather

1. In Brewfather, go to **Settings → Power-ups**.
2. Scroll to **Custom Endpoint** and toggle it on.
3. In the **HTTPS URL** field, enter your WordPress endpoint:
   ```
   https://your-site.com/wp-json/bf-sync/v1/post-batch
   ```
4. Leave **Send method DELETE before POST** disabled (the plugin handles duplicates itself).

#### Send a batch

Once the endpoint is configured, open any batch in Brewfather and either:

- Tap the **Send JSON** button in the batch header, or
- Open the export action sheet and choose **Send Batch JSON**.

Brewfather will POST `{"_id":"<batch_id>"}` to your endpoint, and the plugin will fetch the full batch details from the API and publish the post.

> **Note:** Custom Endpoint requires a Brewfather **Premium** subscription.

## Testing

Send a batch ID to the endpoint and verify a post is created:

```bash
curl -X POST https://your-site.com/wp-json/bf-sync/v1/post-batch \
  -H "Content-Type: application/json" \
  -d '{"_id":"<your_brewfather_batch_id>"}'
```

A `{"success":true,"post_id":123}` response confirms everything is working.

## Refresh Fermentation Readings

If a post was created before API credentials were saved, its graph will be empty. To backfill readings:

1. Go to **Settings → Brewfather Sync**.
2. Scroll to **Refresh Fermentation Readings**.
3. Enter the WordPress post ID and click **Refresh Readings**.

The plugin will re-fetch the readings from the Brewfather API and update the graph.

## Graph

Each post with fermentation data shows an interactive Chart.js line graph below the content with up to three temperature series:

| Series | Brewfather field | Colour |
|---|---|---|
| Fermenter | `temp` | Blue |
| Glycol | `fridgeTemp` | Cyan |
| Ambient | `roomTemp` | Green |

A series is omitted if the batch has no data for it.

**Controls:** scroll to zoom, click-and-drag to pan, **Reset Zoom** button to restore the full view.