# Email image variants

New raster logo and vehicle thumbnail uploads create a cached email-sized copy at upload time. Email rendering only looks up the stored copy; it does not download or resize source images while sending. If no copy exists, it falls back to the original image.

After deploying the migration, backfill existing uploaded logos and vehicle thumbnails with:

```sh
php artisan migrate --force
php artisan media:generate-email-variants
```

The command is safe to rerun. It skips variants already present and does not modify or delete originals. It processes uploaded media records in the `branding`, `logos`, and `vehicles` thumbnail paths.
