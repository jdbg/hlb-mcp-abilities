# Security Policy

## Why this matters

HLB MCP Abilities exposes WordPress **Abilities** — including, when an admin enables
them, write and destructive capabilities (creating/editing/deleting content, managing
users, etc.) — over an authenticated MCP/REST API. A vulnerability here could let an
authenticated (or, in the worst case, unauthenticated) caller perform actions beyond
what the site owner intended. Please report issues privately rather than opening a
public GitHub issue.

## Supported versions

Only the latest released version is supported with security fixes. Please update to
the newest release before reporting, in case the issue is already fixed.

## Reporting a vulnerability

Please report security issues privately using
[GitHub's private vulnerability reporting](https://github.com/hlebarovcom/hlb-mcp-abilities/security/advisories/new)
(repo → **Security** tab → **Report a vulnerability**). Do not open a public issue or
pull request for a suspected vulnerability.

Include, where possible:

- The plugin version, WordPress version, and whether the site is multisite.
- Which ability/abilities are involved and the capability/permission checks that
  appear to be bypassed.
- Steps to reproduce, or a minimal proof of concept.

## What to expect

- Acknowledgement within **5 business days**.
- An initial assessment (confirmed / needs more info / not a vulnerability) within
  **10 business days**.
- Coordinated disclosure: we'll work with you on a fix and release timeline before any
  public disclosure, and credit you in the release notes unless you prefer otherwise.

## Scope

In scope: the code in this repository. Vulnerabilities in WordPress core, the
[MCP Adapter](https://github.com/WordPress/mcp-adapter), or other plugins should be
reported to those projects directly.
