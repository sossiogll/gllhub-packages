# gllHub packages

Everything [gllHub](https://github.com/sossiogll/gll-hub/tree/dev) talks to, and every kind of content it
holds, comes from a package. The core knows none of them by name; each package depends only on
`gllhub/contracts`, which lives with the core.

| Package | What it adds |
| --- | --- |
| [`portfolio`](portfolio) | Content types for a personal site: project, idea, article, page, resume |
| [`medium`](medium) | Source: a Medium author's latest articles, from their RSS feed |
| [`github`](github) | Source and webhook: an account's showcase repositories, as projects |
| [`json-resume`](json-resume) | Source: a CV from a JSON Resume file |

Writing a package: read [`CLAUDE.md`](CLAUDE.md).
