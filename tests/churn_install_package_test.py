"""Reconstruct only the supplied production overlay, then validate the minimal receiver patch."""
import hashlib
import json
import shlex
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
review = json.loads((root/"deploy/churn-permanent/reviewed-git-baseline.json").read_text())
digest = lambda path: hashlib.sha256(path.read_bytes()).hexdigest()
assert review["baseline_kind"] == "firstvds-production-lifecycle-user-diff"
for name, expected in review["after"].items():
    assert digest(root/name) == expected, name
overlay = review["production_overlay"]
assert digest(root/overlay["path"]) == overlay["sha256"]
assert digest(root/"deploy/churn-permanent/integration.patch") == review["patch_sha256"]
with tempfile.TemporaryDirectory(prefix="churn-install-check-") as directory:
    target = Path(directory)
    archive = subprocess.Popen(["git", "archive", review["base_commit"]], cwd=root, stdout=subprocess.PIPE)
    extraction = subprocess.run(["tar", "-x", "-C", str(target)], stdin=archive.stdout)
    archive.stdout.close()
    assert extraction.returncode == 0 and archive.wait() == 0
    for name, expected in overlay["before"].items():
        assert digest(target/name) == expected, name
    patch = (root/overlay["path"]).read_bytes()
    subprocess.run(["patch", "--batch", "--forward", "--fuzz=0", "-p1"], cwd=target, input=patch, check=True)
    for name, expected in review["before"].items():
        assert digest(target/name) == expected, name
    # Review boundary: the receiver may change only the automatic budget block.
    # All production ACL, soft-delete, reopen and dedup code must remain byte-identical.
    def outside_budget_block(content):
        start = content.rindex("        if($automatic!==null){")
        end = content.index("            if(($signal['generator_version']", start)
        return content[:start], content[end:]
    assert outside_budget_block((target/"api/manager_tasks_service.php").read_text()) == outside_budget_block((root/"api/manager_tasks_service.php").read_text())
    # Execute the real --check logic with only the fixed filesystem root/package
    # assignments substituted in a temporary copy. Never call --apply or the VPS.
    script = (root/"scripts/install_churn_permanent.sh").read_text()
    root_line = "root=/var/www/html/vr/calltrack-v3-20261026"
    package_line = 'package=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)'
    assert script.count(root_line) == 1 and script.count(package_line) == 1
    script = script.replace(root_line, "root="+shlex.quote(str(target)))
    script = script.replace(package_line, "package="+shlex.quote(str(root)))
    sandbox = target/"installer-test.sh"
    sandbox.write_text(script)
    snapshot = {str(p.relative_to(target)):digest(p) for p in (target/"api").rglob("*") if p.is_file()}
    subprocess.run(["bash", str(sandbox), "--check"], check=True)
    assert snapshot == {str(p.relative_to(target)):digest(p) for p in (target/"api").rglob("*") if p.is_file()}
    for name in review["before"]:
        path = target/name
        original_bytes = path.read_bytes()
        path.write_bytes(original_bytes+b" ")
        refused = subprocess.run(["bash", str(sandbox), "--check"], capture_output=True, text=True)
        assert refused.returncode != 0 and "Unsupported actual source:" in refused.stderr, name
        path.write_bytes(original_bytes)
    # Plain cfbab96 must not be accepted for the production task service.
    original = subprocess.check_output(["git", "show", review["base_commit"]+":api/manager_tasks_service.php"], cwd=root)
    assert hashlib.sha256(original).hexdigest() != review["before"]["api/manager_tasks_service.php"]
    service = target/"api/manager_tasks_service.php"
    production_bytes = service.read_bytes()
    service.write_bytes(original)
    assert subprocess.run(["bash", str(sandbox), "--check"], capture_output=True).returncode != 0
    service.write_bytes(production_bytes)
    # A one-byte drift in any guarded production file is rejected by the same digest contract.
    for name, expected in review["before"].items():
        assert hashlib.sha256((target/name).read_bytes()+b" ").hexdigest() != expected
    patch = (root/"deploy/churn-permanent/integration.patch").read_bytes()
    changed = [line[6:] for line in patch.decode().splitlines() if line.startswith("+++ b/")]
    assert "api/manager_tasks.php" not in changed and "api/manager_tasks_view.php" not in changed
    assert set(changed) == {"api/analytics_integration.php", "api/manager_tasks_service.php", "api/analytics_manager_pilot_budget.php", "api/analytics_source_trust.php", "api/analytics_verified_approval.php"}
    subprocess.run(["patch", "--dry-run", "--batch", "--forward", "--fuzz=0", "-p1"], cwd=target, input=patch, check=True)
    subprocess.run(["patch", "--batch", "--forward", "--fuzz=0", "-p1"], cwd=target, input=patch, check=True)
    for name in review["before"]:
        assert digest(target/name) == review["after"][name], name
    # Installation leaves both production endpoint/view files byte-identical.
    for name in ("api/manager_tasks.php", "api/manager_tasks_view.php"):
        assert digest(target/name) == overlay["after"][name]
print("churn_install_package_test: production overlay, exact hashes, minimal patch, unchanged endpoint/view and drift rejection OK")
