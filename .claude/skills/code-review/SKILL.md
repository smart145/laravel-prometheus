---
name: code-review
description: "Review the changes since a fixed point (commit, branch, tag or merge-base) on two axes: Standards (does the code follow this package's standards in CLAUDE.md?) and Spec (does it do what the GitHub issue or PR asked?). Runs both reviews as parallel sub-agents. Use when the user wants to review a branch, a PR or work in progress, or asks to \"review since X\"."
---

Two-axis review of the diff between `HEAD` and a fixed point:

- **Standards**: does the code follow this package's standards?
- **Spec**: does the code do what the originating issue or PR asked?

Run both axes as **parallel sub-agents** with fresh context, then put their
reports side by side.

## Process

### 1. Pin the fixed point

Use what the user named (SHA, branch, tag, `main`, `HEAD~3`). If they named
nothing, use `origin/main`. Check it resolves (`git rev-parse <ref>`) and
the diff is not empty before you go on.

- Diff: `git diff <ref>...HEAD`
- Commits: `git log <ref>..HEAD --oneline`

### 2. Find the spec

In this order:

1. Issue or PR references in the commits (`#12`, `Closes #12`) on
   `smart145/laravel-prometheus`, fetched with the GitHub tools.
2. The open PR for the current branch: its description.
3. A path the user gave.
4. Otherwise ask. If there is no spec, skip the Spec axis and say so.

### 3. Collect the standards

Standards sources, always included:

- `CLAUDE.md` (coding standards, vocabulary, test rules).
- `CHANGELOG.md` format (Keep a Changelog, entries under `[Unreleased]`).
- `.github/workflows/ci.yml` (the PHP/Laravel support matrix).

Package checklist (hard rules unless the diff says why not):

- **Breaking changes**: a renamed or removed metric name, label name,
  config key, env var, facade method, route name (`prometheus.metrics`),
  artisan signature or Redis key format (`PROMETHEUS_*`) is breaking.
  It must be called out and listed in `CHANGELOG.md`.
- **Docs**: a new config key has an `env()` default, a comment block in
  `config/prometheus.php`, an entry in `README.md` and a `CHANGELOG.md` line.
- **Both storage adapters**: a change to rendering, wipe or scrape time
  works for `memory` and `redis`, and is tested for both or the gap is named.
- **Scrape safety**: one bad metric or callback must not fail the endpoint.
- **Support matrix**: no API that is missing on PHP 8.2 or on any of
  Laravel 11, 12 or 13.
- **Tests**: new behaviour has a test that asserts on rendered output
  (see the `tdd` skill). No mocks of the package's own classes.
- **Security**: the endpoint still honours `enabled` and `allowed_ips`;
  wipe cannot be triggered unless configured.

Smell baseline (Fowler, *Refactoring* ch. 3). Always a judgement call; a
documented standard overrides it. Skip what tooling already enforces.

- **Mysterious Name**: name does not say what it does. → rename.
- **Duplicated Code**: same logic in more than one hunk (for example the
  three builders). → extract the shared shape (a trait like `ValidatesLabels`).
- **Feature Envy**: a method uses another object's data more than its own. → move it.
- **Data Clumps**: the same parameters travel together (name, help, label
  names). → bundle them.
- **Primitive Obsession**: a string stands in for a concept (storage driver,
  mismatch behaviour). → give it a type or constants.
- **Repeated Switches**: the same `instanceof LaravelRedisAdapter` check in
  several places. → move the behaviour behind the adapter.
- **Shotgun Surgery**: one change edits many files. → gather it.
- **Divergent Change**: one file edited for unrelated reasons. → split.
- **Speculative Generality**: options or hooks no one asked for. → delete.
- **Message Chains**: long `a()->b()->c()` walks. → hide behind one method.
- **Middle Man**: a class that only delegates. → call the target.
- **Refused Bequest**: a subclass that ignores what it inherits. → compose.

### 4. Spawn both sub-agents in parallel

**Standards sub-agent** gets: the diff command, the commit list, the
standards sources, and the full checklist and smell baseline above. Brief:
"Report per file/hunk (a) each breach of a documented standard or checklist
rule, citing the rule; (b) each baseline smell, named, with the hunk quoted.
Mark checklist breaches as hard and smells as judgement calls. Under 400
words."

**Spec sub-agent** gets: the diff command, the commit list and the spec
text. Brief: "Report (a) requirements missing or partial; (b) behaviour
nobody asked for; (c) requirements that look implemented but wrong. Quote
the spec line for each. Under 400 words."

### 5. Run the tests

While the sub-agents work, run `vendor/bin/phpunit` and note the result.

### 6. Report

Show `## Standards`, `## Spec` and `## Tests` sections. Do not merge or
re-rank findings across axes. End with one line: findings per axis and the
worst issue in each.
