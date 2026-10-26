=== WooCommerce Agent Tools ===

Contributors: Automattic
Tags: woocommerce, mcp, abilities, agent, ai
Requires at least: 6.9
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.4.0
WC requires at least: 10.0
WC tested up to: 11.4
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Compiles WordPress abilities from plugin REST routes, so an MCP agent sees what the store can actually do.

== Description ==

An agent connected to a WooCommerce store over MCP can only use the abilities someone wrote by hand. Most extensions ship none, so most of what a store can do is invisible.

This plugin derives them instead. It reads the REST route table, works out which plugin owns each route, and registers one ability per callable operation. Install an extension and its tools appear. Deactivate it and they disappear. Nothing is written to disk, no extension is modified, and no ability definition is maintained by anyone.

Authorization is unchanged. Every call dispatches through the REST API, so the route's own permission callback decides, and the agent reaches exactly what the connected user reaches in wp-admin.

Where a plugin author has written an ability by hand, theirs wins and the derived duplicate is suppressed, unless the derived tool accepts more parameters than the hand-written one. A curated tool is sometimes a deliberate subset, and yielding to it would quietly cost the agent filters it has no other way to reach.

Writes are held until they have been stated. The first attempt at anything that is not read-only runs nothing: it returns a preview of exactly what the call would do, plus a single-use token bound to those exact arguments. The same arguments with that token run. This applies to every write on the store, including abilities WooCommerce and other plugins register, not only the ones this plugin derives.

**This is a prototype for internal evaluation.** It works and it is linted. It is not hardened, not reviewed, and has no tests. See the FAQ for what that means in practice.

== Installation ==

1. Install and activate **WooCommerce**.
2. Install and activate **MCP Adapter**, from Plugins, Add New, searching for "MCP Adapter".
3. Upload and activate this plugin.
4. Go to **Tools, Agent Tools**.

WooCommerce, the MCP Adapter and the required WordPress version are all declared in the plugin header, so WordPress will not activate this plugin until they are in place.

Step four is the whole setup. It shows the endpoint this store serves, creates an application password on a button, and hands you the client configuration with the credential already filled in, for Claude Code and for any other MCP client. It also tells you what the agent will be able to do as the user you created the password for, which is the part worth reading before you connect anything.

Restart your client afterwards, because MCP servers are read at session start.

== Frequently Asked Questions ==

= Where do I connect from? =

Tools, Agent Tools. The MCP Adapter ships no UI of its own, so this plugin provides the screen: endpoint, a button to issue an application password, the ready-made client configuration, and a plain statement of what the agent inherits from the user you issued it to.

= Why does my client only list three tools? =

Those are the MCP Adapter's progressive-disclosure meta-tools: discover-abilities, get-ability-info and execute-ability. The derived surface sits behind them. This is deliberate. The three never change, so a client's cached tool list stays valid while the store's real capabilities change underneath it.

= What will the agent be able to do? =

Whatever the WordPress user whose application password you used can do. Each route's own permission callback decides, so the agent's reach is that user's reach in wp-admin, and no wider.

That cuts both ways. If you use an administrator's password, the agent can do what an administrator can do, which includes installing and activating plugins and placing a real order on WooCommerce.com. Writes have no undo.

Consider making a separate WordPress user with a narrower role and generating the application password there. A shop manager, for instance, has `manage_woocommerce` but not `install_plugins` or `manage_options`, which removes those writes through capability rather than policy.

= Is WooCommerce AI required? =

No, and the plugin does not read anything from it. Its routes and MCP endpoint are skipped, and the only interaction is deduplication: with it installed, its hand-written abilities suppress derived equivalents of the same shape, which is deduplication working as intended. Removing it makes the derived surface grow rather than shrink.

One thing to know if you have it: WooCommerce AI 0.8.0 calls `RestAbilityFactory::register_controller_abilities()`, which WooCommerce removed in 11.3.0, so its REST-derived abilities fail to register and emit a `_doing_it_wrong()` notice on every request. That is its bug, not this plugin's, and this plugin works either way.

= How do I tell the agent how to behave? =

The plugin ships an `agents.md` and puts it in the MCP handshake, where a client places it in front of the model. It tells the agent to discover before guessing, to report a missing capability rather than route around it through the REST API or WP-CLI, how to suggest an extension when nothing installed can do the job, that writes have no undo, and that a `wcat_secret:` value is opaque.

