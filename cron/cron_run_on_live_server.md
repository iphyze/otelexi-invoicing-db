# Live server cron setup

The cron runners and helpers live inside the backend codebase under `api/cron/` and are blocked from direct HTTP access.

## Document maintenance

For a daily run at 6:00 AM in cPanel Cron Jobs:

```text
/usr/local/bin/ea-phpXX /home/CPANEL_USERNAME/public_html/otelex-server/api/cron/documentMaintenance.php >/dev/null 2>&1
```

## Security maintenance

Run once daily, for example at 6:15 AM. It removes expired/revoked authentication records and old rate-limit events while retaining recent security history.

```text
/usr/local/bin/ea-phpXX /home/CPANEL_USERNAME/public_html/otelex-server/api/cron/securityMaintenance.php >/dev/null 2>&1
```

Replace `ea-phpXX` with the PHP version assigned to the backend domain, for example `ea-php83`, and replace `CPANEL_USERNAME`/the path with the actual hosting path.
