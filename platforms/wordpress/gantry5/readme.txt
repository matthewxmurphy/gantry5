=== Gantry 5 Framework ===
Contributors: gantry
Author URI: http://gantry.org
Tags: gantry, gantry5, framework, theme, builder
Requires at least: 7.1.4
Tested up to: 7.1.4
Requires PHP: 8.3.0
Stable tag: @version@
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gantry 6 alpha is an independent Gantry 5 based testing release for PHP 8.3–8.5. Use reviewed packages from https://github.com/matthewxmurphy/gantry5/releases for manual installation and updates. Existing Gantry and third-party licenses remain applicable.

== Description ==

Gantry 5 is a comprehensive set of building blocks to enable the rapid development and realization of a design into a flexible and powerful web platform theme.

> Download matching Gantry 6 alpha Helium or Hydrogen themes from https://github.com/matthewxmurphy/gantry5/releases. The official stable Gantry 5 themes require migration for this alpha.

Gantry 5 is packed full of features created to empower the development of designs into fully functional layouts with the absolute minimum effort and fuss.

[youtube https://www.youtube.com/watch?v=J9Vn9iwGpXs]

= Responsive Layout System =

Gantry 5 uses the **Nucleus** grid system, built from scratch using Flexbox and tied in to the **Layout Manager** to generate classes.

= Cross-platform Support =

The current native verification baseline is **WordPress 7.1.4**, **Joomla 6.2.0** and **Grav 2.2.19/Admin2**. The alpha requires PHP 8.3–8.5; older CMS combinations need separate acceptance.

= Intuitive YAML-driven Configuration =

[YAML](http://yaml.org/) is a human-readable data serialization, making it easy to create and configure blueprints for back-end configuration options.

= Twig Templating =

The alpha uses Twig 3 and WordPress Timber 2; third-party themes and extensions require the documented API migration.

= New Administrative Interface =

Gantry 5's administrative interface has been completely rebuilt from the ground up to meet the needs of tomorrow's developer, today.

= Infinitely Customizable =

Gantry 5 is the most customizable and powerful version of the framework yet, featuring drag-and-drop customization controlling virtually every aspect of the site's layout and design.

= New Particles System =

Gantry 5's new Particles system makes it easy to create, configure, and manage content blocks. Create new Particles in minutes, adjust their settings at multiple levels from global down to the individual block, and more!

= Flexible and Easy Layout Manager =

Set up and configure page layouts with Gantry 5's new Layout Manager. Featuring drag-and-drop functionality that gives you the power to place content blocks, resize them, and configure their unique settings in seconds. Load layout presets or duplicate the layout of another Outline with a click of the mouse, and customize virtually every aspect of the page layout without ever having to touch a text editor.

= No Programming Experience Necessary =

Adding and moving positions, resizing blocks, and more can be done with drag-and-drop simplicity in the new, powerful Layout Manager. Error pages and other areas of site design are also easily handled in Gantry 5's administrative interface. You can change almost any aspect of a theme without breaking out a single editor.

= Powerful Menu Editor =

Change the way your site's menus look with Gantry 5's powerful Menu Editor. Quickly insert Particles, rearrange menu items, and add icons in seconds.

= New Outline System =

Theme overrides are now part of Gantry 5's Outline system, enabling you to easily create and manage Layouts, Settings, Styles, and more and assign them as needed.

= Use SCSS or CSS =

Gantry 5 has built-in support for SCSS and pure CSS.

> For video tutorials and documentation please visit official [Gantry Framework](http://gantry.org/ "Gantry Framework") site.

== Installation ==

This section describes how to install the plugin and get it working.

Using WordPress plugin installer :

1. Go to the Admin Dashboard > Plugins > Add New
1. From the top list select 'Upload'
1. Point the Browse window to the plugin zip package
1. Activate the plugin in Admin Dashboard > Plugins

Using FTP :

1. Extract the zip package with the plugin
1. Upload the plugin directory to the wp-content/plugins/
1. Activate the plugin in Admin Dashboard > Plugins

The framework ZIP does not include a theme. Install matching 6.0.0-alpha.1 Helium or Hydrogen from [this fork's releases](https://github.com/matthewxmurphy/gantry5/releases).

== Frequently Asked Questions ==

= What are the requirements of the plugin ? =

We try to ensure that any Gantry theme and the Gantry Plugin specifically will work with any modern and secure server environment. The recommended minimum requirements are :

* WordPress 7.1.4 (the accepted alpha baseline; older versions need separate acceptance)
* PHP 8.3, 8.4 or 8.5 (below 8.6)

= Is there any documentation available ? =

Yes of course! And it's getting better and better with every day! Please visit our [Gantry 5 Documentation](http://docs.gantry.org/ "Gantry 5 Documentation") site to get a better understanding on how Gantry 5 works. If you feel like some parts of documentation could be changed or you want to add something, please feel free to do so, as the whole documentation is placed on [GitHub](https://github.com/gantry/docs "GitHub") and anyone is free to contribute.

= Where are the options of the plugin ? =

The framework provides the administration interface for each compatible theme. Install the matching alpha Helium or Hydrogen package from [this fork's releases](https://github.com/matthewxmurphy/gantry5/releases).

= How can I test it ? =

Install the framework and a matching alpha theme from [this fork's releases](https://github.com/matthewxmurphy/gantry5/releases) in an isolated WordPress copy. Read docs/dependency-migration.md before porting third-party themes or extensions; this alpha changes Twig, Timber and event APIs.

== Changelog ==

= 5.6.5 (2026-10-15) =
* Security: fixed a sensitive data exposure issue where an unauthenticated particle AJAX request with an invalid outline could show the detailed debug error page. The debug error page is now only shown in debug mode or to administrators, the AJAX handler validates the outline and handles errors safely, and secret values are masked on the debug page.
* Security: invalid particle AJAX requests now return HTTP 404 with a valid JSON Content-Type; detailed error pages are never shown to logged-out visitors, even in debug mode; error output hides environment, cookie, session and POST data.
* Bundled local copies of jQuery, jQuery UI, Bootstrap 2-5 and MooTools instead of loading them from third-party CDNs.
* The admin changelog is now read from the bundled readme.txt instead of a remote URL.
* File picker uses WP_Filesystem to set file permissions.

You can read the full changelog at the [Gantry Framework](http://gantry.org/#changelog:p=wordpress "Gantry Framework") site.