To say something else, put your own `agents.md` in `wp-content/`. It takes precedence and survives a plugin update. The `woocommerce_agent_tools_server_instructions` filter changes the final text.

= Why did my agent's call not run the first time? =

Because it changes something. Any call that is not read-only is held on its first attempt and answered with a preview of what it would do, together with a token. Calling again with identical arguments plus that token runs it. The token is single-use and bound to the exact arguments, so confirming one call cannot authorise a different one.

This exists because instructions alone did not hold. Told not to spend money without asking, an agent read the instruction, agreed it applied, and reasoned its way to an exception. A preview is a mechanism rather than a request, so the agent has to state the call, in its own words, before the irreversible step.

It does not put a human in the loop; an agent can send the token straight back. What it buys is that the intent is on the record where a client or a merchant can read it, and that values the agent inferred rather than was told show up in the preview instead of only in the report afterwards.

Two limits worth knowing. The preview describes the call, not its consequences: setting an order to `refunded` also makes WooCommerce create a refund record against it, which the preview cannot see. And the gate applies to agent traffic only, so other integrations using application passwords of their own, and other plugins using the Abilities API, are unaffected. `woocommerce_agent_tools_ability_needs_confirmation` changes the decision per ability.

= Does it work with High-Performance Order Storage? =

Yes, and it declares so. The plugin never reads or writes an order itself: every call is dispatched through the route's own controller, whatever that controller stores in.

= What about credentials? =

Some read-only routes return stored secrets. WooCommerce core returns a password-type setting's value with no masking, and so do several extensions. Before a response reaches the agent, this plugin replaces such a value with an opaque `wcat_secret:` reference, and resolves it back when the agent passes it to a parameter of the same name it was read from. The agent can reuse a key it was never shown. The reference map stores no secret values.

= A tool is missing parameters the route actually needs. Can I fix that? =

Yes, from the plugin that owns the route, in one filter and with no ability definition:

`add_filter( 'woocommerce_agent_tools_route_args', function ( $args, $route ) { ... return $args; }, 10, 2 );`

Return the args the route should have registered, in `register_rest_route()` form. Nested `properties` and `items` are published as JSON Schema, and a child marked `required` becomes part of its parent's required list.

This exists because `register_rest_route()` args are the only machine-readable description of a payload and a controller is free to ignore them. Where that happens, this plugin reads the handler to recover the parameter *names*, publishes them with a permissive type, and says in the tool's description that their shapes are unknown. It cannot invent types. The filter is how the author supplies them.

= Which routes are exposed? =

All of them that can be derived. The only boundary is the connected user's capabilities. Two filters change this: `woocommerce_agent_tools_route_is_readonly` declares a route read-only despite its HTTP method, and `woocommerce_agent_tools_route_is_public` withholds one.

Some namespaces are skipped deliberately rather than for want of permission: Jetpack and WordPress core's own routes, admin-UI state plumbing such as task-list dismissals, the MCP endpoints themselves, and the Store API. The Store API is the shopper's cart and checkout, scoped to their own session, so an admin credential cannot use it and the two tools it yielded were one permanently denied and one that would have edited a live checkout.

= What is not hardened yet? =

Deliberately deferred. None of it blocks evaluation; all of it would block shipping.

* **No tests and no review.** Nothing here has been through a code review, and there is no test suite, so none of the behaviour below is protected against regression. The design document's reference figures are usable as fixtures.
* Exposure is total by design, so the connected user's role is the entire security story.
* The confirmation gate forces a write to be stated, not read. An agent can return the token immediately, and one confirmed 18 order deletions in a row.
* A preview describes the call and not its consequences. Setting an order to `refunded` makes WooCommerce create a refund record against it, which the preview does not mention.
* 46 tools have parameters their route never declares, 78 parameters in all. The names are recovered by reading the handler and published with a permissive type, and the tool says which ones they are, but their shapes are unknown. A plugin can state them with `woocommerce_agent_tools_route_args`.
* 31 writes publish an empty input schema. Their description says so, rather than letting it read as "takes no arguments".
* The discovery payload is large, and richer schemas made it bigger. It is unpaged, so a client without a way to spill it to disk cannot read the tool list at all. Every agent tested worked around it with a shell.
* The `destructive` annotation is true for every write, which is conservative rather than correct.
* A route whose permission callback is `is_user_logged_in()` still counts as enforcing.
* Credential redaction works and is verified, but has not been reviewed by anyone else.
* Both extension-search tools send their term to WooCommerce.com or WordPress.org.
* No media ability, so an agent can write a product's text but never add its photo.
* Every MCP request loads every WooCommerce REST namespace, which is not measured on a large store.
* The compiled cache keys on the active plugins and their version headers, so editing this plugin's own code does not invalidate it. Bump the version or flush from Tools, Agent Tools.
* Untested on multisite.

