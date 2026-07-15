# Connecting an MCP client

These are sample project-scoped MCP configs (Claude Code format) for connecting to a site running
HLB MCP Abilities. Each server is a remote **Streamable-HTTP** endpoint authenticated with a
WordPress **Application Password** over HTTP Basic auth.

## Which sample to use

| File | Use when |
| --- | --- |
| [`.mcp.json`](.mcp.json) / [`single-site.mcp.json`](single-site.mcp.json) | A standalone WordPress site. |
| [`multisite-subsite.mcp.json`](multisite-subsite.mcp.json) | Multisite, connecting **one specific subsite** by its own endpoint. |
| [`multisite-network-mode.mcp.json`](multisite-network-mode.mcp.json) | Multisite with **Network mode** on — one main-site endpoint reaches every subsite via a `site` argument. |
| [`multiple-servers.mcp.json`](multiple-servers.mcp.json) | Several sites/subsites at once, each with its own token env var. |

Copy one, then fill in three site-specific values:

## 1. URL

`https://<domain>/wp-json/<server-name>/mcp`

The `<server-name>` is shown on the plugin settings page
(**Settings → HLB MCP Abilities → MCP connection**). It follows the naming scheme:

| Site | Server name | URL |
| --- | --- | --- |
| `mysite.com` | `hlb_mysite_com` | `https://mysite.com/wp-json/hlb_mysite_com/mcp` |
| `mysite.com/shop` (subdirectory multisite) | `hlb_mysite_com_shop` | `https://mysite.com/wp-json/hlb_mysite_com_shop/mcp` |

## 2. Credentials

Create an Application Password in WordPress under **Users → Profile → Application Passwords**,
then base64-encode `username:application_password` (keep the spaces WordPress shows in the
password):

```bash
printf '%s' 'admin:abcd EFGH ijkl MNOP qrst UVWX' | base64
```

## 3. Token

Export the encoded value so `${HLB_MCP_TOKEN}` resolves at load time and the secret stays out of
the committed file:

```bash
export HLB_MCP_TOKEN="YWRtaW46YWJjZCBFR0dIIGlqa2wgTU5PUCBxcnN0IFVWV1g="
```

`.mcp.json` expands `${VAR}` from the **process environment**, not from a `.env` file. Put the
`export` in your shell profile, or use [direnv](https://direnv.net/) (an `.envrc` with
`export HLB_MCP_TOKEN=...`) so it loads automatically when you enter the project.

## Multisite: one endpoint for all subsites (network mode)

If the site is a multisite network with **Network mode** enabled (Network Admin → HLB MCP
Abilities), you only need the **main site's** endpoint — no per-subsite entries:

```json
{
  "mcpServers": {
    "mysite": {
      "type": "http",
      "url": "https://mysite.com/wp-json/hlb_mysite_com/mcp",
      "headers": { "Authorization": "Basic ${HLB_MCP_TOKEN}" }
    }
  }
}
```

Every ability then accepts an optional `site` argument (blog ID, path slug, or domain), and a
`hlb/list-sites` tool lets the agent discover subsites — so "create a post on the evrotrust site"
resolves to `site: "evrotrust"` with no config change. Use a **Super Admin** Application Password,
since capabilities are checked on the target subsite.

## Notes

- **Capabilities follow the user.** An agent can only call abilities that are enabled in the
  plugin settings *and* permitted by that WordPress user's role. Match the app-password user's
  role to the access you intend.
- Use **HTTPS** — Basic auth sends credentials on every request.
- Add more entries under `mcpServers` (e.g. `hlb-staging`) to connect several sites, each with
  its own URL and token.
