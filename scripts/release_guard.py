#!/usr/bin/env python3
"""Refuse a Zoop draft release unless this main commit is allowed to ship.

The workflow passes CONFIRM, GITHUB_REF, GITHUB_SHA, and GITHUB_REPOSITORY.
This script does not create a release. It only exits 0 when every safeguard holds.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

REQUIRED_CHECKS = (
    "php (8.1)",
    "php (8.2)",
    "php (8.3)",
    "integration (8.1)",
    "integration (8.2)",
    "integration (8.3)",
    "javascript",
    "e2e",
    "repository-baseline",
)

PASS_VERDICTS = {"PASS", "PASS WITH CONDITIONS"}


def fail(message: str) -> None:
    print(f"Refusing to create a release. {message}", file=sys.stderr)
    raise SystemExit(1)


def gh_json(path: str):
    result = subprocess.run(
        ["gh", "api", path],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip() or "GitHub API request failed"
        fail(detail)
    return json.loads(result.stdout)


def latest_conclusion(checks: list[dict]) -> dict[str, str]:
    """Return the conclusion of the newest run for each check name.

    An unfinished newest run is reported as ``in_progress``.
    """
    grouped: dict[str, list[dict]] = {}
    for check in checks:
        name = str(check.get("name") or "")
        if name:
            grouped.setdefault(name, []).append(check)

    conclusions: dict[str, str] = {}
    for name, rows in grouped.items():
        latest = max(rows, key=lambda row: str(row.get("completed_at") or row.get("started_at") or ""))
        if latest.get("status") != "completed":
            conclusions[name] = "in_progress"
            continue
        conclusions[name] = str(latest.get("conclusion") or "missing")
    return conclusions


def require_checks(conclusions: dict[str, str]) -> None:
    missing = [name for name in REQUIRED_CHECKS if name not in conclusions]
    if missing:
        fail("Required CI checks have not run for this commit: " + ", ".join(missing))
    failed = [f"{name} ({conclusions[name]})" for name in REQUIRED_CHECKS if conclusions[name] != "success"]
    if failed:
        fail("Required CI checks are not green: " + ", ".join(failed))


def gate_b_verdict(path: Path) -> None:
    if not path.is_file():
        fail(
            "docs/reviews/gate-b.md is missing. Gate B is an independent review, "
            "and this packet has not been filed."
        )
    reviewer = ""
    verdict = ""
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith("Reviewer: "):
            reviewer = line[len("Reviewer: ") :].strip()
        elif line.startswith("Verdict: "):
            verdict = line[len("Verdict: ") :].strip()
    if verdict not in PASS_VERDICTS:
        fail(
            f"Gate B verdict is {verdict or 'missing'}. "
            "docs/reviews/gate-b.md must say 'Verdict: PASS' or 'Verdict: PASS WITH CONDITIONS'."
        )
    if not reviewer:
        fail("docs/reviews/gate-b.md must name the reviewer on a 'Reviewer:' line.")


def require_approval(repository: str, sha: str) -> None:
    pulls = gh_json(f"repos/{repository}/commits/{sha}/pulls")
    if not isinstance(pulls, list):
        fail("GitHub did not return the pull requests for this commit.")
    merged = [
        pull
        for pull in pulls
        if pull.get("merged_at") and (pull.get("base") or {}).get("ref") == "main"
    ]
    if not merged:
        fail(f"{sha} was not merged into main through a pull request.")

    for pull in merged:
        number = pull.get("number")
        author = ((pull.get("user") or {}).get("login")) or ""
        page = 1
        while True:
            reviews = gh_json(f"repos/{repository}/pulls/{number}/reviews?per_page=100&page={page}")
            if not isinstance(reviews, list):
                fail(f"GitHub did not return reviews for pull request #{number}.")
            for review in reviews:
                login = ((review.get("user") or {}).get("login")) or ""
                if review.get("state") == "APPROVED" and login and login != author:
                    print(f"Approved pull request #{number} by {login}.")
                    return
            if len(reviews) < 100:
                break
            page += 1
    fail("The merged pull request has no approving review from someone other than its author.")


def require_ci(repository: str, sha: str) -> None:
    page = 1
    checks: list[dict] = []
    while True:
        payload = gh_json(f"repos/{repository}/commits/{sha}/check-runs?per_page=100&page={page}")
        batch = payload.get("check_runs", []) if isinstance(payload, dict) else None
        if not isinstance(batch, list):
            fail("GitHub did not return check runs for this commit.")
        checks.extend(batch)
        if len(batch) < 100:
            break
        page += 1
    require_checks(latest_conclusion(checks))
    print("Required CI checks are green.")


def main() -> None:
    confirm = os.environ.get("CONFIRM", "")
    ref = os.environ.get("GITHUB_REF", "")
    sha = os.environ.get("GITHUB_SHA", "")
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    root = Path(__file__).resolve().parent.parent

    if confirm != "publish":
        fail("Type publish in the confirm field.")
    if ref != "refs/heads/main":
        fail(f"Run this workflow from main. The selected ref is {ref or 'unknown'}.")

    gate_b_verdict(root / "docs" / "reviews" / "gate-b.md")
    print("Gate B verdict is recorded.")

    if not sha or not repository:
        fail("GITHUB_SHA and GITHUB_REPOSITORY are required.")

    require_approval(repository, sha)
    require_ci(repository, sha)
    print(f"Release checks passed for {sha}.")


if __name__ == "__main__":
    main()
