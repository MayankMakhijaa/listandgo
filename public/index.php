<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ListingTool\Columns;
use ListingTool\ListAndGo;

$error = null;
$result = null;
$jobId = null;
$channels = Columns::CHANNELS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channels = array_values(array_intersect(Columns::CHANNELS, (array) ($_POST['channels'] ?? [])));
    $upload = $_FILES['catalog'] ?? null;
    if (!$channels) {
        $error = 'Pick at least one place to list on.';
    } elseif (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Choose a CSV file to upload.';
    } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
        $error = 'The upload failed. Try again with a file under 5 MB.';
    } elseif ($upload['size'] > MAX_UPLOAD_BYTES) {
        $error = 'The file is larger than 5 MB. Split it into smaller files.';
    } else {
        try {
            $result = ListAndGo::check((string) file_get_contents($upload['tmp_name']), $channels);
            $jobId = jobs()->save(['rows' => $result['rows'], 'issues' => $result['issues'], 'channels' => $channels]);
        } catch (InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }
    }
}

$formatNames = ['master' => 'List and Go template', 'shopify' => 'Shopify export', 'woocommerce' => 'WooCommerce export'];
$errorsFor = static function (string $ch) use ($result): int {
    return count(array_filter($result['issues'] ?? [], static fn ($i) => $i['level'] === 'error' && ($i['channel'] === null || $i['channel'] === $ch)));
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <header>
        <h1><?= e(APP_NAME) ?></h1>
        <p class="lead">Upload your products once and download ready-to-upload files for Amazon India, Flipkart, Shopify and WooCommerce.</p>
    </header>

    <section class="card">
        <h2>1. Prepare your file</h2>
        <p>Fill in the List and Go template, or upload a product export straight from Shopify or WooCommerce.</p>
        <p class="links">
            <a href="download.php?template=blank">Download blank template</a>
            <a href="download.php?template=sample">Download filled sample</a>
        </p>
    </section>

    <section class="card">
        <h2>2. Upload and check</h2>
        <form method="post" enctype="multipart/form-data">
            <label class="file">
                <span>Product CSV</span>
                <input type="file" name="catalog" accept=".csv,text/csv" required>
            </label>
            <fieldset>
                <legend>List on</legend>
                <?php foreach (Columns::CHANNEL_LABELS as $key => $label): ?>
                    <label class="check">
                        <input type="checkbox" name="channels[]" value="<?= e($key) ?>" <?= in_array($key, $channels, true) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit">Check my products</button>
        </form>
        <?php if ($error): ?>
            <p class="alert error"><?= e($error) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($result): ?>
        <section class="card">
            <h2>3. Download</h2>
            <p>
                Read <?= count($result['rows']) ?> rows from a <?= e($formatNames[$result['format']] ?? $result['format']) ?>.
                <?= $result['summary']['errors'] ?> errors, <?= $result['summary']['warnings'] ?> warnings.
            </p>
            <div class="downloads">
                <?php foreach ($channels as $ch): $n = $errorsFor($ch); ?>
                    <?php if ($n === 0): ?>
                        <a class="download" href="download.php?job=<?= e($jobId) ?>&amp;channel=<?= e($ch) ?>"><?= e(Columns::CHANNEL_LABELS[$ch]) ?> file</a>
                    <?php else: ?>
                        <span class="download disabled" title="Fix the errors below first"><?= e(Columns::CHANNEL_LABELS[$ch]) ?>: <?= $n ?> errors</span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($result['format'] !== 'master'): ?>
                <p class="hint">Missing details such as HSN, GST and MRP? <a href="download.php?job=<?= e($jobId) ?>&amp;channel=master">Download your products as a List and Go template</a>, fill them in and upload it again.</p>
            <?php endif; ?>
            <p class="hint">Amazon and Flipkart files open in Excel. Copy their rows into the category template from Seller Central or Seller Hub; each file has a "Read me" sheet with the steps.</p>

            <?php if ($result['issues']): ?>
                <h3>What to fix</h3>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Row</th><th>SKU</th><th>Where</th><th>Field</th><th>Problem</th></tr></thead>
                        <tbody>
                        <?php foreach ($result['issues'] as $i): ?>
                            <tr class="<?= e($i['level']) ?>">
                                <td><?= (int) $i['row'] ?></td>
                                <td><?= e($i['sku']) ?></td>
                                <td><?= e($i['channel'] ? Columns::CHANNEL_LABELS[$i['channel']] : 'All') ?></td>
                                <td><code><?= e($i['field']) ?></code></td>
                                <td><span class="tag"><?= e($i['level']) ?></span> <?= e($i['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
