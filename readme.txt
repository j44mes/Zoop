=== Zoop ===
Contributors: zoop
Tags: seo, analysis
Requires at least: 6.4
Tested up to: 6.4
Stable tag: 0.2.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

On-page SEO scoring for any WordPress site.

== Description ==

Zoop scores a page against a 100-point on-page rubric and shows a second content-analysis score in the editor. It does not print meta tags, and it does not change another SEO plugin's data.

The site audit queue, front-end meta output, and importers are later phases.

== Installation ==

1. Install Composer dependencies from the plugin directory: `composer install --no-dev --optimize-autoloader`
2. Build the admin screens: `npm install` and `npm run build`
3. Copy the directory into `wp-content/plugins/`.
4. Activate Zoop. Activation creates the custom tables.

== Frequently Asked Questions ==

= Does activation change my content or another SEO plugin? =

No. Activation creates Zoop's own tables. Uninstall removes them only when the setting `uninstall_delete_data` is enabled.

= Why is the score sometimes out of 94? =

The two code checks need a full-page fetch. A content render leaves them unchecked and drops them from the denominator.

== Changelog ==

= 0.2.0 =
* Add the SEO Studio dashboard, the block editor sidebar, the classic meta box, and the content-analysis worker.
* Preview unsaved editor content without storing it. The site audit route answers 501 until the queue exists.

= 0.1.0 =
* Add the 100-point on-page scorer, custom tables, REST routes, and `wp seostudio analyse`.
* Score the metadata a crawler would receive, keep SEO fields off the public REST API, and accept a first analysis for a post that has no saved row yet.

== Upgrade Notice ==

= 0.1.0 =
First development release.

== Third-party code ==

* yoastseo 3.6.0, GPL-3.0. The licence is in `vendor-js/yoastseo/LICENSE`. The package runs in the admin browser. The interface does not use that vendor's name or logo.
* Action Scheduler 3.9, from WooCommerce, is installed with Composer. It is GPL-3.0-or-later and is not invoked in this version. It is present so later batch jobs can use it.
