"""Compare existing failures against the exact audited Production Git baseline."""
import os
from pathlib import Path
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
baseline_ref = "cfbab96539bbcadcf1963991f77c10ccc78ce5a8"
tests = ["analytics_tasks_test.php", "analytics_sync_test.php", "analytics_policy_test.php"]
def failure(text):
    text = re.sub(r"/[^\s:]+/(api|tests)/", r"<ROOT>/\1/", text)
    text = re.sub(r"\.php:\d+", ".php:LINE", text)
    text = re.sub(r"\.php on line \d+", ".php on line LINE", text)
    return text.split("Stack trace:")[0].strip()
with tempfile.TemporaryDirectory(prefix="churn-baseline-") as directory:
    source = Path(directory)
    archive = subprocess.Popen(["git", "archive", baseline_ref], cwd=root, stdout=subprocess.PIPE)
    extraction = subprocess.run(["tar", "-x", "-C", str(source)], stdin=archive.stdout)
    archive.stdout.close()
    assert extraction.returncode == 0 and archive.wait() == 0
    regressions = []
    for test in tests:
        old = subprocess.run(["php", str(source/"tests"/test)], capture_output=True, text=True)
        new = subprocess.run(["php", str(root/"tests"/test)], capture_output=True, text=True)
        print(test, "baseline_exit=", old.returncode, "current_exit=", new.returncode)
        if old.returncode or new.returncode:
            print("BASELINE:", old.stdout, old.stderr)
            print("CURRENT:", new.stdout, new.stderr)
        if new.returncode and (not old.returncode or failure(old.stdout+old.stderr) != failure(new.stdout+new.stderr)):
            regressions.append(test)
        elif new.returncode:
            print("CONFIRMED PRE-EXISTING FAILURE:", test)
    if regressions:
        raise SystemExit("New or changed regression failures: " + ", ".join(regressions))
