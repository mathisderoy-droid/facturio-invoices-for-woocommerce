=== FacturFlow Invoices for WooCommerce ===
Contributors: mathisdd
Tags: factur-x, e-invoicing, woocommerce, invoice, france
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate compliant Factur-X invoices (PDF/A-3 + EN 16931 XML) from your WooCommerce orders for the French 2026 e-invoicing reform.

== Description ==

**FacturFlow Invoices for WooCommerce** turns every WooCommerce order into a compliant **Factur-X** invoice: a human-readable PDF with a structured XML file (EN 16931 profile) embedded inside it, which accounting software and the tax administration can read automatically. Factur-X is the pivot format chosen by France for the 2026-2027 e-invoicing reform.

The plugin is built for French WooCommerce stores that sell to businesses (B2B) and must issue compliant electronic invoices, without changing their accounting software.

= Free version features (V0.1) =

* **Automatic generation** of an EN 16931 Factur-X invoice when an order reaches the "processing" or "completed" status (your choice).
* **Hybrid PDF/A-3**: the CII XML (`factur-x.xml`) is embedded in the PDF, with the Factur-X XMP metadata and the `/Alternative` relationship required by the standard.
* **Business number validation at checkout**:
  * SIRET checked live against the **INSEE Sirene API** (the legal company name is fetched automatically).
  * **Intra-community VAT number** checked against the European Commission **VIES** service.
* **Tamper-proof sequential numbering** (format `F2026-000001`), with no gaps and no duplicates, as required by French law.
* **Set the next invoice number** to resume an existing series — useful when migrating from another tool mid-year (the plugin refuses any value that would reuse a number).
* **B2B checkout fields**: an "I am ordering for my company" checkbox plus company name, SIRET, VAT and APE code.
* **Customisable logo and primary colour** on the invoice.
* **Download** the invoice from the orders list and the order edit screen, with a **regenerate** option.
* **Automatic email delivery**: the invoice is attached to the WooCommerce order email sent to the customer.
* **HPOS compatible** (High-Performance Order Storage).
* **French interface**, translation-ready (a `.pot` file is included).

= Not included in the free version (yet) =

* Automatic routing to an accredited platform (Plateforme Agréée / former PDP) — planned for the Pro version.
* Automatic B2C e-reporting — planned for the Pro version.
* Credit notes, the EXTENDED-CTC-FR profile and multi-store — planned for later versions.

= Compliance validation =

Generated invoices are designed to pass the **official FNFE-MPE validator** (https://services.fnfe-mpe.org/): valid PDF/A-3, valid XMP, valid XML against the EN 16931 XSD and Schematron.

== Installation ==

1. Install and activate the plugin from the "Plugins" screen in WordPress.
2. Activate **WooCommerce** if it is not already.
3. Go to **WooCommerce → Settings → Factur-X**.
4. Fill in your **seller details**: legal name, SIRET, intra-community VAT number, address, APE code, legal mentions.
5. In the **Integrations** tab, paste your **INSEE Sirene API key** (free, request it at https://portail-api.insee.fr/) to enable SIRET validation at checkout.
6. In the **Appearance** tab, choose your logo and primary colour.
7. Choose the **trigger status** for automatic generation ("processing" or "completed").

Done: every new order reaching that status generates its Factur-X invoice.

== Frequently Asked Questions ==

= Do I need an API key for the plugin to work? =

The plugin generates compliant Factur-X invoices without any key. The (free) **INSEE Sirene** key is only used to verify SIRET numbers live at checkout and to pre-fill the company name. Without it, only the local format of the SIRET is checked.

= Does the plugin route my invoices to an accredited platform? =

Not in the free version. V0.1 produces and archives the compliant Factur-X invoice. Routing to accredited platforms (Iopole, B2Brouter, Pennylane, etc.) is planned for the Pro version.

= Are my generated invoices deleted if I uninstall the plugin? =

No. Uninstalling removes only the plugin **settings** and its temporary caches. It **keeps your whole invoice archive**: the PDF files in `wp-content/uploads/factur-x/`, the invoice records, the B2B data stored on your orders, and the numbering counter — because invoices are legal documents that must be archived, and so that a later re-install never re-issues an already-used number.

= Is the plugin compatible with High-Performance Order Storage (HPOS)? =

Yes. HPOS compatibility is declared and all order reads/writes go through the WooCommerce API.

= VAT validation shows "service unavailable" — is that a bug? =

No. The European VIES service (and the French registry in particular) limits the number of concurrent requests and is regularly down. The plugin retries automatically, and checkout is never blocked: only the local format check is required.

== External services ==

This plugin validates business identifiers against two official third-party services. A request is sent **only** when a SIRET or VAT number is entered in the B2B checkout fields (or saved in the settings); nothing is sent otherwise. The plugin includes no analytics and no tracking.

* **INSEE Sirene API** — when a SIRET is entered, the 14-digit SIRET is sent to the INSEE Sirene API to confirm the establishment exists and to retrieve its legal company name. A request is made only if you have configured your own (free) INSEE API key; with no key, the SIRET is only checked locally and nothing is sent. Service: https://api.insee.fr/ — Terms: https://api.insee.fr/catalogue/ — Privacy: https://www.insee.fr/fr/information/2381863
* **European Commission VIES** — when an intra-community VAT number is entered, that VAT number is sent to the EU VIES service to check its validity (and, when the member state returns it, the trader name). Service & terms: https://ec.europa.eu/taxation_customs/vies/

No invoice content or customer data is sent to the plugin author or to any other server.

== Screenshots ==

1. Seller details settings (WooCommerce → Settings → Factur-X).
2. B2B fields and live SIRET validation at checkout.
3. Generated Factur-X invoice (readable PDF + embedded XML).
4. "Invoice" column and download from the orders list.

== Changelog ==

= 0.1.0 =
* Initial release.
* Automatic generation of EN 16931 Factur-X invoices (PDF/A-3 + embedded CII XML).
* SIRET (INSEE Sirene) and intra-community VAT (VIES) validation at checkout.
* Tamper-proof sequential numbering, with an editable "next invoice number" to resume an existing series.
* B2B checkout fields, HPOS compatible.
* Customisable logo and colour.
* Download and regenerate from the admin, email attachment.

== Upgrade Notice ==

= 0.1.0 =
Initial release of the plugin.
