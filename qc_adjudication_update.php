<?php
/** @var \Stanford\EkgReview\EkgReview $module */

/**
 * The purpose of this page is to go through records to update QC / Adjudication settings
 */

// Make a debug array
$debug = [];

// Step 1 - load all records
$records = $module->getRecords();

$module->emDebug("Found " . count($records) . " records");

// Step 2 - make a map array that has object_name => version => [ $records ]
$map = [];
foreach ($records as $record) {
    $object_name    = $record['object_name'];
    $object_version = $record['object_version'];

    // Make an array for each object to hold versions and records
    if (!isset($map[$object_name])) $map[$object_name] = [];

    // Add the record under object -> version -> records
    $map[$object_name][$object_version] = $record;
}
$debug[] = "Found " . count($map) . " objects...";

// Step 3 - prune the map to remove any object with only one version
foreach ($map as $object_name => $versions) {
    if (count($versions) == 1) unset($map[$object_name]);
}
$debug[] = "After filtering singletons, " . count($map) . " objects with more than one version remain...";


// Step 4 - do some version comparisons
foreach ($map as $object_name => $versions) {

    if (isset($versions[99]) && isset($versions[1])) {
        // we have a QC check
        $result = $module->updateDifferences($versions[1], $versions[99], "qc");
        if ($result) $debug[] = "QC Comparison:\t$object_name\t" . $result;
    }

    if (isset($versions[1]) && isset($versions[2])) {
        // we have a adjudication check
        $result = $module->updateDifferences($versions[1], $versions[2], "adjudication");
        if ($result) $debug[] = "Adjudication:\t$object_name\t" . $result;
    }
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


// DEBUG
if (!empty($debug)) {
    ?>
    <hr>
    <h4>Debug <span class='badge badge-secondary'><?php echo count($debug) ?></span>
        <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseDebug" aria-expanded="false" aria-controls="collapseDebug">
            Toggle Debug
        </button>
    </h4>
    <div class="collapse show" id="collapseDebug">
        <pre><?php echo implode("\n", $debug) ?></pre>
    </div>
    <?php
}

