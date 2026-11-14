# Project skills

Skills for coding agents working on this package. Claude Code loads them
from `.claude/skills/`. They are adapted from
[mattpocock/skills](https://github.com/mattpocock/skills) (MIT, © Matt Pocock),
commit `5863806`. The `pr` template's summary visuals come from
[Dex Horthy's `show-me` skill](https://github.com/humanlayer/humanlayer), via the `pr` skill.

| Skill | Use it to | Changes from the original |
| --- | --- | --- |
| [`tdd`](tdd/SKILL.md) | Build or fix test-first | PHPUnit/Testbench, this package's seam table, assert on rendered Prometheus text, two-adapter rule for `memory`/`redis` |
| [`diagnosing-bugs`](diagnosing-bugs/SKILL.md) | Debug wrong metrics, endpoint, Redis or cleanup bugs | Feedback loops for PHPUnit, real `redis-server`, the Laravel version matrix; common suspects in this package |
| [`code-review`](code-review/SKILL.md) | Review a branch or PR | Standards come from `CLAUDE.md` plus a package checklist (breaking changes, docs, both adapters, support matrix); spec comes from GitHub; no `setup-matt-pocock-skills` dependency |
| [`pr`](pr/SKILL.md) | Write a PR body | Scrape-output diffs as the main visual; one-way doors are metric, label and Redis key changes; adds a Changelog section |

`GLOSSARY.md` references in the originals point to the Vocabulary section of
`CLAUDE.md` here.

## Not adopted

- **Issue-tracker flow** (`setup-matt-pocock-skills`, `triage`, `to-spec`,
  `to-tickets`, `implement`, `implement-spec`, `wayfinder`): built for larger
  projects with a ticket workflow. This package is small enough that a
  GitHub issue plus `tdd` covers it.
- **Personal or general skills** (`grill-me`, `grilling`, `handoff`,
  `writing-for-agents`, `research`, `prototype`, `retro`): useful, but not
  specific to this package. Install them globally if you want them.
- **`codebase-design`, `improve-codebase-architecture`, `domain-modeling`**:
  more than this package needs; the vocabulary lives in `CLAUDE.md`.
- **Misc** (`setup-pre-commit`, `git-guardrails-claude-code`,
  `migrate-to-shoehorn`, `scaffold-exercises`): JavaScript/TypeScript tooling
  or unrelated to the package.
