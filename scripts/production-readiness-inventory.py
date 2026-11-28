#!/usr/bin/env python3
"""Inventory repository-owned files without loading application credentials.

An inventory is coverage accounting, not a semantic review. Agent review records
are accepted only when their SHA-256 still matches the file on disk.
"""

import csv
import hashlib
import json
import re
import subprocess
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / "docs/production-readiness"
OUTPUTS = {"file-inventory.csv", "inventory-summary.json", "configuration-register.csv"}


def git(*args):
    return subprocess.check_output(["git", *args], cwd=ROOT)


def owner(path):
    if path.startswith("app/Http/") or path.startswith(("resources/", "frontend/")) or (
        path.startswith("routes/") and path != "routes/console.php"
    ):
        return "frontend-api"
    if path.startswith(("app/", "database/")):
        return "backend-database"
    if path.startswith(("config/", "bootstrap/", "scripts/", "tests/", ".github/", "ml/")) or path in {
        "phpunit.xml", "composer.json", "composer.lock", "package.json", "package-lock.json",
        "routes/console.php", "compose.yaml", "deploy.sh",
    }:
        return "operations-quality"
    return "integration-documentation"


def configuration_variables(root, destination):
    """Literal references plus source-bound, manually reviewed computed lookups.

    This lexical inventory does not evaluate PHP or claim control-flow coverage.
    A changed source invalidates every supplemental lookup for that file.
    """
    receipts = {}
    receipt_path = destination / "configuration-static-lookups.csv"
    if receipt_path.is_file():
        with receipt_path.open(newline="") as handle:
            for row in csv.DictReader(handle):
                receipts.setdefault(row.get("configuration_file", ""), []).append(row)
    references, computed_sites = [], []
    for source in sorted((root / "config").glob("*.php")):
        relative = str(source.relative_to(root))
        content = source.read_text()
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        keys = set(re.findall(r"\benv\(\s*['\"]([A-Z][A-Z0-9_]*)['\"]", content))
        for line_number, line in enumerate(content.splitlines(), 1):
            if not re.search(r"\benv\(\s*\$", line):
                continue
            accepted = [row for row in receipts.get(relative, [])
                        if row.get("sha256") == digest
                        and row.get("lookup_line") == str(line_number)
                        and row.get("status") == "reviewed_static_keys"
                        and re.fullmatch(r"[A-Z][A-Z0-9_]*", row.get("variable", ""))
                        and row.get("evidence", "").strip()]
            keys.update(row["variable"] for row in accepted)
            computed_sites.append({"configuration_file": relative, "line": line_number,
                                   "state": "reviewed_static_keys" if accepted else "unresolved"})
        references.extend({"configuration_file": relative, "variable": key} for key in sorted(keys))
    return references, computed_sites


def main():
    DEST.mkdir(parents=True, exist_ok=True)
    paths = sorted(set(filter(None, git("ls-files", "--cached", "--others", "--exclude-standard", "-z").decode().split("\0"))))
    review_records = {}
    reports = set(DEST.glob("*-coverage.csv")) | set(DEST.glob("*-coverage-*.csv"))
    for report in sorted(reports):
        with report.open(newline="") as handle:
            for row in csv.DictReader(handle):
                path = row.get("path", row.get("file", ""))
                if path:
                    row["evidence"] = report.name
                    # Keep every receipt: an older reviewer record must not
                    # displace a different review of the current file bytes.
                    review_records.setdefault(path, []).append(row)
    records = []
    for path in paths:
        relative = Path(path)
        if relative.parent == Path("docs/production-readiness") and relative.name in OUTPUTS:
            continue  # Generated ledgers cannot hash themselves consistently.
        file = ROOT / path
        status, digest, size, evidence = "inventoried", "", 0, ""
        if file.is_symlink():
            status = "symlink_requires_review"
        elif not file.is_file():
            status = "deleted_requires_review"
        else:
            size = file.stat().st_size
            digest = hashlib.sha256(file.read_bytes()).hexdigest()
            if path.startswith(("outputs/", "storage/")) or file.suffix in {".zip", ".gz", ".png", ".jpg", ".webp", ".ico", ".woff2"}:
                status = "artifact_inventoried"
            review = next((row for row in review_records.get(path, [])
                           if row.get("sha256", row.get("hash", "")) == digest
                           and row.get("status", row.get("review_status", "")) == "reviewed"), None)
            if review is not None:
                status = "targeted_reviewed"
                evidence = review.get("evidence", "")
        records.append({"path": path, "owner": owner(path), "sha256": digest, "bytes": size, "status": status, "review_evidence": evidence})
    with (DEST / "file-inventory.csv").open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["path", "owner", "sha256", "bytes", "status", "review_evidence"])
        writer.writeheader()
        writer.writerows(records)

    variables = []
    example = ROOT / ".env.example"
    example_keys = set(re.findall(r"^\s*([A-Z][A-Z0-9_]*)=", example.read_text(), re.M))
    references, computed_sites = configuration_variables(ROOT, DEST)
    for reference in references:
        variables.append({**reference,
                          "in_example": "yes" if reference["variable"] in example_keys else "no",
                          "live_verification": "not_verified"})
    with (DEST / "configuration-register.csv").open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["configuration_file", "variable", "in_example", "live_verification"])
        writer.writeheader()
        writer.writerows(variables)
    summary = {
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "head": git("rev-parse", "HEAD").decode().strip(),
        "scope": "Tracked and untracked nonignored repository paths, including pending deletions; no ignored dependencies, credentials, runtime caches, or database dumps.",
        "generated_ledger_exclusions": sorted(OUTPUTS),
        "semantic_review_complete": False,
        "files": len(records),
        "by_owner": dict(Counter(row["owner"] for row in records)),
        "by_status": dict(Counter(row["status"] for row in records)),
        "configuration_inventory_method": "Lexical literal env() references plus SHA-bound manually reviewed static keys at computed env() sites; no PHP execution or general data-flow analysis.",
        "computed_configuration_sites": computed_sites,
        "unresolved_computed_configuration_sites": sum(site["state"] == "unresolved" for site in computed_sites),
        "configuration_references": len(variables),
        "distinct_configuration_variables": len({row["variable"] for row in variables}),
        "warning": "Inventoried means identified and hashed, not reviewed, tested, secure, or production ready. Missing example keys may be optional; each needs classification.",
    }
    (DEST / "inventory-summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
