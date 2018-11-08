<?php
namespace Stanford\EkgReview;

use \REDCap;
use \Project;

# Imports the Google Cloud client library
use Google\Cloud\Storage\StorageClient;
include_once "emLoggerTrait.php";


class EkgReview extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public $group_id = null;
    public $totalCount, $totalComplete, $totalPercent;
    public $totalCountDag, $totalCompleteDag, $totalPercentDag;


    public $unassignedRecords;      // Array of all unassigned records
    public $availableRecords;       // Array of records specifically avaialble for next batch for current DAG user

    function __construct($project_id = null)
    {
        parent::__construct();
        $this->ts_start = microtime(true);
        //if (!empty($project_id)) {}
    }


    function isDagUser() {
        global $project_id;

        if (empty($project_id)) {
            $this->emDebug("project_id is not defined on page " . PAGE);
        } elseif (empty(USERID)) {
            $this->emDebug("USERID is not defined on page " . PAGE);
        } elseif (empty($this->group_id)) {
            // Try to get the group id

            // Get User Rights for User
            $result = REDCap::getUserRights(USERID);
            $user_rights = isset($result[USERID]) ? $result[USERID] : NULL;

            // Get Group ID
            $group_id = $user_rights['group_id'];
            $this->emDebug(USERID . " is in DAG " . $group_id);
            $this->group_id = $group_id;
        }
        return !empty($this->group_id);
    }


    /**
     * This loop does two things at once:
     * - First, it determines which records have not been assigned
     * - Second, it determines assigned count per group for progress bars
     */
    function doRecordAnalysis() {

        // Get all records that are not assigned to a DAG
        //$logic = "[ekg_review_complete] <> '2'";
        $logic = NULL;
        $result = REDCap::getData('json', null, array('record_id', 'object_name', 'object_version', 'ekg_review_complete'), null, null, false, true, false, $logic);
        $records = json_decode($result,true);
        
        $unassigned_objects = array();      // An array of records with a key of the object_name
        $available = array();
        $assigned_objects = array();        // An array of object_names that have been assigned

        $total = 0;
        $completed = 0;

        $user_dag_name = REDCap::getGroupNames(true, $this->group_id);
        $total_in_dag = 0;
        $completed_in_dag=0;

        // Loop one, process the records and build an array of records grouped by object_name that are not yet assigned.
        // Also get a list of all objects assigned to the current DAG
        foreach ($records as $record) {
            $dag         = $record['redcap_data_access_group'];
            $object_name = $record['object_name'];
            $form_status = $record['ekg_review_complete'];

            // Increment counters
            $total++;
            if ($form_status == 2) $completed++;

            if (empty($dag)) {
                // If not in DAG, add to 'unassigned' objects array
                if (!isset($unassigned_objects[$object_name])) $unassigned_objects[$object_name] = [];
                array_push($unassigned_objects[$object_name], $record);
            } else if ($dag == $user_dag_name) {
                // Record is in this user's DAG
                array_push($assigned_objects, $object_name);

                // Increment Counters
                $total_in_dag++;
                if ($form_status == 2) $completed_in_dag++;
            } else {
                // Record is in some other DAG - do nothing
            }
        }
        //$this->emDebug("Unassigned Objects", $unassigned_objects);
        //$this->emDebug("Assigned Objects", $assigned_objects);


        $this->totalCount       = $total;
        $this->totalComplete    = $completed;
        $this->totalPercent     = $this->totalCount == 0 ? 100 : round($this->totalComplete / $this->totalCount * 100, 1);

        $this->totalCountDag    = $total_in_dag;
        $this->totalCompleteDag = $completed_in_dag;
        $this->totalPercentDag  = $this->totalCountDag == 0 ? 100 : round($this->totalCompleteDag / $this->totalCountDag * 100, 1);


        // Now, filter unassigned objects by ensuring they are not already in current user's DAG
        foreach ($unassigned_objects as $object_name => $unassigned_records) {
            if (in_array($object_name, $assigned_objects)) {
                // This object is already in user's dag list so we can't use it again
                //$this->emDebug("Object already in DAG group", $object_name);
            } else {
                // This object is NOT is user's DAG list, so we can take one record and use it
                // There could be multiple copies of an object, so we will only take one and make it available
                //$this->emDebug("Object Available", $object_name, count($records), $records[0]);
                //$available[] = $unassigned_records[0];
                $available[] = array_shift($unassigned_records);
            }
        }
        $this->availableRecords = $available;

        // Debug summary stats:
        $this->emDebug("Total: " . $this->totalCount, "Total in DAG: " . $this->totalCountDag, "Count Available: " . count($this->availableRecords));
    }



    /**
     * Handle custom ajax for 'score_next' and 'get_batch'.  Otherwise, if DAG member protect non-data
     * entry pages with redirect to project home
     *
     * @param $project_id
     * @throws \Exception
     */
    function redcap_every_page_before_render($project_id) {
        // We are going to prevent certain pages if a user is in a DAG
        if (! $this->isDagUser()) {
            //$this->emDebug("Not a DAG User");
            return;
        }

        if (PAGE == "ProjectGeneral/keep_alive.php") {
            return;
        }

        $this->emDebug(__FUNCTION__ . " on " . PAGE . " with: ", $_POST);

        // Handle 'get-batch' post
        if (isset($_POST['get_batch']) && $_POST['get_batch'] == 1)
        {
            $this->doRecordAnalysis();

            $unassigned = $this->availableRecords;
            $batch_size = min(count($unassigned), $this->getProjectSetting('batch-size'));
            $this->emDebug("Batch is $batch_size with " . count($unassigned) . " records available...");

            if ($batch_size == 0) {
                // There are no more remaining - do nothing
                $this->emLog(USERID . "'s request for new batch cannot be filled since there are no unassigned records available to assign");
            } else {
                // Transfer batch to this user
                $records = array_slice($unassigned,0,$batch_size);

                // DAG Name
                $unique_group_name = REDCap::getGroupNames(true, $this->group_id);
                foreach ($records as &$record) {
                    $record['redcap_data_access_group'] = $unique_group_name;
                }
                $result = REDCap::saveData('json', json_encode($records));
                $this->emLog("Assigned batch of " . $batch_size . " records to group " . $this->group_id . " / " . $unique_group_name, $result);

                // Jump to the next score
                $_POST['score_next'] = 1;
            }
        }

        // Handle 'go next'
        if (isset($_POST['score_next']) && $_POST['score_next'] == 1) {
            // Lets redirect to the next record for this user
            $this->emDebug("Score next called for group " . $this->group_id);
            $this->redirectToNextRecord($project_id, $this->group_id);
            $this->exitAfterHook();
            return;
        };


        // Redirect to 'record_home' from other REDCap locations
        if (PAGE == "DataEntry/record_home.php"
            //PAGE == "index.php" ||
            || PAGE == "ProjectSetup/index.php"
            || ( PAGE == "DataEntry/index.php"
                 && empty($_GET["id"])
                 && $_SERVER['REQUEST_METHOD'] === 'GET'
            )
            || ( PAGE == "index.php"
                 && $_SERVER['REQUEST_METHOD'] === 'GET'
            )
        ) {
            // Fallback to home page
            $this->emDebug("Redirecting DAG user to custom home page on " . PAGE . " with request method " . $_SERVER['REQUEST_METHOD']);
            include($this->getModulePath() . "pages/record_home.php");
            $this->exitAfterHook();
            return;
        }

    }


    /**
     * Inject custom CSS and JS
     *
     * @param      $project_id
     * @param null $record
     * @param      $instrument
     * @param      $event_id
     * @param null $group_id
     * @param int  $repeat_instance
     */
    function redcap_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        $review_form = $this->getProjectSetting('review-form');

        if ($instrument == $review_form && $this->isDagUser()) {

            // We are on the review form - inject!
            $this->emDebug("Injecting custom css/js in " . __FUNCTION__ . " on $instrument");

            // Add custom CSS and JS
            ?>
                <style><?php echo file_get_contents($this->getModulePath() . "css/data_entry_index.css")?></style>
                <style><?php echo file_get_contents($this->getModulePath() . "css/ekg_viewer.css")?></style>


                <script type='text/javascript' src='<?php echo $this->getUrl("js/data_entry_index.js") ?>'></script>
                <script type='text/javascript' src='<?php echo $this->getUrl("js/d3.v4.min.js")?>'></script>
                <script type='text/javascript' src='<?php echo $this->getUrl("js/ekg_viewer.js")?>'></script>
                <script type='text/javascript' src='<?php echo $this->getUrl("js/hotkeys.min.js")?>'></script>

                <script type='text/javascript'>
                    var EKGEM = EKGEM || {};
                    EKGEM['progress']   = <?php echo json_encode($this->getProgress($project_id,$this->group_id)) ?>;
                    EKGEM['startTime']  = <?php echo json_encode(date("Y-m-d H:i:s")) ?>;
                    EKGEM['dag']        = <?php echo json_encode($this->group_id ) ?>;
                    EKGEM['userid']     = <?php echo json_encode(USERID) ?>;
                    EKGEM['data']       = <?php echo json_encode($this->getObject($record)) ?>;
                </script>
            <?php
        } else {

            // Turn off Shazam for normal viewers
            ?>
                <script>
                    var Shazam = Shazam || {};
                    Shazam.DisableTransform = true;
                </script>
            <?php
        }
    }



    /**
     * Get progress array for project and group
     * @param $project_id
     * @param null $group_id
     * @return array|mixed
     */
    function getProgress($project_id, $group_id = NULL) {
        $logic = null;
        $result = REDCap::getData($project_id, 'json', null, array('record_id', 'ekg_review_complete'), null, $group_id, false, false, false, $logic);
        $records = json_decode($result,true);
        $total = $complete = 0;
        foreach ($records as $record) {
            if ($record['ekg_review_complete'] == 2) $complete++;
            $total++;
        }

        $percent = $total == 0 ? 100 : round($complete/$total * 100,1);

        $result = array(
            'total' => $total,
            'complete' => $complete,
            'width' => round($percent,0),
            'percent' => $percent,
            'text' => $percent . "% ($complete / $total scored)"
        );

        return $result;
    }


    /**
     * Redirect to next record (be sure to call exitAfterHook() when calling...
     * @param $project_id
     * @param $group_id
     */
    function redirectToNextRecord($project_id, $group_id) {
        // Determine next record
        $logic = '[ekg_review_complete] = 0';
        $next_records = REDCap::getData($project_id, 'array', null, array('record_id', 'ekg_review_complete'), null, $group_id, false, true, false, $logic);
        $this->emDebug("There are " . count($next_records) . " remaining for group $group_id...");
        if (empty($next_records)) {
            // There are none left - lets goto the homepage
            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id . "&msg=" . htmlentities("All Records Complete");
            $this->emDebug("None left - going to homepage");
            $this->redirect($url);
        } else {
            $next_record = key($next_records);
            $form = $this->getProjectSetting( "review-form");
            $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . $project_id . "&page=" . $form . "&id=" . htmlentities($next_record);
            $this->emDebug("Redirect to $next_record at $url");
            $this->redirect($url);
        }
    }


    /**
     * On saving, add the completion time (server-side), and redirect to the next record
     * @param     $project_id
     * @param     $record
     * @param     $instrument
     * @param     $event_id
     * @param     $group_id
     * @param     $survey_hash
     * @param     $response_id
     * @param int $repeat_instance
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 ) {

        $this->emDebug("Just saved record $record in group $group_id");

        // In order to handle server-side validation, we need to check if the url
        // contains any required missing field validations
        // $this->emDebug("_GET", empty($_GET['__reqmsgpre']), $_GET) ;

        if (!empty($this->group_id) && empty($_GET['__reqmsgpre'])) {

            // Set end-time if empty
            $q = REDCap::getData($project_id, 'json', array($record), array('record_id','end_time','ekg_review_complete'));
            $results = json_decode($q,true);

            $this->emDebug("Saving record:",$results);
            if (isset($results[0]['end_time']) && empty($results[0]['end_time'])) {
                $results[0]['end_time'] = Date('Y-m-d H:i:s');
                $results[0]['ekg_review_complete'] = 2;
                $q = REDCap::saveData('json', json_encode($results));
                $this->emDebug("Updating end_time/status", json_encode($results[0]), "Save Result", $q);
            }

            // Save and redirect to next record!
            $this->redirectToNextRecord($project_id, $this->group_id);
            $this->exitAfterHook();
        }
    }


    /**
     * Each redcap record has an object_name - use that to pull the object from GCP and return it as an array
     * @param $record
     * @return array|bool   Array of data or false
     */
    function getObject($record) {
        // Use the ID to find the actual file name
        $q = REDCap::getData('json', array($record), array('object_name', 'ekg_review_complete'));
        $results = json_decode($q,true);
        $result = $results[0];

        //if ($result['ekg_review_complete'] == 2) {
        //    $this->emDebug("The requested record is already complete - no need to re-render");
        //    return false;
        //}

        $object_name = $result['object_name'];
        if (empty($object_name)) {
            // There is no object
            $this->emDebug("There is no object_name for record $record");
            return false;
        }

        $this->emDebug("Getting bucket file " . $object_name . " for record $record");
        $contents = $this->getBucketFile($object_name);

        if ($contents === false) {
            // There was an error
            $this->emError("There was an error getting " . $object_name . " for record $record");
            return false;
        } else {
            //$this->emDebug("Found contents:\n" . substr($result,0,100) . "...");
            $data = csvToArray($contents);
            return $data;
        }
    }


    /**
     * Get file from google bucket
     * @param $filename
     * @return bool|string
     */
    function getBucketFile($filename) {

        # If Dev, return localhost
        if ($this->getSystemSetting('is_dev') == 1) {
            return file_get_contents($this->getModulePath() . "/examples/example.csv");
        }

        # Includes the autoloader for libraries installed with composer
        require $this->getModulePath() . 'vendor/autoload.php';

        # Load KeyFile from Textarea input
        $keyFileJson = $this->getProjectSetting("gcp-service-account-json");
        $keyFile = json_decode($keyFileJson,true);

        # Instantiates a client
        $storage = new StorageClient([
            'keyFile' => $keyFile
        ]);

        # The name of a bucket
        $bucketName = $this->getProjectSetting("gcp-bucket-name");

        # Get the bucket
        $bucket = $storage->bucket($bucketName);
        $this->emDebug("Got Bucket: " . $bucket->name());

        # Get the file
        $object = $bucket->object($filename);
        if ($object->exists()) {
            $this->emDebug("$filename exists");
            //$stream = $object->downloadAsStream();
            //echo $stream->getContents();
            $string = $object->downloadAsString();
            return $string;
        } else {
            $this->emDebug("$filename does not exist");
            return false;
        }
    }


    /**
     * Get all files from the google bucket
     * @return array
     */
    function getBucketContents($options = array()) {

        # Includes the autoloader for libraries installed with composer
        require $this->getModulePath() . 'vendor/autoload.php';

        # Load KeyFile from Textarea input
        $keyFileJson = $this->getProjectSetting("gcp-service-account-json");
        $keyFile = json_decode($keyFileJson,true);

        # Instantiates a client
        $storage = new StorageClient([
            'keyFile' => $keyFile
        ]);

        # The name of a bucket
        $bucketName = $this->getProjectSetting("gcp-bucket-name");

        # Get the bucket
        $bucket = $storage->bucket($bucketName);
        $this->emDebug("Got Bucket: " . $bucket->name());

        # Get the file
        $results = array();
        foreach ($bucket->objects($options) as $obj) {
            $results[] = $obj->name();
        }

        return $results;
    }



    /**
     * Utility for redirecting
     * @param $url
     */
    function redirect($url) {
        // If contents already output, use javascript to redirect instead
        if (headers_sent())
        {
            echo "<script type='text/javascript'>window.location.href=\"$url\";</script>";
        }
        // Redirect using PHP
        else
        {
            header("Location: $url");
        }

    }

}