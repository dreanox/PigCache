# PigCache — Developer Docs

Internal reference. This file is excluded from all distribution ZIPs.

---

## Versioning & Git Tags

### Where the version lives

Every release requires updating **four places** in sync. The build script
asks you to confirm this before generating any ZIP.

| File | What to change |
|------|----------------|
| `pigcache.php` | `Version:` header (line ~5) |
| `pigcache.php` | `PIGCACHE_VERSION` constant |
| `readme.txt` | `Stable tag:` |
| `readme.txt` | New entry under `== Changelog ==` |

---

### Tagging a release

#### Tag a specific past commit (e.g. retroactively marking v1.0.0)

```bash
git tag -a v1.0.0 <commit-hash> -m "Version 1.0.0 — initial WordPress.org submission"
git push origin v1.0.0
```

Find the right commit hash with:

```bash
git log --oneline
```

#### Tag the current HEAD (normal release flow)

```bash
git tag -a v1.0.1 -m "Version 1.0.1 — security fixes, free/pro invalidation split"
git push origin v1.0.1
```

Always use `-a` (annotated tag). Annotated tags carry a message, date,
and author — GitHub displays them as proper releases. Lightweight tags
(without `-a`) are just pointers with no metadata.

---

### Full release workflow

```
1. Finish all changes on your branch

2. Update version in the four places listed above

3. Commit the version bump:
   git add pigcache.php readme.txt
   git commit -m "bump version to x.x.x"

4. Create the annotated tag:
   git tag -a vx.x.x -m "Version x.x.x — short description"

5. Push commits and tag:
   git push origin <branch>
   git push origin vx.x.x

6. Build the ZIPs:
   ./bin/build.sh free     ← generates dist/pigcache-x.x.x.zip
   ./bin/build.sh pro      ← generates dist/pigcache-pro-x.x.x.zip

7. Upload dist/pigcache-x.x.x.zip to WordPress.org
```

---

### Deleting a tag (if you made a mistake)

```bash
# Delete locally
git tag -d v1.0.1

# Delete from GitHub
git push origin --delete v1.0.1
```

Then recreate and push the corrected tag.

---

### Listing all existing tags

```bash
git tag -l
```
