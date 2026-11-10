=== Send4 for WooCommerce ===
Contributors: send4
Tags: woocommerce, sms, delivery, order notifications, appointment booking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Text each buyer when their order is ready, and let Send4's AI book the delivery or installation slot over SMS.

== Description ==

A store that sells sofas, fridges, heat pumps or kitchens has to agree a delivery or an installation time with every
buyer, and that usually means somebody phoning round. Send4 for WooCommerce hands the order to Send4 the moment it
reaches a status you choose, such as Processing or your own "Ready for delivery", and the Send4 flow you set up texts
the buyer, answers their questions and books a slot that suits them.

**What it does**

* Sends an order to Send4 when it reaches a status a Send4 flow starts on, your store's own statuses included.
* Ends the conversation when an order is cancelled or refunded, so nobody is texted about an order that is off.
* Never slows down the checkout: orders are queued in your database and sent seconds later, outside the request.
* Retries for about a day and a half if Send4 is briefly unreachable, so an order is never lost.
* Tells Send4 your order statuses and product categories, so you pick them by name when you build the flow, and a
  flow can start only for orders in the categories you choose.
* Warns you when your checkout lets a buyer leave the phone number empty, because such an order cannot be texted.
* Works with WooCommerce's own order tables (HPOS) and with the Cart and Checkout blocks.

**You need a Send4 account and a number to text from.** Send4 is a paid service; this plugin is the WooCommerce half
of it. Send4 texts your buyers through your own Twilio or Vonage account, which you connect in Send4. If your buyers
are in Lithuania, Send4 can provide the sending number instead.

Contact forms are handled by a separate plugin, **Send4**, which you can install next to this one. Each keeps its own
connection to Send4.

== External services ==

This plugin sends data to Send4 (https://send4.ai), the service it integrates with. Nothing is transmitted until you
start connecting the store to Send4, and no order is sent until a Send4 flow starts on one of its statuses.

* **Connecting.** Pressing **Connect with Send4** sends your site's address, the address of this plugin's screen in
  wp-admin and the plugin's name, so that Send4 can create an API key bound to this store and send you back here to
  finish; the one-time code it returns is then exchanged for that key.
* **What is sent for an order:** when an order reaches a status a Send4 flow starts on — the order's number, status,
  total, currency, date, payment method name, shipping method, the buyer's order note and a link to the order in
  wp-admin; the billing first and last name, email address, phone number and country; the shipping address and
  phone; up to 20 items with their name, SKU, quantity and product category and tag slugs, and a one-line summary
  of all items; and the site's language. Order meta is sent only for keys the site names itself, with the
  `send4wc_order_meta_keys` filter.
* **What is sent when an order is cancelled or refunded:** the order's id, number and status, and nothing about the
  buyer, so that Send4 can stop texting about it.
* **When:** seconds after the order's status changes, from WordPress's own scheduled tasks rather than the
  checkout, and again on retry if an attempt failed. Statuses Send4 has not asked for are never sent.
* **What the store has.** So that Send4 can offer your statuses and categories by name when you build a flow, the
  plugin also sends your order statuses (their key and name), your product categories and tags (slug and name),
  whether your checkout requires a phone number, whether WooCommerce's own order tables are on, and the versions of
  this plugin, WordPress, PHP and WooCommerce, the site's language and whether the site is part of a multisite
  network. **No order and no customer is included.** It is sent when you connect, once an hour, shortly after a
  product category or tag changes, and when you press **Sync now**.
* **Checking the account.** Once an hour, and when you open the plugin's screen, the plugin asks Send4 which order
  statuses its flows start on. That request sends nothing but the connection's credentials and the User-Agent
  described below.
* **Where:** to `https://app.send4.ai` by default, over HTTPS.
* **With every request** the plugin identifies itself and its version, and includes your site's address, in the HTTP
  User-Agent header.
* Send4's terms of service: https://send4.ai/terms — privacy policy: https://send4.ai/privacy

While an order event waits to be sent, this store keeps only the order's id and status, never the buyer's details:
the event is built from the order when it is sent.

Because the data you forward includes your buyers' personal information, tell them so. Say at checkout that you will
text them about their delivery, and name Send4 as a processor in your privacy policy; the plugin suggests the
wording under **Settings → Privacy**.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to **WooCommerce → Send4** and press **Connect with Send4**. Approve the connection in Send4; you are brought
   back and should see "Connected to *your account*".
3. In Send4, create a flow whose trigger is **WooCommerce order**, and pick the order statuses that start it — your
   statuses are listed by name, because the plugin reports them.
4. Back in **WooCommerce → Send4**, press **Sync now**, or wait up to an hour. The screen lists the statuses this
   store now sends.
5. Make the phone number required at checkout. An order without one cannot be texted.

== Frequently Asked Questions ==

= Which orders are sent? =

Only orders that reach a status a Send4 flow starts on, with their details. A cancellation or a refund is sent too,
with the order's number and status only, so that Send4 can end that order's conversation if it has one. The plugin
asks Send4 which statuses those are, and **WooCommerce → Send4** lists them.

= Can a flow start on my own order status, such as "Ready for delivery"? =

Yes. Any status your store has is offered, including ones another plugin adds. Set the order to that status when the
goods arrive, and Send4 texts the buyer then.

= Does the buyer wait for Send4 at checkout? =

No. The status change only writes a row to a queue in your database; the order is sent seconds later, outside the
buyer's request.

= What if Send4 is down? =

The order waits in a table in your database and is retried on a backoff — at once, then after a minute, five
minutes, fifteen, an hour, and every six hours for about a day and a half. Send4 keeps one event per order and
status, so a buyer cannot be texted twice for one.

= Which number are my buyers texted from? =

One you connect in Send4. Send4 sends through your own Twilio or Vonage account: add the account's keys under
**Settings → Integrations → Provider credentials** in Send4, add the phone number under **Settings → Phones**, and put
the reply addresses Send4 shows you into your Twilio or Vonage console so that answers come back. The account, the
number and what they cost are yours, and so are the rules your country sets for business texting.

If your buyers are in Lithuania, Send4 can provide the number instead: ask us to add one to your account. It texts
Lithuanian numbers only.

= Do I also need the Send4 plugin? =

Only if you want your contact forms followed up too. **Send4** handles forms; this plugin handles orders. They can run
side by side, and each keeps its own connection.

= Does it work with the Checkout block and with HPOS? =

Yes. The plugin reads orders only through WooCommerce's own functions, so it works whether orders are stored in
WooCommerce's own tables or as posts, and with the classic and the block checkout.

== Changelog ==

= 0.1.0 =
* First release: order events for the statuses Send4 asks for, cancellations and refunds that end a conversation, a
  retry queue, the store's statuses and categories for Send4's flow builder, and the WooCommerce → Send4 screen.
