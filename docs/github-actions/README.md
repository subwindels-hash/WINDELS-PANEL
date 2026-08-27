# GitHub Actions workflows

The packaging pipeline YAML is in this folder because the repository's
automation token is not allowed to create files under `.github/workflows/`
(`workflows` permission). GitHub will not run a workflow until the file
lives at that path.

## Activate `Deployment package`

1. Open the GitHub repo → **Add file** → **Create new file**.
2. Name it exactly:

   ```text
   .github/workflows/deployment-package.yml
   ```

3. Paste the contents of [`deployment-package.yml`](deployment-package.yml)
   (this folder) and commit to `main`.
4. **Actions** → **Deployment package** → **Run workflow**.

That job is the one that publishes `application-deployment.zip` as an
artifact (and attaches it to a GitHub Release when a `v*` tag is pushed).
Until the copy exists, build the zip locally:

```bash
bash tools/build_deployment_package.sh
```
