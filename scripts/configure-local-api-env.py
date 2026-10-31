#!/usr/bin/env python3
"""Set API local credentials from infrastructure secrets; never overwrite existing API env."""
from pathlib import Path
import os

ROOT = Path(__file__).resolve().parents[1]
src = ROOT / "infrastructure" / ".env"
example = ROOT / "services" / "api" / ".env.example"
dst = ROOT / "services" / "api" / ".env"

if not src.is_file() or not example.is_file():
    raise SystemExit("Generate infrastructure/.env and bootstrap Laravel first.")
if dst.exists():
    raise SystemExit("Existing services/api/.env will not be overwritten.")

values = {}
for line in src.read_text(encoding="utf-8").splitlines():
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    values[key] = value
needed = ["POSTGRES_DB", "POSTGRES_USER", "POSTGRES_PASSWORD", "POSTGRES_PORT", "REDIS_PASSWORD", "REDIS_PORT"]
if any(not values.get(k) for k in needed):
    raise SystemExit("Infrastructure credentials are incomplete.")

replacements = {
    "DB_DATABASE": values["POSTGRES_DB"],
    "DB_USERNAME": values["POSTGRES_USER"],
    "DB_PASSWORD": values["POSTGRES_PASSWORD"],
    "DB_PORT": values["POSTGRES_PORT"],
    "REDIS_PASSWORD": values["REDIS_PASSWORD"],
    "REDIS_PORT": values["REDIS_PORT"],
}
lines = []
for line in example.read_text(encoding="utf-8").splitlines():
    key = line.partition("=")[0]
    if key in replacements and "=" in line:
        lines.append(key + "=" + replacements[key])
    else:
        lines.append(line)
fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, "w", encoding="utf-8") as file:
    file.write("\n".join(lines) + "\n")
os.chmod(dst, 0o600)
print("Created gitignored services/api/.env (0600) without exposing secrets.")
