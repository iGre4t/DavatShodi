# Incremental cPanel deployment

This deployer uploads changed, Git-tracked application files over FTPS. It does
not need SSH access and does not upload database/config secrets, known
server-owned runtime data, tests, documentation, or desktop build files.

## One-time setup

1. In cPanel, create an FTP account whose directory is the site's account home
   (or another directory that can reach `public_html`). Prefer FTPS/TLS.
2. Copy `deploy/deploy.env.example` to `.deploy.env` in the repository root.
3. Put the cPanel FTP host, username, and remote web root in `.deploy.env`.
4. Ensure `git`, `curl`, `sha256sum`, and `od` are available. They are normally
   included with Git Bash on Windows and standard Linux environments.

Do not commit `.deploy.env`. By default, the script prompts for the password.

## Use

Preview the first deployment:

```bash
bash deploy/deploy.sh --dry-run
```

Deploy added and changed files:

```bash
bash deploy/deploy.sh
```

Also remove files from the server that were removed from the repository:

```bash
bash deploy/deploy.sh --delete
```

Force every deployable file to upload again:

```bash
bash deploy/deploy.sh --full
```

The successful local snapshot is stored in `.deploy/manifest.tsv`. If a run
fails, the snapshot is not updated, so the next run retries the deployment.
Uncommitted edits to tracked files are detected, but untracked files are never
uploaded accidentally.

Remote deletion is opt-in because shared hosting often contains server-created
files. The script only offers to delete paths that it previously deployed.

The protected runtime paths include `data/`, `uploads/`, `api/data/`, campaign
redirect state, standalone-draw data, and event/task CSV data. Move those with a
purpose-built backup/migration process, not a code deployment.
