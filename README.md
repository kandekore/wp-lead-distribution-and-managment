# WordPress Lead Distribution Plugin

**Version:** 5.0.0
**Author:** D. Kandekore
**Contact:** darren@kandekore.net

---

## Overview

A WordPress plugin that receives scrap-car leads from third-party sources via a REST API and distributes them to subscribed agents based on postcode areas, credit balance, vehicle model preferences, and a weighted-priority system. Includes credit management, subscription auto-renewal, a 6-tab analytics dashboard, an audit log, duplicate detection, and CSV export.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- WooCommerce Subscriptions 5.0+
- Action Scheduler (bundled with WooCommerce — no separate install needed)
- PHP 8.0+

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins › Installed Plugins**.
3. On first activation the plugin will:
   - Load default UK postcode areas from `data/uk-postcode-areas.json`.
   - Create the `{prefix}ld_audit` database table for the audit log.
4. Configure **Master Admin Settings** and **Fallback User Settings** before going live.

---

## File Structure

```
wp-lead-distribution/
├── wp-lead-distribution.php        # Entry point: includes, activation/deactivation hooks
├── includes/
│   ├── api-endpoints.php           # REST endpoint — queues leads via Action Scheduler
│   ├── lead-processing.php         # Core distribution logic, runs asynchronously
│   ├── utility-functions.php       # store_lead(), SMS helpers, CPT registration, filters
│   ├── duplicate-detection.php     # lmd_is_duplicate_lead() — 24 h dedup check
│   └── load-postcodes.php          # Reads data/uk-postcode-areas.json
├── products/
│   ├── credits.php                 # Credit cron jobs, notifications, auto-renewal
│   ├── lead-distribution.php       # get_customers_by_region(), lead reception toggle
│   └── product-meta.php            # WooCommerce _credits + _renew_on_credit_depletion fields
├── admin/
│   ├── admin-pages.php             # All admin menu/submenu registrations + settings
│   ├── audit-log.php               # lmd_create_audit_table(), lmd_audit(), admin UI
│   ├── reports-dashboard.php       # 6-tab analytics dashboard + CSV export
│   ├── user-backend.php            # Postcode, credits, car model fields on user profile
│   ├── customer-account-page.php   # Frontend My Account enhancements
│   ├── post-pay-user.php           # Post-pay role management
│   ├── resend.php                  # "Duplicate Lead" meta box on lead edit screen
│   └── user-signup.php             # Custom signup flow
└── data/
    └── uk-postcode-areas.json      # UK postcode region definitions
```

---

## API Endpoint

### Submit Lead

```
GET  https://your-site.com/wp-json/lead-management/v1/submit-lead?leadid=...&postcode=...
POST https://your-site.com/wp-json/lead-management/v1/submit-lead
```

**Authentication:** None (public endpoint — restricted to trusted sending sources by network/firewall if needed).

**How it works (async):** The endpoint returns `200 OK` immediately after queuing the lead via Action Scheduler. Distribution, email/SMS, and credit deduction all happen in the background — this eliminates the Gravity Forms webhook 5-second timeout.

**Deduplication:** If the same `leadid` arrives within 5 minutes, the second request is silently ignored (transient keyed on `lead_queued_{md5(leadid)}`).

View queued and failed jobs at: **WP Admin › Tools › Scheduled Actions**.

### Parameters

| Parameter     | Type   | Description                                         |
|--------------|--------|-----------------------------------------------------|
| `leadid`      | string | Unique ID assigned by the sending source            |
| `postcode`    | string | Full UK postcode (e.g. `SW1A 1AA`)                  |
| `vrg`         | string | Vehicle registration plate                          |
| `model`       | string | Vehicle model (e.g. `Ford Focus`)                   |
| `date`        | string | Year of manufacture                                 |
| `fuel`        | string | Fuel type (`Petrol`, `Diesel`, etc.)                |
| `trans`       | string | Transmission type                                   |
| `cylinder`    | string | Engine size / cylinders                             |
| `colour`      | string | Vehicle colour                                      |
| `doors`       | int    | Number of doors                                     |
| `mot`         | string | MOT status                                          |
| `mot_due`     | string | MOT due date                                        |
| `vin`         | string | Vehicle Identification Number                       |
| `milage`      | string | Mileage                                             |
| `keepers`     | string | Customer name                                       |
| `contact`     | string | Customer phone number                               |
| `email`       | string | Customer email address                              |
| `info`        | string | Additional notes                                    |
| `vt_campaign` | string | Campaign identifier                                 |
| `utm_source`  | string | UTM source                                          |
| `vt_keyword`  | string | Keyword                                             |
| `vt_adgroup`  | string | Ad group                                            |
| `resend`      | string | Set to `1` to bypass duplicate detection            |

