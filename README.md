# 🖥️ WHMCS OVHcloud reseller module

![WHMCS](https://img.shields.io/badge/WHMCS-9.0.x-1c4587)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4)
![OVHcloud](https://img.shields.io/badge/OVHcloud-VPS%20API%20v6-123f6d)
![License](https://img.shields.io/badge/license-Source--Available%20(No%20Resale)-2ea44f)

Resell **OVHcloud VPS** straight from WHMCS: one product where the customer picks the OS
image (free Linux distros, the **[Docker](https://www.docker.com/)** and
**[n8n](https://n8n.io/)** app images, and optionally paid Windows), with automatic
ordering on your own OVH account, a full self-service control panel in the client area,
billing that matches the OVH commitment term, and automatic cancellation. cPanel and
Plesk images are never offered (their licenses would be billed to you).

> **TL;DR** A customer orders a VPS in your WHMCS store → the module places the order on
> *your* OVH account, pays it automatically, resolves the delivered VPS, and hands the
> customer a control panel (power, console, reinstall, snapshots, backups, network, ...).
> If the installed image is n8n, an extra **n8n** tab appears on top of the normal ones.
> You keep the margin between your WHMCS price and your OVH cost.

---

## 📑 Table of contents

1. [What it does / does not do](#-what-it-does)
2. [How the code is organised](#-how-the-code-is-organised)
3. [Requirements](#-requirements)
4. [Installation (cPanel, no SSH)](#-installation-cpanel-no-ssh)
5. [OVH API credentials](#-ovh-api-credentials)
6. [Configure the server in WHMCS](#-configure-the-server-in-whmcs)
7. [Create and configure a product](#-create-and-configure-a-product)
8. [Plan codes](#-plan-codes)
9. [Billing: WHMCS cycle to OVH commitment](#-billing-whmcs-cycle-to-ovh-commitment)
10. [Stock / availability](#-stock--availability)
11. [How it works (lifecycle)](#-how-it-works-lifecycle)
12. [OS images (Docker, n8n, Windows)](#-os-images-docker-n8n-windows)
13. [Useful commands](#-useful-commands)
14. [Testing](#-testing)
15. [Localization note](#-localization-note)
16. [Security notes](#-security-notes)
17. [Roadmap](#-roadmap)
18. [Disclaimer & trademarks](#-disclaimer--trademarks)
19. [License](#-license)

---

## ✨ What it does

### ✅ Capabilities

| Area | What you get |
|---|---|
| **Automatic provisioning** | Orders the VPS through the OVH order cart, pays it with the account's preferred payment method (`autoPay`), and resolves the delivered `serviceName`. Idempotent: a service that already has an order is never re-ordered (no double billing). |
| **Asynchronous delivery** | If OVH has not finished building the VPS at checkout time, the WHMCS cron finishes the job and maps the new VPS to the service. |
| **Catalog sync** | Pulls the public OVH VPS catalog (plans, datacenters, OS images, add-ons) into local cache tables for the configuration UI. |
| **One-click configurable options** | Generates the WHMCS *Configurable Options* (Operating System, Datacenter, and paid extras) for a product directly from the OVH catalog - no need to create a service first. The OS dropdown offers every catalog image except the cPanel/Plesk families (never sold). Re-running the generation SYNCS the options: existing prices are kept, new options start at 0, removed ones are deleted. |
| **Billing term matching** | Picks the OVH commitment that matches the customer's WHMCS billing cycle, capturing the deepest discount **without ever committing you on OVH for longer than the customer paid**. Multi-year terms re-commit automatically. |
| **Live stock control** | Hourly check of OVH stock per plan and per datacenter, driving WHMCS native stock control. Out-of-stock datacenters render as disabled options in the cart. |
| **Suspend / unsuspend** | Maps to OVH `stop` / `start`. |
| **Automatic cancellation** | On cancellation/termination, schedules OVH deletion at the end of the paid term (`renew.deleteAtExpiration`, no email token) so OVH stops billing you, and stops the VPS immediately. |
| **Option & model upgrades** | When a customer buys an extra (backup, snapshot, additional disk, IP, Veeam) mid-term, the module orders it on the existing VPS (`cartServiceOption`). It also upgrades the VPS to a bigger model in place (`ChangePackage` to a larger plan, via OVH `order/upgrade`). Both are add-only, auto-paid, and gated (orderability/`availableUpgrade` + a dry-run before every charge). |
| **Customer control panel** | Power, VNC console, OS reinstall, snapshots, automated backups, Veeam, additional disks, IPs + reverse DNS, secondary DNS. Every service gets the full panel and root access, whatever the installed image. |
| **n8n tab** | When the installed OS is an n8n image, the client area shows an **n8n** tab with the editor URL (port 5678 by default) IN ADDITION to all the normal tabs. |
| **Admin service panel** | All client actions plus admin-only controls: sync catalog, generate options, retry provisioning, set `serviceName` manually, confirm immediate termination, toggle delete-at-expiration, and view OVH cost (your margin). |
| **Audit trail** | Every OVH API call and order step is logged to the WHMCS module log and to the module's own task-log table. Each order records the OVH dry-run cost for margin auditing. |

### ❌ What it does NOT do (and why)

| Limitation | Why |
|---|---|
| **No option removal / downgrade** via `ChangePackage` | OVH options are cancelled separately from the order flow; the OVH API does not expose an in-cart removal, so the module is **add-only** and refuses removals/reductions with a clear message rather than silently desyncing WHMCS from OVH. |
| **No model downgrade** (e.g. VPS-3 → VPS-1) | OVH does not support shrinking a VPS in place; it requires a brand-new VPS plus a manual migration. The module refuses any target that is not in the VPS's `availableUpgrade` list. |
| **Immediate hard-termination needs a token** | OVH emails a confirmation token to the account holder for an instant `POST /terminate`. The automatic path (delete-at-expiration) needs no token; for immediate deletion, paste the token into *Confirm Termination* on the admin panel. |
| **Usage graphs are best-effort** | OVH deprecated `/use` and `/monitoring`; the module reads `/statistics`, whose exact contract varies by VPS generation. |
| **First order on a brand-new OVH account may 403** | OVH may require a manual anti-fraud review on the first order of a new account. Validate with one test order before going live. |
| **You can only sell what your OVH account can order now** | The module orders on *your* account, so stock and orderability mirror your account. It surfaces this honestly via stock control instead of failing at checkout. |

---

## 🗂️ How the code is organised

The module follows the standard WHMCS server-module layout. The WHMCS entry points are
thin wrappers; all logic lives in small single-responsibility classes under `lib/`
(PSR-4 namespace `OvhVps\`). The "pure" classes (`Term`, `Catalog::parse`,
`ConfigOptions::map`, `Upgrade::detectChange`) have no WHMCS/HTTP dependency and are
unit-tested offline.

```text
ovhvps/
├── ovhvps.php            # WHMCS module entry points (ovhvps_CreateAccount, _ClientArea, ...)
├── hooks.php             # WHMCS hooks: cancellation, cron, cart/admin asset injection
├── ajax.php              # Secure AJAX endpoint (CSRF + ownership) for panel actions
├── lib/
│   ├── bootstrap.php     # Composer autoload + PSR-4 fallback for OvhVps\
│   ├── Helper.php        # Config fields, OVH client factory, logging, params rebuild
│   ├── OvhClient.php     # Thin wrapper over the OVH SDK with normalised errors
│   ├── OvhApiException.php
│   ├── Provisioning.php  # The full order-cart → checkout → resolve serviceName flow
│   ├── Lifecycle.php     # suspend/unsuspend/terminate, delete-at-expiration, auto-renew
│   ├── Catalog.php       # Sync + read the OVH public VPS catalog into cache tables
│   ├── Availability.php  # OVH stock → WHMCS native stock control (hourly)
│   ├── ConfigOptions.php # Generate WHMCS configurable options + map selections to OVH
│   ├── Term.php          # WHMCS billing cycle → OVH (duration, pricingMode)
│   ├── Upgrade.php        # ChangePackage: add paid options to an existing VPS
│   ├── Actions.php       # Client+admin VPS actions (power, console, snapshots, ...)
│   ├── AdminActions.php  # Admin-only actions (sync, generate, retry, confirm, ...)
│   ├── Cron.php          # Background reconciliation of pending provisioning
│   └── Database.php      # Schema + data access for the module's mod_ovhvps_* tables
├── assets/js/            # Client panel, admin panel, product panel, cart stock script
├── templates/            # Smarty templates for the client area (overview, error)
├── lang/                 # Client-area strings (english.php, portuguese-pt.php)
├── tests/                # Offline unit tests (pure logic, no WHMCS/network)
└── vendor/               # Composer dependencies (OVH SDK). Not committed; see below.
```

> 💡 New to the code? Start at `ovhvps.php` (the WHMCS contract), then read
> `lib/Provisioning.php` (the order flow) and `lib/Lifecycle.php` (cancellation).
> Every class carries a header docblock explaining its responsibility.

---

## 📋 Requirements

- **WHMCS 9.0.x.** The WHMCS site must run on **PHP 8.2 or 8.3** - 9.0 does **not**
  support 8.4. (A newer PHP, e.g. Herd's 8.4, is fine for local dev/Composer, just not the live site.)
- **ionCube Loader ≥ 13.0.2** (14.4 recommended) - required by WHMCS itself.
- An **OVHcloud account** with a **default payment method** set (autoPay needs it).
- The **WHMCS cron** running (the module finishes async provisioning and refreshes stock
  on the `AfterCronJob` hook).

---

## 📦 Installation (cPanel, no SSH)

**You do not need SSH or to run Composer on the server.** The dependencies
(`vendor/`, the OVH SDK) ship inside the release package.

1. **cPanel → File Manager**, open your WHMCS folder → **`modules/servers/`**.
2. **Upload** the module zip (the one that includes the `vendor/` folder) and **Extract**
   it here. The result must be exactly `modules/servers/ovhvps/`, with `ovhvps.php` and the
   `vendor/` folder side by side (no `ovhvps/ovhvps` nesting).
3. Confirm that **`modules/servers/ovhvps/vendor/autoload.php`** exists.
4. Make sure the **WHMCS cron** is active (cPanel → Cron Jobs; if WHMCS already works, the
   cron is already there).

> ⚠️ The error **"OVH SDK not found. Run composer install"** means `vendor/autoload.php` is
> missing at that path (incomplete/corrupted zip, or wrong nesting) - **not** that you need
> to run Composer on the server. Re-upload the package with `vendor/` intact.

To rebuild `vendor/` on a dev machine, see [Useful commands](#-useful-commands).

> 🧩 **Cloning from GitHub?** This repository does **not** track `vendor/`. After cloning,
> run `composer install --no-dev` to fetch the OVH SDK before zipping/deploying.

---

## 🔑 OVH API credentials

Create a token at **https://api.ovh.com/createToken/** (or your region's `eu.` / `ca.` /
`us.` console) with these access rules:

```text
GET    /me*
GET    /vps*
POST   /vps*
PUT    /vps*
DELETE /vps*
GET    /order*
POST   /order*
GET    /services*
PUT    /services*
```

You receive three values: **Application Key**, **Application Secret**, and **Consumer Key**.

---

## 🖧 Configure the server in WHMCS

The API keys go on the **Server** configuration (not on the product):
`Configuration → System Settings → Servers → Add New Server`, module
**OVHcloud VPS**. WHMCS shows generic fields - the module reuses them like this:

| WHMCS field | What to put there |
|---|---|
| **Hostname** | the endpoint: `ovh-eu`, `ovh-ca` or `ovh-us` *(not a real IP/host)* |
| **Username** | Application Key |
| **Password** | Application Secret |
| **Access Hash** | Consumer Key |
| IP Address | *(leave empty)* |

Click **Test Connection** → it should report `Connected to OVH account <nichandle>`.
Then put this server in a **Server Group** and link your products to that group.

> If the Hostname is empty or invalid, the module defaults to `ovh-eu`.

---

## 🛠️ Create and configure a product

1. Create a product using the **OVHcloud VPS** module and link it to the
   **Server Group** that holds the server with the credentials.
2. On the **Module Settings** tab:

   | Field | Value / example |
   |---|---|
   | **OVH Subsidiary** | `PT` (must match the account country) |
   | **VPS Plan Code** | from the [plan table](#-plan-codes), e.g. `vps-2025-model1` |
   | **Billing Duration** | `P1M` *(fallback - see [Billing](#-billing-whmcs-cycle-to-ovh-commitment))* |
   | **Pricing Mode** | `default` *(fallback)* |
   | **Default OS** | e.g. `Debian 12` (fallback only, used if the customer does not pick one; use a plain free Linux image) |
   | **Default Datacenter** | e.g. `GRA` (**required** - the plan's only mandatory config) |
   | **Auto Delete On Terminate** | on (automatic cancellation at end of term) |
   | **Request Immediate Termination** | off (only enable for token-based instant termination) |

3. **Save** the product.
4. At the bottom of the product edit page a panel appears: **"OVH VPS - configurable
   options"** (the script relocates it into the *Module Settings* tab). Click **Generate
   OVH options** → it syncs the catalog and automatically creates the *Configurable
   Options* (Operating System, Datacenter, and extras) for this product. **You do not need
   to create a service first.**
5. New options are created at **price 0**: OS and Datacenter stay free (normal, except a
   Windows markup if you sell Windows); set the **price of the extras** (Backup, Snapshot,
   Disk, IP, Veeam) in the WHMCS native *Configurable Options* editor. Out-of-stock
   datacenters become unavailable automatically (see [Stock](#-stock--availability)).
6. Clicking **Generate OVH options** again **syncs** the options with the catalog:
   existing options **keep their prices**, new catalog entries are added at price 0, and
   options no longer offered (e.g. an image OVH retired, or the excluded cPanel/Plesk
   families) are removed.

> The same set of actions also exists on the admin **service** panel
> (*OVH VPS Management*): Sync Catalog, Generate Config Options, Check Stock, Retry
> Provisioning, Refresh Info, etc.

---

## 🧾 Plan codes

One product = one plan. Codes for the current range (subsidiary PT) - write into the
**VPS Plan Code** field:

| planCode            | Product |
|---------------------|---------|
| `vps-2025-model1`   | VPS-1   |
| `vps-2025-model2`   | VPS-2   |
| `vps-2025-model3`   | VPS-3   |
| `vps-2025-model4`   | VPS-4   |
| `vps-2025-model5`   | VPS-5   |
| `vps-2025-model6`   | VPS-6   |

**Do not use** the `-degressivity*` / `-10percent` variants or older ranges
(`vps-value-*`, `vps-essential-*`, ...). To inspect your account's catalog (public, no
auth required):

```text
GET https://eu.api.ovh.com/1.0/order/catalog/public/vps?ovhSubsidiary=PT
```

---

## 💰 Billing: WHMCS cycle to OVH commitment

The module orders on OVH the **term that matches the billing cycle the customer chose** -
capturing the best discount **without ever committing you on OVH for longer than the
customer paid you for**.

| WHMCS cycle    | OVH order          | Commitment |
|----------------|--------------------|------------|
| Monthly        | `P1M` / `default`   | none |
| Quarterly      | `P1M` / `default`   | none* |
| Semi-Annually  | `P6M` / `upfront6`  | 6 months |
| Annually       | `P12M` / `upfront12`| 12 months |
| Biennially     | `P12M` / `upfront12`| 12 months → re-commits in year 2 |
| Triennially    | `P12M` / `upfront12`| 12 months → re-commits in years 2 and 3 |

\* OVH has no 3-month mode; quarterly falls back to monthly so you never commit longer
than the customer paid.

- Multi-year works because the OVH commitment **re-commits itself** every 12 months
  (`REACTIVATE_ENGAGEMENT`), always at the best price. The module sets
  `renew.automatic = true` after the order so this happens.
- **Requirement:** a synced catalog (click *Generate OVH options* or *Sync Catalog*).
- **Fallback:** without a synced catalog, or for an unknown cycle, it uses the product's
  **Billing Duration** / **Pricing Mode** fields (so the safe default is `P1M` / `default`).
- The price you charge the customer is set on the product's **Pricing** tab (monthly,
  annual, ...) - the module only handles the term ordered on OVH.

---

## 📦 Stock / availability

Because the module orders on **your** OVH account, you can only sell what the account can
order right now. The module keeps WHMCS in sync:

- **Plan level:** the cron checks `GET /vps/order/rule/datacenter` per product (hourly) and
  drives **WHMCS native stock control**: out of stock → `qty=0` (shows *Out of Stock*, the
  product stays visible); back in stock → `qty=999`.
- **Datacenter level:** out-of-stock datacenters are marked on the *Datacenter* option and
  `assets/js/ovhvps.stock.js` (injected on the cart) **disables them** - they stay
  **visible but not selectable**.
- **Provisioning guards:** it never orders a plan *or* a datacenter marked unavailable
  (on top of the checkout dry-run that prevents charging blindly).
- Force a check from the admin service panel → **Check Stock**.

> The module **manages** stock for ovhvps products - do not set the quantity by hand.

💡 Tip: sell the tiers the account can order today; list the rest as *"contact us"* products
(without this module).

---

## ♻️ How it works (lifecycle)

- **Order** (`CreateAccount`): `POST /order/cart` → assign → add `/vps` → read
  `requiredConfiguration` → set config (one label per call) → add options → `GET checkout`
  (dry-run, records your cost) → `POST checkout` with `autoPayWithPreferredPaymentMethod`.
  The `serviceName` is resolved by polling `/vps`; if OVH is slow, the cron finishes it.
- **Suspend / unsuspend**: `stop` / `start`.
- **Cancellation** (`TerminateAccount` + the `CancellationRequest` hook): schedules deletion
  at end of term via `renew.deleteAtExpiration` (no token) and stops the VPS. Automatic for
  both customer cancellations and admin terminations.
- **Auto-renew**: guaranteed after delivery (synchronous path and via cron) so multi-year
  terms survive past the first engagement.
- **Upgrades** (`ChangePackage`): when the customer buys an extra (backup, snapshot,
  additional disk, IP, Veeam) mid-term, the module orders it on the existing VPS
  (`cartServiceOption`); it also upgrades the VPS to a bigger model in place via OVH
  `order/upgrade`. Both are *add-only*, auto-paid, and gated (orderability/`availableUpgrade`
  + dry-run); the model resize reboots the VPS and the cron refreshes the model afterwards.
  Removals and downgrades are refused with a message.
- **Client area**: power, VNC console, OS reinstall, snapshots, backups, Veeam, disks,
  IPs + reverse DNS, secondary DNS - the full panel for every service, plus an **n8n**
  tab shown automatically (in addition to the normal tabs) when the installed OS is an
  n8n image.
- **Admin service panel**: every client action + sync catalog, generate options, retry
  provisioning, set `serviceName`, confirm termination, toggle delete-at-expiration, and
  cost/margin info.

---

## 🤖 OS images (Docker, n8n, Windows)

OVH delivers applications like n8n and Docker as **"distribution + application" OS images**,
not as separate plans - they show up as OS values in the catalog (e.g. `Debian 12 - n8n`).
There is **one product and one sale path**: after *Generate OVH options* the **Operating
System** dropdown lists the plan's catalog images and the customer picks one. Because the
choice comes from the OS option, the module attaches the matching OVH license addon (free
Linux, paid Windows) automatically, so the cart is always complete and the license can never
be mismatched.

Image policy, enforced server-side at generation AND at reinstall:

| Family | Sold? | Reinstall? |
|---|---|---|
| Plain Linux distros (Debian, Ubuntu, ...) | ✅ free | ✅ any service |
| App images (**Docker**, **n8n**) | ✅ free | ✅ any service (in and out) |
| **Windows** | ✅ paid OVH license attached; set your markup as the price of the Windows sub-options | 🔒 only a service ordered with Windows |
| **cPanel / Plesk** | ❌ never (their licenses would be billed to you) | ❌ never |

**n8n is just an image.** The module decides a service is n8n purely from its **installed OS
name containing "n8n"** (refreshed on every reinstall) - there is no separate n8n product or
flag. Every service gets the **full client panel** (Console, Reinstall OS, Network, root
access, password change, ...); when the installed image is n8n, an extra **n8n** tab appears
with the editor URL (port 5678 by default). Reinstall to a plain distro and the tab
disappears; reinstall to n8n and it comes back.

**Access emails.** Every delivered or reinstalled VPS goes through the SSH access bootstrap
and receives the **"OVH VPS Access Ready"** email (root user + password). When the installed
image is n8n, the customer additionally receives **"OVH n8n Access Ready"** with the editor
URL (the owner account is created on the first browser visit; reinstalling resets it).

### Upgrading from the split VPS/VPS-n8n model

Earlier versions sold n8n as a separate trimmed-down product. To migrate a deployed WHMCS:

1. Upload/extract the new module zip over `modules/servers/ovhvps/` (plain overwrite).
2. Delete the dedicated n8n product(s) and their generated *Configurable Options* group.
3. On each remaining VPS product, click **Generate OVH options** once - the sync adds the
   n8n/Docker images, removes cPanel/Plesk, and **keeps your configured prices**.
4. Old n8n test services sit at `access_state = 'web'` (no root access). Either terminate
   them, or force the bootstrap with
   `UPDATE mod_ovhvps_servers SET access_state = 'none' WHERE access_state = 'web';`
   (**destructive**: the bootstrap rebuilds the VPS).
5. Email templates update automatically on the next request (template revision bump).

---

## 🧰 Useful commands

**On the server (cPanel):** you do not run shell commands. The only "command" is the
**WHMCS cron**, configured in *cPanel → Cron Jobs* (you should already have it for WHMCS).
To force it by hand (if you have access): `php -q <whmcs>/crons/cron.php`.

**On the development machine (PHP/Composer):**

```bash
# Offline unit tests (pure logic, no WHMCS/network):
php -n tests/term_test.php          # expects "ALL TESTS PASSED"
php -n tests/upgrade_test.php

# Lint a single file:
php -n -l lib/Term.php

# Rebuild dependencies (generates the vendor/ folder for packaging):
composer install --no-dev
```

> **Herd note (Windows):** Herd's PHP 8.4 has a broken `auto_prepend_file` and missing
> extension warnings. Therefore:
> - tests/lint use **`php -n`** (ignores php.ini).
> - for Composer, use: `php -d auto_prepend_file='' <path>/composer.phar install --no-dev`.

After rebuilding `vendor/`, package the `ovhvps/` folder **with** `vendor/` and follow the
[Installation](#-installation-cpanel-no-ssh) steps.

---

## 🧪 Testing

- **Offline unit tests** cover the pure logic (billing-term mapping, option diffing). Run
  `php -n tests/term_test.php` and `php -n tests/upgrade_test.php` (expect `ALL TESTS PASSED`).
- **Live validation:** the WHMCS/OVH paths can only be fully confirmed against a real
  account. Place one real order with the cheapest plan you can order, then watch
  *Utilities -> Logs -> Module Log* (filter `ovhvps`) to confirm order acceptance,
  `serviceName` resolution, the client-area actions, and automatic cancellation.

---

## 🌍 Localization note

The client area is translatable via `lang/` (`english.php`, `portuguese-pt.php`); WHMCS
loads the file matching the client's language and falls back to English.

Some storefront strings are **not** in the language files, because they are baked into the
generated Configurable Options (whose option names are static text, not run through `$LANG`).
The out-of-stock marker appended to datacenter options defaults to Portuguese
(`" - Fora de Stock"`). If you want a different language, change it in two places (they must
match):

- `lib/ConfigOptions.php` → `OOS_MARKER`
- `assets/js/ovhvps.stock.js` → `MARKER`

The Datacenter options also show friendly **"City, Country"** names in English (e.g.
`Warsaw, Poland` instead of `WAW`); the OVH code is still what gets ordered, only the visible
label changes. Edit or extend the mapping in `lib/Datacenters.php` → `NAMES` (an unmapped code
falls back to its raw code). Re-run "Generate OVH options" after changing it.

---

## 🔒 Security notes

- **No secrets in the repo.** OVH credentials live in the WHMCS Server configuration
  (encrypted by WHMCS) and are read at runtime - never hard-coded.
- **AJAX endpoint** (`ajax.php`) bootstraps WHMCS, enforces a per-session CSRF token, and
  verifies the logged-in client owns the service (admins bypass ownership). Admin-only
  actions require an authenticated admin.
- **Idempotent ordering** prevents double charges: a service that already has an OVH order
  is never re-ordered, and a dry-run runs before every real checkout.
- Output rendered in admin panels is HTML-escaped.

---

## 🗺️ Roadmap

- **Auto-wiring of WHMCS "Package Upgrades"**: option and in-place model upgrades
  (`ChangePackage`) are built and gated (orderability/`availableUpgrade` + dry-run); what
  remains is auto-configuring the product's *Package Upgrades* from the catalog (it writes
  WHMCS core tables, so it needs a WHMCS schema check first). For now, enable those manually.

---

## ⚖️ Disclaimer & trademarks

This is an **independent, unofficial** module. It is **not affiliated with, endorsed by, or
sponsored by OVHcloud or WHMCS**. "OVHcloud", "WHMCS", and "n8n" are trademarks of their
respective owners and are used here only to describe interoperability.

The module orders real, paid services on **your own** OVH account. You are responsible for
your OVH costs, your customer pricing, and compliance with the OVH and WHMCS terms of
service. The software is provided "as is", without warranty of any kind.

---

## 📄 License

**Source-available, no-resale.** See [`LICENSE`](LICENSE) for the exact terms.

- ✅ You may **use, self-host, and modify** this module, including to run your own
  hosting/reseller business.
- ❌ You may **not sell, resell, sublicense, or redistribute** the module (or a modified
  version) as a paid product, service, or download.

Commercial distribution rights are reserved by the copyright holder.

> This is **not** an OSI-approved open-source license. If you need airtight commercial
> protection, have it reviewed by a lawyer.
