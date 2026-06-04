#!/usr/bin/env python3
"""
One-shot, auditable rename helper for the WordPress.org review.

Renames the plugin's *public identity* only — display name, slug, text domain
and the broken GitHub URLs. It deliberately does NOT touch the internal code
prefixes (mathisfx_ / MATHISFX_ / namespace Mathis\\FacturX\\), which are already
unique and guideline-compliant.

Each replacement is an EXACT string swap, applied across a fixed allow-list of
files, and the script reports a per-file count so the diff can be eye-checked.

Usage:
  python bin/rename-plugin.py --check   # report counts, write nothing
  python bin/rename-plugin.py           # apply
"""
import io
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# (old, new) exact string pairs. Order matters: most specific first so a later,
# broader rule never re-hits text an earlier rule already rewrote.
REPLACEMENTS = [
    # Broken GitHub URLs (wrong account 'mathisderoy' -> 'mathisderoy-droid').
    # Do these BEFORE the slug rule (they contain the old slug).
    ("https://github.com/mathisderoy/factur-x-for-woocommerce",
     "https://github.com/mathisderoy-droid/facturflow-invoices-for-woocommerce"),
    ("https://github.com/mathisderoy",
     "https://github.com/mathisderoy-droid"),
    # Display name.
    ("Factur-X for WooCommerce", "FacturFlow Invoices for WooCommerce"),
    # Slug / text domain (covers the quoted text-domain in __() calls, the
    # readme stable header, paths, etc.). Done last so URL/name rules ran first.
    ("factur-x-for-woocommerce", "facturflow-invoices-for-woocommerce"),
]

# Files whose *public identity* strings must change. Internal vendor/ is excluded.
FILES = [
    "factur-x-for-woocommerce.php",
    "uninstall.php",
    "readme.txt",
    "phpcs.xml.dist",
    "languages/factur-x-for-woocommerce.pot",
    "includes/class-admin-download.php",
    "includes/class-admin-order-metabox.php",
    "includes/class-admin-orders.php",
    "includes/class-checkout-fields.php",
    "includes/class-email.php",
    "includes/class-invoice-generator.php",
    "includes/class-invoice-numbering.php",
    "includes/class-invoice-post-type.php",
    "includes/class-order-meta.php",
    "includes/class-pdf-renderer.php",
    "includes/class-plugin.php",
    "includes/class-settings.php",
    "includes/class-siret-validator.php",
    "includes/class-vies-validator.php",
    "includes/class-xml-builder.php",
    "templates/invoice/default.php",
    "composer.json",
]


def main(argv):
    check = "--check" in argv
    grand = 0
    for rel in FILES:
        path = os.path.join(ROOT, rel)
        if not os.path.isfile(path):
            print("  [skip] missing: %s" % rel)
            continue
        with io.open(path, "r", encoding="utf-8") as f:
            text = f.read()
        original = text
        per_file = 0
        for old, new in REPLACEMENTS:
            c = text.count(old)
            if c:
                text = text.replace(old, new)
                per_file += c
        if per_file:
            grand += per_file
            print("  %-55s %d replacement(s)" % (rel, per_file))
            if not check and text != original:
                with io.open(path, "w", encoding="utf-8", newline="\n") as f:
                    f.write(text)
    print(("[CHECK] " if check else "[WRITE] ") + "total replacements: %d" % grand)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
