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


    public $group_id;       // DAG Group ID
    public $dag_name;       // DAG Name


    // Record Summary Output
    public $rs;
    public $totalCount, $totalComplete, $totalPercent;
    public $totalCountDag, $totalCompleteDag, $totalPercentDag;


    const UNASSIGNED = "__unassigned__";
    const COMPARE_FIELDS = array('q1','q2','q3','q4','q5','q6___0','q6___1','q6___2','q6___3','q6___4','q7','q8','q9','q9b','q10','q10b');

    function __construct($project_id = null)
    {
        parent::__construct();
        $this->ts_start = microtime(true);
    }


    /**
     * Quick function to determine who belongs in the 'special view'
     * @return bool
     */
    function isDagUser() {
        global $project_id;

        if (empty($project_id)) {
            $this->emDebug("project_id is not defined on page " . PAGE);
        } elseif (empty(USERID)) {
            $this->emDebug("USERID is not defined on page " . PAGE);
        } elseif (empty($this->group_id)) {
            // Get User Rights for User
            $result = REDCap::getUserRights(USERID);
            $user_rights = isset($result[USERID]) ? $result[USERID] : NULL;

            // Get Group ID
            $group_id = $user_rights['group_id'];
            $this->emDebug(USERID . " is in DAG " . $group_id);
            $this->group_id = $group_id;
            $this->dag_name = REDCap::getGroupNames(true, $this->group_id);
        }
        return !empty($this->group_id);
    }


    /**
     * Loop through all records to determine which records are unassigned and DAG progress per group
     */
    function doRecordAnalysis() {

        // Do not re-do this if the values are already cached
        if (!empty($this->rs)) return;

        // PULL ALL RECORDS (may be slow in larger projects)
        $logic = NULL;
        $result = REDCap::getData('json', null,
            array('record_id', 'start_time', 'end_time', 'object_name',
                'object_version', 'adjudication_required', 'qc_result', 'ekg_review_complete'),
            null, null, false, true, false, $logic);
        $records = json_decode($result,true);

        // Build a record summary object
        // For each DAG a child summary object will be added
        $rs = [
            "total"             => 0,
            "total_completed"   => 0
        ];

        // Loop through the records to build the aggregate summary
        foreach ($records as $record) {

            $dag                    = $record['redcap_data_access_group'];
            $object_name            = $record['object_name'];
            $object_version         = $record['object_version'];
            $form_status            = $record['ekg_review_complete'];
            $adjudication_required  = $record['adjudication_required___1'];
            $qc_result              = $record['qc_result'];
            $start_time             = $record['start_time'];
            $end_time               = $record['end_time'];

            $group = empty($dag) ? self::UNASSIGNED : $dag;

            // V2 Initialize array for each dag in result status
            if (!isset($rs[$group])) $rs[$group] = [
                "total"             => 0,
                "total_complete"    => 0,
                "duration"          => 0,
                "object_names"      => [],
                "records"           => []
            ];

            // Increment group counters

            $rs[$group]['total']++;
            if ($form_status == '2') $rs[$group]['total_complete']++;
            $duration = empty($end_time) ? 0 : strtotime($end_time) - strtotime($start_time);
            $rs[$group]['duration'] += $duration;

            // Keep array of object names for active DAG
            if ($group == $this->dag_name) {
                array_push($rs[$group]['object_names'], $object_name);
            } elseif ($group == self::UNASSIGNED) {
                array_push($rs[$group]['records'], $record);
            }
        }


        // Do aggregate totals for each dag and entire project
        $rs['duration'] = $rs['total'] = $rs['total_complete'] = 0;
        foreach ($rs as $dag_name => $data) {
            $rs[$dag_name]['total_percent'] = $data['total'] == 0 ? 100 : round($data['total_complete'] / $data['total'] * 100, 1);

            $rs['duration']         += empty($data['duration']) ? 0 : (int) $data['duration'];
            $rs['total']            += $data['total'];
            $rs['total_complete']   += $data['total_complete'];
        }
        $rs["total_percent"] = $rs['total'] == 0 ? 100 : round($rs['total_complete'] / $rs['total'] * 100, 1);

        // Save to object
        $this->rs = $rs;
        //$this->emDebug("Record Summary", $rs);
    }


    /**
     * @return array where key is object_name and value is record
     */
    function getAvailableRecords() {
        // Make sure we have done the analysis
        $this->doRecordAnalysis();

        // Get the two relevant blocks of data
        $current_dag_object_names = $this->rs[$this->dag_name]['object_names'];
        $unassigned_records = $this->rs[self::UNASSIGNED]['records'];

        // Build an available records array (key is name and value is record)
        $available_records = [];
        foreach ($unassigned_records as $record) {
            $object_name    = $record['object_name'];
            $object_version = $record['object_version'];

            if (in_array($object_name, $current_dag_object_names)) {
                // This object is already assigned to the dag user - only assign it again if it is version 99 - internal QC
                if ($object_version == 99) $available_records[$object_name] = $record;
            } else {
                // This object is NOT in the dag's existing records so it is available
                $available_records[$object_name] = $record;
            }
        }
        $this->emDebug("Found " . count($available_records) . " of " . count($unassigned_records) . " records available for " . $this->dag_name);
        return $available_records;
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
            $available_records = $this->getAvailableRecords();

            $this->emDebug("Available Records", $available_records);

            $batch_size = min(count($available_records), $this->getProjectSetting('batch-size'));
            $this->emDebug("Batch is $batch_size with " . count($available_records) . " records available...");

            if ($batch_size == 0) {
                // There are no more remaining - do nothing
                $this->emLog(USERID . "'s request for new batch cannot be filled since there are no unassigned records available to assign");
            } else {
                // Transfer batch to this user
                $records = array_slice($available_records,0,$batch_size);

                $record_ids = array();
                foreach ($records as $object_name => &$record) {
                    $record_ids[] = $record['record_id'];
                    $record['redcap_data_access_group'] = $this->dag_name;
                }

                $result = REDCap::saveData('json', json_encode($records));
                $this->emLog("Moving " . $batch_size . " records to DAG $this->dag_name / " . $this->group_id);
                if (!empty($result['errors'])) $this->emError("Error assigning to DAG", $result);

                $this->emDebug("Batch record ids:", $record_ids);

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
                    EKGEM['progress']   = <?php echo json_encode($this->getProgress($this->dag_name)) ?>;
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
     * Take a node from the record summary to generate a progress object
     * @param null $dag_name
     * @return array
     */
    function getProgress($dag_name = null) {
        $this->doRecordAnalysis();
        $node = empty($dag_name) ? $this->rs : $this->rs[$dag_name];

        $total      = $node['total'];
        $complete   = $node['total_complete'];
        $percent    = $node['total_percent'];
        $width      = round($percent,0);
        $duration   = $node['duration'];

        $result = array(
            'total'     => $total,
            'complete'  => $complete,
            'width'     => $width,
            'percent'   => $percent,
            'duration'  => $duration,
            'text'      => $percent . "% ($complete / $total scored)"
        );
        return $result;
    }


    /**
     * Take the progress array and build a progress bar
     * @param        $progress_array
     * @param string $label
     * @param bool   $include_duration (optional)
     */
    function renderProgressArea($progress_array, $label = "Progress", $include_duration = false) {

        if ($include_duration) {
            $d_min = round($progress_array['duration'] / 60, 0);
            $d_avg = empty($progress_array['complete']) ? 0 : round($progress_array['duration'] / $progress_array['complete'], 0);

            $duration = " taking $d_min min total, averaging $d_avg sec/record";
        } else {
            $duration = "";
        }

        ?>
            <div>
                <p>
                    <b><?php echo $label?>:</b>
                    <span class="progress-detail">
                        <?php echo $progress_array['complete'] . " of " . $progress_array['total'] . " records have been reviewed" . $duration ?>
                    </span>
                </p>
                <div class="progress">
                    <div class="progress-bar progress-bar-striped progress-black" style="width:<?php echo $progress_array['percent'] ?>%">
                        <?php echo $progress_array['percent'] ?>%
                </div>
            </div>
        <?php
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

        if ($this->isDagUser() && empty($_GET['__reqmsgpre'])) {

            // Get recent record
            $q = REDCap::getData($project_id, 'json', array($record));
            $results = json_decode($q,true);
            $this->emDebug("Saving record:",$results);

            if (isset($results[0]['end_time']) && empty($results[0]['end_time'])) {
                // Set end-time and mark record as complete if it was empty
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
     * Find differences between two records
     * @param $r1
     * @param $r2
     * @return array
     */
    function findDifferences($r1, $r2) {
        $field_diff = [];
        foreach (self::COMPARE_FIELDS as $field) {
            if ($r1[$field] !== $r2[$field]) array_push($field_diff, $field);
        }
        $this->emDebug("Comparing " . $r1['record_id'] . " with " . $r2['record_id'] . " => " . implode(",",$field_diff));
        return $field_diff;
    }


    /**
     * Update the records after we have two different versions to compare
     * @param $r1
     * @param $r2
     * @param $type
     * @return mixed
     */
    function updateDifferences($r1,$r2,$type) {
        if ($type == "qc") {
            $pair_field   = 'qc_pair_record_id';
            $result_field = 'qc_result';
            $detail_field = 'qc_result_detail';
        } elseif ($type == "adjudication") {
            $pair_field   = 'cross_reviewer_pair_record_id';
            $result_field = 'cross_reviewer_result';
            $detail_field = 'cross_reviewer_result_detail';
        } else {
            $this->emError("Invalid Type:", $type);
            return false;
        }

        $r1_cache = serialize($r1);
        $r2_cache = serialize($r2);

        // In all cases, we will make sure their pair record ids are identified
        $r1[$pair_field] = $r2['record_id'];
        $r2[$pair_field] = $r1['record_id'];
        $text = " - update $type pair record id";

        // If both records are complete, then we will look at their differences
        if ($r1['ekg_review_complete'] == '2' && $r2['ekg_review_complete'] == '2') {
            $differences = $this->findDifferences($r1,$r2);

            if (empty($differences)) {
                // SAME
                $result = "1";
                $text = " - matched";
            } else {
                // DIFFERENT
                $result = "2";
                $text = " - differ at " . implode(",", $differences);
            }
            $r1[$result_field] = $result;
            $r2[$result_field] = $result;
            $r1[$detail_field] = "Record #" . $r2['record_id'] . $text;
            $r2[$detail_field] = "Record #" . $r1['record_id'] . $text;
        }

        // Update database if records changed
        $data = [];
        if (serialize($r1) !== $r1_cache) {
            $data[] = $r1;
            $this->emDebug("Updating R1");
        }
        if (serialize($r2) !== $r2_cache) {
            $data[] = $r2;
            $this->emDebug("Updating R2");
        }

        if (empty($data)) {
            return  false; //"No change"; //for records #" . $r1['record_id'] . " & #" .  $r2['record_id'] . "$text";
        } else {
            $q = REDCap::saveData('json', json_encode($data));
            if (!empty($q['errors'])) $this->emError("Error updating $type", $data, $q);
            $this->emDebug("Update results",$q);

            return "Updated $type - #" . $r1['record_id'] . " & #" . $r2['record_id'] . "$text";
        }
    }


    /**
     * Get all completed records
     * @return mixed
     */
    function getAllCompleteRecords() {
        $filter = "[ekg_review_complete] = '2'";
        $q = REDCap::getData('json', null, null, null, null, false, true, false, $filter);
        $records = json_decode($q,true);
        $this->emDebug("Found " . count($records) . " complete records");
        return $records;
    }



    /**
     * Get all completed records
     * @return mixed
     */
    function getRecords($filter = null) {
        //$filter = "[ekg_review_complete] = '2'";
        $q = REDCap::getData('json', null, null, null, null, false, true, false, $filter);
        $records = json_decode($q,true);
        $this->emDebug("Found " . count($records) . " records with filter = $filter");
        return $records;
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
            $name = $obj->name();
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            if ($ext == "csv") $results[] = $obj->name();
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