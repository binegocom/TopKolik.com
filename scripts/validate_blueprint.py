#!/usr/bin/env python3
"""Offline validation of Topkolik planning and evidence integrity."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
required = [
    "README.md", "AGENTS.md", "PROJECT_STATUS.md", "ROADMAP.json",
    "docs/product/ROADMAP.md", "docs/product/PRODUCT_REQUIREMENTS.md",
    "docs/product/SCREENS_AND_WORKFLOWS.md", "docs/product/DECISION_REGISTER.md",
    "docs/architecture/ARCHITECTURE.md", "docs/architecture/DATA_MODEL.md",
    "docs/architecture/AI_NEWS_PIPELINE.md", "docs/architecture/API_CONTRACT.md",
    "docs/security/IDENTITY_AND_ACCESS.md", "docs/security/THREAT_MODEL.md",
    "docs/design/DESIGN_SYSTEM.md", "docs/design/TOKENS.json",
    "docs/business/OPERATIONS_AND_REVENUE.md",
    "docs/operations/INFRASTRUCTURE.md", "docs/operations/INCIDENT_RUNBOOKS.md",
    "docs/qa/TEST_AND_RELEASE_PLAN.md",
    "docs/compliance/LICENSING_AND_EDITORIAL_POLICY.md",
    "docs/research/OFFICIAL_REFERENCES.md", "assets/brand/README.md",
]
issues = [f"Missing/empty: {name}" for name in required if not (ROOT / name).is_file() or (ROOT / name).stat().st_size == 0]
data = json.loads((ROOT / "ROADMAP.json").read_text(encoding="utf-8"))
tasks = data["tasks"]
phases = {p["id"] for p in data["phases"]}
ids = [t["id"] for t in tasks]
if len(ids) != len(set(ids)):
    issues.append("Duplicate task IDs")
for task in tasks:
    if task["phase"] not in phases or task["status"] not in data["tracking_policy"]["statuses"]:
        issues.append(f"Bad phase or status: {task['id']}")
    if not task.get("implementation") or not task.get("acceptance_criteria"):
        issues.append(f"Missing task specifications: {task['id']}")
    if task["status"] == "done":
        evidence = task.get("evidence", [])
        if not evidence or not task.get("verified_at"):
            issues.append(f"Missing evidence/date: {task['id']}")
        for item in evidence:
            if item.get("type") == "file" and not (ROOT / item.get("path", "")).is_file():
                issues.append(f"Missing evidence file for {task['id']}: {item.get('path')}")
        if task["phase"] != "P00":
            if any(item.get("type") == "file" for item in evidence):
                issues.append(f"Implementation cannot be certified by file presence: {task['id']}")
            if not {"verification", "revision"} <= {x.get("type") for x in evidence}:
                issues.append(f"Missing tests/revision: {task['id']}")
print(f"Topkolik blueprint: {len(tasks)} tasks, {len(phases)} phases, {sum(t['status']=='done' for t in tasks)} done.")
if issues:
    for issue in issues:
        print("FAIL:", issue)
    raise SystemExit(1)
print("VALIDATION PASS: structure and evidence metadata.")
