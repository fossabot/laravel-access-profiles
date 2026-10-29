# Releasing

Every release follows these steps. The website reads the version from `CHANGELOG.md`, so step 1 is required.

1. In the release PR, add the entry at the top of `CHANGELOG.md`:
   ```
   ## X.Y.Z - YYYY-MM-DD

   ### Added | Changed | Fixed | Removed

   - One line per change.
   ```
2. Merge the PR into `main`.
3. Tag the merge commit and push the tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
   The `changelog-guard` workflow fails when `CHANGELOG.md` has no `## X.Y.Z - YYYY-MM-DD` heading.
4. Create the GitHub Release for the tag. Copy the notes from the `CHANGELOG.md` entry.
5. Packagist updates from the tag. The website rebuilds on the push to `main` and shows the new version and changelog.
