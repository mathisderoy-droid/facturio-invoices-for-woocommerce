#!/usr/bin/env python3
"""
Rewrite the hard-coded `horstoeko\\zugferd` namespace inside horstoeko/zugferd's
YAML metadata files to the Strauss-scoped `Mathis\\FacturX\\Vendor\\horstoeko\\zugferd`.

WHY THIS EXISTS
  Strauss rewrites PHP class names when scoping dependencies, but it does NOT
  look inside the 267 *.yml metadata files that jms/serializer (used by zugferd)
  reads at runtime to map classes to XML. After scoping, the PHP class is
  `Mathis\\FacturX\\Vendor\\horstoeko\\zugferd\\...` while the YAML still says
  `horstoeko\\zugferd\\...`, so the serializer throws:
    "Expected metadata for class ...\\CrossIndustryInvoiceType to be defined in
     .../CrossIndustryInvoiceType.yml"
  and NO invoice can be generated from the built (scoped) plugin.

USAGE
  python bin/fix-zugferd-yaml-namespace.py <path-to-zugferd-yaml-dir> [--check]

  --check : read-only; report what would change, write nothing, exit non-zero
            if any old reference remains.

Idempotent: already-prefixed references are left untouched (no double prefix).
"""
import io
import os
import sys

OLD = "horstoeko\\zugferd"
PREFIX = "Mathis\\FacturX\\Vendor\\"
NEW = PREFIX + OLD


def iter_yaml(root):
    for dirpath, _dirs, files in os.walk(root):
        for fn in files:
            if fn.endswith(".yml"):
                yield os.path.join(dirpath, fn)


def transform(text):
    # Mask already-prefixed refs so we never double-prefix, then replace, unmask.
    mask = "\x00M\x00"
    masked = text.replace(NEW, mask)
    masked = masked.replace(OLD, NEW)
    return masked.replace(mask, NEW)


def unprefixed_count(text):
    """Count OLD references that are NOT already Strauss-prefixed.

    OLD is a substring of NEW, so a plain `text.count(OLD)` also counts the
    already-correct prefixed refs. Mask the prefixed ones out first.
    """
    mask = "\x00M\x00"
    return text.replace(NEW, mask).count(OLD)


def main(argv):
    if len(argv) < 2:
        print("usage: fix-zugferd-yaml-namespace.py <yaml-dir> [--check]", file=sys.stderr)
        return 2
    root = argv[1]
    check = "--check" in argv[2:]
    if not os.path.isdir(root):
        print("ERROR: not a directory: " + root, file=sys.stderr)
        return 2

    remaining = changed = 0
    for path in iter_yaml(root):
        with io.open(path, "r", encoding="utf-8") as f:
            text = f.read()
        # Count genuinely unprefixed refs (ignores already-correct ones).
        if unprefixed_count(text) == 0:
            continue
        new_text = transform(text)
        if check:
            remaining += 1
        else:
            changed += 1
            with io.open(path, "w", encoding="utf-8", newline="\n") as f:
                f.write(new_text)

    mode = "CHECK" if check else "WRITE"
    if check:
        print("[CHECK] files still referencing the unscoped namespace: %d" % remaining)
        if remaining:
            print("FAIL: zugferd YAML namespace not fully scoped.")
            return 1
        print("OK: all zugferd YAML metadata is Strauss-scoped.")
        return 0
    print("[WRITE] yaml files rewritten: %d" % changed)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
