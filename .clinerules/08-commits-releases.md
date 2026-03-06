# Releases & Commit Conventions

This project uses **semantic-release** with **Conventional Commits**.

## Commit Message Format

```
type(scope): Description starting with a capital letter

Optional longer body explaining the what and why.

BREAKING CHANGE: Description of the breaking change (triggers a major release).
```

- `scope` is **optional** — omit the parentheses when no scope applies: `type: Description`
- The **description** must be written as a proper sentence: first word capitalised, natural prose, no trailing period
- The **body** is optional but encouraged for non-trivial changes
- `BREAKING CHANGE:` in the footer triggers a **major** release; include a clear explanation

## Release Impact by Type

| Type       | Release triggered |
|------------|-------------------|
| `feat`     | Minor             |
| `fix`      | Patch             |
| `perf`     | Patch             |
| `refactor` | Patch (if configured) |
| `chore`, `docs`, `test`, `build`, `ci` | No release |
| Any type + `BREAKING CHANGE` footer | Major |

## Examples

```
feat(engine): Add batch size configuration option

fix: Correct sale price rounding for variable products

chore(deps): Update WooCommerce stubs to 9.4

feat!: Replace post-meta storage with post_content JSON

BREAKING CHANGE: All existing job records must be migrated before upgrading.
```

## Rules

- **Never** use all-lowercase imperative descriptions — always capitalise the first word
- **Never** end the subject line with a period
- Keep the subject line under 72 characters
- Reference issues/PRs in the body or footer when relevant (`Closes #42`)
