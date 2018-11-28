<?php
/** @var \Stanford\EkgReview\EkgReview $module */

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


?>

<style>
    .col-form-label {
        font-size: 14pt;
    }
</style>

<?php

$bucket_name = $module->getProjectSetting('gcp-bucket-name');

$raw_bucket_prefix = isset($_POST['bucket_prefix']) ? $_POST['bucket_prefix'] : "";
$bucket_prefix = mb_ereg_replace("([^\w\s\d\-_~,;\/\[\]\(\).])", '', $raw_bucket_prefix);
if (strlen($bucket_prefix) > 0 && substr($bucket_prefix, -1) != "/") $bucket_prefix .= "/";

?>
    <h4>Recordset Creator: <?php echo $bucket_name . " " . $bucket_prefix ?></h4>
<?php

// We have a bucket prefix - get contents
$bucket_contents = $module->getBucketContents(["prefix" => $bucket_prefix]);


// Parse post if available
$f_reviewers = !empty($_POST['f_reviewers']) ? $_POST['f_reviewers'] : "a,b,c,d,e,f,g,h,i,j,k,l";
$f_versions = !empty($_POST['f_versions']) ? $_POST['f_versions'] : "1,2";
$f_iapr = !empty($_POST['f_iapr']) ? intval($_POST['f_iapr']) : 800;
$f_iqcpr = !empty($_POST['f_iqcpr']) ? intval($_POST['f_iqcpr']) : 80;
$f_mapr = !empty($_POST['f_mapr']) ? $_POST['f_mapr'] : 400;
$f_oc = !empty($_POST['f_oc']) ? $_POST['f_oc'] : count($bucket_contents);

// Render input form
?>

<form method="post">

    <div class="form-group row">
        <label class="col-sm-2 col-form-label" for="f_oc"><i class="fab fa-bitbucket mr-2"></i> Bucket Folder</label>
        <div class="input-group-prepend col-sm-10">
            <input type="text" class="form-control" id="bucket_prefix" name="bucket_prefix" placeholder="Name of folder in the bucket to scan"
                   value="<?php echo $bucket_prefix ?>">
            <span class="input-group-btn">
                <button type="submit" name="scan" value="scan" class="btn btn-success">Scan</button>
            </span>
        </div>
    </div>

    <?php if (!empty($bucket_prefix)) { ?>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_oc">CSV Objects</label>
            <div class="col-sm-10">
                <input type="number" class="form-control" id="f_oc" name="f_oc" placeholder=""
                       value="<?php echo $f_oc ?>" max="<?php echo count($bucket_contents) ?>">
                <small class="form-text text-muted">Number of EKG objects to use for this data set.  Defaults to count in bucket and can't be more than bucket count of <?php echo count($bucket_contents) ?></small>
            </div>
        </div>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_reviewers">Reviewers</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" id="f_reviewers" name="f_reviewers" placeholder="a,b,c,d,..."
                       value="<?php echo $f_reviewers ?>">
                <small class="form-text text-muted">Enter DAGs as comma-separated list: e.g. a,b,c,d</small>
            </div>
        </div>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_versions">Versions</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" id="f_versions" name="f_versions" placeholder="1,2"
                       value="<?php echo $f_versions ?>">
                <small class="form-text text-muted">Enter versions to create for each file as a comma-separated list: e.g. 1,2</small>
            </div>
        </div>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_iapr">Initial Allocation</label>
            <div class="col-sm-10">
                <input type="number" class="form-control" id="f_iapr" name="f_iapr" placeholder=""
                       value="<?php echo $f_iapr ?>">
                <small class="form-text text-muted">Number of records to initially allocate to each reviewer (remainder, if any, will be unallocated)</small>
            </div>
        </div>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_mapr">Maximum Version 1 Records</label>
            <div class="col-sm-10">
                <input type="number" class="form-control" id="f_mapr" name="f_mapr" placeholder=""
                       value="<?php echo $f_mapr ?>">
                <small class="form-text text-muted">Maximum number of version 1 objects to initially assign to a reviewer. This is used to ensure equal adjudication across users.  Recommended value is Initial Allocation / # versions</small>
            </div>
        </div>

        <div class="form-group row pt-2">
            <label class="col-sm-2 col-form-label" for="f_iqcpr">Initial QC Repeats</label>
            <div class="col-sm-10">
                <input type="number" class="form-control" id="f_iqcpr" name="f_iqcpr" placeholder=""
                       value="<?php echo $f_iqcpr ?>">
                <small class="form-text text-muted">Number of EKGs to repeat from initial allocation of version 1 records for the SAME reviewer (i.e. versions 99s).  Cannot be more than Max Ver 1 records.</small>
            </div>
        </div>

        <button type="submit" name="generate" value="generate" class="w-100 btn btn-primaryrc">Generate</button>

    <?php } ?>

