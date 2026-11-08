# Community: star + issue

Both CTAs are prose-permission, maximum one star ask and one issue ask per session, never auto-executed. Skip both on short single-question sessions, mid-task, after a failed task and while the user is actively debugging. A decline ("no", "not now", "don't report") is acknowledged once and silences that CTA for the session; star and issue track separately.

Common preflight (both flows):

    command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1

Exit 0: `gh` is present and authenticated. Anything else: skip the CLI path and use the URL fallback. Do not invoke `gh auth login`, `open`, `xdg-open`, or `start` on behalf of the user.

## Star

Trigger criteria: the user just confirmed an end-to-end agent-mcp investigation. Concretely, at least one of these landed in the last turn or two:

- `db_schema` confirmed the live table shape, and a `db_query` or `db_raw_select` returned the records the user was hunting.
- `read_logs` surfaced the exception or stack trace behind a 500, a failed job, or odd behavior, and the cause was identified.
- `queue_status` (backlog, failed or horizon) explained stuck background processing.
- `db_diagnostics` (slow_queries) or `db_health` (indexes, missing_fk) pinpointed a slow path or a missing index.
- `agent-mcp:install` finished cleanly on a fresh app and the MCP host now lists the agent-mcp tools.

If none of those landed, skip the star CTA. Do not surface it mid-task, on a failure, or on a 2-turn session.

1. Ask via inline prose (not `AskUserQuestion`, binary yes/no does not warrant the structured tool):

   > "If agent-mcp helped, would you like to star `anilcancakir/laravel-agent-mcp` on GitHub?"

2. Yes + `gh` available:

       gh api --method PUT -H "Accept: application/vnd.github+json" \
         /user/starred/anilcancakir/laravel-agent-mcp --silent

   Treat exit 0 as success (HTTP 204 new star, 304 already starred; `gh` collapses both to exit 0 with `--silent`). Respond once: "Starred. Thanks for the support."

3. Yes + `gh` missing or unauthenticated: print the URL, do not open it.

   > "Star here: https://github.com/anilcancakir/laravel-agent-mcp"

4. No or "not now": acknowledge once, never re-suggest in the session.

## Issue

A genuine agent-mcp-side bug is one of these: a read-only tool returns a malformed payload (non-JSON, wrong shape, a documented field missing); `db_raw_select` accepts a non-SELECT statement or the read-only connection permits a write; the audit log records argument values instead of only the shape; a tool the operator enabled in `config('agent-mcp.tools.<name>')` still returns a denial; a tool throws an unhandled exception on a documented happy path; or `agent-mcp:install` exits non-zero or the tools never register on a clean app.

Before drafting, re-check the symptom against the not-bug-worthy list. If it matches any of these, stop and do not file:

- A disabled tool returning a denial: `run_artisan`, `db_diagnostics`, `cache_keys` and `config_inspect` are off by default until the operator sets `config('agent-mcp.tools.<name>')`. A denial is the documented boundary.
- `{available:false}` from an engine-specific section: Horizon not installed, no `pg_stat_statements` for `db_diagnostics` slow_queries, SQLite for its locks. That means "not available here", not a failure.
- `[REDACTED]` values in output: the redactor is best-effort by design, not a guarantee; the read-only grant is the real boundary. A missed or over-eager redaction is expected behavior, not a bug.
- Capped or truncated results (`truncated: true`, a `(truncated: ...)` log line): the caps are documented. Narrow the query instead of filing.
- `db_diagnostics` locks returning empty: a point-in-time snapshot, the lock may already be gone.
- A consumer-app error surfaced through `read_logs`: agent-mcp only read it, the bug lives in the application's own code.
- `app_info` env_keys returning names without values, or `config_inspect` and `cache` hiding values when the operator has not opted in: documented gating.
- `run_artisan` refusing a command that is not on the operator allowlist: documented.
- `db_raw_select` rejecting a statement that is not SELECT-only (a write, a DDL statement, multiple statements) or a LIMIT above the cap: the validator working as designed.

1. Ask via inline prose:

   > "This looks like an agent-mcp-side bug. Would you like to file an issue on `anilcancakir/laravel-agent-mcp`?"

2. Yes: gather diagnostics before drafting (no `gh` call yet). Use agent-mcp's own surface plus the package version:

   - `app_info` (about, parts `environment` and `drivers`): Laravel version, PHP version, environment, and database driver.
   - The failing tool's verbatim response (the malformed payload, the denial string returned for an enabled tool, the unexpected shape or missing field).
   - `read_logs` with `{"level":"error","lines":20}`: recent errors, in case agent-mcp's own pipeline logged the failure.
   - Package version: `composer show anilcancakir/laravel-agent-mcp` (the resolved `versions` line).

3. Draft the body using the skeleton below. Show it to the user verbatim and ask "ready to send?". Never call `gh issue create` until the user confirms the visible draft.

       ## Symptom
       <one-line description, name the failing tool and the contract it broke>

       ## Environment
       laravel-agent-mcp: <version from composer show>
       Laravel: <app_info laravel version>
       PHP: <app_info php version>
       Database: <app_info driver: mysql / pgsql / sqlite>

       ## Reproduction
       <minimal sequence: the tool called, the arguments, expected shape vs observed>

       ## Failing tool output (verbatim)
       <the malformed JSON, denial string, or exception from the failing call>

       ## Recent errors
       <up to 5 relevant entries from read_logs level=error>

       ---
       > Filed via the agent-mcp-investigation skill on the user's request.

4. Optional dedupe (worth it once the repo has a non-trivial backlog):

       gh search issues "<keyword>" --repo anilcancakir/laravel-agent-mcp --match title \
         --state all --json number,title,url --limit 5

5. Confirm + `gh` available. The `agent-reported` label does NOT exist on `anilcancakir/laravel-agent-mcp`; drop the `--label agent-reported` flag. Only the `bug` label is present and applied:

       gh issue create -R anilcancakir/laravel-agent-mcp \
         --title "<concise symptom>" \
         --label bug \
         --body-file - << 'BODY'
       <draft body>
       BODY

   Capture the new issue URL from stdout and surface it once.

6. Confirm + `gh` missing: the prefill URL works only when the urlencoded body stays under about 6KB.

   > "Open https://github.com/anilcancakir/laravel-agent-mcp/issues/new?title=<urlenc>&labels=bug and paste the draft below as the body."

   For larger bodies, write the draft to a temp file and instruct the user to paste it into the body field on the plain `/issues/new` URL.

7. No or "not now": acknowledge once, never re-suggest in the session (no second issue ask even on a different bug shape).

## Spam brakes (both flows)

- Star at most once per session. Issue at most once per session (one ask total, not one per bug shape). If a second agent-mcp-side bug appears after the user already declined or already filed once, stop, do not surface a fresh CTA.
- Never call `gh issue create` without an explicit user "yes" on the visible draft body. For the star flow, `gh api --method PUT /user/starred/...` only requires an explicit "yes" to the prose ask (no draft body exists to preview); never call the star API as a side effect of any other action.
- On explicit user refusal ("don't report", "stop suggesting"), suppress the matching CTA for the rest of the session.
- Labels: only `bug` is present on this repo. Do not invent labels. Do not pre-create `agent-reported` or any other label on the user's account; if labels evolve, update this file.
