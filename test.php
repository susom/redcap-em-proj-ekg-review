<?php
/** @var \Stanford\EkgReview\EkgReview $module */



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

$debug = [];
$alert = [];


$reviewers                                  = [
    "one",
    "two",
    "three",
    "four",
    "five",
    "six",
    "seven",
    "eight",
    "nine",
    "ten",
    "eleven",
    "twelve"
];
$initial_allocation_per_reviewer            = 800;   // Number of records to allocate to each reviewer (before QC)
$max_initial_allocation_per_version         = 400;    // Max number of records for any given version to be allocated ( use 1/2 of $initial_allocation for 2 versions)
$initial_qc_per_reviewer                    = 80;    // Number of version 1 records to QC per reviewer
$object_start                               = 1; // Just a starting number - could be anything
$object_count                               = 6000;   // How many objects to make for this dataset
$versions                                   = range(1,2);

// Generate objects
$objects = range($object_start, $object_start+$object_count-1);


$random_objects = array();
foreach ($objects as $object) {
    foreach ($versions as $version) {
        $random_objects[] = array('object_name' => $object, 'object_version' => $version);
    }
}


$debug[] = "Reviewers: " . implode(",", $reviewers);
$debug[] = "Objects: " . count($random_objects) . " total objects => " . count($objects) . " * " . count($versions) . " versions";

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

$debug[] = "Results After fixing order";
$debug[] = $results;








// Output to CSV format

$i = 0;
$rows= [];
foreach ($results as $reviewer => $data) {
    foreach ($data['records'] as $object) {
        $i++;
        list($object_name, $object_version) = getNameVersion($object);
        $rows[] = array(
            'record_id' => $i,
            'reviewer' => $reviewer,
            'object_name' => $object_name,
            'object_version' => $object_version
        );
    }
}
foreach ($unassigned['records'] as $object) {
    $i++;
    list($object_name, $object_version) = getNameVersion($object);
    $rows[] = array(
        'record_id' => $i,
        'reviewer' => '',
        'object_name' => $object_name,
        'object_version' => $object_version
    );
}

$HtmlPage = new HtmlPage();
$HtmlPage->PrintHeaderExt();

echo "<h4>CSV for Import <span class='badge badge-primary'>" . count($rows) . " rows</span></h4>";
echo "<pre>" . arrayToCsv($rows, true) . "</pre>";


if (!empty($_GET['debug'])) {

    echo "<hr><h4>Debug</h4><pre>" . print_r($debug,true) . "</pre>";

}


exit();



# Includes the autoloader for libraries installed with composer
require $module->getModulePath() . 'vendor/autoload.php';

# Imports the Google Cloud client library
use Google\Cloud\Storage\StorageClient;


//
//echo "TEST";
//exit();
//
//
//echo "<pre>";
//
//echo "File Path: " . $module->getUrl("file.php", false, false);
//

# Load KeyFile from Textarea input
$keyFileJson = $module->getProjectSetting("gcp-service-account-json");
$keyFile = json_decode($keyFileJson,true);

//echo $keyFileJson;
//exit();


//# Get the keyfile
//$edoc_id = $module->getProjectSetting("gcp-service-account-json-file");
//if (empty($edoc_id)) {
//    $module->emError("Unable to load required JSON file");
//    exit();
//}
//$path = \Files::copyEdocToTemp($edoc_id);
//$module->emDebug("Path", $path);
//
//$keyFile = json_decode(file_get_contents($path), true);
//$module->emDebug($keyFile);


# Instantiates a client
$storage = new StorageClient([
    'keyFile' => $keyFile
//    'keyFilePath' => $path
]);


# The name of a bucket
//$bucketName = 'qsu-uploads-dev/adjudication';
$bucketName = $module->getProjectSetting('gcp-bucket-name');

echo "<pre>";

echo "\n Bucket: " . $bucketName;
echo "\n Key: " . implode(",", array_keys($keyFile));


//exit($bucketName);


# Get the bucket
$bucket = $storage->bucket($bucketName);

//$objects = $bucket->objects();

$objects = $bucket->objects([
    "prefix" => "adjudication/"
]);

//echo "\n Object Count: " . count($objects);
//exit();

//echo "<h3>$bucketName objects</h3>
//<div class='jumbo'>";
foreach ($bucket->objects() as $obj) {
    echo "\n" . $obj->name;
}
//echo "</div>";
exit();






$object = $bucket->object("adjudication/a123456783.csv");

$contents = $object->downloadAsString();

echo $contents;
file_put_contents("example.csv", $contents);




foreach ($objects as $object) {
    echo "\n" . $object->name();
    $module->emDebug( $object->name());

//    if ($object->name() == 'dropbox/biot-v1/data/zs5fq-nqwm-ctk9z2_8678361c-0d81-43a3-bdc3-33b2450e5f84.xml') {
//
//        $module->emDebug("MATCH");
//        $module->emDebug("CONTENTS", $object->downloadAsString());

//    }


}
//










//$module->emDebug($bucket->info());
