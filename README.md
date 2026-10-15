# Chada AIO - Backup, Migrate and Optimize

[![Version 1.0.0](https://img.shields.io/badge/version-1.0.0-0f766e.svg)](https://github.com/markwebdev86/chada-aio/releases)
[![License GPLv2 or later](https://img.shields.io/badge/license-GPLv2%20or%20later-2563eb.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

Chada AIO is a standalone GPL-licensed WordPress plugin for local backup, migration, image optimization, and database cleanup on single-site installations. It keeps processing on the WordPress server and provides reviewable, resumable workflows from the administrator area.

The current release is `1.0.0`. The packaged artifact is generated as `dist/chada-aio-1.0.0.zip`; published artifacts are listed on [GitHub Releases](https://github.com/markwebdev86/chada-aio/releases). The [WordPress.org listing](https://wordpress.org/plugins/chada-aio/) is the canonical directory location when the plugin is published.

This repository contains the Free release and all of its advertised workflows. Component-specific backup, migration, restore, and content-mapping implementations are supplied only by the optional, separate Pro add-on and are excluded from this package.

## Requirements

- WordPress 6.6 or later.
- PHP 7.4 or later.
- MySQL or MariaDB.
- PHP ZipArchive support for archive creation and import.
- A writable private storage directory outside the public document root.
- A single-site installation. Multisite is not supported by this release.

## Privacy and safety

All backup, migration, optimization, cleanup, and recovery processing runs on the WordPress server. Chada AIO does not require telemetry, cloud storage, external runtime assets, or a licensing service. Archives can contain personal and sensitive site data, so protect private storage and downloaded copies.

The plugin creates a protected recovery path before supported destructive operations. Restore and migration previews identify the affected scope before confirmation. Migration uploads use durable, site-scoped checkpoints and stale sessions are cleaned up automatically; destination-only files are preserved during supported restores, and interrupted jobs can resume when the operation state is still valid.

Archives and job journals are stored outside the public document root when the server permits it. Review filesystem permissions, available disk space, database compatibility, and recovery results before relying on an archive for production recovery.

Only active worker batches request a PHP `max_execution_time` target of 600 seconds when the host permits runtime changes. Activation, administrator screens, and status requests do not change PHP execution limits. Resumable workers still stop after bounded five-second windows. Web-server, proxy, FastCGI, and input-parsing timeouts remain hosting configuration.

## Features

- **Backup All:** Create a verified local recovery archive covering the supported WordPress database, uploads, active plugin roots, active and parent themes, must-use plugins, and present runtime drop-ins.
- **Migrate All:** Create a package for review and application on another single-site installation, with supported URL, table-prefix, content-ID, and media-path mapping.
- **Image optimization:** Process selected JPEG, PNG, and WebP attachments while preserving formats, metadata, URLs, and private originals for undo. New installations default to quality `80` and `1600×1200` pixels. Settings provides standard `3840×2880`, `2560×1920`, `1920×1440`, `1600×1200`, `1366×1024`, and `1024×768` resolution presets plus Custom dimensions; Custom width is validated from `64` to `7680` pixels and height from `64` to `15360` pixels.
- **Database cleanup:** Preview old revisions, trash, spam or trashed comments, and expired transients before cleanup. Unknown tables remain inspection-only.
- **Resumable jobs:** Continue safe interrupted work and view operation-specific progress from 0% through completion. Upload retries reconcile the server checkpoint before sending a chunk again.
- **Migration preflight:** Review disk space, provider readiness, source/destination runtime differences, existing paths, package scope, and hard blockers before applying an import. Warnings require explicit acknowledgement.
- **Verified import recovery:** Migrate All uses targeted recovery and final checks validate files, database swaps, destination identity, caches, and rewrite rules. Pro component providers are maintained separately in the optional add-on.
- **Immediate action feedback:** Backup, migration, optimization, history, upload, restore, detail, and delete buttons show a rotating inline loader while their request or job is active. Buttons that open a popup clear their loader when the confirmation or details appear.
- **History and recovery:** Review backup, migration, and optimization activity with protected recovery records and completed-job details.
- **Runtime headroom:** Request PHP execution-time headroom only inside active processing batches, while preserving bounded resumable work windows.
- **Setup Wizard:** Configure private storage, processing defaults, recovery behavior, and privacy settings during initial setup or later review.
- **Accessible administration:** Use separate Dashboard, Backup, Migration, Optimization, Settings, Features, and Instructions screens with progressive disclosure and keyboard-accessible guidance.
- **Security boundaries:** Validate administrator REST inputs before actions or settings changes, escape rendered values for their output context, and encode inline JSON safely for script embedding. REST errors remain JSON and the admin interface renders returned text without interpreting it as HTML.

## Installation

### WordPress dashboard

1. Open **Plugins > Add New** in WordPress.
2. Search for **Chada AIO**, or select **Upload Plugin** when installing a downloaded ZIP.
3. Install and activate the plugin.
4. Open **Chada AIO > Settings** and complete the Setup Wizard.
5. Confirm private storage readiness, create a backup, and test recovery on an isolated site.

The runtime diagnostic reports the current PHP execution-time state. Limits are requested only inside active worker batches; the plugin does not rewrite `php.ini`, `.user.ini`, `.htaccess`, or web-server settings.

### GitHub release artifact

1. Download the matching `chada-aio-<version>.zip` file from [GitHub Releases](https://github.com/markwebdev86/chada-aio/releases).
2. In WordPress, open **Plugins > Add New > Upload Plugin** and select the ZIP.
3. Activate **Chada AIO** and complete the Setup Wizard.

Manual installation is also supported by extracting the ZIP into `wp-content/plugins/chada-aio/` and activating **Chada AIO** from the Plugins screen.

## Usage

Chada AIO does not register front-end shortcodes or blocks. Use the administrator screens:

1. Open **Chada AIO > Settings** and complete the Setup Wizard.
2. Use **Backup** to review the Backup All scope and start a protected local backup.
3. Use **Migration** to create a Migrate All package on the source site, then upload, review preflight checks, acknowledge warnings when applicable, and confirm it on the destination site. Pro component packages appear only when the separate add-on is installed. If the browser or network interrupts an upload, select the same file to resume its durable checkpoint.
4. Use **Optimization** to process images or preview database cleanup. Image originals remain available for undo until deliberately removed.
5. Use **Instructions** for recovery, migration, cleanup, troubleshooting, and walkthrough guidance.

Action buttons show a rotating inline process loader immediately after a valid action is triggered. The loader rotates continuously, including when reduced motion is enabled, while the button's request or bounded job is running. When a confirmation, preview, or details popup opens, the originating button's loader hides immediately. Confirming an action starts a separate loader on the popup's confirmation button until the action succeeds or fails. Loaders also clear on errors or cancellation.

To select a private storage directory in `wp-config.php`, define the optional constant with a server path outside the public document root:

```php
define('CHADA_AIO_STORAGE_DIR', '/path/outside/document-root/chada-aio');
```

When the constant is not defined, configure the storage path in **Settings > Storage & Retention**. The blank field displays the current default as a non-submitted placeholder, and the field also shows the host filesystem root, a safe private sibling-path example, and the blank-value fallback. Do not place archives in a publicly accessible directory.

### Runtime limits

Chada AIO requests `max_execution_time` headroom only after an active processing batch begins. Activation, administrator screens, and status checks leave the current PHP limit unchanged. The existing five-second worker slices remain in place for resumability and recovery safety. `max_input_time`, socket timeouts, browser timeouts, and web-server or proxy timeouts must be configured by the hosting provider when required.

### Directory resolution and private storage

Version 1.0.0 resolves local installation, content, and plugin roots through WordPress's direct filesystem API, themes through their registered theme roots, and media through `wp_upload_dir()`. Backup, migration, restore, rollback, and image undo honor relocated directories while retaining the existing logical archive paths. The directory resolver leaves WordPress's global filesystem transport unchanged and rejects non-local paths, traversal, and symlinked destinations.

Private archives remain outside publicly served WordPress, content, uploads, and server document directories. Existing storage settings and the `CHADA_AIO_STORAGE_DIR` override are preserved; the default sibling-directory name is unchanged. New private directories use `0700`, intended content directories use `0755`, and existing directory permissions are preserved. No database schema or archive-format migration is required. Direct-access guards and loading WordPress core helpers still use `ABSPATH`; the configured must-use root is resolved through the filesystem API because WordPress has no dedicated accessor for it.

For a subdirectory installation without `DOCUMENT_ROOT`, the resolver derives the public parent from the site URL and installation path. If an aliased layout prevents that validation, configure the server's `DOCUMENT_ROOT` before processing private data. A sibling default under a detected public parent is rejected; choose an explicit storage directory outside that boundary.

Migrate All preserves the destination's uploads directory and URL settings, resolves imported active themes against destination roots, and maps serialized content/uploads URLs using the source package metadata. Older packages without the optional URL metadata remain supported. Local uploads destinations may be resolved before their first directory is created; only active file operations create the validated destination.

## Project reference values

| Value | Active setting | Purpose |
| --- | --- | --- |
| Plugin version | `1.0.0` | Public release version in the plugin header and package name. |
| Minimum WordPress | `6.6` | Minimum supported WordPress version. |
| Minimum PHP | `7.4` | Minimum supported PHP version. |
| Plugin slug and folder | `chada-aio` | WordPress plugin directory, package root, and public identifier. |
| Text domain | `chada-aio` | Translation domain for plugin strings. |
| Admin prefix | `chada-aio-` | Prefix for Dashboard, Backup, Migration, Optimization, Settings, Features, and Instructions pages. |
| REST namespace | `chada-aio/v1` | Administrator-only REST routes used by the admin workflows. |
| Database prefix | `chada_aio_` | Active option, metadata, and operational storage namespace. |
| Database schema | `0.0.4` | Active Free-plugin storage schema marker. |
| Storage override | `CHADA_AIO_STORAGE_DIR` | Optional private filesystem location defined in `wp-config.php`. |
| PHP execution target | `600 seconds` | Requested only during active worker processing batches when runtime changes are allowed. |

## Database table structure

The Free plugin creates two operational tables beneath the site’s normal `$wpdb->prefix`:

- `{$wpdb->prefix}chada_aio_records` stores verified backup, migration, optimization, and cleanup history metadata.
- `{$wpdb->prefix}chada_aio_jobs` stores resumable job ownership, status, and update timestamps.

Options and WordPress post or user metadata use the active `chada_aio_` namespace. Private archive, upload, original, recovery, and job-journal files remain in the configured storage directory. See the [complete active database and storage reference](https://github.com/markwebdev86/chada-aio/blob/master/docs/db-schema.md), including its [Entity Relationship Diagram](https://github.com/markwebdev86/chada-aio/blob/master/docs/db-schema.md#entity-relationship-diagram) with editable Mermaid source and a synchronized [SVG fallback](https://github.com/markwebdev86/chada-aio/blob/master/docs/images/database-entity-relationship-diagram.svg).

## Screenshots

![Chada AIO repository preview](https://raw.githubusercontent.com/markwebdev86/chada-aio/master/docs/images/github-repo-social-preview.png)

The repository preview is the currently published public image asset.

## Frequently asked questions

### Can I repeat backup, migration, or image operations?

Yes. Review the selected scope and recovery guidance before each operation. Jobs that stop safely can be resumed from the operation panel, and failed jobs that have not changed live data expose a safe retry.

### Where is data processed?

On your WordPress server. Chada AIO does not send site content, archives, or telemetry to a cloud service.

### Does Chada AIO change PHP or web-server timeouts?

Only active processing batches request a 600-second PHP `max_execution_time` target when the host allows runtime changes. Activation, administrator screens, and status requests do not change PHP limits. Chada AIO does not write server configuration; web-server, proxy, FastCGI, input-parsing, and browser timeouts remain host-managed.

### What does Backup All include?

It includes the supported WordPress database tables, uploads, active plugin roots, active and parent themes, must-use plugins, and present runtime drop-ins. Inactive plugins and themes, arbitrary custom content directories, core files, `wp-config.php`, server configuration, caches, logs, and development material are excluded.

### Where are component backup and migration providers implemented?

Component-specific backup, migration, restore, and content mapping are supplied by the separate Pro add-on. Those implementations are not included in the Free package distributed through WordPress.org.

### How is migration different from backup?

Backup creates a protected recovery archive for the current site. Migration creates a package intended for review and application on another site, including supported URL, table-prefix, content-ID, and media-path mapping. Its import flow seals resumable uploads, runs structured preflight checks, creates targeted recovery, streams verified files, and performs final destination verification.

### What happens when the plugin is uninstalled?

Plugin settings and operational indexes follow the removal choice in **Settings > Data & Privacy**. Private archives, job journals, recovery data, and retained image originals require deliberate server-side cleanup and should be reviewed before removal.

## Contributing

Open a focused issue for bugs or documentation corrections through [GitHub Issues](https://github.com/markwebdev86/chada-aio/issues). Pull requests should explain the user-visible behavior, preserve WordPress and PHP compatibility, include relevant tests, and avoid adding credentials, site archives, private paths, or generated development artifacts.

## Administration

The plugin adds a **Chada AIO** administrator menu with Dashboard, Backup, Migration, Optimization, Settings, Features, and Instructions pages. Administrators need the WordPress `manage_options` capability. All REST operations are protected by the WordPress REST nonce and the same administrator permission boundary.

The Settings page separates General, Storage & Retention, Backup & Restore, Optimization, and Data & Privacy controls. Required values are marked with an inline asterisk and screen-reader text, then validated in the browser and on the server; intentional optional defaults such as blank private storage and zero retention remain supported. The Private storage path field displays the current default as a non-submitted placeholder, explains the detected host filesystem root, provides a private sibling-path example, and explains the blank fallback. Optimization offers predefined resolutions that populate read-only width and height fields, while Custom enables validated dimensions. Cleanup age is validated from `1` to `1095` days. Each tab saves independently, rejected saves remain visible with the server-provided reason, and the Dashboard links directly to settings that need attention. The Setup Wizard can be reopened whenever storage or processing settings need review and cannot be completed until required settings and runtime readiness are valid. Existing saved values are preserved across upgrades; values outside the current limits are reported for correction rather than silently rewritten.

## Free release

This package is a complete standalone Free release under the GPLv2-or-later license. Backup All, Migrate All, selected-image optimization, database cleanup, recovery, history, progress reporting, and the Setup Wizard are included in this package. No Free workflow is disabled by a payment requirement.

## Extension contracts

Compatible companion add-ons can extend behavior through the following hooks:

| Hook | Contract |
| --- | --- |
| `chada_aio_register_providers` | Register compatible backup, migration, import, or optimization providers. |
| `chada_aio_template_path` | Override a validated template path for an integrated administration extension. |
| `chada_aio_record_written` | Observe a completed record after it is written to the catalog. |
| `chada_aio_managed_record` | Mark a completed record as managed by an integrated provider. |
| `chada_aio_settings_panels` | Add an integrated settings panel to the shared Settings screen. |

The separate Pro companion is an optional paid/proprietary extension contract, not a requirement for the Free package. Chada AIO has no required SaaS integration or licensing service.

## Release verification

From the repository root, validate the source and build the package with the allowlisted script:

```powershell
composer validate --strict
composer test
composer lint
composer analyse
powershell -ExecutionPolicy Bypass -File scripts/build.ps1
powershell -ExecutionPolicy Bypass -File scripts/release-audit.ps1
```

The check command runs PHPCS, PHPStan, and the fast general and migration-import contracts; PHPStan is configured with a 2 GiB analysis limit for the complete codebase. The migration UI also exposes a Retry safely action for failed jobs that have not touched live data.

`composer test` also runs independent directory-resolution and storage-boundary tests. Run `vendor/bin/phpunit` for the PHPUnit contracts and the guarded WordPress integration suite in an independent test database for workflow verification. The 1.0.0 release updates configured-directory handling and file-target checks while retaining private recovery and existing package formats.

The build always takes the root `README.md` and writes it to `dist/chada-aio/README.md`, removing stale unversioned archives first. Development notes, schema references, and private project conditions belong under `docs/` and are excluded from the runtime package. The audit checks metadata, public content, privacy markers, active identifiers, README synchronization, archive roots, forward-slash paths, and exclusion of development files. Generated `dist/` files are local release artifacts and are not source-controlled.

## Support boundaries

Report reproducible issues through [GitHub Issues](https://github.com/markwebdev86/chada-aio/issues). When the directory listing is available, use the [WordPress.org support forum](https://wordpress.org/support/plugin/chada-aio/). Include WordPress and PHP versions, sanitized logs, and a description of the affected workflow; never attach credentials, private archives, or sensitive site data.

Chada AIO supports single-site MySQL/MariaDB installations meeting the requirements above. Filesystem permissions, available disk space, third-party database schemas, administrator access after a database import, and recovery testing remain the site administrator’s responsibility.

## License

Chada AIO is distributed under the [GNU General Public License, version 2 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

Author: [markwebdev86](https://github.com/markwebdev86)
