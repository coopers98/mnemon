---
description: Check whether this device's connection to Mnemon works, and if not, why not
allowed-tools: Bash(bash:*)
---

Mnemon doctor report for this device:

!`bash "${CLAUDE_PLUGIN_ROOT}/scripts/mnemon-doctor.sh" 2>&1 || true`

If the report above is empty, or says the script could not be found, run it
yourself with the Bash tool: `bash "${CLAUDE_PLUGIN_ROOT}/scripts/mnemon-doctor.sh"`
(the script is `scripts/mnemon-doctor.sh` inside the installed mnemon plugin).

Relay the report to the user as it is, in a code block, without the secrets it
never contains anyway. Then, in a sentence or two:

- if the last line is "All checks passed.", say the connection works and name
  any warnings worth acting on;
- otherwise name the first FAIL and give its `fix:` line as the next step. Do
  not run the fix yourself: re-enrolment opens a browser approval and replaces
  this device's credentials, so the user should run it.
