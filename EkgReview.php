<?php
namespace Stanford\EkgReview;

use \REDCap;
use \Project;

# Imports the Google Cloud client library
use Google\Cloud\Storage\StorageClient;




class EkgReview extends \ExternalModules\AbstractExternalModule
{

    public $group_id = null;

    function __construct($project_id = null)
    {
        parent::__construct();
//        $this->emLog("Construct");

        if (!empty($project_id)) {}
    }


    function isDagUser() {
        global $project_id;

        if (empty($project_id)) {
            $this->emLog("project_id is not defined on page " . PAGE);
        } elseif (empty(USERID)) {
            $this->emLog("USERID is not defined on page " . PAGE);
        } elseif (empty($this->group_id)) {
            // Try to get the group id

            // Get User Rights for User
            $result = REDCap::getUserRights(USERID);
            $user_rights = isset($result[USERID]) ? $result[USERID] : NULL;

            // Get Group ID
            $group_id = $user_rights['group_id'];
            $this->emLog(USERID . " is part of DAG " . $group_id);
            $this->group_id = $group_id;
        }

        return !empty($this->group_id);
    }

    /**
     * Get unassigned and incomplete records
     * @return array
     */
    function getUnassignedRecords() {
        // Get all records that are not assigned to a DAG
        $logic = "[ekg_review_complete] <> '2'";
        $result = REDCap::getData('json', null, array('record_id', 'ekg_review_complete'), null, null, false, true, false, $logic);
        $records = json_decode($result,true);
        $unassigned = array();

        foreach ($records as $record) {
            if (empty($record['redcap_data_access_group'])) $unassigned[] = $record;
        }

        return $unassigned;
    }


    function redcap_every_page_before_render($project_id) {
        // We are going to prevent certain pages if a user is in a DAG

        if (! $this->isDagUser()) {
            $this->emDebug("Not a DAG User");
            return;
        }


        $this->emDebug(__FUNCTION__ . " on " . PAGE);


        // Handle 'get-batch' post
        if (isset($_POST['get_batch'])
            && $_POST['get_batch'] == 1
            )
        {
            $unassigned = $this->getUnassignedRecords();
            $batch_size = min(count($unassigned), $this->getProjectSetting('batch-size'));
            $this->emDebug("Batch is $batch_size with " . count($unassigned) . " remaining...");

            if ($batch_size == 0) {
                // There are no more remaining - do nothing
                $this->emLog(USERID . " request for new batch cannot be filled since there are no batches remaining");
            } else {
                // Transfer batch to this user
                $records = array_slice($unassigned,0,$batch_size);
                // DAG Name
                $unique_group_name = REDCap::getGroupNames(true, $this->group_id);

                $this->emDebug("Slice of records: ", $records);
                foreach ($records as &$record) {
                    $record['redcap_data_access_group'] = $unique_group_name;
                }
                $result = REDCap::saveData('json', json_encode($records));
                $this->emLog("Assigned batch of " . $batch_size . " records to group " . $this->group_id . " / " . $unique_group_name, $result);
                $_POST['score_next'] = 1;
            }
        }

        // Handle 'go next'
        if (isset($_POST['score_next']) && $_POST['score_next'] == 1) {
            // Lets redirect to the next record for this user
            $this->redirectToNextRecord($project_id, $this->group_id);
            $this->exitAfterHook();
            return;
        };


        // Redirect to 'record_home' from other REDCap locations
        if (PAGE == "DataEntry/record_home.php"
            //PAGE == "index.php" ||
            //|| PAGE == "ProjectSetup/index.php"
            || ( PAGE == "DataEntry/index.php"
                 && empty($_GET["id"])
                 && $_SERVER['REQUEST_METHOD'] === 'GET'
            )
            || ( PAGE == "index.php"
                 && $_SERVER['REQUEST_METHOD'] === 'GET'
            )
        ) {

            // Fallback to home page
            $this->emDebug("Loading custom home on request to " . PAGE);
            include($this->getModulePath() . "pages/record_home.php");
            $this->exitAfterHook();
            return;
        }

    }


    function redcap_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        $review_form = $this->getProjectSetting('review-form');

        if ($review_form == $instrument && $this->isDagUser()) {

            // We are on the review form - inject!
            $this->emDebug("Injecting custom css/js");

            // Add custom CSS and JS
            ?>
                <style><?php echo file_get_contents($this->getModulePath() . "css/data_entry_index.css")?></style>
                <style><?php echo file_get_contents($this->getModulePath() . "css/ekg_viewer.css")?></style>

                <script type='text/javascript' src='<?php echo $this->getUrl("js/data_entry_index.js") ?>'></script>
                <script type='text/javascript' src='<?php echo $this->getUrl("js/d3.v4.min.js")?>'></script>
                <script type='text/javascript' src='<?php echo $this->getUrl("js/ekg_viewer.js")?>'></script>

                <script type='text/javascript'>
                    var EKGEM = EKGEM || {};
                    EKGEM['progress']   = <?php echo json_encode($this->getProgress($project_id,$this->group_id)) ?>;
                    EKGEM['startTime']  = <?php echo json_encode(date("Y-m-d H:i:s")) ?>;
                    EKGEM['dag']        = <?php echo json_encode($this->group_id ) ?>;
                    EKGEM['userid']     = <?php echo json_encode(USERID) ?>;
                    EKGEM['data']       = <?php echo json_encode($this->getObject($record)) ?>;
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



    /**
     * Redirect to next record (be sure to call exitAfterHook() when calling...
     * @param $project_id
     * @param $group_id
     */
    function redirectToNextRecord($project_id, $group_id) {
        // Determine next record
        $logic = '[ekg_review_complete] = 0';
        $next_records = REDCap::getData($project_id, 'array', null, array('record_id', 'ekg_review_complete'), null, $group_id, false, true, false, $logic);
        //$this->emDebug($next_records);
        if (empty($next_records)) {
            // There are none left - lets goto the homepage
            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id . "&msg=" . htmlentities("All Records Complete");
            $this->redirect($url);
        } else {
            $next_record = key($next_records);
            $form = $this->getProjectSetting( "review-form");

            $this->emDebug("Next record is $next_record");
            $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . $project_id . "&page=" . $form . "&id=" . htmlentities($next_record);
            $this->emDebug("Redirect to $url");
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

        if (!empty($this->group_id)) {

            // Set end-time if empty
            $q= REDCap::getData($project_id, 'json', array($record), array('record_id','end_time'));
            $results = json_decode($q,true);
            $this->emDebug("Record after Save:",$results);
            if (isset($results[0]['end_time']) && empty($results[0]['end_time'])) {
                $results[0]['end_time'] = Date('Y-m-d H:i:s');
                $q = REDCap::saveData('json', json_encode($results));
                $this->emDebug("Save Results:",$q);
            }

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
            $this->emDebug("Found contents:\n" . substr($result,0,100) . "...");
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
     * Logging Functions
     * @throws \Exception
     */
    function emLog() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->log($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug() {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || $this->getProjectSetting('enable-project-debug-logging')) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->log($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->log($this->PREFIX, func_get_args(), "ERROR");
    }

}