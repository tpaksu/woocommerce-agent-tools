=== WooCommerce Agent Tools ===

Contributors: Automattic
Tags: woocommerce, mcp, abilities, agent, ai
Requires at least: 6.9
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.5.1
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

**This is a prototype for internal evaluation.** It works, it is linted, and the confirmation gate's token lifecycle has unit tests. The rest is not hardened and has no tests of its own. See the FAQ for what that means in practice.

== Installation ==

1. Install and activate **WooCommerce**.
2. Install and activate **MCP Adapter**, from Plugins, Add New, searching for "MCP Adapter".
3. Upload and activate this plugin.
4. Go to **Tools, Agent Tools**.

WooCommerce, the MCP Adapter and the required WordPress version are all declared in the plugin header, so WordPress will not activate this plugin until they are in place.

Step four is the whole setup. The screen opens with what this store exposes: how many tools an agent can see, how many of them change something and are held until stated, and how many come back to you instead of running. Below that are two steps. The first states what the agent will inherit from the user you are about to issue a credential for, which is the part worth reading before you issue one, and then issues it. The second hands you ready-made configuration for Claude Code, Claude Desktop, Cursor, VS Code or any other MCP client, with the credential already filled in.

Restart your client afterwards, because MCP servers are read at session start.

== Using it ==

Once a client is connected, you talk to your agent in ordinary language and it works the store through the tools. This section is what happens underneath, so you can tell whether what you are reading back is what you asked for.

= A session always starts with discovery =

