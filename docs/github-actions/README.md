# GitHub Actions workflows

Both workflow YAMLs live in this folder because the repository's automation
token is not allowed to create files under `.github/workflows/` (`workflows`
permission — re-confirmed 2026-08-31: a push adding that path is rejected with
*"refusing to allow a GitHub App to create or update workflow … without
`workflows` permission"*). GitHub will not run a workflow until the file
lives at that path, so a human with UI access performs the one-time
copy-paste below.

Two workflows are kept here:

| File | What it does |
| --- | --- |
| [`deployment-package.yml`](deployment-package.yml) | Builds `application-deployment.zip` (composer `--no-dev`, compiled CSS, the zip, extract-verification), uploads it as an artifact and attaches it to a GitHub Release on `v*` tags. |
| [`verify.yml`](verify.yml) | The full `tools/verify_all.sh` pipeline on a hosted runner — static checks, JS behaviour tests, the PHP suite, production-SQL freshness, the package build, then every end-to-end check against the real application on the dev database. This closes the "has never run on a hosted runner" gap from `docs/unfinished.md` item 22. |

## Activate either workflow (one-time, ~1 minute each)

1. Open the GitHub repo → **Add file** → **Create new file**.
2. Name it exactly:

   ```text
   .github/workflows/deployment-package.yml     # or verify.yml
   ```

3. Paste the contents of the matching file from this folder and commit to
   `main`.
4. **Actions** → pick the workflow → **Run workflow** (verify.yml also runs
   by itself on every push to `main` and on pull requests).

Until the copies exist, run the same stages locally:

```bash
bash tools/verify_all.sh --admin-password '<demo password>'   # everything
bash tools/verify_all.sh --unit-only                          # no server needed
bash tools/build_deployment_package.sh                        # the zip
```
