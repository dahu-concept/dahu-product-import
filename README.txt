=== Dahu Product Import ===
Contributors: hugovialjaime
Tags: woocommerce, products, csv, import, export
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Edit your WooCommerce product text in a spreadsheet and import it back, with a dry run that shows every change before anything is written.

== Description ==

Rewriting the text of three hundred products one admin screen at a time is a
week of work and a week of mistakes. A spreadsheet is the right tool for it —
one row per product, one column per field, find and replace, a colleague
reviewing it.

Dahu Product Import closes the loop. It writes the spreadsheet from your site,
reads it back, and tells you exactly what it would change before changing
anything.

= What it will not do =

This matters more than the feature list, because an import that touches your
whole catalogue is only usable if its limits are clear:

* It **never creates** a product. A row naming an id this site does not have is
  reported and skipped.
* It **never deletes** one.
* It **never touches** prices, stock, weights, dimensions, categories, tags,
  variations, global attributes, orders or customers.
* It **never truncates** a value to make it fit. A value over a declared
  maximum rejects that row and says so.

= The dry run is not optional =

Running an import produces a report and writes nothing. You add `--execute`
once the report says what you expected.

The report is written to be read. Beyond the per-product list of changes, three
sections exist for the mistakes that otherwise slip through:

* **Unrecognised columns** — a misspelt header imports nothing at all, silently,
  file after file. It is named at the top of the report instead.
* **Erasures grouped by column** — an empty cell clears a field, so a column
  accidentally emptied in the spreadsheet shows up as two hundred erasures on
  one line rather than scattered through the report.
* **Rejected rows**, each with its reason.

Every value about to be written is also compared against what the site holds,
and a cell that would only change what the cleaning normalises is left alone —
so an export followed by an import, with no edit, writes nothing at all.

= Rolling back =

Every import writes a backup of the previous value of every field it touched,
and a rollback command puts them back.

A field somebody has edited BY HAND since the import is left alone and
reported. That is deliberate: a bad import is usually noticed days later, by
which time three products have been fixed manually, and a rollback that
restored everything would silently throw that work away. `--force` overrides
it, for somebody who has read the report.

= Which fields =

Out of the box: the title, the description and the short description, plus the
SKU and the product's local attributes. Those exist in every shop.

Your theme's own fields are declared with a filter, and then behave exactly
like the built-in ones — in the export, the dry run, the import and the
rollback:

`add_filter( 'dahu_product_import_fields', function ( $fields ) {
    $fields['subtitle'] = array(
        'target' => '_my_theme_subtitle',
        'nature' => 'text',
    );
    $fields['claim'] = array(
        'target'     => '_my_theme_claim',
        'nature'     => 'text',
        'max_length' => 55,
    );
    $fields['leaflet'] = array(
        'target' => '_my_theme_leaflet',
        'nature' => 'url',
    );
    return $fields;
} );`

`wp product-import check` then shows the columns your file will have.

= The attributes column =

One column drives a product's local attributes, one per line as `Name : value`,
several values separated by a pipe.

It deliberately refuses to touch global attributes (the `pa_*` taxonomies),
attributes used for variations, and hidden local attributes. A line naming one
of those is **reported** and skipped — reporting it is the point, since
silently ignoring it would leave you editing the same cell again next week.

= Why the command line =

An import writes to hundreds of products in one pass. It needs a dry run you
can read at leisure, a report that is still there tomorrow, and no browser
timing out halfway through with no way to tell what was written. There is an
admin screen; it tells you what to run, and shows the columns your file will
have.

== Installation ==

1. Install and activate the plugin.
2. Run `wp product-import check` to see what it reads and writes.
3. Run `wp product-import export` to get your spreadsheet.

The working directory holding reports, backups and logs must sit **outside any
web root** — the plugin refuses one inside, because a backup holds the previous
text of every product it touched under a guessable file name. It defaults to
`$HOME/dahu-product-import`.

== Frequently Asked Questions ==

= How is a row matched to a product? =

By the `product_id` column, confirmed against the `slug` column. An id and not
a SKU, because a SKU is editable and often empty; and the slug is checked
because ids get reused when a product is deleted, and a spreadsheet lives for
months. A mismatch rejects the row rather than overwriting whatever product
inherited the id.

= What does an empty cell do? =

It clears that field — which is why the report groups erasures by column.

The one exception is the SKU: an empty SKU cell keeps the site's SKU and says
so. A missing SKU in a spreadsheet is almost always an omission, while a
cleared SKU breaks order history, exports and shipping labels.

= Why is one field rewritten on every import although nobody edited it? =

Something between the site and the file is changing it, and
`wp product-import trace --ids=<id>` names which step. It walks the site's
value, the export, this server's CSV encoding, the file as your spreadsheet
saved it, the trim and the cleaning, and shows the first difference with its
byte offset and hex. It is usually a non-breaking space or a BOM.

= My spreadsheet saves semicolons, not commas. =

That is fine. The separator is detected from the header line: comma, semicolon
or tab.

= It says my file is not UTF-8. =

Save it as "CSV UTF-8" rather than plain "CSV". The plugin refuses to guess an
encoding, because a wrong guess turns every accented character in your
catalogue to nonsense in one pass.

= Can I run it without WooCommerce? =

The text fields work; the SKU and attributes columns are skipped and say so.

= Does it work with HPOS? =

Yes — it never reads or writes an order at all, and declares itself compatible.

== Changelog ==

= 1.1.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.0.1 =
* A third-party hook that raises an Error while the SKU is being written is now a counted error on that one row, instead of ending the import on a product whose text had just been written.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
