<?php
/** @var \Stanford\EkgReview\EkgReview $module */


require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Display records in bucket
$contents = $module->getBucketContents(["prefix" => "adjudication/"]);

$bucket_name = $module->getProjectSetting('gcp-bucket-name');

// DEBUG
?>
<hr>
<h4>Bucket Contents (<?php echo $bucket_name?>) <span class='badge badge-secondary'><?php echo count($contents) ?></span>
    <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseContents" aria-expanded="false" aria-controls="collapseContents">
        Toggle Contents
    </button>
</h4>
<div class="collapse show" id="collapseContents">
    <pre><?php echo implode("\n", $contents) ?></pre>
</div>
