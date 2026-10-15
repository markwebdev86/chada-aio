=== Chada AIO - Backup, Migrate and Optimize ===
Contributors: markwebdev86
Tags: backup, migration, optimization, database, images
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resumable WordPress backups and migrations, image optimization, and database cleanup with protected local recovery.

== Description ==

Chada AIO is a standalone GPL-licensed plugin providing resumable workflows for backups, migrations, image optimization, and database cleanup.

Backup All creates a verified local recovery archive for the current site. Migrate All creates a package for review and application on another single-site installation. Both workflows cover supported WordPress database tables, uploads, active plugin roots, active and parent themes, must-use plugins, and present runtime drop-ins. Component-specific backup and migration providers are supplied only by the separate Pro add-on and are not included in this plugin package.

The plugin preserves destination-only files during supported restores, maps supported site URLs and content references during migration, and provides recovery guidance before confirmation. Migration uploads use durable checkpoints, preflight warnings require acknowledgement, interrupted jobs can resume when their operation state remains valid, and completed jobs report their processing results.

Image optimization processes supported JPEG, PNG, and WebP attachments while preserving formats, metadata, URLs, and private originals for undo. Database cleanup provides previews before removing supported revisions, comments, and expired transients; unknown tables remain inspection-only.

New installations use image quality 80 and a default resolution of 1600×1200 pixels. Optimization Settings provide standard 3840×2880, 2560×1920, 1920×1440, 1600×1200, 1366×1024, and 1024×768 resolution presets plus Custom dimensions. Presets populate read-only width and height fields; Custom enables browser and server validation for administrator-entered dimensions. Custom width accepts 64–7680 pixels, height accepts 64–15360 pixels, and cleanup age accepts 1–1095 days. Existing saved values are preserved across upgrades; invalid existing values are reported until corrected rather than silently rewritten.

== Requirements ==

Chada AIO requires WordPress 6.6 or later, PHP 7.4 or later, MySQL or MariaDB, PHP ZipArchive support, a writable private storage directory, and a single-site installation. Multisite and third-party archive formats are outside the supported scope.

== Privacy and safety ==

All processing runs on the WordPress server. Chada AIO sends no site data, archives, telemetry, or licensing requests off-site. Inputs and saved settings are validated, rendered output is context-escaped, and inline JSON uses script-safe encoding.

Archives, job journals, recovery data, and image originals can contain personal or sensitive site information. Protect the configured storage directory and downloaded archives, review filesystem permissions and available disk space, and test recovery on an isolated site before relying on an archive in production.

Only active backup, restore, migration, image-optimization, and cleanup worker batches request a PHP `max_execution_time` target of 600 seconds when the host permits it. Administration screens, activation, and status requests do not change PHP execution limits. Resumable workers still use bounded five-second windows; web-server, proxy, FastCGI, input-parsing, and browser timeouts remain hosting configuration.

== Features ==

* Backup All creates a verified local recovery archive.
* Migrate All creates a reviewable package for another single-site installation.
* The separate Pro add-on supplies component-level backup and migration providers; their implementations are excluded from the WordPress.org package.
* Migration imports retry interrupted uploads, run preflight safety checks, and verify the destination after applying changes.
* Image optimization preserves supported image formats, metadata, URLs, and originals for undo.
* Database cleanup previews supported revisions, comments, and expired transients before cleanup.
* Resumable jobs report operation state and progress from start through completion.
* History and recovery records help review completed operations and restore preparation.
* Active worker batches request PHP execution headroom only while processing work.
* Setup Wizard guides storage, retention, recovery, and privacy configuration.
* Separate administrator pages provide Dashboard, Backup, Migration, Optimization, Settings, Features, and Instructions workflows.

== Installation ==

1. Download the stable ZIP from the WordPress.org listing or the project release artifacts.
2. In WordPress, open **Plugins > Add New > Upload Plugin**.
3. Select the Chada AIO ZIP, choose **Install Now**, and activate the plugin.
4. Open **Chada AIO > Settings** and complete the Setup Wizard.
5. Confirm private storage readiness, create a backup, and test recovery on an isolated site.

