# Changelog

All notable changes to Domain Monitor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Docker image** - Each release is published on Docker Hub as `ispgrid/domain-monitor` and as `ghcr.io/ispgrid/domain-monitor` (tags `X.Y.Z`, `X.Y` and `latest`, amd64 and arm64), with an example `docker-compose.yml` that adds MariaDB. Settings, uploads and logs live in a `/data` volume and survive updates. See **Docker** in the README
- **Docker mode** - With `DOCKER=true` in `.env` (the image sets it), **Settings → Updates** shows how to pull the new image instead of the update and rollback buttons, **Settings → System** explains that the checks run inside the container, and cron alerts point to the container logs. Installs without the line work as before
- **Logs → Container** - On the Docker image: the container's startup log, the scheduler and web server errors, each kept under 5 MB. The web access log can be added with `ACCESS_LOG_FILE=true`
- **Scheduler** - `cron/scheduler.php` starts the domain, SSL and DNS checks when their interval in Settings is due. The Docker image runs it every 15 minutes; other installs can use it as a single crontab line instead of three
- **Alerts per domain** (#88) - The domain's edit page has **Alerts for this domain**: expiry, status, DNS and SSL can each follow the notification group (the default), be switched off for that domain only, or be switched on even when the group has them off. The checks still run and keep their results when an alert is off
- **Watched domains still expired** (#95) - **Settings → Monitoring** has **Watched domains: still expired after (days)**, e.g. `7,14,30`. A domain you are watching to acquire then gets an alert on each of those days after its expiry date while it isn't available yet. Empty (the default) keeps today's behaviour. These alerts follow the status alert switch
- **Gotify and ntfy channels** - Push alerts to your own Gotify server (application token) or to ntfy.sh or a self-hosted ntfy (topic, with an optional access token or username and password). Priority follows how urgent the alert is unless you pick one, and the alert links to the domain
- **WhatsApp through WACRM** - Sends alerts from a self-hosted [WACRM](https://github.com/ArnasDon/wacrm) to one or more WhatsApp numbers with an API key that has the `messages:send` scope. WhatsApp only delivers plain text to people who messaged your number in the last 24 hours, so alerts normally go out as an approved template with one variable, which gets the alert on one line
- **SMS through MSG91** - Sends alerts as SMS with an MSG91 Flow template (DLT-approved in India). The template gets two short values, the domain and a few words about the alert (e.g. `expires in 7 days`), 30 characters each by default; the variable names and length can be changed
- **Alert switches in the CSV group export** - The CSV export has an `alerts` column with the kinds that are on (e.g. `expiry;status`), and the import reads it. CSV files from older versions import with every alert on, as before

### Fixed
- **Day count in expiry alerts** - Reminders counted the days from the current time, so the "7 days" reminder could say "expires in 6 days". They now count calendar days, like the domain list and the cron that picks the reminder day
- **Cloudflare alerts after moving a domain away** - When a domain's nameservers no longer point to its Cloudflare zone (or Cloudflare reports the zone as pending or moved), the zone is marked **not in use** and is no longer compared with public DNS, so moving a domain to another DNS host doesn't send a "zone and public DNS differ" alert for every record left in the old zone. The nameserver change itself is still reported by the normal DNS change alert. No alert is sent either when the domain's nameservers can't be looked up, since every record would look missing

## [1.8.0] - 2026-10-29

### Added
- **Release zip** - Each release now has `domain-monitor-vX.Y.Z.zip` attached (a `domain-monitor/` folder, run `composer install` after unpacking), for install scripts that download a release file, such as the Proxmox VE helper script
- **Site icons in the domain list** - Each domain shows its website's icon in the list and on the domain page, instead of the globe. The server fetches the icon itself (the page's `<link rel="icon">`, then `/favicon.ico`), so your domain list is never sent to Google or any other icon service. Icons are cached and refreshed weekly by the domain cron, and **Refresh All** fetches one straight away. Domains with no website or no icon keep the globe. Local and private network addresses are never fetched, and SVG icons are skipped
- **Custom logo** - **Settings → Application** takes a logo (PNG, JPG, WebP or GIF, up to 1 MB). It replaces the built-in logo in the sidebar, on the login page and as the browser tab icon. **Use the default logo** switches back

### Fixed
- **Latest channel missed commits after a release update** - Updating to a release recorded the newest commit on main as installed instead of the release's own commit, so commits pushed to main before the update never showed as a hotfix. The updater now records the commit it actually downloaded

## [1.7.1] - 2026-10-29

### Changed
- **Faster Cloudflare connect** - **Test and connect** only checks the token and saves the account. Zones are read with **Sync now** or the next DNS check, so adding a big account no longer hangs for a minute
- **Sync now runs in the background** - The click returns at once and the account shows **Syncing** until the zones are read; the page refreshes itself when it's done. A second click while it runs is ignored, and a sync that hasn't finished after 15 minutes shows **Sync did not finish**. The run shows in **Logs → Commands**. Servers that don't allow PHP to start commands (exec disabled) still sync in the request as before

Migrations: 046_update_app_version_v1.7.1.sql (version number only)

## [1.7.0] - 2026-10-29

### Added
- **DNS history** (#75) - The DNS tab has a **History** view: every check that found records added, removed or changed, newest first, with old and new values side by side and a record type filter. It covers the DNS cron, Refresh DNS, Refresh All and the scans. The first scan of a domain is the starting point and isn't listed. History is kept for 365 days
- **Domain list columns** (#85) - **Columns** on the domain list picks which columns show: Registrar, Expiration, Days Left, Status, SSL Expiry, Tags, Purpose, Group, Last Checked and DNS Checked. The choice is saved to your account; **Default** brings back the usual columns
- **Cloudflare DNS (read only)** - Connect a Cloudflare account under **Integrations** with an API token that has Zone Read and DNS Read. Zones are matched to your domains by name, and the DNS tab gets a **Cloudflare** view that compares each zone record with public DNS (match, different, not in public DNS; proxied and flattened records are checked for an answer only) and checks that the domain uses the zone's nameservers. Every name in the zone is also looked up by the DNS checks, so **Records** no longer misses subdomains discovery never found, and Records rows in the zone get a **cloudflare** tag with **Proxied** or **DNS only** (values stay what public DNS answers). When they still differ after 60 minutes, the domain's notification group is alerted (the group's DNS alert switch applies) and the account owner gets an in-app notification, once per difference. Zones are read again with every DNS check, with **Sync now** on the account, or with **Re-check now** on the domain. The token is stored encrypted and never shown again; Domain Monitor only reads from Cloudflare. Connecting, changing and removing accounts shows in **Logs → Activity**

### Fixed
- **Refreshing a domain that was deleted meanwhile** - A refresh takes a few seconds; when the domain was deleted in that time, saving its DNS records or SSL certificates failed with a database error page. You now go back to the domain list with a message

Migrations: 045_add_dns_history_cloudflare_v1.7.0.sql

## [1.6.0] - 2026-10-29

### Added
- **Read-only API** (#93, #102) - JSON endpoints under `/api/v1` for dashboards (e.g. Homepage), scripts and monitoring: `/api/v1/stats` (domain and SSL counts), `/api/v1/domains` (list with status, group, tag and search filters, sorting and pages), `/api/v1/domains/{id or name}` (one domain with nameservers, registry statuses and SSL certificates) and `/api/v1/ssl` (certificates). The API sees the same domains as the token's user: all of them in shared mode, only their own in isolated mode. Requests don't create a session
- **API tokens** - Created under **Profile → API Tokens**, with an expiry of 30 days, 90 days, 1 year or never. A token is shown once; only a hash is stored. Send it as `Authorization: Bearer <token>` or `X-API-Key: <token>`. Each token can make 120 requests per minute, and an IP address that sends 20 wrong tokens in 15 minutes is paused for 15 minutes. Creating and deleting tokens shows in **Logs → Activity**
- **Allow API Access** switch under **Settings → Security** (on by default). Turning it off stops all tokens

### Security
- **SMTP password no longer sent to the browser** - The Email settings page put the saved SMTP password into the page source. The field is now empty: leave it blank to keep the saved password, or tick **Remove the saved password**

### Changed
- `public/.htaccess` passes the `Authorization` header to PHP, which some Apache and PHP-FPM setups drop otherwise. With nginx no change is needed

Migrations: 044_add_api_tokens_v1.6.0.sql

## [1.5.2] - 2026-10-27

Security release. Fixes the issues found in a security review of the app, the installer and the Docker setup.

### Security
- **Sign-in attempt limit** - After 5 wrong passwords for one account from one IP address, or 20 failed sign-ins from one IP address, sign-in is paused for 15 minutes. A successful sign-in clears that account's failures. Before, passwords could be guessed without limit unless CAPTCHA was turned on
- **Password reset limit** - Each IP address can request 10 password resets per 15 minutes, and each email address gets at most 3 reset emails per 15 minutes. Over the per-address limit the page still says the email was sent, so it doesn't reveal which addresses have accounts
- **SMTP certificate check** - Email now checks the mail server's TLS certificate and host name. Before, any certificate was accepted, so someone on the network path could pose as the mail server and read the SMTP password, password-reset links and 2FA email codes. For a mail server with a self-signed certificate, or one set by IP address, untick **Settings → Email → Verify SMTP certificate**
- **Webhook addresses** - Webhook, Discord, Slack and Mattermost channels can no longer send to this server, the local network or cloud metadata addresses (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, ::1, fc00::/7 and other non-public ranges). Before, any signed-in user could use a channel or "Test channel" to make the server send requests to internal services. Redirects are no longer followed, and each request goes to the address that was checked. If your chat server or automation tool runs on your own network, tick **Settings → Monitoring → Allow webhooks to local and private network addresses**
- **TLD Registry actions need a form post** - Refresh and Enable/Disable on a TLD now use POST with a CSRF token. Before, they were plain links, so another site could make a signed-in admin's browser run them
- **Docker: phpMyAdmin is local only** - phpMyAdmin now listens on 127.0.0.1:8081 instead of every network interface, and the image is pinned to 5.2. Use an SSH tunnel to reach it, or set `PMA_BIND=0.0.0.0` in `.env.docker` to open it up
- **Docker: `.env` backups readable by root only** - The bootstrap set every file to 644, so old `.env` backups (with database passwords) were readable by any user on the host. Backups, including ones made before, are now 600

### Fixed
- **Database updates stop on real errors** - When a migration fell back to running statement by statement, any error whose text contained "Table" was ignored, including "Table ... doesn't exist", and the migration was still marked as done. Only errors that mean a step already ran (table, column, index, key or row already there, or already dropped) are skipped now; any other error stops the update and the migration stays pending, so it can run again
- **Docker: passwords with `&`, `#`, `$`, `\` or quotes** - The bootstrap wrote values into `app/.env` with `sed`, which changed or broke passwords containing `&` or `#`. Values are now written quoted, exactly as given. In `.env.docker`, put such values in single quotes

Migrations: 043_add_security_fixes_v1.5.2.sql

## [1.5.1] - 2026-10-27

### Changed
- **Days left count calendar days** - A domain that expires 30 days from today now shows 30 days left, not 29. Reminders go out on the day they are set for instead of a day early: the "1 day" reminder comes the day before expiry and the "expired" alert on the expiry date. The expiry date itself counts as expired, as before
- The domain's WHOIS tab shows the "Manual" label for hand-entered expiry dates, and Edit Domain says whether the current date was entered by hand or came from WHOIS/RDAP

### Fixed
- **Status for hand-entered expiry dates** - Domains whose registry gives no expiry date (e.g. .eu) now show "Expiring soon" and "Expired" from the saved date. Before, they stayed "Active" because the registry lists them as active with nameservers. The "expired" alert says the date is the saved one
- No "registered" alert when an expired domain becomes active again: that is a renewal, covered by the "renewed" alert. A domain that comes back through available, redemption or pending delete still sends "registered"
- No "renewed" alert when a hand-entered expiry date is replaced by the first real date from the registry

Migrations: 042_update_app_version_v1.5.1.sql

## [1.5.0] - 2026-10-27

### Added
- **Choose which alerts a group gets** - Each notification group now has "Send these alerts" switches: expiry reminders and expired alerts, status changes and renewals, DNS record changes, and SSL certificate alerts. All are on by default. With a switch off, the checks still run and record changes, but no channel or bell alert goes out for that group's domains (Hosteroid/domain-monitor#88)
- **Watching to acquire** - When adding or editing a domain (also in Bulk Add), choose "Watching to acquire it" instead of "I own this domain". Watched domains get no expiry reminders and no "expired" alerts, only the steps that matter to a buyer: redemption, pending delete, available and registered. They show a "Watching" label in the domain list (Hosteroid/domain-monitor#95)
- Group export and import (JSON) keep the alert switches

### Changed
- **Faster DNS checks** - Underscore names such as `_dmarc` are only asked for TXT and CNAME records, the DNS_ALL fallback only runs when every typed lookup came back empty, `_dmarc` and `_mta-sts` are no longer looked up twice, and reverse DNS and IP details are cached for the run. A run that cannot reach ip-api.com stops asking it after the first failure instead of waiting on every domain (Hosteroid/domain-monitor#98)

### Fixed
- **Reminders for manual expiry dates** - Expiry reminders and "expired" alerts now use the date set in Edit Domain → Manual Expiration Date when WHOIS/RDAP gives no date (e.g. .nl), and when the lookup fails on a check. Before, these domains could show "Expiring soon" but never got a reminder. Such alerts say the date is the saved one, so you can confirm it with your registrar
- Domains retried after a rate limit no longer get a duplicate bell alert for the same reminder
- DNS lookups use absolute names, so a server whose resolver has a search domain with a wildcard record no longer shows made-up records for names that do not exist (Hosteroid/domain-monitor#98)

Migrations: 041_add_notification_choices_v1.5.0.sql

## [1.4.1] - 2026-10-27

### Fixed
- **App name setting** - The name set in **Settings → Application** now shows in the sidebar, the footer and the sign-in, register and password pages. It was only used in the browser tab title and emails (Hosteroid/domain-monitor#106)
- Names with `&` or `<` no longer show as `&amp;` in the browser tab title

### Changed
- Slack, Discord, Mattermost and Pushover alerts, channel test messages, the welcome and update notifications, and new 2FA setups (the name shown in your authenticator app) use the app name instead of "Domain Monitor". 2FA codes you already set up keep working

Migrations: 040_update_app_version_v1.4.1.sql

## [1.4.0] - 2026-10-27

### Added
- **Logs** - "Error Logs" in the menu is now **Logs** (admins only), with tabs for everything the app records:
  - **Overview** - When each cron job last ran and whether it is on time, the last update, and errors, failed alerts and failed sign-ins in the last 24 hours
  - **Cron Runs** - Every run of the domain, SSL and DNS checks: start time, duration, result, how much was checked, alerts sent, errors and the full output. **Run now** starts a job from the browser and shows its output live
  - **Updates** - Every update, rollback and database update: versions, who ran it, result, files updated, whether Composer ran, backup files, the commands run and the updater log lines
  - **Commands** - Shell commands the app ran (composer, mysqldump, mysql, Run now) with exit code, duration and output
  - **Notifications** - Every alert sent to email, Telegram, Slack and the other channels, with failed ones and their errors
  - **Activity** - Who did what: sign-ins and failed sign-ins, settings changes (old → new), domains, users, notification groups, 2FA and passkeys, updates
  - **Errors** - The existing error log
  - **Files** - Browse, search, filter by level, download and delete the files in `logs/`
- **Cron Alerts** - Admins get a notification and a banner when a cron job has not run for twice its interval or its last two runs failed. Turn it off in Settings → Maintenance
- **Log Retention** - Settings → Maintenance → "Keep logs for" (default 30 days, or forever). Older logs are deleted once a day by the domain check cron; update history is kept. Logs → Overview → "Clean up now" runs it at once

### Changed
- `logs/cron.log`, `logs/ssl_cron.log` and `logs/dns_cron.log` start a new file each day; earlier days are kept as e.g. `cron.2026-10-26.log`
- Passwords, tokens and webhook secrets are hidden (`***`) in log files viewed or downloaded in Logs, and in recorded commands and cron output
- Rolling back an update keeps the log entries written since the backup was made

## [1.3.0] - 2026-10-26

### Added
- **Passkeys** - Sign in with a fingerprint, face, device PIN or security key instead of a password. Add, rename and remove passkeys in Profile → Passkeys, then use "Sign in with a passkey" on the login page (or pick one from the username field's autofill). A passkey sign-in skips the 2FA code, since the device already verifies you; passwords keep working. Admins can turn passkeys off in Settings → Security. Passkeys need HTTPS (or localhost) and only work on the address they were added on
- **Passkey Added Notification** - Users get a notification when a passkey is added to their account

### Fixed
- **Remember Me** - Ticking "Remember me" did nothing: the token pointed at a session that wasn't saved yet, so saving it failed quietly. It now works for password, 2FA and passkey sign-ins

### Changed
- New dependency `lbuchs/webauthn`. The updater runs `composer install`; if it can't, run `composer install --no-dev` by hand as the user that owns the files (not root), or later updates can't replace `vendor/`
- Users with 2FA enabled are still asked for their code when signing in with a password. A passkey sign-in skips it, also under the Forced policy; untick Allow Passkeys to always require the code

## [1.2.7] - 2026-10-26

### Fixed
- **Pages Not Updated After Update** - With `APP_ENV=production`, pages kept using templates compiled for the old version after an update, rollback or `git pull`, so new options (e.g. SSL alert days, Renewed alerts, monitoring switches on Add Domain) did not appear while the version number did. Changed templates are now recompiled, and the updater clears the template cache after updating and after a rollback

## [1.2.6] - 2026-10-26

### Fixed
- **Rollback Database Restore** - Rolling back restored the files but never the database, so the old version still showed the new version number and schema. Database backups started with mysqldump's "Using a password on the command line" warning, which is not SQL. Backups no longer contain it, and rollback skips it in backups made by earlier versions
- **PDO Database Restore** - Restoring a mysqldump backup without shell access skipped every `DROP TABLE` (they follow a comment block) and failed
- **Database Password on the Command Line** - mysqldump and mysql get the database login from a temporary file readable only by the owner instead of the command line, so it no longer shows in the server's process list
- **Rollback Message** - When the database cannot be restored, rollback shows a warning that only the files were restored, instead of a success message

## [1.2.5] - 2026-10-26

### Fixed
- **Updater Composer Step** - The updater could not run `composer install` when the web server user has no home directory (common with PHP-FPM or Apache as `www-data` on Ubuntu/Debian), so new dependencies were never installed. It now gives Composer a home directory inside the app (`cache/composer`). If an earlier update logged "HOME or COMPOSER_HOME environment variable must be set", run `composer install --no-dev` once by hand
- **Updater Message** - When Composer cannot run, the update screen and notification no longer blame a disabled `exec` and point to the updater log

## [1.2.4] - 2026-10-26

### Added
- **SSL Alert Days** - SSL certificate alerts have their own days in Settings → Monitoring, separate from domain expiry. A certificate counts as expiring at the largest number and gets a reminder on each other day, so Let's Encrypt users can set `14,7,3,1` and only hear about failed renewals. Default `30` keeps the old behaviour ([Hosteroid#72](https://github.com/Hosteroid/domain-monitor/issues/72))
- **SSL Dashboard Widget** - The dashboard lists the monitored certificates that fail or expire first, with counts of expiring and failing ones ([Hosteroid#77](https://github.com/Hosteroid/domain-monitor/issues/77))
- **Domain Renewed Notification** - New "Renewed" trigger sends an alert when a domain's expiration date moves later. Off by default, turn it on in Settings → Monitoring ([Hosteroid#74](https://github.com/Hosteroid/domain-monitor/issues/74))
- **Billing Preview Toggle** - The Billing tab and Financial Summary card only show sample data, so they are now hidden by default. Turn them on in Settings → Application ([Hosteroid#69](https://github.com/Hosteroid/domain-monitor/issues/69))
- **Bundled TLD Servers** - `database/tld-servers.json` holds RDAP/WHOIS servers for TLDs IANA does not list, starting with `.iq`. Anyone can add one with a pull request; the file is checked by `php scripts/check-tld-servers.php`, on GitHub for each change and in the admin TLD Registry
- **Monitoring Options on Add Domain** - Active, DNS and SSL monitoring can be set when adding a domain

### Changed
- **Dependencies** - Guzzle 8, PHPMailer 7, google2fa 9 and symfony/var-dumper 7.4 (dev)

### Fixed
- **TLD Import WHOIS Servers** - IANA no longer returns WHOIS servers over RDAP and redesigned its TLD pages, so imports stored none. WHOIS servers now come from IANA's `tlds.json` registry file, and the TLD page parser reads the new layout
- **TLD Import Skipping TLDs** - Import batches resumed from the wrong place and skipped most TLDs
- **Wildcard DNS** - Hosts removed from a zone with a wildcard record no longer stay listed because the wildcard still answers for them ([Hosteroid#76](https://github.com/Hosteroid/domain-monitor/issues/76))
- **Imported SOA** - An SOA record imported from a zone file is replaced by the live one instead of being reported as removed on every check ([Hosteroid#97](https://github.com/Hosteroid/domain-monitor/issues/97))
- **Webhook Errors** - A webhook answering with an HTTP error no longer crashes the notification
- **.pro Lookups** - Removed an old hard-coded `.pro` WHOIS server that no longer answers
- **Updater** - The commit SHA is saved before Composer runs, so a later dependency change cannot break the update screen

## [1.2.3] - 2026-10-26

### Changed
- **Locked Dependencies** - `composer.lock` is now part of the repository and every release, so `composer install` gets the tested versions, and `git pull` installs and the updater no longer fail with a stale lock file when a release changes dependencies
- **PHP 8.2 Dependency Target** - Composer picks versions that run on PHP 8.2, so a `vendor` folder built on a newer PHP also works on an 8.2 host (`endroid/qr-code` is now 6.0.x)

## [1.2.2] - 2026-10-26

### Fixed
- **Session Race** - A long request (TLD import batch, domain refresh) no longer writes back the session it read at the start, which could bring back an already shown message or undo a logout made meanwhile
- **DNS Refresh on Windows** - Refreshing a domain no longer fails with `Undefined constant DNS_CAA` on Windows, where PHP has no CAA lookups (CAA records are skipped there)
- **Updater Integrity Check** - The updater reads the commit SHA from the release archive's comment, so releases tagged with annotated tags no longer fail the check

## [1.2.1] - 2026-10-26

### Added
- **Updater PHP Check** - The built-in updater reads the PHP requirement from the new release and stops before changing any files if the server's PHP is too old

### Fixed
- **Multi-Level ccTLDs** - Domains like `example.co.uk`, `example.com.br` and `example.net.au` were rejected as subdomains ([Hosteroid#91](https://github.com/Hosteroid/domain-monitor/issues/91))
- **.sk and .it WHOIS Parsing** - Expiry date ("Valid Until") is now read for .sk, and creation/updated dates and registrar no longer come from contact or registrar sections ([Hosteroid#104](https://github.com/Hosteroid/domain-monitor/issues/104))
- **Updater on Shared Hosting** - Looking for `mysqldump` no longer fails the update when `open_basedir` is set; the backup falls back to the PHP dump ([Hosteroid#73](https://github.com/Hosteroid/domain-monitor/issues/73))

## [1.2.0] - 2026-10-26

### Changed
- **Fork Maintenance** - Project is now maintained as the [ispgrid fork](https://github.com/ispgrid/domain-monitor) of [Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor)
- **Updater Source** - Built-in updater checks releases and commits on `ispgrid/domain-monitor` instead of the original repository
- **Project Links** - README, CONTRIBUTING, issue templates, Docker bootstrap, composer metadata and in-app GitHub links point to the fork; original authorship credited in README and composer authors
- **PHP Requirement** - Minimum PHP version is now 8.2 (8.1 is end of life)
- **QR Code Library** - Upgraded `endroid/qr-code` to 6.x for 2FA QR codes

### Fixed
- **PHP 8.5 Compatibility** - Removed deprecated `curl_close()` and `finfo_close()` calls that broke RDAP lookups and avatar uploads in development mode on PHP 8.5

## [1.1.5] - 2026-03-08

### Added
- **Twig Templating** - All PHP views migrated to Twig; `twig/twig` added as dependency
- **Twig-Only Rendering** - Removed legacy PHP view fallbacks; ErrorHandler renders via Twig with safe escaped HTML fallback on failure
- **DNS Monitoring** - Track DNS record changes with DnsService (lookup, crt.sh discovery, Cloudflare detection, IP enrichment), DnsRecord model for snapshots and diffs, per-domain `dns_monitoring_enabled` toggle, manual and scheduled refresh via `cron/check_dns.php`
- **SSL Certificate Monitoring** - Track TLS certificates with SslService and SslCertificate model; validity, expiry, issuer, SAN list; per-domain `ssl_monitoring_enabled`; add/refresh/delete endpoints for root and custom hostnames/ports; `cron/check_ssl.php` for scheduled checks
- **Domain View Tabs** - New tabbed domain view: Overview, DNS, Billing, Notifications, SSL, WHOIS
- **Domain View Template Setting** - Choose `detailed` (tabbed) or `legacy` view per installation
- **Cron Staleness Warnings** - Settings shows warnings when domain/DNS/SSL cron runs are overdue
- **Timezone on Installer Routes** - App timezone now applied even on `/install` and `/install/update` when installed, so upgrade notifications use correct timezone

### Changed
- **2FA Flows** - Twig templates for setup, verify, backup-codes; TwoFactorService silences deprecated QR code warnings
- **Settings Page** - Timezone lists, notification preset selection, cron path display, cached update state, rollback availability
- **Avatar and 2FA Data in Controllers** - ProfileController and UserController pass avatar and two-factor info to Twig views
- **EmailHelper** - Safer subject handling
- **TldRegistry** - Search improvements
- **Domain Sorting** - Uses effective status (e.g. `expiring_soon`) for ordering
- **Discord Channel** - Null-safe field handling

### Technical
- **Core** - `Core\TwigService` for Twig rendering; Controller and Router always use Twig
- **Models** - `DnsRecord`, `SslCertificate`
- **Services** - `DnsService` (DNS lookup, crt.sh, Cloudflare detection), `SslService` (certificate fetch and parsing)
- **DomainController** - `performWhoisRefresh`/`performDnsRefresh`, `refreshWhois`, `refreshDns`, `refreshAll`; SSL endpoints: `addSslHost`, `refreshAllSsl`, `bulkRefreshSsl`, `bulkDeleteSsl`, `refreshSsl`, `deleteSsl`
- **NotificationService** - Notifications when DNS or SSL monitoring is toggled
- **domains table** - `dns_last_checked`, `dns_monitoring_enabled`, `crtsh_last_fetched`, `ssl_last_checked`, `ssl_monitoring_enabled`
- **settings** - `domain_view_template`, `dns_check_interval_hours`, `last_dns_check_run`, `ssl_check_interval_hours`, `last_ssl_check_run`

### Migrations
- `027_add_dns_monitoring.sql` - dns_records table, domain columns, DNS cron settings
- `028_add_ssl_monitoring.sql` - ssl_certificates table, domain columns, SSL cron settings, app version 1.1.5

---

## [1.1.4] - 2026-03-02

### Added
- **CSV/JSON Import & Export for TLD Registry** - Export all TLDs with WHOIS servers, RDAP servers, registry URLs, and active status; import from CSV/JSON with create-or-update logic and duplicate detection
- **Manual TLD Creation** - Create button with popup modal to add custom TLD entries (supports multi-level TLDs like .co.uk, .co.za, .com.au)
- **IANA Dropdown Menu** - Consolidated "Import TLDs from IANA", "Check for Updates", and "IANA Import Logs" into a single indigo dropdown, reducing button clutter and separating IANA sync from file import/export
- **TldRegistry::findByTld()** - Lookup TLDs regardless of active status (used by import deduplication and create duplicate check)
- **TldRegistry::getAll()** - Retrieve all TLDs ordered by name for export

### Changed
- **Standardized Import Logging** - Added consistent `Logger('import')` calls across all four import functions (Tags, Domains, Notification Groups, TLD Registry) with start, file info, parse count, validation warnings, and completion stats
- **Standardized Export Logging** - TLD Registry export now uses local `Logger('export')` instances matching Tags, Domains, and Notification Groups pattern
- **TLD Registry Action Bar Redesigned** - Six separate buttons consolidated into four: IANA dropdown (indigo), Export dropdown (emerald), Import button, Create TLD button

### Technical
- **Drag-and-Drop File Upload for TLD Import** - Same dropzone pattern as Tags and Groups with file preview, remove, and submit spinner
- **TLD Validation** - Regex supports multi-level TLDs (`^\.[a-z0-9\-]+(\.[a-z0-9\-]+)*$`), auto-lowercasing, dot-prefix normalization
- **Import Create-or-Update** - File import creates new TLDs or updates existing ones; RDAP servers parsed from JSON arrays or comma/semicolon-separated strings
- **Routes** - Added `GET /tld-registry/export`, `POST /tld-registry/import`, `POST /tld-registry/create` before `{id}` catch-all

### Migrations
- `026_update_app_version_v1.1.4.sql` - Updates app version to 1.1.4

---

## [1.1.3] - 2026-02-11

### Added
- **CSV/JSON Import & Export for Domains** - Export all domains with tags, groups, and notes; import from file with WHOIS auto-lookup, group matching by name, and duplicate skip
- **CSV/JSON Import & Export for Tags** - Export/import user tags with human-readable color names and descriptions
- **CSV/JSON Import & Export for Notification Groups** - Export groups with channels (sensitive data masked); import with auto-disable for masked credentials
- **In-App Update System** - Check, download, and apply updates directly from Settings (GitHub Releases & hotfix tracking)
  - Two update channels: Stable (releases only) and Latest (releases + hotfixes)
  - Full file and database backup before every update, with one-click rollback
  - Automatic `composer install` when dependencies change (detects cPanel/shared hosting limitations)
  - Commit SHA integrity verification on downloaded archives
  - Update badge in top navigation bar (admin-only, configurable)
  - Cron-based background update checks with admin notifications
- **Update Available Notifications** - In-app alerts for admins when a new release or hotfix is detected
- **Tag Transfer** - Admin-only transfer of individual or bulk-selected tags to another user
- **Domain Bulk Transfer** - Admin-only bulk transfer of selected domains to another user
- **Drag-and-Drop File Upload** - File import zones on Domains (bulk-add), Tags, and Groups pages with format hints and size limits

### Changed
- **Bulk Action Bars Redesigned** - Consistent inline toolbar across Domains, Tags, Groups, Users, Errors, and TLD Registry
- **Notification Click Routing** - `update_available` notifications redirect to Settings → Updates tab
- **Domains Per-Page Preference** - Remembered via cookie (persists for 1 year)
- **Installer Route Protection** - Requires admin auth for post-install routes; blocks re-installation
- **Settings Page** - New Updates tab with status card, preferences, rollback, and release notes viewer (Markdown rendered via marked.js + DOMPurify)
- **Button Color Consistency** - TLD Registry and transfer modals use `bg-primary` branding instead of mixed indigo/green
- **ErrorHandler Hardened** - Recursion guard, `JSON_PARTIAL_OUTPUT_ON_ERROR` for stack traces, `\Throwable` catch, graceful fallback to `error_log()`

### Fixed
- **Tag Delete XSS** - Fixed escaping of tag names containing quotes in delete confirmation
- **Bulk Actions Bar Toggle Bug** - Removed flex class toggling that caused display issues

### Security
- **Sensitive Data Masking in Exports** - API tokens show `****` + last 4 chars; webhook URLs show scheme + host only; masked channels imported as disabled
- **Installer Access Control** - Post-install pages (update, migration runner) require admin authentication
- **Import Validation** - File size limits (5 MB domains, 2 MB groups, 1 MB tags), extension whitelist (`.csv`, `.json`), CSRF on all import forms

### Technical
- **UpdateController** - New admin-only controller with check, apply, rollback, and preference endpoints
- **UpdateService** - GitHub API integration with release/commit tracking, file + DB backup, staged extraction, and rollback
- **LayoutHelper::getUpdateBadgeInfo()** - Cached badge state for top-nav without API calls on page load
- **ViewHelper::getMaxUploadSize()** - Returns effective PHP upload limit as human-readable string
- **NotificationGroup::findByName()** - Lookup groups by name with optional user scope
- **Setting::getUpdateSettings()** - Returns all update-related settings in one call
- **In-memory CSV building** - Uses `php://temp` streams to avoid output buffer conflicts

### Migrations
- `025_add_update_system_v1.1.3.sql` - Adds `update_channel` and `update_badge_enabled` settings, updates app version to 1.1.3

---

## [1.1.2] - 2026-02-09

### Added
- **Google Chat Webhook Support** - Selectable payload formats: Generic (n8n/Zapier/Make), Google Chat (rich card), and Simple Text
- **Domain Status Change Notifications** - Configurable alerts for domain lifecycle events: available, registered, expired, redemption_period, pending_delete
- **Failed Login Notifications** - In-app alerts for failed login attempts with geolocation, device info, and reason
- **Domain Expiration Bell Notifications** - In-app notifications for expiring domains, respects user isolation mode
- **Admin User Profile Page** (`/users/{id}`) - Detailed view with Overview, Domains, Tags, and Notification Groups tabs
- **Dashboard Insights Widgets** - Registrar distribution, tag usage, and notification coverage for logged-in users
- **Quick Actions Dropdown** - Top-nav `+` button with Add Domain, Create Group, Create Tag, and WHOIS Lookup
- **WHOIS Rate Limit Handling** - Exponential backoff with retry logic, grouped by TLD to avoid repeated throttling
- **Admin TLD Registry Editing** - Edit WHOIS and RDAP servers directly from the TLD registry UI
- **Redemption Period & Pending Delete Detection** - New domain statuses parsed from EPP status codes (`redemptionPeriod`, `pendingDelete`)
- **Configurable Status Triggers** - Settings UI to choose which domain status changes trigger notifications
- **Sidebar Branding** - SVG logo with clickable "Domain Monitor" title and "Track your domains" subtitle
- **404 Error Logging** - Router logs 404 errors with request method, IP, user-agent, and referer details
- **Copy Error Report** - Clipboard copy with toast feedback in admin error detail view

### Changed
- **Dashboard Redesigned** - Compact admin system status bar, balanced widget grid, removed Quick Actions widget
- **Mobile UI Overhauled** - Sidebar overlay with swipe-to-close, body scroll lock, responsive layout tweaks
- **Error Log Deduplication Improved** - Matches on type + file + line + message; resolution operates on all matching errors
- **Webhook Logging Enhanced** - Masked URLs, response body truncation, payload previews, structured error handling
- **Notification Dropdown Enriched** - Country flags, device icons for login alerts, clickable domain links
- **User Create Form Redesigned** - Centered card layout, responsive grid, password show/hide toggles, live validation
- **WHOIS Date Parsing** - Added DD/MM/YYYY format support for European registries (.pt, .es, .fr)
- **Domain Status ENUM Expanded** - Added `redemption_period` and `pending_delete` values
- **Status Detection Improved** - Better handling for .nl and .eu domains missing expiration dates
- **Login Success Messages** - Now include the user's full name
- **Centralized Logging** - Logger service replaces all remaining `error_log()` calls

### Fixed
- **Notification Group Delete** - Changed from GET to POST with CSRF token (was vulnerable to CSRF)
- **Bulk Domain Create** - Wrapped in try/catch to handle duplicate domain conflicts gracefully
- **User Edit Form Action** - Fixed route mismatch (`/users/update` → `/users/{id}/update`)
- **Tag Isolation Access** - Enforced permission checks in TagController for isolated mode
- **RDAP Server Route** - Fixed route name mismatch between definition and controller method
- **Top-Nav Dropdowns** - Fixed broken dropdown toggle logic after Quick Actions addition
- **PHP 8.x Compatibility** - Fixed null parameter warnings in date functions
- **Sidebar Quick Stats** - Fixed variable collision when viewing user profiles

### Security
- **CSRF Protection** - Added to profile delete, notification delete/clear-all, user delete, user toggle-status
- **POST Method Enforced** - All destructive actions changed from GET to POST (profile, notifications, users, groups)
- **Failed Login Alerts** - Target user notified with IP address and user-agent details
- **Tag Access Control** - Isolated mode users blocked from viewing other users' tags via direct URL

### Migrations
- `024_add_status_notifications_v1.1.2.sql` - Expands domain status ENUM, adds notification status triggers setting, updates app version

---

## [1.1.1] - 2025-11-18

### Added
- **Pushover Notification Channel** - Send domain expiration alerts via Pushover (iOS, Android, Desktop)
  - Priority-based notifications (Emergency, High, Normal, Low) based on days until expiration
  - Emergency alerts (expired or expiring in ≤1 day) with auto-retry every 5 minutes for 1 hour
  - 23 custom notification sounds to choose from
  - Device targeting - send to specific devices or all devices
  - Rich notifications with title, message, and clickable URL to domain details
  - Optional custom sound and device configuration
  - Database migration `022_add_pushover_channel_type.sql` to add Pushover support

### Fixed
- **Security: PHP 8.x URI Injection Vulnerability** - Fixed deprecated `strpos()` null parameter warning
  - Added early request validation in `public/index.php` to block malformed URIs
  - Enhanced `core/Auth.php` to handle null values from `parse_url()` gracefully
  - Malformed requests are now logged and return 400 Bad Request
  - Prevents attackers from causing PHP warnings via malformed URI probes
- **PHP 8.x Compatibility: strtotime() Null Parameter** - Fixed deprecated warnings for null expiration dates
  - Added null checks before calling `strtotime()` in all domain view templates
  - Displays "Unknown" for domains without expiration dates (e.g., .nl domains)
  - Updated 9 view files: groups/edit, domains/index, domains/view, domains/edit, dashboard/index, tags/view, search/results
  - Also fixed `NotificationService::formatExpirationMessage()` to handle null dates
- **Domain Status Detection for .nl Domains** - Fixed incorrect "available" status for registered .nl domains
  - `.nl` WHOIS/RDAP doesn't always provide expiration dates or explicit status flags
  - Improved `WhoisService::getDomainStatus()` to detect registered domains via nameservers and valid registrar
  - Cron job now preserves existing expiration dates when WHOIS doesn't return one
  - Prevents false positives for domain availability
- **Domain Status Detection for .eu Domains** - Fixed incorrect status and registrar parsing for .eu domains
  - Added specific `.eu` registrar format parsing (`Name: Registrar Name`)
  - Fixed RDAP vCard parsing to strip "Name:" prefix from registrar field  
  - Fixed WHOIS parsing to handle "Name: Company" format in registrar sections
  - Enhanced status detection logic to recognize registered domains without explicit status flags
  - Consistent behavior between manual refresh and automated cron checks
- **Logging Consistency** - Replaced all remaining `error_log()` calls with custom Logger service
  - Updated `WhoisService.php`, `NotificationService.php`, `AuthController.php`, `UserController.php`
  - Centralized structured logging throughout the application
  - Better debugging and audit trail capabilities

### Changed
- **Status Detection** - Unified `DomainHelper::determineStatus()` to use `WhoisService::getDomainStatus()` for consistency
- **Documentation** - Updated README.md to reflect all available notification channels including Pushover

## [1.1.0] - 2025-10-09

### Added
- **User Notifications System** - In-app notification center with filtering and pagination
- **Welcome Notifications** - Automatically sent to new users on registration or fresh install
- **System Upgrade Notifications** - Admins notified when system is upgraded with migration details
- **Notification Types**:
  - System: Welcome, Upgrade notifications
  - Domain: Expiring, Expired, Updated
  - Security: New login detection
  - WHOIS: Lookup failures
- **Notification Features**:
  - Unread notification count in top navigation
  - Dropdown preview of recent notifications
  - Full notification page with filtering (status, type, date range)
  - Pagination and sorting
  - Mark as read / Mark all as read
  - Delete individual / Clear all notifications
- **Database-Backed Sessions** - Full session management stored in database
- **Active Session Management** - View, monitor, and control all logged-in devices
- **Geolocation Tracking** - IP-based location detection (country, city, region, ISP)
- **Session Details Display**:
  - Country flags with flag-icons library
  - City and country name
  - ISP/Network provider
  - Device type detection (Desktop/Mobile/Tablet)
  - Browser detection (Chrome/Firefox/Safari/Edge/Opera)
  - Session age and last activity timestamps
  - Remember me indicator (cookie badge)
- **Remote Session Control**:
  - Terminate individual sessions with delete button
  - Logout all other sessions with one click
  - Immediate logout validation (deleted sessions can't access anything)
- **Enhanced Profile Page**:
  - Sidebar navigation layout
  - Four sections: Profile Information, Security, Active Sessions, Danger Zone
  - URL hash navigation (#profile, #security, #sessions, #danger)
  - Clean design matching application theme
- **Remember Token Security**:
  - Remember tokens linked to specific sessions
  - Deleting session also invalidates remember token
  - Prevents auto-login after remote logout
- **Session Validator Middleware** - Validates sessions on every request
- **Auto-Detected Cron Paths** - Settings page shows actual installation paths (thanks @jadeops)
- **Automatic Session Cleanup** - Multiple cleanup triggers (no cron job needed)
- User registration with email verification
- Password reset via email
- Remember me functionality (30-day cookies)
- User profile management
- Change password
- Email verification with token expiry (24h)
- Password reset tokens (1h expiry)
- Registration enable/disable toggle
- User CRUD management (admin-only)
- Role-based access control (admin/user)
- Centralized app version in database
- Web-based installer (replaces CLI migrate.php)
- Web-based updater for new migrations
- Auto-detection of installation status
- Migration tracking system
- Consolidated database schema for v1.1.0 fresh installs
- Smart migration system (consolidated for new, incremental for upgrades)
- **Two-Factor Authentication (2FA) System**:
  - TOTP (Time-based One-Time Password) implementation
  - Email backup codes for 2FA recovery
  - 2FA verification attempts tracking with rate limiting
  - 2FA policy settings (optional/required/disabled)
  - Complete 2FA setup, verification, and management flow
  - Backup codes generation and verification system
- **CAPTCHA Security System**:
  - Support for reCAPTCHA v2, reCAPTCHA v3, and Cloudflare Turnstile
  - Configurable CAPTCHA settings in admin panel
  - Score-based verification for reCAPTCHA v3
  - Integration with login and registration forms
  - CAPTCHA provider selection and configuration
- **Domain Tags System**:
  - Domain tagging for organization and categorization
  - Comma-separated tags field in domains table
  - Tag-based domain filtering and organization
  - Indexed tag searches for performance
- **Advanced Error Logging System**:
  - Database-backed error logging and tracking
  - Error deduplication and occurrence counting
  - Request context capture (method, URI, data)
  - User context (IP, user agent, session data)
  - System context (PHP version, memory usage)
  - Error resolution tracking and management
  - Admin error log interface for debugging
- **Enhanced Logger Service**:
  - Structured logging with context arrays
  - Multiple log levels (debug, info, warning, error, critical)
  - Date-based log file rotation
  - Context-aware logging throughout the application
  - JSON-formatted log entries with timestamps
- **User Avatar System**:
  - Avatar upload and deletion functionality
  - Gravatar integration with fallback to user initials
  - Dynamic web root detection for file uploads
  - Avatar display in profile, navigation, and user listings
  - File validation and security measures
- **WHOIS Parsing Improvements**:
  - Enhanced WHOIS data parsing and processing
  - Better referral server handling and following
  - Improved domain availability detection
  - Status parsing cleanup and consistency
  - WHOIS server display improvements

### Changed
- Profile page completely redesigned with sidebar layout
- Session system migrated from file-based to database-backed
- Top navigation dropdown links updated with hash navigation
- Settings → System tab now shows auto-detected cron paths
- Help & Support menu links to GitHub repository
- Auth views refactored with base layout
- System section (Settings/Users) restricted to admins
- TLD Registry read-only for regular users
- Sidebar shows role-based links
- Profile integrated with dashboard layout
- Installation now via web UI instead of CLI
- Auto-redirect to installer on first run
- Domain management enhanced with tagging system
- Error handling improved with comprehensive logging
- WHOIS parsing enhanced with better data extraction
- User interface updated with avatar display throughout

### Security
- **Database Session Storage** - True session control with remote termination
- **Session Validation** - Every request validates session exists in database
- **Geolocation Logging** - Track suspicious login locations
- **Remember Token Linking** - Tokens tied to sessions, deleted together
- **Immediate Logout** - Deleted sessions invalidated within seconds
- Bcrypt password hashing
- Secure 32-byte tokens
- Time-limited tokens
- One-time use reset tokens
- HttpOnly secure cookies
- Email enumeration protection
- Session-based verification resend
- Admin-only route protection
- **Two-Factor Authentication** - TOTP and email backup codes for enhanced security
- **CAPTCHA Protection** - Anti-bot protection for login and registration
- **Advanced Error Logging** - Comprehensive error tracking and debugging
- **File Upload Security** - Avatar upload validation and secure file handling

### Technical
- **MVC Architecture Refactoring** - Complete separation of concerns
  - `LayoutHelper` - Global layout data (notifications, stats, settings)
  - `DomainHelper` - Domain formatting and business logic
  - `SessionHelper` - Session display formatting
  - `NotificationService` - Notification creation and management
  - All business logic removed from views (~265 lines cleaned)
- Database session handler implementing SessionHandlerInterface
- IP geolocation via ip-api.com (free, 45 req/min)
- Session validator middleware for real-time validation
- Automatic session cleanup (no cron needed for sessions)
- Flag-icons library integration for country flags
- User-agent parsing for device and browser detection
- Remember token cascade deletion on session termination
- Notification system with 7 notification types
- Welcome notifications on user creation and fresh install
- Upgrade notifications for admins with version tracking
- **TwoFactorService** - Complete 2FA implementation with TOTP and backup codes
- **CaptchaService** - Multi-provider CAPTCHA verification system
- **ErrorHandler** - Centralized error handling with database logging
- **Logger** - Enhanced logging service with structured context
- **AvatarHelper** - User avatar management with Gravatar integration
- **Tag Model** - Domain tagging system with user isolation
- **ErrorLog Model** - Error tracking and deduplication system

### Contributors
- Special thanks to @jadeops for auto-detected cron path improvement & XSS protection enhancement (PR #1)

## [1.0.0] - 2024-10-08

### Added
- Initial release of Domain Monitor
- Modern PHP 8.1+ MVC architecture
- Domain management system with CRUD operations
- Automatic WHOIS lookup for domain information
- Multi-channel notification system:
  - Email notifications via PHPMailer
  - Telegram bot integration
  - Discord webhook support
  - Slack webhook support
- Notification groups feature
- Assign domains to notification groups
- Dashboard with real-time statistics
- Domain status tracking (active, expiring_soon, expired, error)
- Notification logging system
- Customizable notification intervals
- Cron job for automated domain checks
- Test notification script
- Responsive, modern UI design
- Database migration system
- Comprehensive documentation
- Installation guide
- Basic login/logout authentication
- Security features (prepared statements, session management)
- **TLD Registry System with IANA integration**
  - Import and manage TLD data (RDAP servers, WHOIS servers, registry URLs)
  - Progressive import workflow with real-time progress tracking
  - Support for 1,400+ TLDs with automatic updates
  - Import logs and history tracking
- Advanced domain verification using TLD registry data
- RDAP protocol support for modern domain queries
- Automatic WHOIS server discovery per TLD
- Monitoring status change notifications (activated/deactivated alerts)
- Notification group assignment change alerts
- Enhanced domain detail view with channel status indicators
- Comprehensive notification threshold configuration
- Debug logging for notification thresholds

### Changed
- Unified design system across all views
  - Consistent header styles (bordered instead of gradients)
  - Standardized button sizes and padding
  - Consistent form input styling
  - Unified empty state designs
  - Removed emojis from UI elements
- Improved navigation flow (edit page returns to detail view)
- Enhanced cron job logging with threshold display
- Streamlined installation process
  - Encryption key auto-generation during migration
  - No separate script needed for encryption key setup

### Fixed
- Notification channel type display error in domain view
- Navigation redirect after domain update
- Cancel button redirect in domain edit page
- Design inconsistencies in notification group views

### Security
- Random secure password generation on installation
- One-time password display during migration
- Removed hardcoded default credentials
- 16-character cryptographically secure admin passwords

### Features
- ✅ Add, edit, delete, and view domains
- ✅ Automatic expiration date detection via WHOIS
- ✅ Support for multiple notification channels per group
- ✅ Flexible notification scheduling (60, 30, 21, 14, 7, 5, 3, 2, 1 days before)
- ✅ Email notifications with HTML templates
- ✅ Rich Discord embeds with color coding
- ✅ Telegram messages with formatting
- ✅ Slack blocks for structured messages
- ✅ Notification deduplication (prevent spam)
- ✅ Manual domain refresh
- ✅ Active/inactive domain toggle
- ✅ Comprehensive logging
- ✅ Statistics dashboard
- ✅ Recent notifications view
- ✅ Domain details with WHOIS data
- ✅ Nameserver display
- ✅ Notification history per domain

### Technical
- PHP 8.1+ with modern features (match expressions, typed properties)
- MySQL/MariaDB database
- PSR-4 autoloading
- Environment-based configuration
- MVC pattern implementation
- Service layer architecture
- Repository pattern for data access
- Interface-based notification channels
- JSON configuration storage
- Prepared statements for SQL injection prevention
- CSRF token support ready
- Responsive CSS with CSS variables
- No JavaScript framework dependencies (vanilla JS where needed)

### Documentation
- README.md with comprehensive guide
- Inline code documentation
- Configuration examples
- Troubleshooting guide

---

## Roadmap - Future Enhancements

- [x] User authentication system (completed - v1.1.0)
- [x] Session management with geolocation (completed - v1.1.0)
- [x] TLD Registry System (completed - v1.0.0)
- [x] Remote session termination (completed - v1.1.0)
- [x] In-app user notifications (completed - v1.1.0)
- [ ] Multi-user support with advanced permissions and roles
- [ ] API for external integrations
- [x] Domain grouping/tagging (completed - v1.1.0)
- [ ] Custom notification templates
- [ ] SMS notifications (Twilio)
- [x] Google Chat notifications (completed - v1.1.2)
- [ ] WhatsApp notifications
- [x] Export functionality (CSV, JSON) (completed - v1.1.3, TLD Registry - v1.1.4)
- [x] Import domains from CSV/JSON (completed - v1.1.3, TLD Registry - v1.1.4)
- [ ] Domain transfer tracking
- [x] DNS record monitoring (completed - v1.1.5)
- [x] SSL certificate monitoring (completed - v1.1.5)
- [ ] Downtime monitoring
- [x] 2FA for login (completed - v1.1.0)
- [ ] Mobile app
- [ ] Docker support
- [ ] Redis caching
- [ ] Rate limiting
- [ ] Webhook support for third-party integrations
- [ ] Dark mode UI toggle
- [ ] Multi-language support
- [x] Advanced filtering and search (completed - v1.1.0)
- [x] Bulk operations (completed - v1.1.0)
- [ ] Scheduled reports
- [ ] Integration with domain registrars

---

## Version History

### 1.1.5 (2026-03-08)
- **Twig Templating** - All PHP views migrated to Twig; Twig-only rendering with safe error fallback
- **DNS Monitoring** - DnsService, DnsRecord model, crt.sh discovery, Cloudflare detection, per-domain toggle, `cron/check_dns.php`
- **SSL Certificate Monitoring** - SslService, SslCertificate model, add/refresh/delete endpoints, `cron/check_ssl.php`
- **Domain View Tabs** - Overview, DNS, Billing, Notifications, SSL, WHOIS; `domain_view_template` setting (detailed/legacy)
- **Cron Staleness Warnings** - Settings shows overdue warnings for domain/DNS/SSL cron runs
- **Timezone on Installer** - App timezone applied on `/install` and `/install/update` when installed
- **2FA/Settings** - Twig templates for 2FA, timezone lists, notification presets, cron path in Settings
- Migrations: `027_add_dns_monitoring.sql`, `028_add_ssl_monitoring.sql`

### 1.1.4 (2026-03-02)
- **TLD Registry Import & Export** - CSV/JSON export/import for TLD entries with WHOIS, RDAP, registry URL data
- **Manual TLD Creation** - Modal form to add custom TLDs with multi-level support (.co.uk, .co.za, .com.au)
- **IANA Dropdown** - Consolidated IANA operations (Import TLDs, Check Updates, Import Logs) into a single dropdown
- **Standardized Import/Export Logging** - Consistent `Logger` usage across Tags, Domains, Notification Groups, and TLD Registry
- **TLD Registry Action Bar Redesigned** - Cleaner layout: IANA (indigo), Export (emerald), Import, Create TLD
- Migration: `026_update_app_version_v1.1.4.sql`

### 1.1.3 (2026-02-11)
- **CSV/JSON Import & Export** - Domains, Tags, and Notification Groups with drag-and-drop file upload
- **Sensitive Data Masking** - API tokens and webhook URLs masked in group exports; masked channels imported as disabled
- **In-App Update System** - Check, apply, and rollback updates from Settings (GitHub Releases + hotfix tracking)
- **Update Channels** - Stable (releases only) or Latest (releases + hotfixes) with configurable badge
- **File & Database Backup** - Automatic backup before every update, one-click rollback
- **Update Notifications** - In-app alerts for admins when new releases or hotfixes are detected
- **Tag Transfer** - Admin-only individual and bulk transfer of tags between users
- **Domain Bulk Transfer** - Admin-only bulk transfer of domains to another user
- **Bulk Action Bars Redesigned** - Consistent inline toolbar styling across all list pages
- **Installer Hardened** - Admin auth required post-install; re-installation blocked
- **ErrorHandler Improvements** - Recursion guard, graceful fallback logging, `\Throwable` catch
- Migration: `025_add_update_system_v1.1.3.sql`

### 1.1.2 (2026-02-09)
- **Google Chat Webhook Support** - Selectable payload formats (Generic, Google Chat, Simple Text)
- **Domain Status Change Notifications** - Configurable alerts for available, registered, expired, redemption_period, pending_delete
- **Failed Login Notifications** - In-app alerts with geolocation, device info, and failure reason
- **Domain Expiration Bell Notifications** - In-app alerts respecting user isolation mode
- **Admin User Profile Page** - `/users/{id}` with Overview, Domains, Tags, Notification Groups tabs
- **Dashboard Insights** - Registrar distribution, tag usage, notification coverage widgets
- **Quick Actions Dropdown** - Top-nav shortcut for Add Domain, Create Group, Create Tag, WHOIS Lookup
- **WHOIS Rate Limit Handling** - Exponential backoff with TLD-grouped retry logic
- **Admin TLD Registry Editing** - Edit WHOIS/RDAP servers from UI
- **Redemption Period & Pending Delete** - New domain lifecycle statuses from EPP codes
- **Sidebar Branding** - Logo, title, and subtitle in sidebar navigation
- **Mobile UI Overhaul** - Sidebar overlay, swipe-to-close, responsive layout improvements
- **CSRF Protection** - POST method enforced on all destructive actions
- **Error Log Deduplication** - Improved matching on type + file + line + message
- **WHOIS Date Parsing** - DD/MM/YYYY format support for European registries
- **404 Error Logging** - Router logs with full request context
- Migration: `024_add_status_notifications_v1.1.2.sql`

### 1.1.0 (2025-10-09)
- **User Notifications System** - In-app notification center with 7 notification types, filtering, pagination
- **Advanced Session Management** - Database-backed sessions with geolocation (country, city, ISP)
- **Remote Session Control** - Terminate any device instantly with immediate logout validation
- **Enhanced Profile Page** - Sidebar navigation with 4 tabs, hash-based routing (#profile, #security, #sessions)
- **Two-Factor Authentication** - Complete TOTP implementation with email backup codes and rate limiting
- **CAPTCHA Security System** - Support for reCAPTCHA v2/v3 and Cloudflare Turnstile with admin configuration
- **Domain Tags System** - Organize domains with custom tags for better categorization and filtering
- **Advanced Error Logging** - Database-backed error tracking with deduplication, context capture, and admin interface
- **User Avatar System** - Avatar upload with Gravatar integration and fallback to user initials
- **Enhanced Logger Service** - Structured logging with context arrays and multiple log levels
- **WHOIS Parsing Improvements** - Enhanced domain data parsing, referral handling, and availability detection
- **MVC Architecture Refactoring** - 3 new Helpers (Layout, Domain, Session), ~265 lines cleaned from views
- **Geolocation Tracking** - IP-based location detection using ip-api.com, country flags with flag-icons
- **Device Detection** - Browser & device type parsing (Chrome/Firefox/Safari, Desktop/Mobile/Tablet)
- **Auto-Detected Cron Paths** - Settings show actual installation paths (thanks @jadeops)
- **Welcome Notifications** - Sent to new users on registration or fresh install
- **Upgrade Notifications** - Admins notified on system updates with version & migration count
- **Web-Based Installer** - Replaces CLI, auto-generates encryption key, one-time password display
- **Web-Based Updater** - `/install/update` for running new migrations with smart detection
- **User Registration** - Full signup flow with email verification, password reset, resend verification
- **User Management** - CRUD for users with filtering, sorting, pagination (admin-only)
- **Remember Me** - 30-day secure tokens linked to sessions, cascade deletion on logout
- **Session Validator** - Middleware validates sessions on every request for instant remote logout
- **Consistent UI/UX** - Unified filtering, sorting, pagination across Domains, Users, Notifications, TLD Registry
- **Smart Migrations** - Consolidated schema for fresh installs, incremental for upgrades
- **XSS Protection** - htmlspecialchars() applied across all user-facing data (thanks @jadeops)

### 1.0.0 (2024-10-08)
- Initial public release
- Created by [Hosteroid](https://www.hosteroid.uk) - Premium Hosting Solutions

---

## 🙏 Special Thanks

### Contributors
- **@jadeops** - Auto-detected cron path improvement & XSS protection enhancement (PR #1)

