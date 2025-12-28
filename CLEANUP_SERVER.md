# Server Cleanup Instructions

## Problem
The server has untracked files (`license.txt`, `readme.txt`, etc.) that conflict with files in the repository.

## Solution - Clean up all untracked files on server

SSH into your server and run these commands:

```bash
cd /home/reactwoo/public_html/wp-content/plugins/reactwoo-api-manager

# First, check what files are untracked
git status

# Option 1: Remove all untracked files (SAFE - only removes files not in git)
git clean -fd

# Option 2: Preview what would be removed first (recommended)
git clean -fdn

# Option 3: If you want to keep some files, remove them individually
# rm license.txt
# rm readme.txt
# (or any other conflicting files)
```

## After cleanup on server

From your local machine, push again:
```bash
git push -u origin master
```

## Alternative: Force push (NOT RECOMMENDED if others are using the repo)

If you're the only one working on this and want to force push:
```bash
git push -u origin master --force
```

**Warning:** Only use `--force` if you're sure no one else is working on the server repository.

