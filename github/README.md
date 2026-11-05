# gllhub/github

Brings an account's showcase repositories into gllHub as `project` entries (defined by `gllhub/portfolio`).

## Source: `repositories`

| Setting | |
| --- | --- |
| `account` | the GitHub account, written exactly as GitHub writes it |

| Credential | |
| --- | --- |
| `token` | a token with read access to public repositories and to Projects (`read:project`) |

- Lists the account's **public** repositories, forks included, and keeps those with the `showcase` topic. The listing
  is complete, so a repository that loses the topic or goes private leaves the site.
- Reads the version of the **latest release**, or the default branch for a repository without releases. A showcase
  file added after the latest release is read from the next one.
- Runs every six hours by default, and at once when the webhook names a repository.

### `.github/showcase.md`

```markdown
---
title: my-project
claim: One line that says why it exists.
summary: One line that says what it is.
color: "#D4FF3A"
cover: ./docs/cover.png
since: 2022
demo: video               # widget | video | image
demo_media: ./docs/demo.mp4   # for video and image
# demo_widget: playground     # for widget: the name of a widget of the site, which gllHub does not read
milestone: v1.0
features:
  - title: It does this
    text: In this way.
    icon: shapes
decisions:
  - what: A choice
    why: The reason.
related: [Another project]
---

The free text: why it was made.
```

- `title` and `summary` are optional: without them, the repository's name and description.
- The other keys are the fields of the `project` type. Curated fields (`featured`, `position`, `card_claim`) and what
  comes from GitHub (`repository`, `languages`, `stars`, `version`, `readme`, `releases`, `milestones`, `docs`,
  `discussions`, `boards`, `images`) cannot be written in the file.
- `milestone` must name a milestone of the repository.
- `demo: widget` needs `demo_widget`.
- `related` names projects by title or by slug. A name counts once it is in slug form, so `b5srl.eu` finds a project
  with that title, whose slug is `b5srleu`, and `b5srl-eu` does not.
- The body is kept as it is, HTML comments included: a frontend may give them a meaning.
- `.github/showcase.it.md` is the Italian version: what it says wins, what it leaves out comes from the main file. It
  says only what can be translated (`title`, `summary`, `claim`, `features`, `decisions`, the body), and any other key
  makes it refused. It is read only when the main file exists.

**A file that cannot be accepted changes nothing.** The source sends an entry the contract's rules refuse, so the core
reports it and keeps what it already has, and it logs the reason as `Showcase file refused`.

### Without the file

The title is the repository's name, the summary and claim its description, the body the README's first paragraph and
the cover the README's first image that is not a badge. Without a description, the claim is that first paragraph. A
repository with neither has no claim: the core refuses it and reports it.

### Images

`cover`, `demo_media` and the README's first image go to the media library. Relative images in the body and in the
README are made absolute in the text and sent as `images`, so the core copies them too; serving those copies in place
of the links in the text is the job of whoever serves the text. Relative paths start at the root of the repository. A
reference-style Markdown image (`![alt][id]`) is left as it is.

## What comes from GitHub

- **Milestones**, each with its issues and pull requests in `items`.
- **Docs**: every Markdown file under `docs/`, at the version it reads, at most 100. The title is the first `#` heading,
  or the file name. Relative images start at the file's folder. Links between pages are left as they are.
- **Discussions**, all of them, each with its comments; replies to a comment are only counted (`reply_count`). They are
  a read-only copy: answering happens on GitHub.
- **Boards**: the public, open GitHub Projects linked to the repository, with what is still open on them (issues, pull
  requests, drafts) and each one's `Status`. An item of a private repository stays out even if the token can read it.

A list that cannot be read to its end fails the run: a shorter list would look like things were removed.

## Webhook: `repository`

Serve it at `POST {publicUrl}/webhooks/gllhub/github/repository`, with content type `application/json`, the `secret`
credential as its secret, and the events `push`, `release`, `milestone`, `discussion`, `discussion_comment`, `issues`
and `pull_request`. GitHub has no webhook for a personal account: it is set on each repository.

Of `issues` and `pull_request`, only a change of milestone counts. A change on a Project sends no repository webhook:
boards refresh with the periodic sync.

The signature in `X-Hub-Signature-256` is checked, and without a configured secret nothing is accepted. A push to a
branch other than the default one is ignored.

## Not yet

- Setting the repository's Website field: the contract has no way for a source to write back.
- The reusable GitHub Action that validates the file on each pull request.
- Listing a language other than Italian: `ShowcaseRepositories::TRANSLATIONS` holds the list.
