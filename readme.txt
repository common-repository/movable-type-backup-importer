=== Plugin Name ===
Contributors: rogerdudler
Tags: importer, import, movabletype, movable type, mt, backup
Requires at least: 3.0
Tested up to: 3.2.1
Stable tag: 1.1.2

This plugin allows you to import Movable Type backups. It reads the backup XML and creates the correct objects and relations in your Wordpress Blog.

== Description ==

This plugin allows you to import Movable Type backups instead of exports. It reads the Meta information from the Movable Type XML file and creates the correct objects and relations in your Wordpress Blog.

You can import the following contents from your Movable Type blog:

* Posts
* Tags
* Categories
* Users
* Files
* Images
* Comments

Some additional goodies:

* Support for Multisite setups
* Associations between Posts, Tags, Categories & Media
* Updated internal URLs in your posts (Images, Links)
* Users added as authors to blog instance
* Images will be added to the media library (with thumbnails)
* Support chunked backups for importing large sites

== Installation ==

1. Unzip `movable-type-backup-importer-$VERSION.zip`.
1. Upload the `movable-type-backup-importer` folder to your `/wp-content/plugins` folder
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to the Tools -> Import screen, Click on 'Movable Type (Backup)'

If you have large Backups, try to use a chunked backup from Movable Type, like 2 MB chunks for example. This is important to avoid memory problems during the import.

== Known Limitations ==

* It's possible to get an Out-of-Memory Exception while importing, if your XML backup is too large to be processed in memory. Try using chunked backups from Movable Type (e.g. 2MB) instead.

== Roadmap ==

* Try to use information from newly created image library entries, to generate new clean img tags with correct sizing, etc.

== Frequently Asked Questions ==

= Where can I change the Movable Type Backup directory? =
You need to add/change a the following line in the `cgi-bin/mt-config.cgi` file
`TempDir /tmp/mt`

== Screenshots ==

1. This screen shot show the Tools -> Import screen with the new entry 'Movable Type (Backup)'.
2. The importer shows backups which are located in the correct directory. Later, it will allow you to choose the objects you want to import. Currently, it imports all of them.

== Changelog ==

= 1.1.2 =
* Fixed a bug on single file backups (without *-1.xml)

= 1.1.1 =
* Fixed a bug on single file backups (without *-1.xml)

= 1.1.0 =
* Added error message on lack of permissions on upload folder
* Fixed image paths on single site installations

= 1.0.9 =
* Support for extended content

= 1.0.8 =
* Better link and image URL replacement
* Support for unfiltered HTML content, bypass WP filters
* Performance optimizations
* XML Extension (php-xml) not required anymore
* Changed default role of users from author to editor (MU)
* Support for users with "ldaprename" Prefix
* Detection of broken images
* Download of images which are outside of MT but within site

= 1.0.7 =
* Using authored_on as post date instead of created_on

= 1.0.6 =
* Kicked out junk comments

= 1.0.5 =
* Added support for chunked backups

= 1.0.4 =
* Added a check for the "add_user_to_blog" function

= 1.0.3 =
* Embedded content (iframes) handled by oEmbed
* Stripped out MT specific form containers for images

= 1.0.2 =
* Support for embedded content (e.g. iframe)

= 1.0.1 =
* Internal links and file URLs are updated in posts
* Users are now added as authors to the current blog

= 1.0.0 =
* Initial version

== Upgrade Notice ==

No steps needed.