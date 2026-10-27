---
description: Scan this device's past Claude Code history for what Mnemon could import (free; nothing is spent)
allowed-tools: Bash(bash:*)
---

Mnemon backfill scan for this device:

!`bash "${CLAUDE_PLUGIN_ROOT}/scripts/mnemon-backfill.sh" --dry-run`

If the report above is empty, or says the script could not be found, run it
yourself with the Bash tool (Claude Code does not set the plugin root there, so
use the newest installed copy):

```bash
bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | sort -V | tail -1)scripts/mnemon-backfill.sh" --dry-run
```

Relay the report to the user as it is, in a code block. This was only a scan:
nothing was imported and nothing was spent.

Then tell the user the next step, a trial on one project, which they run
themselves in a terminal (it asks for confirmation and spends tokens, so do not
run it for them):

```bash
bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | sort -V | tail -1)scripts/mnemon-backfill.sh" --project <name>
```

using a project name from the report. If the report says a backfill was left
part-way, the same command continues it.
