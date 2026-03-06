# Task Management

Tasks are tracked as Markdown files inside the `tasks/` folder.

## File Naming

Files are named using a state prefix and a numeric ID:

| State       | File name pattern       |
|-------------|-------------------------|
| In progress | `current-{ID}.md`       |
| Completed   | `completed-{ID}.md`     |
| Failed      | `failed-{ID}.md`        |

IDs are integers, auto-incrementing from the highest existing ID in `tasks/`.

## Workflow

1. **"New task" trigger** — when a user prompt starts with `new task`, the very first thing to do upon entering Act mode is determine the next available ID and create `tasks/current-{NEXT_ID}.md` with the full implementation plan (objective, ordered steps, acceptance criteria). Do this **before** writing any code.
2. **"Continue task: {task_number}" trigger** — when a user prompt starts with `continue task: {task_number}`, open the corresponding `current-{task_number}.md` file and review the implementation plan before proceeding with any work.
3. **During work** — keep the task file updated with progress notes as steps are completed.
4. **On completion** — rename the file from `current-{ID}.md` to `completed-{ID}.md` (or `failed-{ID}.md` if the task could not be finished), add a brief outcome summary, then commit all changes to git.
5. **Commit timing is mandatory** — create that commit immediately after finishing each task (completed or failed). Do not batch multiple finished tasks into one later commit.

## Task File Structure

```markdown
# Task {ID}: {Short title}

## Objective
One-paragraph description of what needs to be done and why.

## Implementation Plan
- [ ] Step 1
- [ ] Step 2
- [ ] ...

## Outcome
(Filled in on completion — what was done, any deviations from the plan, follow-up notes.)
```
