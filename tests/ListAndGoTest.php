<?php

declare(strict_types=1);

namespace ListingTool\Tests;

use ListingTool\Csv;
use ListingTool\Exporters\Amazon\AmazonMapper;
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

    /** Read the Amazon export back as rows keyed by short attribute key (row 5), data from row 7. */
    private static function amazonRows(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lag') . '.xlsx';
        file_put_contents($tmp, $content);
        $all = IOFactory::load($tmp)->getSheetByName('Template')->toArray(null, false, false);
        unlink($tmp);
        $keys = array_map(static fn ($k) => AmazonMapper::shortKey((string) $k), $all[4]);
        return array_map(static fn ($r) => array_combine($keys, array_map('strval', $r)), array_slice($all, 6));
    }

    public function testValidatorCatchesPriceAboveMrpBadHsnAndDuplicateSku(): void
    {
        $rows = self::master();
        $rows[3]['selling_price_inr'] = '900';
        $rows[3]['hsn_code'] = '61A1';
        $rows[2]['sku'] = $rows[1]['sku'];
        $fields = array_column(Validator::validate($rows), 'field');
        $this->assertContains('selling_price_inr', $fields);
        $this->assertContains('hsn_code', $fields);
        $this->assertContains('sku', $fields);
    }

    public function testClothingNeedsSizeAndColourOnMarketplacesOnly(): void
    {
        $rows = self::master();
        $rows[1]['size'] = '';
        $issues = array_filter(Validator::validate($rows), static fn ($i) => $i['field'] === 'size' && $i['level'] === 'error');
        $this->assertEqualsCanonicalizing(['amazon', 'flipkart'], array_values(array_unique(array_column($issues, 'channel'))));
    }

    public function testBabySizesMustBeAges(): void
    {
        $rows = self::master();
        $rows[1]['size'] = 'M';
        $issues = array_filter(Validator::validate($rows), static fn ($i) => $i['field'] === 'size' && $i['level'] === 'error');
        $this->assertEqualsCanonicalizing(['amazon', 'flipkart'], array_column($issues, 'channel'));
    }

    public function testApparelGstWarningAbovePriceBand(): void
    {
        $rows = self::master();
        $rows[3]['mrp_inr'] = '3500';
        $rows[3]['selling_price_inr'] = '3000';
        $gst = array_filter(Validator::validate($rows), static fn ($i) => $i['field'] === 'gst_rate_pct');
        $this->assertCount(1, $gst);
        $this->assertStringContainsString('18%', array_values($gst)[0]['message']);
    }

    public function testAgeSizeParsing(): void
    {
        $this->assertSame(['class' => 'Age', 'from' => '0 Months', 'to' => '3 Months'], AmazonMapper::ageSize('0-3 Months'));
        $this->assertSame(['class' => 'Age', 'from' => '6 Months', 'to' => '12 Months'], AmazonMapper::ageSize('6-12M'));
        $this->assertSame(['class' => 'Age', 'from' => '1 Years', 'to' => '2 Years'], AmazonMapper::ageSize('1-2 Yrs'));
        $this->assertSame(['class' => 'Age', 'from' => 'Newborn', 'to' => ''], AmazonMapper::ageSize('NB'));
        $this->assertNull(AmazonMapper::ageSize('XL'));
    }

    public function testChannelFlagSkipsRows(): void
    {
        $rows = self::master();
        $rows[3]['list_on_flipkart'] = 'N';
        $file = ListAndGo::export($rows, 'flipkart');
        $this->assertNotContains('LL-NS-PNK-6-12M', array_column(self::sheetRows($file->content), 'Seller SKU ID'));
    }

    public function testAmazonExportMatchesTemplateColumns(): void
    {
        $schema = AmazonMapper::schema();
        $tmp = tempnam(sys_get_temp_dir(), 'lag') . '.xlsx';
        file_put_contents($tmp, ListAndGo::export(self::master(), 'amazon')->content);
        $sheet = IOFactory::load($tmp)->getSheetByName('Template');
        unlink($tmp);
        $this->assertSame(array_column($schema['columns'], 'key'), $sheet->rangeToArray('A5:' . $sheet->getHighestColumn() . '5')[0]);
        $this->assertSame('SKU', $sheet->getCell('A4')->getValue());
    }

    public function testAmazonBabyParentAndChildRows(): void
    {
        $bySku = array_column(self::amazonRows(ListAndGo::export(self::master(), 'amazon')->content), null, 'contribution_sku#1.value');
        $parent = $bySku['LL-TEE-HS'];
        $this->assertSame('Parent', $parent['parentage_level#1.value']);
        $this->assertSame('SHIRT', $parent['product_type#1.value']);
        $this->assertSame('SIZE/COLOR', $parent['variation_theme#1.name']);
        $this->assertSame('', $parent['purchasable_offer[audience=ALL]#1.our_price#1.schedule#1.value_with_tax']);

        $child = $bySku['LL-TEE-HS-BLU-0-3M'];
        $this->assertSame('Child', $child['parentage_level#1.value']);
        $this->assertSame('LL-TEE-HS', $child['child_parent_sku_relationship#1.parent_sku']);
        $this->assertSame('Baby Boys', $child['department#1.value']);
        $this->assertSame('Male', $child['target_gender#1.value']);
        $this->assertSame('Baby', $child['age_range_description#1.value']);
        $this->assertSame('Age', $child['shirt_size#1.size_class']);
        $this->assertSame('0 Months', $child['shirt_size#1.size']);
        $this->assertSame('3 Months', $child['shirt_size#1.size_to']);
        $this->assertSame('Blue', $child['color#1.standardized_values#1']);
        $this->assertSame('Sky Blue', $child['color#1.value']);
        $this->assertSame('Crew Neck', $child['neck#1.neck_style#1.value']);
        $this->assertSame('Baby/Infant', $child['compliance_age_range#1.value']);
        $this->assertSame('HSN Code', $child['external_product_information#1.entity']);
        $this->assertSame('6111', $child['external_product_information#1.value']);
        $this->assertSame('A_GEN_SUPERREDUCED', $child['product_tax_code#1.value']);
        $this->assertSame('349', $child['purchasable_offer[audience=ALL]#1.our_price#1.schedule#1.value_with_tax']);
        $this->assertSame('499', $child['purchasable_offer[audience=ALL]#1.maximum_retail_price#1.schedule#1.value_with_tax']);
        $this->assertSame('EAN', $child['amzn1.volt.ca.product_id_type']);
        $this->assertSame('Centimeters', $child['item_package_dimensions#1.length.unit']);

        $nightSuit = $bySku['LL-NS-PNK-6-12M'];
        $this->assertSame('NIGHTGOWN_NIGHTSHIRT', $nightSuit['product_type#1.value']);
        $this->assertSame('Baby Girls', $nightSuit['department#1.value']);
        $this->assertSame('6 Months', $nightSuit['apparel_size#1.size']);
        $this->assertSame('Pajama Set', $nightSuit['item_type_name#1.value']);
        $this->assertSame('', $nightSuit['parentage_level#1.value']);
    }

    public function testFlipkartRowsShareStyleCodeAndConvertUnits(): void
    {
        $out = self::sheetRows(ListAndGo::export(self::master(), 'flipkart')->content);
        $this->assertCount(3, $out, 'variant parent rows are not listed on Flipkart');
        $bySku = array_column($out, null, 'Seller SKU ID');
        $m = $bySku['LL-TEE-HS-BLU-0-3M'];
        $this->assertSame('LL-TEE-HS', $m['Style Code']);
        $this->assertSame('LittleLeaf Baby Boys Cotton Half Sleeve T-Shirt', $m['Model Name']);
        $this->assertSame('0.09', $m['Weight (KG)']);
        $this->assertSame('GST_5', $m['Tax Code']);
        $this->assertSame('Baby Boys', $m['Ideal For']);
        $this->assertSame('0-3 Months', $m['Size']);
        $this->assertSame('Round Neck', $m['Neck']);
        $this->assertSame('LittleLeaf Kids Pvt Ltd, 12 Kumaran Rd, Tiruppur, TN 641601', $m['Manufacturer Details']);
        $this->assertSame('Baby Girls', $bySku['LL-NS-PNK-6-12M']['Ideal For']);
    }

    public function testShopifyGroupsVariantsAndImagesUnderOneHandle(): void
    {
        $out = Csv::read(ListAndGo::export(self::master(), 'shopify')->content);
        $tee = array_values(array_filter($out, static fn ($r) => $r['Handle'] === 'll-tee-hs'));
        $this->assertCount(2, $tee);
        $this->assertSame('Color', $tee[0]['Option1 Name']);
        $this->assertSame('Size', $tee[0]['Option2 Name']);
        $this->assertSame(['0-3 Months', '3-6 Months'], array_column($tee, 'Option2 Value'));
        $this->assertSame('', $tee[1]['Title'], 'only the first line of a product carries the title');
        $this->assertSame(['1', '2'], array_column($tee, 'Image Position'));
        $ns = array_values(array_filter($out, static fn ($r) => $r['Handle'] === 'll-ns-pnk-6-12m'));
        $this->assertSame('IN', $ns[0]['Country of Origin']);
        $this->assertSame('Default Title', $ns[0]['Option1 Value']);
    }

    public function testWooCommerceVariableProduct(): void
    {
        $out = Csv::read(ListAndGo::export(self::master(), 'woocommerce')->content);
        $bySku = array_column($out, null, 'SKU');
        $this->assertSame('variable', $bySku['LL-TEE-HS']['Type']);
        $this->assertSame('0-3 Months, 3-6 Months', $bySku['LL-TEE-HS']['Attribute 2 value(s)']);
        $this->assertSame('variation', $bySku['LL-TEE-HS-BLU-3-6M']['Type']);
        $this->assertSame('LL-TEE-HS', $bySku['LL-TEE-HS-BLU-3-6M']['Parent']);
        $this->assertSame('499', $bySku['LL-TEE-HS-BLU-3-6M']['Regular price']);
        $this->assertSame('349', $bySku['LL-TEE-HS-BLU-3-6M']['Sale price']);
        $this->assertSame('simple', $bySku['LL-NS-PNK-6-12M']['Type']);
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
        $this->assertContains('age_group', $fields);
        $this->assertContains('gst_rate_pct', $fields);
        $this->assertContains('country_of_origin', $fields);
    }

    public function testRejectsUnknownCsv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Loader::load("name,price\nshirt,10\n");
    }
}
