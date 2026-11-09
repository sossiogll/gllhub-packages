# gllhub/json-resume

Brings a CV into gllHub as a `resume` entry (defined by `gllhub/portfolio`), from a
[JSON Resume](https://jsonresume.org/schema) file at a public URL.

## Source: `file`

| Setting | |
| --- | --- |
| `url` | a public https address that answers with the JSON |
| `language` | the language of the file, `en` by default |
| `pdf_url` | optional: a PDF of the CV, copied into the media library |

- Runs once a day by default. The entry's slug is always `cv`.
- A second language is a second instance, with its own file: they share a translation key.
- **It takes only what it names:** `basics.name` (title), `basics.label` (summary), `basics.summary` (body), `work`,
  `education`, `languages`, `interests`, and `meta.lastModified` as the publication date when there is one. An email, a phone number or a birth
  date in the file never reaches the site. A PDF does not get this protection: it is public as it is.
- `highlights` of a job become a list after its `summary`.
- Keys outside the JSON Resume schema, read when present:
  - on a job, `employmentType` (`full-time`, `freelance` or `apprenticeship`) and `skills`, a list of `{ name, keywords }`;
  - on a course, `skills`, the same list;
  - on a language, `levels`, an object with `reading`, `writing`, `listening` and `speaking` from `A1` to `C2`. It becomes
    the list `levels` of the language, `[{ skill, level }]` in that order, with only the skills the file gives; with
    none, the language has no `levels`.
- A document that cannot be accepted changes nothing: the source sends the CV as refused, with the reason, which
  reaches the admin as the source's `last_error` while the old content stays. The reason is also logged as `Resume refused`. A date must be `YYYY`, `YYYY-MM` or `YYYY-MM-DD`.
- An unknown address is an `InvalidConfiguration` on `url`, and so is a page that is not such a document.
