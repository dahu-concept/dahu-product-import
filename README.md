# Dahu Product Import

Edit WooCommerce product text in a spreadsheet and import it back, with a dry
run that shows every change before anything is written, and a rollback.

MIT licensed. Requires WordPress 6.0, PHP 7.4, and WooCommerce 7.0 for the SKU
and attributes columns.

## The sequence

```bash
wp product-import check                              # what this site reads and writes
wp product-import export                             # site → spreadsheet
#   ... edit the file ...
wp product-import import --file=products.csv         # dry run: the report, no writes
wp product-import import --file=products.csv --execute
wp product-import rollback --file=backup-….jsonl --execute
```

## What it will not do

* Never **creates** a product — a row naming an unknown id is reported and skipped.
* Never **deletes** one.
* Never touches prices, stock, weights, dimensions, categories, tags,
  variations, global attributes, orders or customers.
* Never **truncates** a value to make it fit: a value over a declared maximum
  rejects that row.

## The columns

| Column | Writes | Notes |
|---|---|---|
| `product_id` | — | matches the row to a product |
| `slug` | — | confirms the id still means that product |
| `sku` | `_sku` | an empty cell never clears a SKU |
| `type`, `category` | — | for information, never written |
| `title` | `post_title` | required; a product cannot lose its title |
| `description` | `post_content` | rich text |
| `short_description` | `post_excerpt` | plain text, or HTML if the cell holds any |
| `attributes` | local attributes | visible, non-variation only |

Plus whatever your theme declares.

## Declaring your own fields

```php
add_filter( 'dahu_product_import_fields', function ( $fields ) {
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
	$fields['related'] = array(
		'target' => '_my_theme_related',
		'nature' => 'product_id',
	);
	return $fields;
} );
```

A declared field behaves exactly like a built-in one, in the export, the dry
run, the import and the rollback.

| Key | |
|---|---|
| `target` | `post:<field>` for a posts-table column, or a meta key |
| `nature` | how the value is cleaned, and what counts as valid |
| `max_length` | in characters; a longer value **rejects the row** |
| `required` | an empty cell rejects the row |

### Natures

| Nature | Cleaning |
|---|---|
| `text` | plain text, newlines collapsed |
| `textarea` | plain text, newlines kept |
| `title` | plain text through `sanitize_text_field()` |
| `html` | `wp_kses_post()` |
| `excerpt` | plain multi-line text, or HTML kept as typed |
| `url` | an http or https URL, or nothing |
| `product_id` | the id of a product on this site |

`text` and `textarea` do **not** use `sanitize_text_field()`: that turns a lone
`<` into an entity, which corrupts a legitimate technical value like `<50dB`,
and with a `>` later on the line removes everything between them. Escaping
belongs to output.

## Other hooks

| Hook | Kind | Purpose |
|---|---|---|
| `dahu_product_import_fields` | filter | Declare columns |
| `dahu_product_import_excluded_product_types` | filter | Product types to leave to another tool; their rows are reported as rejected, not silently skipped |
| `dahu_product_import_plan_row` | action | Add a write that follows from the row rather than from a column of its own. Receives `&$item, $row, $id, $wanted`; append to `$item['changes']` for the report and `$item['values']` for the write. Must not touch the database — it runs during the dry run too |

## The round trip

Exporting and importing with no edit writes **nothing**. That is the property
the design rests on, and it is asserted by the bench rather than assumed.

Keeping it true is why a cell is treated as unchanged in three cases: the raw
cell matches the site, the cleaned cell matches the site, or cleaning the
site's own value gives the cleaned cell. The third is the one that matters —
without it, the first import after an export rewrites a large part of the
catalogue with laundered versions of what was already there, and buries the
real changes among them.

## When a field keeps being rewritten

```bash
wp product-import trace --ids=42 --file=products.csv
```

Read-only. It walks the site's value, the export, this server's CSV encoding,
the file as your spreadsheet saved it, the trim and the cleaning, and names the
first step that changes the value, with its byte offset and hex. Usually a
non-breaking space or a BOM.

## Data

Nothing in the database belongs to this plugin: it writes to fields and meta
belonging to WooCommerce or to your theme. Uninstalling deletes nothing.

The reports, backups and logs live in a working directory that must sit outside
any web root — the plugin refuses one inside. A backup holds the previous text
of every product touched, under a guessable name; served over HTTP it is a free
copy of your editorial work, taken without a trace.

## Licence

MIT. See `LICENSE`.
