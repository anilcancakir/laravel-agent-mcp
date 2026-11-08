# Architecture

## Overview

The package puts one pipeline in front of a Laravel app and runs it from three doors: an HTTP MCP route, an artisan CLI (local or against a remote app), and a stdio relay for clients that cannot send headers. Every door ends at the same tool classes, so the read-only boundary lives in the tools and the database layer, never in a door.

```
HTTP route  -> KeyAuthMiddleware -> throttle -> AgentMcpServer -> Tool::handle
agent-mcp:call (local)  ------------------------------------------> Tool::handle
agent-mcp:call (remote) -> RemoteToolClient -> HTTP route (above)
agent-mcp:stdio         -> HTTP route (above), no package code on the way
Tool::handle = authorize -> audit -> read-only work -> redact
```

## Codemap

- `Server` owns the tool roster (`AgentMcpServer::$tools`) and the instructions text. The CLI reads the roster by reflection on that property, so `Cli` depends on `Server`.
- `Tools` owns each tool's gate, audit call, read logic and redaction. Tools reach the database through `Database`, `Schema` or `DB` directly (see Traps), not only through the resolver.
- `Database` owns the hardened connection (`ReadonlyConnectionResolver`) and package catalog SQL (`CatalogQuery`). It imports no sibling module.
- `Sql` owns the SELECT-only validator for `db_raw_select`. Statement shape is an allowlist; the forbidden-name scan is a blocklist and incomplete by design.
- `Http` owns `KeyAuthMiddleware` and the `StripsErrorTraces` trait, which is mixed into the server (not middleware) and flips `app.debug` off for the length of one call.
- `Logs` resolves and contains the log path; `ReadLogsTool` does the reading.
- `Auditing` writes tool name, argument shape and resolved section; never values.
- `Cli` is the shared base for the artisan commands and the remote JSON-RPC client. `Commands` holds the five commands. `StdioBridgeCommand` imports nothing from the package.
- `Support` mixes install-time file writers (`GuidelineInjector`, `SkillInstaller`, `InstallMode`, `AgentTarget`) with runtime code on the tool path (`OutputRedactor`, `RemoteUrl`, `RetiredToolKeys`). The boost blades call `InstallMode::current()`.
- `AgentMcpServiceProvider` wires transports, the `agent-mcp` rate limiter, the default audit channel and publishing. Its `Mcp::local` call is laravel/mcp's own stdio transport, not `agent-mcp:stdio`.

## Invariants

| Rule | Why | Verify |
|------|-----|--------|
| Every tool calls `authorize()` first, and a flag unset in config means off | A new tool must fail closed | `grep -L 'authorize()' src/Tools/*Tool.php` prints nothing |
| Agent-supplied SQL reaches the database in one place: `DbRawSelectTool` after `SelectStatementValidator`, on `readonly()` | One gate to harden and review | `grep -rn 'readonly()->select' src` lists `DbRawSelectTool` and `CatalogQuery` only |
| No raw statement API is used in `src` | Stacked statements are the bypass | `grep -rnE 'DB::(select\|statement\|unprepared)\|->unprepared\(' src` prints nothing |
| No static state in `src` | Octane and queue workers share the process | `grep -rn 'static \$' src` prints nothing |
| The Bearer key leaves only in an Authorization header, never in output | Output reaches transcripts | `vendor/bin/pest tests/Feature/Cli/RemoteToolClientTest.php tests/Feature/Commands/CallToolCommandTest.php` |
| An empty `AGENT_MCP_KEY` rejects the request before any comparison | `hash_equals('', '')` is true | `vendor/bin/pest tests/Feature/Http/KeyAuthMiddlewareTest.php` |
| `run_artisan` is the only tool that writes, and only through an exact allowlist | The read-only claim has one named exception | `grep -rln 'Artisan::call' src` lists `RunArtisanTool` only |

## Flows

1. HTTP call: `AgentMcpServiceProvider::registerTransports` mounts `AgentMcpServer` on the configured route with the configured middleware (`KeyAuthMiddleware`, then `throttle:agent-mcp`). `StripsErrorTraces::handle` wraps the call, laravel/mcp dispatches `tools/call`, and `AbstractAgentTool::jsonResponse` redacts the answer.
2. `agent-mcp:call`: `CallToolCommand::handle` runs `AbstractMcpCliCommand::guardSensitiveTty` before it picks a mode. `resolveMode` takes `--local` or `--remote`, else remote when `RemoteToolClient::configured` finds `AGENT_MCP_URL` or the committed `.agent-mcp.json` url. Local calls `invokeLocal` (container call on `handle`); remote calls `RemoteToolClient::callTool`, which refuses non-https URLs through `RemoteUrl::valid`.
3. `db_raw_select`: `DbRawSelectTool::handle` authorizes, audits, then `SelectStatementValidator::validate` (one statement, read-only CTE tail, tokeniser-agreement checks, forbidden names). A top-level LIMIT above the cap is refused; without one the tool appends `LIMIT max_rows + 1` and sets `truncated` only when the extra row appears. `ReadonlyConnectionResolver::connection` returns the dedicated connection or a hardened clone named `agent-mcp-readonly`.
4. `agent-mcp:install`: `InstallCommand::handle` resolves the mode (`--mode`, prompt, default `mcp`), validates and commits the url through `InstallMode::write`, publishes config, then injects the guideline and skill unless boost is installed (`class_exists` on a string FQCN) and `--inject` is absent.