== Changelog ==

= 0.4.0 - 2026-10-26 =
* Add - The confirmation gate now covers every write on the store, including abilities WooCommerce and other plugins register, not only the ones this plugin derives. A first call runs nothing and returns a preview plus a token; the same arguments with that token run.
* Add - Hold a direct REST write made with an application password issued from this plugin's own screen, so a route called without its tool meets the same preview. Every other application password behaves as before.
* Add - Declare compatibility with High-Performance Order Storage. The plugin never reads or writes an order itself; every call goes through the route's own controller, whatever that controller stores in.
* Add - Payload shapes for the WooCommerce Shipping rate and label routes, including the fields the carrier server requires but the route never declares.
* Add - `woocommerce_agent_tools_ability_needs_confirmation`, `woocommerce_agent_tools_route_needs_confirmation` and `woocommerce_agent_tools_guard_rest_request` filters.
* Fix - Two namespaces compiling to one tool name silently discarded 49 tools and handed every merchant CRUD tool to `wc-analytics` rather than the public `wc/v3` API. Namespace precedence is now declared, and a discarded name is counted instead of lost.
* Fix - A read handler wrapped in a closure for caching lost its attribution, so a route kept its writes and lost its reads: a tax rate could be created and deleted but never listed. Restores `taxes-list`, `taxes-get`, `products-get`, `orders-get`, `coupons-get` and `customers-get`.
* Fix - Fetching one record by id is no longer treated as the same capability as querying a collection, which had removed every single-item read.
* Fix - A hand-written ability no longer suppresses a derived one that publishes more parameters. Restores filtering products by category, tax class, brand, price, sale status and stock.
* Fix - Stop compiling the Store API into merchant tools. Its routes are scoped to a shopper's own session, so they answered "Permission denied" while shadowing the tools that work.
* Fix - A boolean `false` rendered as nothing in a preview, so `force=` and `api_refund=` read as absent on the two calls where they matter most.
* Tweak - agents.md states the rule the store actually enforces, and warns that a write can cascade: setting an order to refunded makes WooCommerce create a refund record against it.
* Tweak - Say in a tool's description when a write's route declares no parameters and none could be recovered, rather than letting an empty schema read as "takes no arguments".
* Dev - Dedupe no longer counts this plugin's own discovery tools as another author's work.
* Dev - Stubs for the MCP Adapter, which ships none, so static analysis no longer stops at every call into it.

= 0.3.0 - 2026-10-24 =
* Add - Recover parameter names a route never declared by reading its handler, and say in the tool's description which ones have unknown shapes.
* Add - `woocommerce_agent_tools_route_args` filter, so the plugin owning a route can declare the payload it really accepts without writing an ability definition.
* Add - Publish the route's own required parameters, so a missing one is reported by name before the call is made.
* Fix - Keep union types instead of collapsing them to the first, which was rejecting null on 149 parameters the routes accept.
* Fix - Stop inventing the type `string` for a parameter whose type a route never stated, which rejected every structured value it would have accepted.
* Fix - Normalise nested schemas at every level, so `arg_options`, `sanitize_callback`, `context` and WordPress's boolean `required` no longer reach the client.
* Add - Tell agents not to reconstruct a payload from source code, the database or the admin app's JavaScript, and never to guess on a call that spends money.

= 0.2.0 - 2026-10-24 =
* Add - Extension discovery: search WooCommerce.com and the WordPress.org plugin directory when no installed plugin provides what was asked.
* Add - agents.md in the MCP handshake, overridable from wp-content.
* Add - Opaque references for credentials in responses, resolved back when passed to the field they came from.
* Tweak - Expose every derivable route, leaving the connected user's capabilities as the only boundary.

= 0.1.0 - 2026-10-23 =
* Add - Initial prototype: abilities compiled from the REST route table.