Manual installation is also supported by extracting the ZIP into `wp-content/plugins/chada-aio/` and activating **Chada AIO** from the Plugins screen.

== Usage ==

Use the administrator menu to select a workflow, review its scope and recovery guidance, and confirm it. Backup creates a protected archive. Migration supports resumable uploads, preflight checks, targeted recovery, and destination verification. Optimization processes images or previews supported database cleanup. Use **Instructions** for recovery and troubleshooting.

Chada AIO does not register front-end shortcodes or blocks. Required values are marked with an inline asterisk and screen-reader text, then validated before saving or starting an operation. When no path is saved, Private storage displays the default as a non-submitted placeholder, plus the host root, a private sibling example, and the blank fallback. The Dashboard reports invalid settings with a direct link to the affected Settings tab. The Setup Wizard can be reopened from Settings whenever storage, retention, recovery, or privacy settings need review.

== Frequently Asked Questions ==

= How is Backup All different from Migrate All? =

Backup All creates a protected recovery archive for the current site. Migrate All creates a package intended for review and application on another site, including supported URL, table-prefix, content-ID, and media-path mapping.

= Can I repeat an operation? =

Yes. Backup, migration, image, and cleanup operations can be repeated as needed. Failed jobs that have not changed live data can be retried safely, and interrupted package uploads can resume from their last durable checkpoint.

= Where is data processed? =

On your WordPress server. Chada AIO does not send site content, archives, or telemetry to a cloud service.

= Does Chada AIO change PHP or web-server timeouts? =

Only active processing batches request a 600-second PHP `max_execution_time` target when runtime changes are allowed. Activation, administrator screens, and status requests leave the current limit unchanged. Chada AIO does not write `php.ini`, `.user.ini`, `.htaccess`, or web-server settings.

= Where are archives stored? =

Archives and job journals are stored in the private directory selected in Settings or defined with `CHADA_AIO_STORAGE_DIR`. Do not place archives in a publicly accessible directory.

= What happens when the plugin is uninstalled? =

Settings and operational indexes follow the removal choice in **Settings > Data & Privacy**. Private archives, job journals, recovery data, and retained image originals require deliberate server-side cleanup.

== Screenshots ==

1. The Backup and Migration screens show operation scope, recovery guidance, confirmation controls, and job progress.
2. The Setup Wizard and Settings screens guide private storage, retention, cleanup, recovery, and privacy configuration.

== Changelog ==

= 1.0.0 =
* Resolve relocated content, plugin, theme and uploads directories through WordPress APIs.
* Preserve private archives and validate storage boundaries, filesystem targets and directory permissions.
* Preserve destination uploads settings and remap URLs during migration.


= 0.6.2 =

* Moved component-specific backup, migration, restore, and content-mapping implementations into the separate Pro add-on.
* Limited PHP execution-time changes to active worker processing windows.
* Resolved image attachments and derivatives under the configured local WordPress uploads directory, including custom paths and undo.
* Corrected the WordPress.org short description and synchronized Free and Pro package requirements.

= 0.6.1 =

* Hardened REST validation and inline JSON encoding.
* Updated optimization defaults, image bounds, and cleanup validation while preserving existing saved administrator values.
* Added accessible resolution presets, Custom width/height validation, and shared Settings/Wizard guidance.
* Added OS-aware Private storage path guidance with a safe sibling-path example and blank-value fallback explanation.
* Coordinated the Free and Pro release metadata and distribution checks for 0.6.1.

= 0.6.0 =

* Previous 0.6.0 coordinated release.

== Upgrade Notice ==

= 1.0.0 =
Directory resolution and private-storage safety updates. Existing archives and database schemas remain compatible.

== Support ==

WordPress.org Support Forum: https://wordpress.org/support/plugin/chada-aio/

GitHub Issues: https://github.com/markwebdev86/chada-aio/issues

When reporting an issue, include WordPress and PHP versions, the affected workflow, and sanitized error details. Do not include credentials, private archives, database dumps, or sensitive site data.

== License ==

Chada AIO is distributed under the GNU General Public License, version 2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
