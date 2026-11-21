"""Validate the exact baseline patch in a temporary copy; never invoke the VPS installer."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
review = json.loads((root/"deploy/churn-permanent/reviewed-git-baseline.json").read_text())
digest = lambda path: hashlib.sha256(path.read_bytes()).hexdigest()
for name, expected in review["after"].items():
    assert digest(root/name) == expected, name
assert digest(root/"deploy/churn-permanent/integration.patch") == review["patch_sha256"]
with tempfile.TemporaryDirectory(prefix="churn-install-check-") as directory:
    target = Path(directory)
    archive = subprocess.Popen(["git", "archive", review["base_commit"]], cwd=root, stdout=subprocess.PIPE)
    extraction = subprocess.run(["tar", "-x", "-C", str(target)], stdin=archive.stdout)
    archive.stdout.close()
    assert extraction.returncode == 0 and archive.wait() == 0
    for name, expected in review["before"].items():
        assert digest(target/name) == expected, name
    patch = (root/"deploy/churn-permanent/integration.patch").read_bytes()
    subprocess.run(["patch", "--dry-run", "--batch", "--forward", "--fuzz=0", "-p1"], cwd=target, input=patch, check=True)
    subprocess.run(["patch", "--batch", "--forward", "--fuzz=0", "-p1"], cwd=target, input=patch, check=True)
    for name in review["before"]:
        assert digest(target/name) == review["after"][name], name
print("churn_install_package_test: reviewed hashes and exact patch application OK; production not inspected")
