=== PrymeOperator – File Manager, Media Folders & Backups ===
Contributors: prymeoperator
Tags: file manager, media folders, backup, media library, organize
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A safe file manager, media and post folders, and site backups in one plugin. Deleting is undoable, edits are versioned and everything is logged.

== Description ==

PrymeOperator brings a server file manager, a folder organiser for the Media Library and your posts, and site backups into one plugin, built around one idea: **nothing you do should be impossible to undo.**

= File manager =

* Folder tree, list and grid views, breadcrumbs, sorting, multiple selection, context menu and keyboard shortcuts.
* Upload by drag and drop, in pieces, so large files work without touching `php.ini`.
* Create, rename, duplicate, copy, move, ZIP and unzip, download (folders as ZIP), visual permissions (chmod) and properties.
* Search by name and inside text files. Preview images, PDF, video and audio.
* Code editor (the CodeMirror bundled with WordPress) with highlighting for PHP, JS, CSS, HTML, SQL and Markdown.

= Safety net =

* **Real trash:** deleting never destroys. Undo right away, or restore later from the Trash tab.
* **Versions** of every file edited, which you can bring back from the editor.
* **PHP syntax check** before saving, and **automatic rollback** if a PHP change breaks the site.
* **Activity log** of who did what, where and when.

= Library folders =

* A folder sidebar on Media (grid and list), Pages, Posts and any other post type you enable.
* Drag rows or thumbnails onto a folder; several at once. Nest folders by dragging them.
* Files uploaded while a folder is open go into it.
* Folders are virtual: files and URLs never change, and deleting a folder never deletes content.
* One-click import from MediaFlow Pro.

= Backups =

* The database and/or files (wp-content or the whole WordPress folder) in one ZIP, built in short steps so large sites do not time out.
* Download, delete and restore the database. A safety copy is taken first, so a restore can be undone too.
* Automatic daily or weekly backups at night, keeping as many as you choose.

= On your site =

* "Folder" block and `[prymeoperator_folder]` shortcode: show a Media Library folder as a gallery or a list of downloads.

= Security =

* Every path is confined to an allowed root folder with `realpath()`: `../`, null bytes and symbolic links that lead outside are refused.
* ZIP protection against zip slip, symbolic links, zip bombs and blocked file types.
* Blocked file types are checked on every extension, so `shell.php.jpg` counts as PHP.
* Downloads are served with `nosniff` and a sandboxing Content Security Policy.
* Respects `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT`.
* Trash, versions and backups live in a private folder denied to web requests.

= Access for other roles =

Administrators always have full access. You can let other roles use the file manager inside their own folder, with only the actions you allow. They can never edit code, change permissions or upload blocked file types.

= WP-CLI =

`wp prymeoperator backup create|list|restore|delete`, `wp prymeoperator trash list|restore|empty`, `wp prymeoperator folders list|import-mediaflow` and `wp prymeoperator audit`.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or search for "PrymeOperator".
2. Activate it.
3. Open **PrymeOperator** in the admin menu. Folders appear on Media, Pages and Posts straight away.

To lock the file manager to one folder, add this to `wp-config.php`:

`define( 'PRYMEOPERATOR_ROOT', '/absolute/path/to/folder' );`

== Frequently Asked Questions ==

= Does deleting a file really delete it? =

No. It goes to PrymeOperator's trash, from where you can restore it until it expires (30 days by default, configurable) or until an administrator empties the trash.

= What happens if I break the site editing a PHP file? =

PrymeOperator checks the syntax before saving. After saving, it loads the site once; if that fails, it puts the previous version back automatically.

= Do folders move my files? =

No. Library folders are virtual, so file paths and URLs never change. Deleting a folder only takes its items out of it.

= Where are backups stored? Are they safe? =

In `wp-content/prymeoperator-private/backups/`, which is denied to web requests. Download important backups and keep a copy elsewhere too.

= Can I restore files from a backup? =

The database can be restored with one click. Files are in the `files/` folder of the downloaded ZIP; you can extract the ones you need with the file manager.

= Which hooks can I use? =

Filters: `prymeoperator_server_root`, `prymeoperator_admin_capability`, `prymeoperator_vault_dir`, `prymeoperator_library_can_organise`, `prymeoperator_backup_exclude` and `prymeoperator_fix_list_layout`.

== Screenshots ==

1. The file manager with the folder tree, list view and context menu.
2. The code editor with versions and syntax check.
3. Folders on the Media Library.
4. Folders on the Pages list: drag rows onto a folder.
5. Backups: create, download and restore.
6. The dashboard with disk space, trash and recent activity.

== Changelog ==

= 0.4.0 =
* Backups: the database and/or files (wp-content or the whole WordPress folder) in one ZIP, built in short steps so large sites do not time out.
* Download, delete and restore the database from the new Backups tab. Restoring takes a safety backup first, rebuilds each table aside and swaps it in, keeps PrymeOperator active and keeps you logged in.
* Automatic daily or weekly backups at night, keeping the number you choose.
* "Folder" block and `[prymeoperator_folder]` shortcode: show a Media Library folder on the site as a gallery or a list of downloads.
* WP-CLI: `wp prymeoperator backup|trash|folders|audit`.
* More tests for the trash, file versions, the private vault and the backup file scanner.

= 0.3.0 =
* Library folders: a folder sidebar on Media (grid and list), Pages, Posts and any other post type you enable in Settings.
* Drag rows or thumbnails onto a folder to file them; tick several (or use Bulk select) to move them together. Drag folders to nest them.
* Subfolders, inline rename, folder search, counts, "Unassigned" view and a collapsible sidebar that follows the page.
* Files uploaded while a folder is open in the Media Library go into that folder.
* Deleting a folder never deletes content: its items become unassigned and its subfolders move up one level.
* Editors and administrators organise folders; authors can file their own items into them. Every change is in the activity log.
* One-click import of folders from MediaFlow Pro.

= 0.2.2 =
* The folders sidebar on Posts, Pages and Media now follows the page as you scroll, with its own scroll when it is taller than the window.

= 0.2.1 =
* Fix: on Posts, Pages and Media, a folders sidebar floated to the left no longer pushes the bulk-actions row and the table below it. Disable with the `prymeoperator_fix_list_layout` filter.

= 0.2.0 =
* Full file manager: upload in chunks, create, rename, copy, move, duplicate, ZIP, chmod, search, properties.
* Folder tree, list and grid views, context menu, drag and drop, keyboard shortcuts, dark theme.
* Trash with undo, file versions, PHP syntax check and automatic rollback when a change breaks the site.
* Dashboard, filterable activity log, settings page and access for other roles.
* Spanish translation.

= 0.1.0 =
* Secure core: path jail, permission guard, encrypted secrets.
* Read-only server explorer with file preview.
* Audit log.
