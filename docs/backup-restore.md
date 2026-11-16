# Backup and restore

The database and the exact matching `.env` file are one recovery set. In particular, the `APP_KEY` value is required to decrypt mailbox passwords stored in the database. A database-only backup is not a complete recovery.

## Create a backup

Run from the private application directory. Use the database name, username and host from `.env`; enter the database password at the prompt rather than putting it in shell history.

```sh
umask 077
mkdir -p backups
mysqldump --single-transaction --routines --triggers \
  -h 127.0.0.1 -u c0mmail_user -p c0mmail \
  > "backups/c0mmail-$(date +%F-%H%M%S).sql"
cp .env "backups/c0mmail-env-$(date +%F-%H%M%S).env"
```

Keep backups outside the website document root. Encrypt them before copying them to remote storage. Retain the application key securely and restrict access to backups because they contain account and mailbox metadata.

## Restore

1. Put the matching `.env` file back in the private application directory and verify its permissions.
2. Create an empty database and grant the application database user access.
3. Restore the SQL dump:

   ```sh
   mysql -h 127.0.0.1 -u c0mmail_user -p c0mmail < backups/c0mmail-backup.sql
   ```

4. Restore the exact matching `APP_KEY` from that backup set. Do not generate a new key for an existing database.
5. Run `php bin/check-environment.php`, then test a mailbox with `php bin/check-mailbox.php MAILBOX_ID`.
6. Sign in and verify Inbox, Sent, Drafts and attachment access before reopening the service to users.

Do not run `php bin/migrate.php --baseline-existing` as a restore shortcut. Restored databases already contain their schema; use the normal migration runner only when applying a new, explicitly versioned application migration.
