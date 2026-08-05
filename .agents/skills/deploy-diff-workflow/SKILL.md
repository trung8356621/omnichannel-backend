---
name: deploy-diff-workflow
description: "Trigger for deploy, deployment, upload, up code, track diff, tao danh sach file deploy, start task, deploy-diff, trien khai, day code len server. Use for backend deploy session start/track/deploy workflow. Do not use for WordPress plugin packaging except to report that backend deploy-diff does not cover the sibling plugin repo."
---

# Purpose

Guide Codex through the backend deploy-diff workflow implemented by `.secure/deploy-diff.ps1`.

# Trigger conditions

Use this skill when the user mentions deploy, deployment, upload, up code, track diff, deploy-diff, start task, tao danh sach file deploy, trien khai, or day code len server.

Do not use it to package the WordPress plugin; plugin packaging is a separate explicit-release workflow.

# Required context

- Read `.secure/deploy-diff.ps1` only as needed to confirm param/mode behavior.
- Use a short stable kebab-case task id.
- Know which application files were modified/deleted.

# Workflow

1. Start
   - Before first backend application-code edit, ensure a session exists:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode start -Id "<task-id>"
```

2. Track
   - After meaningful changes and before final response, track every changed/deleted backend application file:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode track -Id "<task-id>" -Path "<file1>","<file2>"
```

   - Use `-Action deleted` only when explicitly tracking deletions or when needed for clarity.
   - MUST NOT use `-Current`; the script does not support it.

3. Deploy
   - Run deploy ONLY WHEN the user explicitly requests deploy:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode deploy -Id "<task-id>"
```

# Verification

- Confirm `track` ran or report why it did not.
- Check tracked file list before saying anything is ready for deploy.
- For documentation-only edits, deploy-diff tracking is not required unless the user asks.

# Safety and approval boundaries

- MUST NOT deploy automatically.
- MUST NOT read deployment logs or credentials.
- MUST NOT edit `.secure/deploy-diff.ps1`.
- MUST NOT FTP/SFTP/SCP/rsync upload unless explicitly requested.
- Backend deploy-diff only accepts files under `D:\work\omnichannel-backend`; it does not track `D:\work\wp-seo-ai`.

# Expected final report

- Task id used.
- Whether start was created or already existed.
- Files tracked.
- Whether deploy was run; if not, state that deploy was not requested.
- Any plugin files excluded because the script does not cover the sibling repo.
