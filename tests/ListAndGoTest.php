<?php

declare(strict_types=1);

namespace ListingTool\Tests;

use ListingTool\Csv;
use ListingTool\ListAndGo;
use ListingTool\Loader;
use ListingTool\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class ListAndGoTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    private static function master(): array
    {
        return Loader::load(self::fixture('master_sample.csv'))['rows'];
    }

    /** Read an .xlsx export's first sheet back into associative rows. */
    private static function sheetRows(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lag') . '.xlsx';
        file_put_contents($tmp, $content);
        $book = IOFactory::load($tmp);
        unlink($tmp);
        $all = $book->getSheet(0)->toArray(null, false, false);
        $head = array_shift($all);
        return array_map(static fn ($r) => array_combine($head, array_map('strval', $r)), $all);
    }

    public function testSampleTemplateIsValidForAllChannels(): void
    {
        $result = ListAndGo::check(self::fixture('master_sample.csv'));
        $this->assertSame('master', $result['format']);
        $this->assertCount(4, $result['rows']);
        $this->assertSame(['errors' => 0, 'warnings' => 0], $result['summary'], json_encode($result['issues']));
    }

    public function testValidatorCatchesPriceAboveMrpBadHsnAndDuplicateSku(): void
    {
        $rows = self::master();
        $rows[0]['selling_price_inr'] = '700';
        $rows[0]['hsn_code'] = '69A2';
        $rows[3]['sku'] = $rows[2]['sku'];
        $fields = array_column(Validator::validate($rows), 'field');
        $this->assertContains('selling_price_inr', $fields);
        $this->assertContains('hsn_code', $fields);
        $this->assertContains('sku', $fields);
    }

    public function testClothingNeedsSizeAndColourOnMarketplacesOnly(): void
    {
        $rows = self::master();
        $rows[2]['size'] = '';
        $issues = array_filter(Validator::validate($rows), static fn ($i) => $i['field'] === 'size' && $i['level'] === 'error');
        $this->assertEqualsCanonicalizing(['amazon', 'flipkart'], array_values(array_unique(array_column($issues, 'channel'))));
    }

    public function testChannelFlagSkipsRows(): void
    {
        $rows = self::master();
        $rows[0]['list_on_flipkart'] = 'N';
        $file = ListAndGo::export($rows, 'flipkart');
        $this->assertNotContains('MUG-CER-350-WHT', array_column(self::sheetRows($file->content), 'Seller SKU ID'));
    }

    public function testAmazonParentChildRows(): void
    {
        $out = self::sheetRows(ListAndGo::export(self::master(), 'amazon')->content);
        $bySku = array_column($out, null, 'item_sku');
        $this->assertSame('parent', $bySku['TSH-CTN-CREW']['parent_child']);
        $this->assertSame('', $bySku['TSH-CTN-CREW']['standard_price']);
        $child = $bySku['TSH-CTN-CREW-BLK-M'];
        $this->assertSame('child', $child['parent_child']);
        $this->assertSame('TSH-CTN-CREW', $child['parent_sku']);
        $this->assertSame('SizeColor', $child['variation_theme']);
        $this->assertSame('499', $child['standard_price']);
        $this->assertSame('799', $child['maximum_retail_price']);
        $this->assertSame('8909876543210', $child['external_product_id']);
        $this->assertSame('Men', $child['department_name']);
        $this->assertSame('Half Sleeve', $child['sleeve_type']);
        $this->assertSame('100% combed cotton, 180 GSM', $child['bullet_point1']);
    }

    public function testFlipkartRowsShareStyleCodeAndConvertUnits(): void
    {
        $out = self::sheetRows(ListAndGo::export(self::master(), 'flipkart')->content);
        $this->assertCount(3, $out, 'variant parent rows are not listed on Flipkart');
        $bySku = array_column($out, null, 'Seller SKU ID');
        $m = $bySku['TSH-CTN-CREW-BLK-M'];
        $this->assertSame('TSH-CTN-CREW', $m['Style Code']);
        $this->assertSame("Men's Cotton Crew Neck T-Shirt", $m['Model Name']);
        $this->assertSame('0.25', $m['Weight (KG)']);
        $this->assertSame('GST_5', $m['Tax Code']);
        $this->assertSame('Men', $m['Ideal For']);
        $this->assertSame('Round Neck', $m['Neck']);
        $this->assertSame('UrbanThread Apparel LLP, 45 Avinashi Rd, Tiruppur, TN 641602', $m['Manufacturer Details']);
    }

    public function testShopifyGroupsVariantsAndImagesUnderOneHandle(): void
    {
        $out = Csv::read(ListAndGo::export(self::master(), 'shopify')->content);
        $tee = array_values(array_filter($out, static fn ($r) => $r['Handle'] === 'tsh-ctn-crew'));
        $this->assertCount(2, $tee);
        $this->assertSame('Color', $tee[0]['Option1 Name']);
        $this->assertSame('Size', $tee[0]['Option2 Name']);
        $this->assertSame(['M', 'L'], array_column($tee, 'Option2 Value'));
        $this->assertSame('', $tee[1]['Title'], 'only the first line of a product carries the title');
        $mug = array_values(array_filter($out, static fn ($r) => $r['Handle'] === 'mug-cer-350-wht'));
        $this->assertSame(['1', '2', '3'], array_column($mug, 'Image Position'));
        $this->assertSame('IN', $mug[0]['Country of Origin']);
    }

    public function testWooCommerceVariableProduct(): void
    {
        $out = Csv::read(ListAndGo::export(self::master(), 'woocommerce')->content);
        $bySku = array_column($out, null, 'SKU');
        $this->assertSame('variable', $bySku['TSH-CTN-CREW']['Type']);
        $this->assertSame('M, L', $bySku['TSH-CTN-CREW']['Attribute 2 value(s)']);
        $this->assertSame('variation', $bySku['TSH-CTN-CREW-BLK-L']['Type']);
        $this->assertSame('TSH-CTN-CREW', $bySku['TSH-CTN-CREW-BLK-L']['Parent']);
        $this->assertSame('799', $bySku['TSH-CTN-CREW-BLK-L']['Regular price']);
        $this->assertSame('499', $bySku['TSH-CTN-CREW-BLK-L']['Sale price']);
    }

    public function testImportsShopifyExport(): void
    {
        $loaded = Loader::load(self::fixture('shopify_export.csv'));
        $this->assertSame('shopify', $loaded['format']);
        $bySku = array_column($loaded['rows'], null, 'sku');
        $this->assertSame('size-color', $bySku['linen-kurta']['variant_theme']);
        $this->assertSame('https://cdn.example.com/kurta-3.jpg', $bySku['linen-kurta']['image_url_3']);
        $this->assertSame('linen-kurta', $bySku['KUR-LIN-M-BLU']['parent_sku']);
        $this->assertSame('M', $bySku['KUR-LIN-M-BLU']['size']);
        $this->assertSame('Blue', $bySku['KUR-LIN-M-BLU']['color']);
        $this->assertSame('1999', $bySku['KUR-LIN-M-BLU']['mrp_inr']);
        $this->assertSame('', $bySku['CAP-001']['parent_sku']);
    }

    public function testImportsWooCommerceExport(): void
    {
        $loaded = Loader::load(self::fixture('woocommerce_export.csv'));
        $this->assertSame('woocommerce', $loaded['format']);
        $bySku = array_column($loaded['rows'], null, 'sku');
        $this->assertSame('POLO-01', $bySku['POLO-01-M']['parent_sku']);
        $this->assertSame('699', $bySku['POLO-01-M']['selling_price_inr']);
        $this->assertSame('999', $bySku['POLO-01-M']['mrp_inr']);
        $this->assertSame('999', $bySku['POLO-01-L']['selling_price_inr']);
        $this->assertSame('300', $bySku['POLO-01-M']['weight_g']);
    }

    public function testImportedExportFlagsMissingIndiaFields(): void
    {
        $result = ListAndGo::check(self::fixture('shopify_export.csv'), ['amazon']);
        $fields = array_unique(array_column(array_filter($result['issues'], static fn ($i) => $i['level'] === 'error'), 'field'));
        $this->assertContains('hsn_code', $fields);
        $this->assertContains('gst_rate_pct', $fields);
        $this->assertContains('country_of_origin', $fields);
    }

    public function testRejectsUnknownCsv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Loader::load("name,price\nshirt,10\n");
    }
}
