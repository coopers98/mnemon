---
description: Check whether this device's connection to Mnemon works, and if not, why not
allowed-tools: Bash(bash:*)
---

Mnemon doctor report for this device:

!`bash "${CLAUDE_PLUGIN_ROOT}/scripts/mnemon-doctor.sh" --exit-zero`

If the report above is empty, or says the script could not be found, run it
yourself with the Bash tool (Claude Code does not set the plugin root there, so
use the newest installed copy):

```bash
bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | sort -V | tail -1)scripts/mnemon-doctor.sh"
```

Relay the report to the user as it is, in a code block. Then, in a sentence or
two:

- if the last line is "All checks passed.", say the connection works and name
  any warnings worth acting on;
- otherwise name the first FAIL and give its `fix:` line as the next step. Do
  not run the fix yourself: re-enrolment opens a browser approval and replaces
  this device's credentials, so the user should run it.
