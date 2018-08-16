<?php

/** @var \Stanford\EkgReview\EkgReview $module */

// API for pulling files
if (isset($_POST['file'])) {
    $result = $module->getBucketFile($_POST['file']);

    if ($result === false) {
        // There was an error
        $module->emError("There was an error getting " . $_POST['file']);
    } else {
        $module->emDebug("Downloading " . $_POST['file']);
        //TODO: Set Headers
        echo $result;
    }
}
