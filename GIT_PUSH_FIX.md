# Git Push Fix Instructions

## Issue
The push failed because there's an untracked `license.txt` file on the server that would be overwritten by the merge.

## Solution

SSH into your server and run one of these commands:

### Option 1: Remove the untracked file (if it's not needed)
```bash
cd /home/reactwoo/public_html/wp-content/plugins/reactwoo-api-manager
rm license.txt
```

### Option 2: Add it to git first (if you want to keep a local version)
```bash
cd /home/reactwoo/public_html/wp-content/plugins/reactwoo-api-manager
git add license.txt
git commit -m "Add license.txt"
git pull origin master
```

### Option 3: Stash the file temporarily
```bash
cd /home/reactwoo/public_html/wp-content/plugins/reactwoo-api-manager
mv license.txt license.txt.backup
git pull origin master
# If you need the old file, compare and merge manually
```

## After fixing on server

Then from your local machine, push again:
```bash
git push -u origin master
```

