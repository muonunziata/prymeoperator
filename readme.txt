=== PrymeOperator ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure file operator for WordPress: server file manager, media library folders and reversible, audited operations.

== Description ==

PrymeOperator brings the server file manager and the media library organiser into one plugin, without elFinder. Every path is confined to an allowed root folder, every action is recorded in an audit log, and destructive operations will be reversible.

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
