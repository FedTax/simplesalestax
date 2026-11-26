#!/usr/bin/env bash
# Runs every known-defect proof as a strict expected failure: each exits 1 while its defect is
# present and 0 once fixed. Any other exit code (a crash) or a pass fails this script, so a fix
# turns CI red until the proof is updated or removed.
status=0
for proof in tests/known-defects/*.php; do
	[ "$(basename "$proof")" = bootstrap.php ] && continue
	php "$proof" > /dev/null 2>&1
	case $? in
		1) echo "XFAIL (known defect still present): $proof" ;;
		0) echo "XPASS (defect fixed, remove the expected failure): $proof"; status=1 ;;
		*) echo "ERROR (proof crashed): $proof"; status=1 ;;
	esac
done
exit $status
