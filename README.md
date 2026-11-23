# List and Go

List and Go turns one product file into ready-to-upload listing files for **Amazon India, Flipkart, Shopify and WooCommerce**.

Phase 1 is a single upload page written in plain PHP and HTML. It needs no database, so it runs on Hostinger shared hosting.

1. The seller fills in the List and Go template, or uploads a product export from Shopify or WooCommerce.
2. List and Go checks every row against each channel's rules. These include required fields, HSN and GST, selling price against MRP, image links, variants, and the extra fields clothing needs.
3. The seller downloads one file per channel:
   - Shopify: a product import CSV
   - WooCommerce: a product import CSV
   - Amazon India: an .xlsx laid out exactly like Amazon's clothing template for SHIRT, SWEATSHIRT and NIGHTGOWN_NIGHTSHIRT. It has the same 317 columns in the same order, with data starting at row 7, and uses the template's own dropdown values.
   - Flipkart: an .xlsx whose rows follow the clothing listing sheet

   Each Amazon and Flipkart file has a "Read me" sheet. It explains how to copy the rows into the category template from Seller Central or Seller Hub.

The first category is **baby clothing**. For it, List and Go:

- turns age sizes ("0-3 Months", "6-12M", "1-2 Years", "Newborn") into Amazon's size fields
- picks the Baby Boys, Baby Girls or Unisex Baby department
- fills in Amazon's baby compliance fields
- checks that clothing GST matches the price band

Live API publishing to the marketplaces is planned for phase 2.

## The template

Get the templates from `templates/`, or from the links on the upload page:

- `listing_template_blank.csv`: headers only.
- `listing_template_sample.csv`: a simple product (a mug) and a t-shirt with two sizes.

The file has one row per SKU.

- **Variants:** a product with variants gets a parent row with no price or stock. Each size or colour is a child row whose `parent_sku` names the parent, and `variant_theme` says what varies (for example `color-size`). A child row leaves a field blank to take the value from its parent row.
- **Channels:** the `list_on_amazon`, `list_on_flipkart`, `list_on_shopify` and `list_on_woocommerce` columns take Y or N. A blank counts as Y.
- **Extra attributes:** add any category-specific attribute as an `attr:<name>` column. The clothing columns are:
  - `attr:fit_type`, `attr:sleeve_type`, `attr:neck_type`, `attr:closure_type` and `attr:care_instructions`
  - `attr:fabric_composition`, for example "100% Cotton"
  - `attr:weave_type`: Knitted or Woven
  - `attr:item_type_name` and `attr:amazon_product_type`, which override the item type and product type List and Go works out from `product_type`

  Any other `attr:` column is passed through as its own column.
- **Baby sizes:** write baby sizes as ages: `0-3 Months`, `3-6 Months`, `6-12 Months`, `1-2 Years`. Set `age_group` to `Baby`, and `target_gender` to `Male`, `Female` or `Unisex`.

The full field mapping is in the plan doc.

## Run locally

```bash
composer install
php -S 127.0.0.1:8000 -t public
composer test
```

PHP 8.1 or newer is required, with the `zip`, `xml`, `mbstring` and `gd` extensions. Hostinger's default PHP builds include them.

## Deploy on Hostinger

1. In hPanel, open **Websites > Manage > Advanced > Git**. Add this repository with branch `main`, and leave the install path empty so it deploys into `public_html`.
2. Open **Advanced > SSH Access** and connect. Then run:
   ```bash
   cd ~/domains/<your-domain>/public_html
   composer install --no-dev --optimize-autoloader
   ```
3. Check that **Advanced > PHP Configuration** is set to PHP 8.1 or newer.
4. Open your domain. The root `.htaccess` sends every request to `public/` and blocks `src/`, `vendor/`, `storage/` and `templates/`. `storage/jobs` must be writable, which it is by default.

After each new deploy from hPanel, run `composer install --no-dev` again if `composer.lock` changed.

## Code layout

| Path | What it does |
| --- | --- |
| `src/Loader.php` | Detects the upload format (template, Shopify, WooCommerce) and turns the file into template rows |
| `src/Importers/` | Converts Shopify and WooCommerce exports into template rows |
| `src/Validator.php` | Checks rows against each channel's rules |
| `src/Exporters/` | Builds one file per channel |
| `src/Catalog.php` | Shared helpers for variants, channel flags and images |
| `public/` | Upload page and download endpoint |
| `tests/` | PHPUnit tests and fixture files |

The `src/` folder has no web code. The future WordPress plugin can reuse it as is.

## Updating the Amazon template

Amazon changes its category templates from time to time. To refresh the column layout and dropdown values:

1. Download the template from Seller Central.
2. Run:

   ```bash
   php bin/import-amazon-template.php path/to/TEMPLATE.xlsm src/Exporters/Amazon/schema_clothing_in.php
   ```

The script copies only column names and dropdown values. It does not copy the seller-specific settings row, so no seller ids end up in the repo.

## Known limits

- **Amazon:** the columns come from a real Seller Central template for SHIRT, SWEATSHIRT and NIGHTGOWN_NIGHTSHIRT. Other baby product types, such as rompers and bodysuits, need their own template. Browse nodes are left blank.
- **Flipkart:** the column names have not yet been checked against a real Flipkart template.
- **Product tax codes:** the GST-rate-to-Amazon-tax-code mapping and the clothing GST price band (5% up to ₹2,500, 18% above) are our reading of the September 2025 GST revision. Confirm both with your tax advisor.
- GST 12% and 28% are accepted with a warning, because those slabs were largely removed in the September 2025 GST revision.
