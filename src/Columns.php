<?php

declare(strict_types=1);

namespace ListingTool;

/** Master template columns. Keep in sync with templates/listing_template_blank.csv. */
final class Columns
{
    public const MASTER = [
        'sku', 'parent_sku', 'variant_theme', 'title', 'brand', 'manufacturer', 'description_html',
        'bullet_1', 'bullet_2', 'bullet_3', 'bullet_4', 'bullet_5', 'search_keywords', 'tags',
        'category_path', 'product_type', 'gtin', 'gtin_type', 'hsn_code', 'gst_rate_pct',
        'mrp_inr', 'selling_price_inr', 'cost_price_inr', 'quantity', 'condition', 'color', 'size',
        'material', 'pattern', 'target_gender', 'age_group', 'model_number', 'weight_g', 'length_cm',
        'width_cm', 'height_cm', 'package_weight_g', 'package_length_cm', 'package_width_cm',
        'package_height_cm', 'country_of_origin', 'manufacturer_address', 'packer_details',
        'importer_details', 'net_quantity', 'net_quantity_unit', 'warranty', 'dispatch_days',
        'main_image_url', 'image_url_2', 'image_url_3', 'image_url_4', 'image_url_5', 'image_url_6',
        'image_url_7', 'image_url_8', 'status', 'seo_title', 'seo_description', 'list_on_amazon',
        'list_on_flipkart', 'list_on_shopify', 'list_on_woocommerce',
    ];

    public const CHANNELS = ['amazon', 'flipkart', 'shopify', 'woocommerce'];

    public const CHANNEL_LABELS = [
        'amazon' => 'Amazon India',
        'flipkart' => 'Flipkart',
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ];

    public const IMAGES = [
        'main_image_url', 'image_url_2', 'image_url_3', 'image_url_4',
        'image_url_5', 'image_url_6', 'image_url_7', 'image_url_8',
    ];

    public const BULLETS = ['bullet_1', 'bullet_2', 'bullet_3', 'bullet_4', 'bullet_5'];

    /** Indian GST rates. 12 and 28 were largely removed in the Sept 2025 revision but still accepted with a warning. */
    public const GST_RATES = ['0', '0.25', '3', '5', '12', '18', '28', '40'];

    public const NUMERIC = [
        'gst_rate_pct', 'mrp_inr', 'selling_price_inr', 'cost_price_inr', 'quantity', 'weight_g',
        'length_cm', 'width_cm', 'height_cm', 'package_weight_g', 'package_length_cm',
        'package_width_cm', 'package_height_cm', 'dispatch_days', 'net_quantity',
    ];

    /** Extra category attributes use columns named `attr:<name>`, e.g. attr:sleeve_type. */
    public static function isAttr(string $column): bool
    {
        return str_starts_with($column, 'attr:');
    }
}
