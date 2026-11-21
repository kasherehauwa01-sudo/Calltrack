"""Real PHP CLI, synthetic signed evidence and disposable MariaDB. No production access."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
if os.environ.get("ANALYTICS_TASK_TEST_DSN") != "mysql:host=analytics-test-mariadb;dbname=analytics_tasks_test":
    raise SystemExit("Disposable MariaDB fixture required")
with tempfile.TemporaryDirectory(prefix="churn-cli-test-") as temp:
    prepend = Path(temp, "bootstrap.php")
    prepend.write_text("<?php define('DB_PASS','');")
    prepend.chmod(0o600)
    php = ["php", "-d", "auto_prepend_file=" + str(prepend)]
    fixture = subprocess.run(php + [str(root/"tests/churn_permanent_cli_fixture.php")], check=True, capture_output=True, text=True)
    data = json.loads(fixture.stdout.strip().splitlines()[-1])
    env = {**os.environ, **data["environment"], "CALLTRACK_DB_HOST": "analytics-test-mariadb",
           "CALLTRACK_DB_NAME": "analytics_tasks_test", "CALLTRACK_DB_USER": "root"}
    def cli(action, mode, page=None):
        return subprocess.run(php + [str(root/"api/analytics_permanent_cli.php"), action],
            input=json.dumps(page if page is not None else {}), env={**env, "CALLTRACK_ANALYTICS_MODE": mode},
            capture_output=True, text=True)
    def count():
        code = "require $argv[1].'/api/config.php';echo getPdo()->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn();"
        # php -r does not use auto_prepend_file.
        code = "define('DB_PASS','');" + code
        return int(subprocess.check_output(["php", "-r", code, str(root)], env=env, stderr=subprocess.DEVNULL))
    before = count()
    assert json.loads(cli("health", "disabled").stdout) == {"receiver":"reachable"}
    assert cli("deliver", "disabled", data["page"]).returncode != 0
    assert cli("deliver", "dry_run", data["page"]).returncode != 0
    for mode in ("dry_run", "enabled"):
        preview = cli("preflight", mode, data["page"])
        assert preview.returncode == 0, preview.stderr
        result = json.loads(preview.stdout)
        assert result["job_id"] == data["page"]["job_id"]
        assert result["release_id"] == data["page"]["release_id"]
        assert result["receipts"][0]["decision"] == "DRY_RUN_READY", result
        assert count() == before
    delivered = cli("deliver", "enabled", data["page"])
    assert delivered.returncode == 0, delivered.stderr
    receipt = json.loads(delivered.stdout)["receipts"][0]
    assert receipt["decision"] == "TASK_CREATED" and receipt["created_for_release"] is True, receipt
    replay = json.loads(cli("deliver", "enabled", data["page"]).stdout)["receipts"][0]
    assert replay["decision"] == "TASK_EXISTS" and replay["task_id"] == receipt["task_id"]
    assert replay["created_for_release"] is True
    assert count() == before + 1
    invalid = dict(data["page"], events=data["page"]["events"]*2)
    assert cli("deliver", "enabled", invalid).returncode != 0
    assert count() == before + 1
print("churn_permanent_cli_test: health, modes, exact receipts, no dry-run tasks, replay and duplicates OK")
