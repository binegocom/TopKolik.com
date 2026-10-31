#!/usr/bin/env python3
"""Inspect or update task status. Never silently mark implementation done."""
import argparse
import json
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
manifest = ROOT / "ROADMAP.json"
parser = argparse.ArgumentParser()
parser.add_argument("command", choices=["check", "render", "set"])
parser.add_argument("--task")
parser.add_argument("--status")
parser.add_argument("--evidence")
parser.add_argument("--test-command")
parser.add_argument("--test-result")
parser.add_argument("--commit")
args = parser.parse_args()
data = json.loads(manifest.read_text(encoding="utf-8"))
tasks = data["tasks"]
statuses = set(data["tracking_policy"]["statuses"])

if args.command == "set":
    task = next((x for x in tasks if x["id"] == args.task), None)
    if task is None or args.status not in statuses:
        parser.error("Known --task and valid --status are required")
    if args.status == "done":
        if not args.evidence:
            parser.error("--evidence required for completion")
        if task["phase"] != "P00" and not all([args.test_command, args.test_result, args.commit]):
            parser.error("Implementation completion requires test command, result and PR/commit reference")
        task["verified_at"] = date.today().isoformat()
    task["status"] = args.status
    if args.evidence:
        task.setdefault("evidence", []).append({"type": "reference", "value": args.evidence})
    if args.status == "done" and task["phase"] != "P00":
        task["evidence"].extend([
            {"type": "verification", "command": args.test_command, "result": args.test_result},
            {"type": "revision", "value": args.commit},
        ])
    manifest.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Updated {task['id']} => {task['status']}; inspect git diff and rerun checks.")
elif args.command == "check":
    for task in tasks:
        if task["status"] not in statuses:
            raise SystemExit(f"Invalid status: {task['id']}")
        if task["status"] == "done":
            evidence = task.get("evidence", [])
            if not task.get("verified_at") or not evidence:
                raise SystemExit(f"Missing completion evidence: {task['id']}")
            if task["phase"] != "P00" and not {"verification", "revision"} <= {e.get("type") for e in evidence}:
                raise SystemExit(f"Missing implementation tests/revision: {task['id']}")
    print(f"STATUS PASS: {len(tasks)} tracked, {sum(t['status']=='done' for t in tasks)} documented as done.")
else:
    lines = [
        "# Topkolik — Generated project status", "",
        "> Automated from ROADMAP.json. Does not certify release readiness.", "",
        f"**Tasks:** {len(tasks)} | **Done:** {sum(t['status']=='done' for t in tasks)}", "",
        "| Phase | Todo | In progress | Blocked | In review | Done |",
        "|---|---:|---:|---:|---:|---:|",
    ]
    for phase in data["phases"]:
        subset = [t for t in tasks if t["phase"] == phase["id"]]
        counts = [sum(t["status"] == state for t in subset) for state in ["todo", "in_progress", "blocked", "in_review", "done"]]
        lines.append("| " + phase["id"] + " " + phase["name"] + " | " + " | ".join(map(str, counts)) + " |")
    lines += ["", "## Tasks", ""]
    for task in tasks:
        lines.append(f"- {task['id']} — {task['status']} — {task['title']}")
    (ROOT / "PROJECT_STATUS.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("Rendered PROJECT_STATUS.md; review diff before committing.")
