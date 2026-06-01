#!/usr/bin/env python3
"""Quick local inspector for a generated Factur-X PDF.

Checks the things the FNFE-MPE validator checks structurally, WITHOUT needing
their (sometimes-down) web service:
  - PDF/A-3 identification in the XMP metadata
  - Factur-X XMP block (DocumentType / DocumentFileName / Version / profile)
  - the embedded factur-x.xml, extracted and pretty-printed with key fields

Usage: python inspect-facturx.py <invoice.pdf>
"""
import re
import sys
import zlib


def find_embedded_xml(raw: bytes):
    """Return the decompressed factur-x.xml bytes, or None."""
    # Embedded files are FlateDecode streams. Find every stream, inflate it,
    # and keep the one that looks like the CII XML.
    for m in re.finditer(rb"stream\r?\n(.*?)\r?\nendstream", raw, re.DOTALL):
        chunk = m.group(1)
        for candidate in (chunk, chunk.strip(b"\r\n")):
            try:
                data = zlib.decompress(candidate)
            except Exception:
                continue
            if b"CrossIndustryInvoice" in data:
                return data
    # Fallback: maybe stored uncompressed.
    m = re.search(rb"(<\?xml.*?CrossIndustryInvoice.*?</rsm:CrossIndustryInvoice>)", raw, re.DOTALL)
    return m.group(1) if m else None


def show(label, found):
    print(("  [OK] " if found else "  [!!] ") + label + (": yes" if found else ": NOT FOUND"))


def main(argv):
    if len(argv) < 2:
        print("usage: inspect-facturx.py <invoice.pdf>", file=sys.stderr)
        return 2
    raw = open(argv[1], "rb").read()
    print("File: %s (%d bytes)" % (argv[1], len(raw)))

    print("\n== PDF / PDF-A ==")
    show("PDF header", raw[:5] == b"%PDF-")
    show("PDF/A part=3 (XMP pdfaid:part 3)", re.search(rb"pdfaid[:>].{0,40}part[^0-9]{0,10}>?\s*3", raw, re.DOTALL) is not None or b"part=\"3\"" in raw or b">3</pdfaid:part>" in raw)

    print("\n== Factur-X XMP ==")
    show("Factur-X XMP namespace", b"urn:factur-x" in raw or b"fx:" in raw or b"Factur-X" in raw or b"FACTUR-X" in raw)
    show("DocumentFileName factur-x.xml", b"factur-x.xml" in raw)
    show("EN 16931 profile in XMP", b"en16931" in raw.lower())

    print("\n== Embedded CII XML ==")
    xml = find_embedded_xml(raw)
    if not xml:
        print("  [!!] could not extract embedded XML")
        return 1
    text = xml.decode("utf-8", "replace")
    print("  [OK] extracted factur-x.xml (%d bytes)" % len(xml))

    def grab(tag):
        m = re.search(r"<%s>(.*?)</%s>" % (tag, tag), text, re.DOTALL)
        return m.group(1).strip() if m else None

    # A few key business identifiers.
    fields = {
        "Profile (GuidelineSpecifiedDocumentContextParameter/ID)":
            re.search(r"GuidelineSpecifiedDocumentContextParameter>\s*<ram:ID>(.*?)</ram:ID>", text, re.DOTALL),
        "Invoice number (ram:ID)":
            re.search(r"<rsm:ExchangedDocument>\s*<ram:ID>(.*?)</ram:ID>", text, re.DOTALL),
        "Type code (380)":
            re.search(r"<ram:TypeCode>(.*?)</ram:TypeCode>", text, re.DOTALL),
        "Currency":
            re.search(r"InvoiceCurrencyCode>(.*?)<", text, re.DOTALL),
        "Grand total":
            re.search(r"GrandTotalAmount[^>]*>(.*?)<", text, re.DOTALL),
        "Tax basis total":
            re.search(r"TaxBasisTotalAmount[^>]*>(.*?)<", text, re.DOTALL),
        "Tax total":
            re.search(r"TaxTotalAmount[^>]*>(.*?)<", text, re.DOTALL),
    }
    for label, m in fields.items():
        print("    - %-40s %s" % (label + ":", (m.group(1).strip() if m else "?")))

    # VAT breakdown groups present?
    cats = re.findall(r"<ram:ApplicableTradeTax>.*?<ram:CategoryCode>(.*?)</ram:CategoryCode>.*?<ram:RateApplicablePercent>(.*?)</ram:RateApplicablePercent>", text, re.DOTALL)
    print("    - VAT breakdown groups:")
    if cats:
        for cat, rate in cats:
            print("        category=%s rate=%s%%" % (cat.strip(), rate.strip()))
    else:
        # category-only (exempt 0%) groups have no RateApplicablePercent
        only = re.findall(r"<ram:CategoryCode>(.*?)</ram:CategoryCode>", text)
        print("        (categories seen: %s)" % ", ".join(sorted(set(only))) if only else "none")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
