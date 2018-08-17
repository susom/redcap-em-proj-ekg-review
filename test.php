<?php
/** @var \Stanford\EkgReview\EkgReview $module */




echo "<pre>";

echo "File Path: " . $module->getUrl("file.php", false, false);


//$module->getBucketFile("adjudication/test.txt");




# Includes the autoloader for libraries installed with composer
require $module->getModulePath() . 'vendor/autoload.php';

# Imports the Google Cloud client library
use Google\Cloud\Storage\StorageClient;

# Load KeyFile from Textarea input
$keyFileJson = $module->getProjectSetting("gcp-service-account-json");
$keyFile = json_decode($keyFileJson,true);



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
$bucketName = 'qsu-uploads-dev';

# Get the bucket
$bucket = $storage->bucket($bucketName);

echo "\nGot Bucket: " . $bucket->name();



$objects = $bucket->objects([
    "prefix" => "adjudication/"
]);


$object = $bucket->object("adjudication/a123456783.csv");

$contents = $object->downloadAsString();

echo $contents;
file_put_contents("example.csv", $contents);

exit();



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
