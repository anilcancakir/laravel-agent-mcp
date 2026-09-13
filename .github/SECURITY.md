# Security Policy

## Supported versions

Security fixes land on the latest released minor. Older minors are not
backported.

| Version | Supported |
|---------|-----------|
| 1.x     | yes       |
| < 1.0   | no        |

## Reporting a vulnerability

Report privately through GitHub's private vulnerability reporting on this
repository: open the [Security tab](https://github.com/anilcancakir/laravel-agent-mcp/security/advisories)
and choose "Report a vulnerability". That channel is private, so the report and
the discussion stay between you and the maintainer until a fix ships.

Please do not open a public issue, a pull request or a discussion for a security
report. A public report tells everyone running the package about the problem
before there is a version they can upgrade to.

What helps, in rough order of usefulness:

- The package version, the PHP version and the database engine.
- A minimal reproduction: the smallest tool call or SQL string that shows the
  behaviour.
- What you expected to happen and what happened instead.
- Whether the tool involved is one that ships enabled or one the operator has to
  turn on in `config('agent-mcp.tools.*')`.

You will get an acknowledgement, and if the report is accepted you will be
credited in the advisory unless you would rather not be.

## Scope

This package exposes a read-only MCP endpoint on a running Laravel application,
guarded by a single server-admin key. Reports that bear on that boundary are in
scope, including anything that reads state the read-only guarantee is meant to
withhold, anything that reaches past the SELECT-only validator, any way to reach
a tool the operator has disabled, and any leak of credentials or secrets through
tool output or an error path.

Out of scope: findings that require the `AGENT_MCP_KEY` to already be known to
an untrusted party (the key is a server-admin credential and is assumed secret),
and behaviour an operator has explicitly opted into by enabling a tool that is
documented as sensitive.