</form>

<?php

// Quit if nothing else to do.
if (empty($_POST['generate'])) exit();

$debug   = [];
$alert   = [];
$missing = [];

$reviewers = array_map("trim", explode(",", $f_reviewers));
$versions = array_map("intval", explode(",", $f_versions));
$object_count = $f_oc;

$initial_allocation_per_reviewer = $f_iapr;
$initial_qc_per_reviewer = $f_iqcpr;
$max_initial_allocation_per_version = $f_mapr;

if (empty($reviewers)) $alert[] = "Missing required reviewers";
if (empty($versions)) $alert[] = "Missing required versions";


    //$initial_allocation_per_reviewer            = 800;   // Number of records to allocate to each reviewer (before QC)
    //$max_initial_allocation_per_version         = 400;    // Max number of records for any given version to be allocated ( use 1/2 of $initial_allocation for 2 versions)
    //$initial_qc_per_reviewer                    = 80;    // Number of version 1 records to QC per reviewer
    //$object_start                               = 1; // Just a starting number - could be anything
    //$object_count                               = 6000;   // How many objects to make for this dataset
    //$versions                                   = range(1,2);


    //$versions                                   = range(1,2);
    //$initial_allocation_per_reviewer            = 600;   // Number of records to allocate to each reviewer (before QC)
    //$max_initial_allocation_per_version         = 300;    // Max number of records for any given version to be allocated ( use 1/2 of $initial_allocation for 2 versions)
    //$initial_qc_per_reviewer                    = 60;    // Number of version 1 records to QC per reviewer
    ////$object_start                               = 1000; // Just a starting number - could be anything
    //$object_count                               = 10000;   // How many different objects to use (will end up record count =  $versions * $object_count + QC


if (isset($_GET['test'])) {
    $reviewers                                  = ["a", "b", "c"];
    $versions                                   = range(1,2);
    $initial_allocation_per_reviewer            = 6;   // Number of records to allocate to each reviewer (before QC)
    $initial_qc_per_reviewer                    = 2;    // Number of version 1 records to QC per reviewer
    $max_initial_allocation_per_version         = floor($initial_qc_per_reviewer/ count($versions));    // Max number of records for any given version to be allocated ( use 1/2 of $initial_allocation for 2 versions)
    //$object_start                               = 1000; // Just a starting number - could be anything
    $object_count                               = 15;   // How many different objects to use (will end up record count =  $versions * $object_count + QC
}



// Generate objects from GCP bucket
$object_names =  array_slice( $bucket_contents, 0, min($object_count, count($bucket_contents)) );

if (count($bucket_contents) > $object_count) {
    $alert[] = "You are taking a subset of the total bucket objects (" . count($bucket_contents) . ") because your object_count setting is less (" . $object_count . ")";
} else {
    $debug[] = "Using all " . count($bucket_contents) . " csv objects from GCP bucket";
}

// Convert object names into something with name and version
$random_objects = array();
foreach ($object_names as $object_name) {

    if (!in_array($object_name, $bucket_contents)) {
        array_push($missing, "Object '$object_name' is not present in gcp bucket");
    }

    foreach ($versions as $object_version) {
        $random_objects[] = array('object_name' => $object_name, 'object_version' => $object_version);
    }
}


$debug[] = "Reviewers: " . implode(",", $reviewers);
$debug[] = "Objects: " . count($random_objects) . " records = " . count($object_names) . " objects x " . count($versions) . " versions";

// Randomize Objects
shuffle($random_objects);
$debug[] = "Objects Randomized";

/*
//FORMAT
[
   "one" => [
        "records" => [],
        "object_names" => [],
        "version_counts" => [
            "1" => 0,
            "2" => 0
        ]
    ],
    ...
*/


$results = [];
$version_counts = [];
foreach ($versions as $version) {
    $version_counts[$version] = 0;
}

// Prepare destination structure
foreach ($reviewers as $reviewer) {
    $results[$reviewer] = [
        "records"        => [],
        "object_names"   => [],
        "version_counts" => $version_counts
    ];
}

// Add group for unassigned records
$unassigned = [
    "records"        => [],
    "object_names"   => [],
    "version_counts" => $version_counts
];


