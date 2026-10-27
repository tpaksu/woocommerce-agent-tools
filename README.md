# WooCommerce Agent Tools

Compiles WordPress abilities from plugin REST routes, so an MCP agent sees what a WooCommerce store can actually do.

> **This is a prototype for internal evaluation.** It works, it is linted, and the confirmation gate's token lifecycle has unit tests. The rest is not hardened and has no tests of its own. Do not point it at a production store you care about.

## What it does

An agent connected to a WooCommerce store over MCP can only use the abilities someone wrote by hand. Most extensions ship none, so most of what a store can do is invisible to it.

This plugin derives them instead. It reads the REST route table, works out which plugin owns each route, and registers one ability per callable operation. Install an extension and its tools appear; deactivate it and they go. Nothing is written to disk, no extension is modified, and no ability definition is maintained by anyone.

Authorization is unchanged. Every call dispatches through the REST API, so the route's own permission callback decides, and the agent reaches exactly what the connected WordPress user reaches in wp-admin.

Writes are held until they have been stated. The first attempt at anything that is not read-only runs nothing: it returns a preview of what the call would do plus a single-use token bound to those exact arguments. The same arguments with that token run. This applies to every write on the store, including abilities WooCommerce and other plugins register, not only the ones this plugin derives.

## Requirements

| | |
|---|---|
| WordPress | 6.9 or later (the Abilities API is in core from 6.9) |
| WooCommerce | 10.0 or later |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | active |
| PHP | 7.4 or later |

Both plugin dependencies are declared in the plugin header, so WordPress will not activate this until they are in place.

## Install

Download the zip from [Releases](https://github.com/tpaksu/woocommerce-agent-tools/releases), upload it under **Plugins, Add New, Upload Plugin**, and activate.

Then go to **Tools, Agent Tools**. That screen is the whole setup: it shows the MCP endpoint this store serves, issues an application password on a button, and hands you the client configuration with the credential already filled in. It also reports how many tools the store currently exposes, and what the agent will be able to do as the user you issued the password to — which is the part worth reading before connecting anything.

Restart your MCP client afterwards, because clients read their server list at session start.

## Using it

You talk to your agent in ordinary language. This is what happens underneath, so you can tell whether what you read back is what you asked for.

### A session starts with discovery

Your client lists three tools and no more, which the MCP Adapter names `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and `mcp-adapter-execute-ability`. The store's real capabilities sit behind them: the agent asks for the list, picks a tool, reads its parameters, then calls it.

The list is not fixed. Activate an extension and its tools appear, so a tool name that worked on another store may not exist on yours.

### Reads answer immediately

"How many orders are still processing?" needs no permission from you. Reads are the majority of the surface; nothing is held and nothing is previewed.

### Writes take two calls, and the first one is yours to approve

Anything that is not read-only runs nothing on the first attempt:

```text
woocommerce/coupons-create is not reversible, so it did not run. This is what it would do:
Create coupons with code=summer-sale, discount_type=percent, amount=5. Put that in front of
the merchant in those terms, including anything you inferred or chose yourself, and wait for
their answer. If they agree, call again with the same arguments plus confirm="0bb0dba7...".
```

Read the arguments rather than the summary. The token is bound to those exact values, so what you approve is what runs, and a changed amount or a different order id produces a different token that has to be previewed again.

A payload that has already been confirmed will not be previewed or run again for ten minutes, whether the token is resent or the agent starts over. That is deliberate: a client that times out on a slow call and retries would otherwise refund twice.

### Some writes cannot be previewed, and those come back to you

A few routes declare no parameters at all, so the store cannot tell the agent what payload they expect. That class is small and it is exactly where the consequential things live: accepting a terms of service, connecting a payment or shipping account, triggering a deposit. Those refuse differently, naming an admin screen instead:

```text
woocommerce-shipping/tos-create is not reversible, so it did not run. Its route declares no
parameters, so nothing here can tell you what payload that route expects. ... the normal
answer is for them to do it themselves at https://example.com/wp-admin/.
```

If such a refusal lists arguments, read them closely: the route declared none, so anything there is the agent's guess at what the route wants, not something the route asked for.

### After you change something in wp-admin, ask it to refresh

Accepting an agreement or connecting an account changes what the store exposes without changing which plugins are active, and the compiled tool list is cached against the active plugins and their versions. So it will not notice on its own. `woocommerce/refresh-abilities` drops the list and the next discovery rebuilds it. Until you refresh, an unchanged list is not evidence that what you did in wp-admin failed.

### When there is no tool for something

The agent is told to say so rather than reach for the REST API, WP-CLI or the database, and to suggest what would provide it: an inactive plugin that needs activating, a curated suggestion feed, or a search of WooCommerce.com and the WordPress.org directory. It names candidates and stops. Installing an extension is what creates its tools, so that decision stays with you.

## Telling the agent how to behave

The operating instructions sent in the MCP handshake are a single file, [`agents.md`](agents.md). It tells the agent that it is your store manager on this connection, how the two-phase gate works, what to do when a schema is incomplete, and that it is not a shopping assistant here — because these tools run with your user's capabilities across every order and customer rather than one shopper's own.

Drop your own copy at `wp-content/agents.md` to override it. That location wins and survives a plugin update.

## Extending it

Eight filters, all documented at their call sites:

| Filter | Purpose |
|---|---|
| `woocommerce_agent_tools_route_args` | Supply the args a route should have registered |
| `woocommerce_agent_tools_route_is_readonly` | Declare a route read-only despite its HTTP method |
| `woocommerce_agent_tools_route_is_public` | Withhold a route from the surface |
| `woocommerce_agent_tools_route_needs_confirmation` | Change whether a derived route is gated |
| `woocommerce_agent_tools_ability_needs_confirmation` | Change whether any ability is gated |
| `woocommerce_agent_tools_self_serve_url` | Point an unpreviewable call at its own settings screen |
| `woocommerce_agent_tools_guard_rest_request` | Change whether a direct REST write is held |
| `woocommerce_agent_tools_server_instructions` | Filter the handshake instructions |

## Development

```sh
composer install
composer test        # PHPUnit, no WordPress needed
composer check-php   # phpcs, WooCommerce-Core standard
composer phpstan     # level 5, no baseline
composer build       # installable zip
```

The test suite covers the confirmation gate's token lifecycle and runs against a model of WordPress's transient semantics rather than booting WordPress, so it needs no database and finishes in milliseconds.

## How it works

[`docs/derived-abilities-design.md`](docs/derived-abilities-design.md) is the design record: what the REST route table does and does not describe, why naming and deduplication work the way they do, and what was tried and withdrawn. Counts in it are readings from a named store on a named date rather than current state.

[`readme.txt`](readme.txt) is the WordPress-format readme, with a longer FAQ and the full changelog.

## License

GPL-3.0-or-later. See the plugin header for details.
