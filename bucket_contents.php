<?php
/** @var \Stanford\EkgReview\EkgReview $module */


require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Display records in bucket
$contents = $module->getBucketContents(["prefix" => "adjudication/"]);

$bucket_name = $module->getProjectSetting('gcp-bucket-name');

// DEBUG
?>
<hr>
<h4>BUCKET CONTENTS (<?php echo $bucket_name?>) <span class='badge badge-secondary'><?php echo count($contents) ?></span>
    <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseDebug" aria-expanded="false" aria-controls="collapseDebug">
        Toggle Contents
    </button>
</h4>
<div class="collapse show" id="collapseDebug">
    <pre><?echo implode("\n", $contents) ?></pre>
</div>
