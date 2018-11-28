<?php
/** @var \Stanford\EkgReview\EkgReview $module */

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$raw_bucket_prefix = isset($_POST['bucket_prefix']) ? $_POST['bucket_prefix'] : "";
$bucket_prefix = mb_ereg_replace("([^\w\s\d\-_~,;\/\[\]\(\).])", '', $raw_bucket_prefix);
if (strlen($bucket_prefix) > 0 && substr($bucket_prefix, -1) != "/") $bucket_prefix .= "/";

$bucket_name = $module->getProjectSetting('gcp-bucket-name');

?>

    <h4>GCP Bucket for serving images: <?php echo $bucket_name ?></h4>
    <form method="post">
        <div class="input-group">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fab fa-bitbucket mr-2"></i> Bucket folder containing files: </span>
                <input type="text" class="form-control" placeholder="e.g. group1"
                       name="bucket_prefix" value="<?php echo $bucket_prefix ?>" aria-describedby="bucket_prefix_label">
                <span class="input-group-btn">
                <button type="submit" name="action" value="set" class="btn btn-primaryrc">Scan</button>
            </span>
            </div>
        </div>

    </form>

<?php


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Display records in bucket
    $contents = $module->getBucketContents(["prefix" => $bucket_prefix]);

    ?>
    <hr>
    <h4><?php echo $bucket_prefix ?> Contents: <span class='badge badge-secondary'><?php echo count($contents) ?></span> files
        <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseContents" aria-expanded="false" aria-controls="collapseContents">
            Toggle Contents
        </button>
    </h4>
    <div class="collapse show" id="collapseContents">
        <pre><?php echo implode("\n", $contents) ?></pre>
    </div>
    <?php

    REDCap::logEvent("EKG Bucket Contents", "Found " . count($contents) . " files in $bucket_prefix folder of $bucket_name");
}


