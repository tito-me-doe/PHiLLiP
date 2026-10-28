# Recursive workflow implementation contract

This is a working implementation brief, not a second workflow framework.

## Invariants

- A Project contains ordinary recursive ToDos; every executing ToDo owns one thread.
- Its assignment is fixed during execution. A runtime agent can create/refine only direct children it created, never itself, siblings, human-created tasks, or ancestry.
- Planning agents can continue managing the unstarted plan. Runtime edits are scoped and must not invalidate the entire project's Start authorization.
- Children must be fully specified and receive an ephemeral, zero-tool readiness check before their first dispatch at a new revision. Existing project preflight can approve initial revisions.
- Parent threads yield and resume with bounded direct-child output and inbound decisions, not sibling conversations. Waiting/resuming does not consume failed-attempt budget.
- Child completion never implies parent completion. Every node's current revision gets independent completion-gate verification.
- Decision requests target the immediate parent; only root requests require human response. A parent can resolve within its assignment or escalate further.
- Revision requests must stop/cancel an affected subtree and wait for terminal runs before changing definitions. Never wait inside a row-lock transaction. Return an explicit pending-stop response if necessary; no partial definition edits.
- Changed assignments invalidate subtree/ancestor active evidence and reviews. Historic events remain history, never current evidence. Stale runs/reviews cannot commit against a newer revision.
- Depth, node count, and continuation turns are bounded. No implicit permission grants or workspace/Delegate tools.

## Shared surface

Keep existing Project add/update/remove APIs and planning behavior. Introduce a small ProjectWorkflow helper if needed for substantial recursive logic; no generic orchestration framework.

Migration `database/018_recursive_projects.sql` adds:

- `project_todos.creator_todo_id` nullable, immutable provenance; null means human/planning-created (not runtime-agent editable).
- `revision` bigint default1, `run_revision` nullable, `ready_revision` nullable, `readiness` JSON object.
- `wait_reason` nullable (`children` or `decision`), `continuations` integer default0.
- Extend status CHECK with `waiting`.
- `project_decisions`: id, project_id, todo_id (requester), target_todo_id nullable (human), todo_revision, question, evidence JSON object, answer nullable, answered_by nullable, created_at/answered_at. Same-project references enforced.

Domain API implemented by `ProjectWorkflow(Project $project)`:

- `current()` validates actual ToolContext thread/run against working ToDo, its current run pointer and matching run_revision/revision.
- `addChild(array $input)` creates a fully specified direct child, forces parent/current creator, bounded tree; no client-supplied protected fields.
- `reviseChild($id,array $changes)` requires direct child created by current task; fixed ancestry/provenance, stop barrier, revision/evidence invalidation; returns `{pending_stop:true}` when live runs remain, otherwise updated row.
- `waitForChildren()` records a yield intent while current run finishes normally; does not mark completed or stop it mid-tool-protocol.
- `escalate($question,array $evidence=[])` records current-revision question addressed to immediate parent and yield intent.
- `answer($decisionId,$answer,$actorId=null)` runtime only by target parent; human only for target=null through existing CLI/frontend policy boundary. Must reject stale questions and invalid task authority.
- `decisions($todoId=null)` returns project-wide for authorized human/root planning; task-scoped inbound/outbound when an execution context is active.
- Public narrowly scoped runner helpers are allowed, but model tools never accept arbitrary current-task identity.

Tools\Project delegates runtime addTodo/updateTodo to addChild/reviseChild, planning calls remain existing behavior. Add public tools waitForChildren, escalate, answerDecision. report/blocked must use current revision; blocked escalates locally rather than immediately blocking unrelated work.

## Runner integration

- Preserve existing preplanned child-before-parent scheduling for compatibility. A task with no children can execute and create children recursively.
- Reconcile a completed agent turn with unfinished children/yield intent into waiting, not gate review. Waiting nodes do not occupy the one-active-work slot.
- Wake a waiting/pending parent on an inbound open decision, even with unfinished children. Wake a waiting child when its question is answered. Wake waiting parents when all direct children are done.
- Resume the same thread with current assignment, child summaries, current decisions, and gate feedback. Do not replay superseded-revision assignment histories.
- Distinguish continuations from failed gate attempts; continuations capped64 per node, total nodes capped256, depth32. No new model transport or worker.
- Dynamic readiness check uses `kind=project.todo_preflight`, requires exact `ready`, records revision-bound findings. Gaps escalate to parent, never manufacture readiness.
- Include todo revision in execution_context and every dispatch/review CAS. Use current child revision digest or lock/revalidate children before accepting a parent review.
- Root completion means all top-level deliverables independently passed current gates. Never invent a root goal from just project title.

## Integration ownership

Domain agent: Project.php, new ProjectWorkflow.php, migration018, tests/project_workflow.php.
Runner agent: ProjectRunner.php, tests/project_recursive.php, tests/fixtures/project_recursive_engine.php.
Interface agent: Tools/Project.php, ProjectCli.php, ProjectFrontend.php, views/projects.php, Controller.php, docs/projects.md and focused interface tests. Coordinate method names above; no edits to other owners' files.
Coordinator: Run.php history/revision guard integration, shared tests/fixtures compatibility, worker reload/migration, independent review and end-to-end dogfood. TUI implementation assigned separately after APIs stabilize.

## Acceptance

Actual tool-driven depth3 creation; forbidden self/sibling/foreign/human edits; nearest-parent escalation and human-root resolution; in-flight revision barrier and stale result/review refusal; failed parent gate despite passing children; continuation budget; crash/restart and duplicate tick safety; existing frontend ACL/tool ceilings; host and Docker regressions; live Nemotron recursive project. A small read-only PHP TUI monitor exercises the shared durable state without granting workspace authority.
