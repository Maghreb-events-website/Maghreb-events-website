# ⚠️  DO NOT store your production database here

This `data/` folder is **inside the deployment directory**.  
Any file placed here **will be deleted or overwritten** the next time you
upload / deploy a new version of the backend.

---

## Where to put your database

Set `MAGHREB_DB_PATH` in `vatsim_config.php` to a path **outside** the
web root — a directory that your web-server user can write to but that is
not touched by deployments.

```php
// vatsim_config.php
define('MAGHREB_DB_PATH', '/home/yourusername/data/maghreb.db');
```

Then create the directory and move the existing database there once:

```bash
mkdir -p /home/yourusername/data
mv /path/to/webapi/data/maghreb.db /home/yourusername/data/maghreb.db
chown www-data:www-data /home/yourusername/data/maghreb.db
chmod 660 /home/yourusername/data/maghreb.db
```

After that, `data/maghreb.db` here is only used as a **local-dev fallback**
when `MAGHREB_DB_PATH` is not defined.

---

## Files in this directory

| File | Purpose |
|------|---------|
| `maghreb.db` | Fallback SQLite database (local dev only) |
| `db.sqlite`  | Legacy file — safe to delete |
| `.htaccess`  | Blocks direct HTTP access to all files here |

Add this whole directory to `.gitignore`:

```
/data/*.db
/data/*.sqlite
```