## Decisions

| Decision | Why | Rejected | Status |
|----------|-----|----------|--------|
| Tools carry the boundary, doors stay thin | Three doors would otherwise need three audits | Per-transport checks | Active |
| The retired-key warning is returned text printed by commands | `read_logs` tails the application log, so a logged warning would reach the agent | A provider `Log::warning` | Active |
| Skills ship as `SKILL.blade.php` plus `SKILL.md` | Boost renders blades; the installer copies only markdown | One file per skill | Active |

## Change guide

| Change | Touch | Check |
|--------|-------|-------|
| Add a tool | `src/Tools/<X>Tool.php`, `AgentMcpServer::$tools` and `#[Instructions]` (stay under 1,800 characters), `config/agent-mcp.php` `tools`, `tests/Feature/Tools/<X>ToolTest.php`, `resources/boost/skills/agent-mcp-investigation/references/tools.md`, README, `llms.txt`, CHANGELOG | Default-off tools also join `AbstractMcpCliCommand::SENSITIVE_TOOLS`, the off list in `InstallCommand`, `resources/boost/skills/agent-mcp-cli/references/community.md` and the dataset in `tests/Feature/Cli/AbstractMcpCliCommandTest.php` |
| Add a section to a merged tool | The tool's section list and match arm, its schema text, its test, `references/tools.md`, the section names in `#[Instructions]` | `RetiredToolKeys::REPLACEMENTS` if a retired key maps there |
| Change a config flag or default | `config/agent-mcp.php`, README | `tests/Feature/ConfigTest.php` pins middleware and connection defaults |
| Change the SELECT validator | `src/Sql/SelectStatementValidator.php`, `DbRawSelectTool` (LIMIT handling), `tests/Unit/Sql/SelectStatementValidatorTest.php`, `tests/Feature/Tools/DbRawSelectToolTest.php` | `.github/SECURITY.md`, the tool description, CHANGELOG |
| Add an agent target | `AgentTarget::all()`, `tests/Feature/Support/AgentTargetTest.php` | README target table |
| Change redaction patterns | `redaction.patterns` in `config/agent-mcp.php`, `tests/Feature/Support/OutputRedactorTest.php` | Best-effort wording in README |
| Move the `laravel/mcp` range | `composer.json`, `.github/workflows/ci.yml` matrix | The compatibility comments in `AbstractAgentTool`, `StripsErrorTraces` and `StdioBridgeCommand`; `StdioBridgeCommandTest` |
| Edit a boost skill or guideline | Both `SKILL.md` and `SKILL.blade.php`, `references/`, `core.blade.php` | `vendor/bin/pest tests/Feature/Boost/` |
| Bump the version | `#[Version]` in `AgentMcpServer`, `server.json`, CHANGELOG | `tests/Feature/ServerManifestTest.php` ties the first two |

## Cross-cutting

- Errors: a denied tool returns fixed text; a refused section is a `ValidationException` thrown from `requestedSection` after the call is audited. CLI exit codes come from the tool's `isError`.
- Configuration: tool flags are config only. The HTTP key comes from `config('agent-mcp.key')`; the CLI and the stdio bridge read `AGENT_MCP_KEY` and `AGENT_MCP_URL` from the environment, bypassing config caching. `.agent-mcp.json` holds only `mode` and `url`; `AGENT_MCP_URL` overrides it.
- Testing: Pest on Testbench with SQLite `:memory:` for both `testbench` and `readonly`, package config loaded from file. Every database tool test sets `agent-mcp.connection` to `readonly`.

## Traps

- `StdioBridgeCommand` never calls `RemoteUrl::valid` and has no enabled check, so a plain `http://` `AGENT_MCP_URL` sends the Bearer key in clear text.
- `DbSchemaTool` (and `DbQueryTool`'s table-exists check) call `Schema::connection((string) config('agent-mcp.connection', 'readonly'))`. The shipped config default is null, so the name is `''` and the app's default connection answers, unhardened. Row reads in `DbQueryTool` use `readonly()` and are fine.
- Tool names come from `#[Name]` on most tools and `protected string $name` on `DbRawSelectTool`, `ReadLogsTool` and `RunArtisanTool`; both must equal the config key.
- Four sectioned tools (`AppInfoTool`, `DbHealthTool`, `QueueStatusTool`, `DbDiagnosticsTool`) resolve the section before `audit()`; a refused section is audited inside `requestedSection`.
- `AgentMcpServiceProvider` and `KeyAuthMiddleware` each carry a copy of `presentedKey`; the rate-limit bucket and the auth check must read the same credential.
- `CallToolCommand` catches only `RemoteInvocationException` and `RuntimeException`, so a `ValidationException` from a local call reaches Artisan's renderer.

Last reviewed: 2026-11-09 after the 2.0 merge.
