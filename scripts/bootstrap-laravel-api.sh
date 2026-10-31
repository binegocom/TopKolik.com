#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$ROOT/services/api"
TEMPLATE="$ROOT/scripts/laravel-template"

if [[ -e "$TARGET/artisan" ]]; then
  echo 'Laravel API already exists. Refusing to overwrite an existing application.' >&2
  exit 1
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
composer create-project laravel/laravel "$tmp/app" '^13.0' --prefer-dist --no-interaction --no-progress --no-scripts

mkdir -p "$TARGET/app/Http/Middleware" "$TARGET/app/Http/Controllers" "$TARGET/routes" "$TARGET/tests/Feature"
rsync -a --ignore-existing \
  --exclude='/vendor/' --exclude='/.env' --exclude='/.git/' \
  --exclude='/node_modules/' --exclude='/database/database.sqlite' \
  --exclude='/.github/' --exclude='/AGENTS.md' --exclude='/CLAUDE.md' \
  --exclude='/resources/' --exclude='/package.json' --exclude='/package-lock.json' \
  --exclude='/vite.config.js' --exclude='/tests/Feature/ExampleTest.php' \
  --exclude='/tests/Unit/ExampleTest.php' \
  "$tmp/app/" "$TARGET/"

cp "$TEMPLATE/bootstrap-app.php" "$TARGET/bootstrap/app.php"
cp "$TEMPLATE/routes-api.php" "$TARGET/routes/api.php"
cp "$TEMPLATE/routes-web.php" "$TARGET/routes/web.php"
cp "$TEMPLATE/ReadinessController.php" "$TARGET/app/Http/Controllers/ReadinessController.php"
cp "$TEMPLATE/AttachRequestId.php" "$TARGET/app/Http/Middleware/AttachRequestId.php"
cp "$TEMPLATE/HealthEndpointTest.php" "$TARGET/tests/Feature/HealthEndpointTest.php"
cp "$TEMPLATE/env.example" "$TARGET/.env.example"

python3 - "$TARGET" <<'PY'
import json
import sys
from pathlib import Path
from xml.etree import ElementTree

target = Path(sys.argv[1])
composer_path = target / "composer.json"
composer = json.loads(composer_path.read_text(encoding="utf-8"))
composer["name"] = "binegocom/topkolik-api"
composer["description"] = "Topkolik API and verified sports-news editorial service"
for key in ("setup", "dev", "post-create-project-cmd", "post-root-package-install"):
    composer.get("scripts", {}).pop(key, None)
composer_path.write_text(json.dumps(composer, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")

xml_path = target / "phpunit.xml"
xml = ElementTree.parse(xml_path)
unit = xml.find('.//testsuite[@name="Unit"]')
if unit is None:
    raise SystemExit("Laravel 13 starter PHPUnit Unit suite missing")
ElementTree.SubElement(unit, "exclude").text = "tests/Unit/PublicationGuardTest.php"
for element in list(xml.findall(".//php/env")):
    if element.attrib.get("name") in {"DB_CONNECTION", "DB_DATABASE", "DB_URL"}:
        parent = xml.find("./php")
        parent.remove(element)
ElementTree.indent(xml, space="    ")
xml.write(xml_path, encoding="unicode", xml_declaration=True)
PY

(
  cd "$TARGET"
  composer update --lock --no-install --no-scripts --no-interaction --no-progress
)
echo 'Laravel 13 API scaffold generated without overwriting the existing editorial domain.'