Your client lists three tools, which the MCP Adapter names `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and `mcp-adapter-execute-ability`. The store's real capabilities sit behind them. An agent asks for the list, picks a tool, reads its parameters, then calls it.

Ask it to discover first in any session where the store may have changed. The list is not fixed: activate an extension and its tools appear, deactivate it and they go. A tool name that worked on another store may not exist on yours.

= Reading is immediate =

"How many orders are still processing?" needs no permission from you. A read runs on the first call and answers:

    products-list, per_page 1
    {"success":true,"data":[{"id":33,"name":"WordPress Pennant", ...

Reads are the majority of the surface. Nothing is held, nothing is previewed.

= Writing takes two calls, and the first one is yours to approve =

Anything that is not read-only runs nothing on the first attempt. It comes back with a statement of what the call would do and a single-use token:

    woocommerce/coupons-create is not reversible, so it did not run. This is what it would do:
    Create coupons with code: SPRING, discount type: percent, amount: 10. Put that in
    front of the merchant in their words rather than these, including anything you
    inferred or chose yourself: what changes, what it changes, and every amount. Keep
    the tool name and the field names to yourself unless they ask for them. ...

Your agent is instructed to show you that sentence before going any further. Read the arguments, not the summary: the token is bound to those exact values, so what you approve is what runs, and a changed amount or a different order id produces a different token that has to be previewed again.

Say yes and the second call runs. Say nothing and nothing happens.

This covers every write on the store, including abilities WooCommerce and other extensions register, not only the ones this plugin derives.

= Some writes declare no payload, and those come back to you =

A few routes declare no parameters at all, so the store cannot tell the agent what payload they expect. That class is about a fifth of the writes, and it is exactly where the consequential things live: accepting a terms of service, connecting a payment or shipping account, triggering a deposit. Those refuse differently:

    woocommerce-shipping/tos-create is not reversible, so it did not run. Its route declares no
    parameters, so nothing here can tell you what payload that route expects. The call is
    nevertheless sending accepted=true. What the tool says it does is: Create tos. Relay all of
    that, and say that the route has not stated what it takes, so any values above are ones you
    inferred rather than ones it asked for. ... the normal answer is for them to do it
    themselves at https://example.com/wp-admin/.

Read the arguments in that one especially carefully. A route declaring no parameters does not stop the agent sending some: it is told to infer them from the error the route gives back, so anything listed there is the agent's guess at what the route wants, not something the route asked for. The agent should hand you the link and stop. Do it in wp-admin, where the screen states the terms, then tell it you are done.

= After you change something in wp-admin, ask it to refresh =

Accepting an agreement or connecting an account changes what the store exposes without changing which plugins are active, and the compiled tool list is cached against the active plugins. So it will not notice on its own. `woocommerce/refresh-abilities` drops the list, and the next discovery rebuilds it:

    {"flushed":true,"next":"Call discover-abilities again. ..."}

Accepting the WooCommerce Shipping terms, for instance, takes it from two tools to several dozen; the exact count depends on the version and what else is installed. Until you refresh, an unchanged list is not evidence that what you did in wp-admin failed.

= Repeating a confirmed call =

A payload that has already been confirmed will not be previewed or run again for ten minutes, whether the token is resent or the agent starts over from scratch. This is deliberate: a client that times out on a slow carrier call and retries would otherwise refund twice. If you genuinely want the same thing done again, wait it out, or send a request that differs in a way you actually want.

= When there is no tool for something =

The agent is told to say so rather than reach for the REST API, WP-CLI or the database, and to suggest what would provide it: an inactive plugin that needs activating, a curated suggestion feed, or a search of WooCommerce.com and the WordPress.org directory. It will name candidates with prices and links and stop. Installing an extension is what creates its tools, so that decision stays with you.

= Changing what the agent is told =

The operating instructions sent in the MCP handshake are a single file, `agents.md`, in this plugin. It tells the agent that it is your store manager on this connection, how to handle the two-phase gate, what to do when a schema is incomplete, and that it is not a shopping assistant here, because these tools run with your user's capabilities across every order and customer rather than one shopper's own. Drop your own copy at `wp-content/agents.md` to override it; that location wins and survives a plugin update.

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
* The confirmation gate forces a write to be stated, not read. An agent can return the token immediately, and one confirmed eighteen order deletions in a row.
* A preview describes the call and not its consequences. Setting an order to `refunded` makes WooCommerce create a refund record against it, which the preview does not mention.
* Roughly one tool in nine has parameters its route never declares. The names are recovered by reading the handler and published with a permissive type, and the tool says which ones they are, but their shapes are unknown. A plugin can state them with `woocommerce_agent_tools_route_args`.
* About a fifth of writes declare no parameters of their own, so the only property they publish is `confirm`. Their description says so, and the gate hands them to the merchant rather than previewing a payload the route never stated.
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

= 0.5.1 - 2026-10-27 =
* Tweak - Rework the Tools screen. It leads with what the store exposes, how much of that changes something, and how much comes back to the merchant; the two stages of setup are numbered panels rather than three equal headings; the clients collapse so you read one; and the warning about what the agent inherits sits above the button that issues the credential rather than below it, which is where the decision it describes is made.
* Tweak - The Tools screen hands you ready-made configuration for Claude Desktop, Cursor and VS Code as well as Claude Code, each with the file it belongs in. Every client but one was previously left to work out the transport and headers for itself.
* Tweak - The gate told the agent to put the preview in front of the merchant "in those terms", which meant reading them the tool name and the raw field names. It now asks for the same substance in the merchant's own words, and the preview renders `discount_type=percent` as `discount type: percent` so a verbatim relay is not code either.

= 0.5.0 - 2026-10-27 =
* Add - `refresh-abilities`, which drops the compiled tool list so the next discovery rebuilds it. The list is cached against the active plugins and their versions, and accepting an agreement or connecting an account changes neither: WooCommerce Shipping goes from two tools to several dozen on accepting its terms, with an identical fingerprint either side, so the stale list was served for up to a day with nothing able to notice. The refusal for a call handed to the merchant now tells the agent to refresh and discover again once they report it done, and says that an unchanged list is not evidence they failed until it has been refreshed.
* Add - A write whose route declares no parameters is handed to the merchant instead of previewed. The gate cannot say what such a route expects, so the refusal names an admin screen, relays what the tool says it does, and lists anything the agent inferred and is sending anyway. That class is where accepting a terms of service, connecting a payment or shipping account and triggering a deposit all sit. The token still works for a merchant who reads the refusal and asks the agent to proceed.
* Add - `woocommerce_agent_tools_self_serve_url`, so the plugin that owns a route can send one of those calls to its own settings screen rather than the admin root. Returning an empty string removes the link; the route still declares no payload, so the refusal keeps saying so and names no screen.
* Fix - `Cache::flush()` documented itself as the manual refresh and had no caller, so the only way to rebuild the tool list was to wait out the day-long cache or delete the transient by hand.
* Fix - `woocommerce_agent_tools_route_needs_confirmation` decided whether a derived tool published its `confirm` property and its not-reversible note, while the gate that enforces the hold read the route's own read/write annotation instead. A filter that asked for a read to be held left a tool promising a preview it never gave, and one that released a write produced a hold the tool had not advertised. A derived tool now records the decision its description was built from, and the gate honours that record for abilities in this plugin's own category; anything else is judged on its annotation as before.
* Fix - A confirmation claimed for a call that was then refused before it ran, by the route's permission check or by input validation, left the merchant told their call had already been attempted and locked out of the same payload for ten minutes. The gate runs before either check, so it now hands the confirmation back when one of them refuses the call, on the ability path and on a direct REST write alike, and stops tracking it once the call is certain to run so a later refusal cannot re-arm a confirmation that has been spent.
* Fix - A payload that cannot be JSON-encoded, because it nests past the encoder's depth limit, hashed to the same confirmation token as every other such payload, so a preview of one would have authorised any of the others. The gate now refuses to confirm a payload it cannot identify.
* Fix - A confirmation token could authorise more than one call: two concurrent calls could both claim it, and a spent token was handed back to a caller that restarted the protocol. A payload that has already been confirmed is now refused with `wcat_confirmation_spent` and status 409, with no token returned, while one that merely expired still gets a fresh preview. It matters most where there is no inverse, such as a second shipping-label refund.
* Fix - A throw while re-reading a redacted secret left the re-entrancy flag set, and every later response in that request was returned to the agent unredacted.
* Fix - A cache entry holding a fingerprint but no descriptors made the compiler skip loading the full route table and then compile against a partial one, emitting a fraction of the store's tools.
* Fix - A tool whose route ran another ability stopped being recognised as internal traffic partway through, because the inner call reset a shared flag while the outer one was still running. The REST guard then began demanding a confirmation token for the rest of that outer call.
* Fix - A route registering POST, PUT and PATCH against one callback compiled into two tools that called the same handler with the same parameters, leaving an agent no way to choose between them. The edit verbs on one handler now yield one tool, named after what the route does: a collection keeps `create`, an action route keeps its tail. A route registering POST and PUT as separate handlers keeps both, since those are different callbacks.
* Tweak - agents.md tells a connecting agent who it is on this connection: the merchant's store manager, talking in the store's terms, and explicitly not a shopping assistant, since the tools here run with the connected user's capabilities over every order and customer rather than one shopper's own.
* Tweak - agents.md says what to do after the merchant finishes a task in wp-admin: refresh the tool list before concluding anything from it.
* Tweak - agents.md explains the class of call whose route states no payload, what the refusal says instead, and what to do with the admin screen it usually names.
* Tweak - Drop the parameter list from every tool description. It repeated `input_schema`, which get-ability-info returns in full, and named only the first ten parameters, so a tool taking twenty-three read as taking ten. Shortens what `discover-abilities` returns by 15.6%.
* Dev - Unit tests for the confirmation gate's token lifecycle, run with `composer test`. The harness models WordPress's transient semantics rather than booting WordPress.
* Dev - Move the classes to PSR-4 under `src/`, in the `WooCommerce\AgentTools` namespace, grouped by responsibility (`Route`, `Ability`, `Safety`, `Agent`). The build now ships Composer's autoloader instead of a hand-maintained list of twenty `require_once` lines, which was a silent fatal whenever a new class was forgotten.
* Dev - Replace the static classes with constructor injection. Collaborators are passed in, `Plugin` is the only place that calls `new`, and the request-scoped state that lived in static properties now belongs to the object that owns it.
* Dev - Class names change with the namespace: `WC_Agent_Tools_Gate` is now `WooCommerce\AgentTools\Safety\Gate`, and so on. The filters are unchanged, so anything hooking `woocommerce_agent_tools_*` keeps working.
* Dev - Stub the two PHP 8 string functions WordPress polyfills, so static analysis runs with no suppressed errors at all rather than ignoring them by pattern.
* Dev - Exclude the analysis-only stubs from the coding-standards run, and apply WooCommerce core's own sniff swap for a PSR-4 directory: the WordPress file-name rules off, the stricter class-name and file-match rules on.

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