// Loop through records and assign to a group
foreach ($random_objects as $object) {
    list($object_name, $object_version) = getNameVersion($object);

    $assigned = false;

    // Try to assign to a reviewer
    foreach ($reviewers as $reviewer) {

        if (count($results[$reviewer]["object_names"]) >= $initial_allocation_per_reviewer) {
            //$debug[] = "$reviewer full";
            continue;   // Next Reviewer
        }

        if (in_array($object_name, $results[$reviewer]["object_names"])) {
            //$debug[] = $object_name . " already in $reviewer bin";
            continue;   // Next Reviewer
        }

        if ($results[$reviewer]["version_counts"][$object_version] >= $max_initial_allocation_per_version) {
            //$debug[] = "$reviewer has maximum number of $object_version records";
            continue;   // Next Reviewer
        }

        array_push($results[$reviewer]["records"], $object);
        array_push($results[$reviewer]["object_names"], $object_name);
        $results[$reviewer]["version_counts"][$object_version]++;
        //$debug[] = "$object_name : $object_version added to $reviewer bin";
        // Stop the foreach loop
        $assigned = true;
        break;
    }

    // Add unassigned records to unassigned bin
    if ($assigned == false) {
        // Assign to 'unused' group
        array_push($unassigned["records"], $object);
        array_push($unassigned["object_names"], $object_name);
        $unassigned["version_counts"][$object_version]++;
        //$debug[] = "$object_name : $object_version added to $reviewer bin";
    }




}

//$debug[] = "Final Bins";
//$debug[] = $results;


//// Add QC Records
foreach ($results as $reviewer => $data) {
    $v1_objects = array();

    // Get all of the version 1 records per reviewer
    foreach ($data['records'] as $object) {
        if ($object['object_version'] == 1) $v1_objects[] = $object;
    }

    // slice max of count and $initial_qc_per_reviewer and duplicate as object 99.
    $v1_qc = array_slice($v1_objects, 0, min($initial_qc_per_reviewer, count($v1_objects)));

    foreach ($v1_qc as $object) {
        $object['object_version'] = 99;
        array_push($results[$reviewer]["records"], $object);
    }
    $results[$reviewer]["version_counts"]["99"] = count($v1_qc);

    // Shuffle records for each reviewer
    shuffle($results[$reviewer]["records"]);

    $debug[] = "Adding " . count($v1_qc) . " objects and randomizing to $reviewer";
}

//$debug[] = "Results 1";
//$debug[] = $results;




// Make sure version 1 comes before version 99 for QC runs - the alternative is to put all the '99's at the end
// but this means we won't get any internal qc until people finish their initial batch of records
foreach ($results as $reviewer => $data) {
    $object_names = [];
    $object_versions = [];
    // Get all of the version 1 records per reviewer
    foreach ($data['records'] as $object) {
        array_push($object_names, $object['object_name']);
        array_push($object_versions, $object['object_version']);
    }

    //$debug[] = "$reviewer long arrays";
    //$debug[] = $object_names;
    //$debug[] = $object_versions;

    for($i = 0; $i < (count($object_versions) - 1); $i++) {

        // if version is 99, then search to see where the partner is
        if ($object_versions[$i] == '99') {
            // Look at the later elements in the array
            for ($j = $i + 1; $j < count($object_versions); $j++) {
                if ($object_names[$j] == $object_names[$i] && $object_versions[$j] == "1") {
                    $object_versions[$j] = "99";
                    $object_versions[$i] = "1";
                    $debug[] = "Swapping order of reviewer $reviewer's elements $i and $j for $object_names[$i] so 1 is first";
                    break;
                }
            }
        }

        // Make sure we don't have two of the same objects in a row for a reviewer
        if ($object_names[$i] == $object_names[$i+1]) {
            // We have two in a row
            $alert[] = "Two entries in a row for $reviewer: objects $i and " . ($i+1) . " are the same object " . $object_names[$i] . " -- consider manually reordering";
        }


    }

    // Rebuild the results array
    $results[$reviewer]['records'] = [];
    while (!empty($object_names)) {
        array_push($results[$reviewer]['records'], array(
            'object_name' => array_shift($object_names),
            'object_version' => array_shift($object_versions)
        ));
    }
}

//$debug[] = "Results After fixing order";
//$debug[] = $results;








// Output to CSV format

