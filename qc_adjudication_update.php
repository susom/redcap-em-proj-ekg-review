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

// Step 2 - make a map array that has object_name => version => [ $record data ]
$map = [];
foreach ($records as $record) {
    $object_name    = $record['object_name'];
    $object_version = $record['object_version'];

    // Make an array for each object to hold versions and records
    if (!isset($map[$object_name])) $map[$object_name] = [];

    // Add the record under object -> version -> records
    $map[$object_name][$object_version] = $record;
}
$debug[] = "Analyzing " . count($map) . " objects...";
$module->emDebug("Made map of " . count($map) . " objects");
//- here are two: ", array_slice($map,0,2));


//// Step 3 - prune the map to remove any object with only one version
//foreach ($map as $object_name => $versions) {
//    if (count($versions) == 1) unset($map[$object_name]);
//}
//$debug[] = "After filtering singletons, " . count($map) . " objects with more than one version remain...";


// Step 4 - do some version comparisons
foreach ($map as $object_name => $versions) {

    $module->emDebug("In $object_name with versions: " . json_encode(array_keys($versions)));

    if (isset($versions[1]) && isset($versions[99])) {
        // we have a QC check
        $result = $module->updateDifferences($versions[1], $versions[99], "qc");
        if ($result !== false) $debug[] = "QC comparison\t$object_name\t" . $result;
    }

    if (isset($versions[1]) && isset($versions[2])) {
        // we have a adjudication check
        $result = $module->updateDifferences($versions[1], $versions[2], "adjudication");
        if ($result !== false) $debug[] = "Adjudication\t$object_name\t" . $result;
    }

    if (isset($versions[3]) && isset($versions[1]) && isset($versions[2])) {
        // We have a tie breaker record
        // INCOMPLETE
        $result = $module->doTieBreaker($versions);


    }

}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


if (count($debug) == 1) $debug[] = "No changes were detected.";

// DEBUG
if (!empty($debug)) {
    ?>
    <hr>
    <h4>QC and Adjudication Update <span class='badge badge-secondary'><?php echo count($debug) ?></span>
        <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseDebug" aria-expanded="false" aria-controls="collapseDebug">
            Toggle Results
        </button>
    </h4>
    <div class="collapse show" id="collapseDebug">
        <pre><?php echo implode("\n", $debug) ?></pre>
    </div>
    <?php
}

