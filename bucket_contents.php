<?php
/** @var \Stanford\EkgReview\EkgReview $module */


// Display records in bucket
$contents = $module->getBucketContents(["prefix" => "adjudication/"]);
echo "<pre>" . implode("\n", $contents) . "</pre>";
