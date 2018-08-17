<?php

/** @var \Stanford\EkgReview\EkgReview $module */


$contents = file_get_contents($module->getModulePath()."example.csv");

$data = csvToArray($contents);

$module->emDebug($data);
exit();


header("Content-type: text/csv");
header("Content-Disposition: attachment; filename=".$id.".csv");
header("Pragma: no-cache");
header("Expires: 0");
echo $contents;
exit();


$id = empty($_GET['id']) ? NULL : $_GET['id'];

if (empty($id)) {
    $module->emDebug("Invalid ID:",$_GET);
    exit();
}

// Use the ID to find the actual file name
$q = REDCap::getData('json', array($id), array('object_name', 'ekg_review_complete'));
$results = json_decode($q,true);
$result = $results[0];

if ($result['ekg_review_complete'] == 2) {
    $module->emDebug("The requested record is already complete - no need to re-render");
    exit();
}

$object_name = $result['object_name'];
if (empty($object_name)) {
    // There is no object
    $module->emDebug("There is no object_name for record $id");
    exit ();
}

$module->emDebug("Getting bucket file " . $object_name);
$result = $module->getBucketFile($object_name);

if ($result === false) {
    // There was an error
    $module->emError("There was an error getting " . $object_name);
} else {
    $module->emDebug("Found: " . substr($result,0,100) . "...");
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename=".$id.".csv");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo $result;
}







//$module->emDebug("File called with id: " . $_GET['id']);




exit();


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