---

## Lead Distribution Logic

Each lead is processed in the background by `process_lead_data()` in this order:

1. **Duplicate detection** — contact/phone or email matching same postcode area (first 2 chars) within 24 hours → lead dropped and audited as `duplicate_dropped`.
2. **Master Admin** — if enabled and vehicle year exceeds the configured minimum, lead goes exclusively to Master Admin → audited as `master_admin`.
3. **Eligible recipients** — all WP users evaluated against:
   - **Postcode** — user's selected postcode codes matched against lead prefix via regex.
   - **Credits/role** — pre-pay users need `_user_credits > 0`; post-pay users need `enable_lead_reception = 1`.
   - **Model preference** — if a user has `_user_car_models` set, only matching leads are sent.
   - **VIN dedup** — users who already hold a lead with the same VIN are excluded.
4. **Priority weighting** — model-specific users get 4 weighted entries; `lead_priority` flag adds 1× (general) or 3× (model-specific) extra entries. `array_rand()` picks the winner.
5. **Fallback** — no eligible recipients → lead forwarded to Fallback User via email or external API → audited as `fallback_assigned`/`no_recipients`.
6. **Credit deduction** — one credit deducted; at ≤5 credits auto-renewal is triggered; at 0 credits, subscription is cancelled and depletion email sent if renewal fails.

---

## Credit System

Credits are stored in the `_user_credits` user meta field.

### Granting Credits

Credits are granted when a WooCommerce order reaches **Completed** status. The number of credits per product is set in the **Credits** field on the product General tab. An `_ld_credits_assigned` order meta flag prevents double-granting if the hook fires more than once.

### Auto-renewal

When credits drop to ≤5, the system attempts an early subscription renewal if:
- The subscription is active and not manual.
- The product has **Renew on Credit Depletion** checked.

If renewal succeeds, credits are replenished. If it fails, the user is notified and another attempt is made after 24 hours (via the 5-minute cron).

### WP-Cron Jobs

| Event                          | Interval      | Purpose                                    |
|-------------------------------|---------------|--------------------------------------------|
| `daily_credit_check_event`     | Every 24 h    | Credit balance warning emails              |
| `renew_subscription_cron_job_event` | Every 5 min | Auto-renewal attempts for low-credit users |

---

## Admin Pages

All pages live under **Lead Management** in the WordPress admin sidebar.

| Menu Item              | Slug                      | Description                                                  |
|-----------------------|--------------------------|--------------------------------------------------------------|
| Reports Dashboard      | `lead-reports-dashboard`  | 6-tab analytics dashboard with charts and CSV export         |
| URL Reports            | `lead-url-reports`        | Legacy per-URL lead reports                                  |
| Postcode Areas         | `manage-postcode-areas`   | Manage available UK postcode regions                         |
| User Credits           | `user-credits-management` | View and manually adjust user credit balances                |
| Regions & Users        | `regions-and-users-credits` | Active agents per postcode region                          |
| Master Admin Settings  | `master-admin-settings`   | Master Admin email, user ID, minimum vehicle year            |
| Fallback User Settings | `fallback-user-settings`  | Fallback email, user ID, optional external API endpoint      |
| SMS Providers          | `sms-provider-settings`   | Email-to-SMS gateway URLs                                    |
| Audit Log              | `lead-audit-log`          | Filterable, paginated log of all routing decisions           |

### Manual Credit Check Trigger

Logged-in admins can manually fire the daily credit check by visiting:

```
https://your-site.com/?trigger_credit_check=1
```

Requires `manage_options` capability.

---

## Reports Dashboard

### Period Filter

Select a reporting window using the pill buttons at the top: Today, 7 Days, 30 Days, 90 Days, This Month, Last Month, All Time.

### Tabs

| Tab              | Charts & Tables                                                          |
|-----------------|--------------------------------------------------------------------------|
| Overview         | KPI cards, 30-day trend line, top sources this month, top agents all time, CSV export button |
| Sources          | Source domain donut, campaign bar, UTM source bar, ad group bar, keywords table |
| Agent Performance | Horizontal bar chart, table with lead count and current credits per agent |
| Geographic       | Postcode area horizontal bar, percentage breakdown table                 |
| Vehicle Analysis | Fuel type donut, transmission donut, year bar, top models bar            |
| Time Patterns    | Day-of-week bar, monthly volume line, hour-of-day bar, insights table    |

### CSV Export

