# Idloom Webhook Plugin

**Repo:** https://github.com/UWPIAATech/idloom-webhook-plugin
(moved from `tresero/idloom-webhook-plugin`)
**On the server:** `/opt/uwpiaa/wp_files/wp-content/plugins/idloom-webhook/`
**Endpoint:** `POST https://uwpiaa.org/wp-json/idloom/v1/webhook`

## What it does

When someone registers in Idloom, Idloom sends the registration to this
endpoint. If they ticked the "update my information" consent (`free_field59`),
the plugin adds or updates them in CiviCRM. Either way it emails
tech.cmte@uwpiaa.org; without the consent the email says CiviCRM was not
updated. Every email shows both checkboxes: "update my record" (`free_field59`)
and "Who's Registered" (`free_field56`).

- Finds an existing contact by email and updates it. A new contact is created
  only if no contact has that email, or if several do.
- Saves email, phone and primary address. Idloom's country/state codes
  (e.g. `US`, `AZ`) are converted to CiviCRM's country and state.
- Blank fields never overwrite existing data. New contacts get the source
  "Idloom Registration".
- Repeat deliveries of the same webhook within 5 minutes are skipped.

## Files

| File | Purpose |
|---|---|
| `idloom-webhook.php` | REST route, token check, CiviCRM create/update |
| `idloom-notify.php` | Notification emails (success / no consent / failure / duplicate) |

## Security

Idloom doesn't sign webhooks, so a shared token is used instead.

**wp-config.php**
```php
define('IDLOOM_WEBHOOK_TOKEN', '<secret>');   // the shared token
define('IDLOOM_WEBHOOK_ENFORCE', true);       // false = only log bad tokens (rollout)
```

**Idloom:** Manager → Integration → Idloom → edit the integration → the
webhook → **Headers**:
```json
{
 "Content-Type": "application/json",
 "Authorization": "Bearer <secret>"
}
```
(`X-Idloom-Token: <secret>` is also accepted.)

When enforcement is on, requests without the right token get a `401`.
Note that the route appears publicly at `/wp-json/`, so the token is the
only thing protecting it.

**Changing the token:** generate a new one (`openssl rand -hex 32`), put it in
both wp-config.php and the Idloom headers, and check that a delivery succeeds.

## Logs

```
docker logs --since 24h uwpiaa-wordpress 2>&1 | grep "Idloom Webhook"
```
Each delivery logs its ID, the contact created or updated, and "Complete".
"Missing or invalid token" means the Idloom header is wrong or missing.

## Maintenance notes

- Files must be owned by `www-data`. Run git as that user
  (`sudo -u www-data git ...`); running it as root leaves files owned by
  root.
- Needs the CiviCRM plugin and the custom field
  `Extra_IAA_Contact_Info.Primary_Cast`.
- The attendee list on the site (the `aaw-attendee-list` plugin) doesn't use
  this webhook; it reads straight from the Idloom API.

## Revision History

| Version | Date | Changes |
|---|---|---|
| 1.3.1 | 2026-11-10 | Email tech.cmte@uwpiaa.org for registrations without the `free_field59` consent too (CiviCRM still not updated). Every registration email shows the `free_field59` and `free_field56` checkboxes. |
| 1.3.0 | 2026-11-10 | Shared-token authentication (`IDLOOM_WEBHOOK_TOKEN` / `IDLOOM_WEBHOOK_ENFORCE`); existing contacts found by email instead of duplicated; `match` fixed for email/phone/address; country and state now saved; blanks no longer overwrite data; README rewritten. |
| 1.2.1 | 2026-03-30 | Added email notifications (`idloom-notify.php`) for success, failure and duplicates. |
| 1.2 | 2026-03-30 | Skip repeat deliveries of the same webhook (idloom retries). |
| 1.1 | 2025-11-19 | Create/update CiviCRM contacts instead of activities; Primary Cast from `cpy_name`. |
| 1.0 | 2025-05-17 | Initial release: creates a CiviCRM activity for each registration. |

## Author

Jon Griffin

## License

GPL v2 or later.
