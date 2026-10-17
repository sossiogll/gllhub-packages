# gllhub/medium

Brings a Medium author's articles into gllHub as `article` entries (defined by `gllhub/portfolio`).

## Source: `feed`

| Setting | |
| --- | --- |
| `username` | the author, with or without `@` |
| `language` | `en` or `it`, the language of the articles |

- Reads `https://medium.com/feed/@<username>` through the core's HTTP client, every hour by default.
- Medium puts only the latest ten articles in the feed, so the source declares `listsEverything: false`: the core
  keeps what it already has and never removes an article because it left the feed.
- Each item becomes an entry: the id from the guid (stable across renames), slug and canonical URL from the link,
  summary from the first paragraph, reading time from the text, tags from the categories, the first image as
  cover. The body is passed as HTML; the core sanitizes and converts it.
- Member-only articles arrive as the preview Medium puts in the feed.
- An unknown author is an `InvalidConfiguration` on `username`; a page that is not a feed is `Unavailable`.

## Not yet

Importing the full archive (Medium's data export) is a separate step: an import into the core, not a source.