$i = 0;
$rows= [];
foreach ($results as $reviewer => $data) {
    foreach ($data['records'] as $object) {
        $i++;
        list($object_name, $object_version) = getNameVersion($object);
        $rows[] = array(
            'record_id'                 => $i,
            'redcap_data_access_group'  => $reviewer,
            'object_name'               => $object_name,
            'object_version'            => $object_version
        );
    }
}
$total_assigned = $i;
foreach ($unassigned['records'] as $object) {
    $i++;
    list($object_name, $object_version) = getNameVersion($object);
    $rows[] = array(
        'record_id' => $i,
        'redcap_data_access_group' => '',
        'object_name' => $object_name,
        'object_version' => $object_version
    );
}


$summary = [];
$summary[] = "Bucket objects in " . $bucket_prefix . " of " . $bucket_name . " = " . count($bucket_contents);
$summary[] = "Reviewers (" . count($reviewers) . ") = " . implode(",", $reviewers);
$summary[] = "Versions (" . count($versions) . ") = " . implode(",", $versions);
$summary[] = "Bucket objects used = " . $object_count;
$summary[] = "Initial records created (i.e. object-versions) = " . count($random_objects);
$summary[] = "Initial QC per reviewer = " . $initial_qc_per_reviewer;
$summary[] = "Total records created = " . count($rows);
$summary[] = "Initial allocation per reviewer = " . $initial_allocation_per_reviewer;
$summary[] = "Max initial allocations of version 1 per reviewer = " . $max_initial_allocation_per_version;
$summary[] = "Number of records assigned = " . $total_assigned;
$summary[] = "Number of records unassigned = " . count($unassigned['records']);


REDCap::logEvent("EKG Recordset Creator Run", implode("\n", $summary));

?>
    <hr>
    <h4>Results Summary:</h4>
    <pre><?php echo implode("\n", $summary) ?></pre>
    <hr>
<?php

    // ALERTS
    if (!empty($alert)) {
        ?>
        <hr>
        <h5>Alerts <span class='badge badge-secondary'><?php echo count($alert) ?></span>
            <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseAlert" aria-expanded="false" aria-controls="collapseAlert">
                Toggle Alerts
            </button>
        </h5>
        <div class="collapse show" id="collapseAlert">
            <pre><?php echo print_r($alert,true) ?></pre>
        </div>
        <?php
    }


    // MISSING
    if (!empty($missing)) {
        ?>
        <hr>
        <h5>Missing Bucket Items <span class='badge badge-secondary'><?php echo count($missing) ?></span>
            <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseMissing" aria-expanded="false" aria-controls="collapseMissing">
                Toggle Missing Bucket Items
            </button>
        </h5>
        <div class="collapse show" id="collapseMissing">
            <pre><?php echo print_r($missing,true) ?></pre>
        </div>
        <?php
    }


    // CSV
    if (!empty($rows)) {
        ?>
        <h5>CSV For Import <span class='badge badge-secondary'><?php echo count($rows) ?> rows </span>
            <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseCSV" aria-expanded="false" aria-controls="collapseCSV">
                Toggle CSV
            </button>
        </h5>
        <div class="collapse" id="collapseCSV">
            <pre><?php echo arrayToCsv($rows, true) ?></pre>
        </div>
        <?php
    }



    // BUCKET CONTENTS
    if (!empty($bucket_contents)) {
        ?>
        <hr>
        <h5>Bucket Items <span class='badge badge-secondary'><?php echo count($bucket_contents) ?></span> files
            <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseContents" aria-expanded="false" aria-controls="collapseContents">
                Toggle Contents
            </button>
        </h5>
        <div class="collapse" id="collapseContents">
            <pre><?php echo implode("\n", $bucket_contents) ?></pre>
        </div>
        <?php
    }


    // DEBUG
    if (!empty($debug)) {
        ?>

        <hr>
        <h5>Debug <span class='badge badge-secondary'><?php echo count($debug) ?></span>
            <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseDebug" aria-expanded="false" aria-controls="collapseDebug">
                Show Debug
            </button>
        </h5>
        <div class="collapse" id="collapseDebug">
            <pre><?php echo print_r($debug,true) ?></pre>
        </div>
        <?php
    }

    ?>
</div>




<?php


function getNameVersion($object) {
    return array($object['object_name'], $object['object_version']);
}

function arraySwapAssoc($key1, $key2, $array) {
    $newArray = array ();
    foreach ($array as $key => $value) {
        if ($key == $key1) {
            $newArray[$key2] = $array[$key2];
        } elseif ($key == $key2) {
            $newArray[$key1] = $array[$key1];
        } else {
            $newArray[$key] = $value;
        }
    }
    return $newArray;
}

function convertArrayToCsv($array) {
    $csv = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'r+');
    fputcsv($csv, $array);
    rewind($csv);

    // put it all in a variable
    return stream_get_contents($csv);
}