Click **Export leads as CSV** on the Overview tab to download all leads for the selected period. Columns: Lead ID, Registration, Model, Postcode, Contact, Email, Fuel, Transmission, Year, Source Domain, Campaign, UTM Source, Agent, Date.

---

## Audit Log

All lead routing decisions are written to `{prefix}ld_audit` and viewable at **Lead Management › Audit Log**.

### Event Types

| Event               | Description                                                    |
|--------------------|----------------------------------------------------------------|
| `duplicate_dropped` | Lead rejected — same contact/email+postcode within 24 hours   |
| `no_recipients`     | No eligible agents; fallback disabled                          |
| `master_admin`      | Lead routed to the Master Admin                                |
| `fallback_assigned` | Lead routed to the Fallback User                               |
| `lead_assigned`     | Lead assigned to an agent (includes credit delta in context)   |

Each row stores: event type, lead ID, user ID, human-readable message, and a JSON context blob for debugging. The table is paginated (50/page) and filterable by event type.

---

## Duplicate Detection

`lmd_is_duplicate_lead()` in `includes/duplicate-detection.php` queries existing `lead` posts from the last 24 hours and returns `true` (drop the lead) if either:

- Same **contact phone number** AND same **2-char postcode prefix**, or
- Same **email address** AND same **2-char postcode prefix**.

Resent leads (`resend=1`) always bypass this check.

---

## Product Configuration (WooCommerce)

Two custom fields are added to each subscription product's **General** tab:

| Field                      | Meta key                   | Description                                             |
|---------------------------|----------------------------|---------------------------------------------------------|
| Credits                    | `_credits`                 | Number of credits granted when the order completes      |
| Renew on Credit Depletion  | `_renew_on_credit_depletion` | If checked, triggers early renewal when credits ≤ 5   |

---

## Hooks & Filters

| Hook / Filter                                                    | File                    | Description                                          |
|-----------------------------------------------------------------|-------------------------|------------------------------------------------------|
| `process_lead_async` (action)                                    | lead-processing.php     | Action Scheduler callback for async lead processing  |
| `woocommerce_order_status_completed` (action)                   | product-meta.php        | Assigns credits from completed orders (with guard)   |
| `woocommerce_subscription_status_updated` (action)              | credits.php             | Sends renewal / cancellation / failure notifications |
| `woocommerce_payment_complete_order_status` (filter)            | credits.php             | Forces renewal orders to completed status            |
| `woocommerce_subscription_payment_complete` (action)            | wp-lead-distribution.php | Pushes next payment date 10 years out               |
| `woocommerce_email_enabled_customer_completed_renewal_order` (filter) | lead-processing.php | Suppresses duplicate renewal emails for credit-triggered renewals |
| `cron_schedules` (filter)                                        | credits.php             | Adds `daily` (86400 s) and `every_five_minutes` (300 s) intervals |
| `daily_credit_check_event` (action)                              | credits.php             | Daily credit balance notification emails             |
| `renew_subscription_cron_job_event` (action)                     | credits.php             | Every-5-min auto-renewal processing                  |
| `admin_post_ld_export_leads` (action)                            | reports-dashboard.php   | Streams CSV export of leads                          |

---

## Changelog

### 5.0.0
- Rewrote API endpoint to return 200 immediately and process leads asynchronously via Action Scheduler — eliminates Gravity Forms webhook timeouts (cURL error 28).
- Added GET + POST support on the submit-lead endpoint.
- Added 5-minute `leadid` deduplication transient at the API layer.
- Added 24-hour contact/email+postcode duplicate detection (`lmd_is_duplicate_lead`).
- Added full audit log (`{prefix}ld_audit` table, `lmd_audit()`, filterable admin UI).
- Added 6-tab Reports Dashboard with Chart.js 4.4.9 and period filtering.
- Added CSV export for leads on the Overview tab.
- Fixed double credit assignment — removed the `woocommerce_subscription_status_active` hook and added `_ld_credits_assigned` idempotency guard on the completed-order hook.
- Fixed `exit()` in auto-renewal cron aborting processing of remaining users — replaced with `return`.
- Fixed unreachable `error_log` in `store_lead()` (was positioned after `return $post_id`).
- Removed all debug `error_log` calls that were leaking POST data (including passwords) and PII to server logs.
- Removed all commented-out dead code from `lead-distribution.php` and `resend.php`.
- Removed dead `process_lead_submission_with_lock()` function from `lead-processing.php`.
- Added `manage_options` capability check to the manual credit-check trigger URL.

### 4.0.0 and earlier
- Initial subscription-based lead distribution system.
- WP-Cron credit checking and auto-renewal.
- Master Admin and Fallback User routing.
- SMS gateway integration via email-to-SMS.
