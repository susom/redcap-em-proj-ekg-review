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
    // Get array of values for question in $pair_field;
    const QUESTION_FIELDS = [ "q1","q2","q3","q4","q5","q6___0","q6___1","q6___2","q6___3","q6___4","q7","q8","q9","q9b","q10","q10b" ];

    // Array of fields we are comparing for QC/Adjudication
    const COMPARE_FIELDS  = [ 'q1','q2','q3','q4','q5','q6___0','q6___1','q6___2','q6___3','q6___4','q7','q8','q9','q9b','q10','q10b' ];

    // These are the fields for the tie-breaker summary results
    const TB_FIELDS       = [ 'tb_q1','tb_q2','tb_q3','tb_q4','tb_q5','tb_q6','tb_q7','tb_q8','tb_q9','tb_q10' ];
    const TB_LOCK_ALWAYS     = [ 'q7','q8' ];
    const TB_LOCK_CBX_FIELDS = [ 'q6___0','q6___1','q6___2','q6___3','q6___4' ];

    const EKG_FORM = 'ekg_review';
    const QC_FORM = 'internal_qc';
    const ADJ_FORM = 'cross_review';
    const TB_FORM = 'tie_breaker';

    function __construct()
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
                'object_version', 'adjudication_required', 'qc_results', 'ekg_review_complete'),
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
            $qc_results              = $record['qc_results'];
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
                //$this->emDebug($object_name . " is already part of this dag");
                if ($object_version == 99) $available_records[$object_name] = $record;
            } else {
                // This object is NOT in the dag's existing records so it is available
                $available_records[$object_name] = $record;
                //$this->emDebug($object_name . " is not in dag - can be used");
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

        $this->emDebug(__FUNCTION__ . " on " . PAGE . " with: " . json_encode($_POST));

        // Handle 'get-batch' post
        if (isset($_POST['get_batch']) && $_POST['get_batch'] == 1)
        {
            $available_records = $this->getAvailableRecords();
            $this->emDebug("Available Records", $available_records);


            // Batch size is smaller of available and bin size
            $batch_size = $this->getProjectSetting('batch-size');
            $this_batch_size = min(count($available_records), $batch_size);
            $this->emDebug("Based on " . count($available_records) . " available records and batch size of $batch_size - this batch will be $this_batch_size");

            // We may have to further reduce batch size in some cases based on per-group limits.
            $max_number_per_dag = $this->getProjectSetting("max-number-per-dag");
            if ($max_number_per_dag > 0) {
                $num_left_this_dag = $max_number_per_dag - $this->rs[$this->dag_name]['total_complete'];
                $this_batch_size = min($this_batch_size, $num_left_this_dag);
                $this->emDebug("Based on max num per dag of $max_number_per_dag, there are $num_left_this_dag remaining slots in this dag, therefore this batch is $this_batch_size");
            }

            if ($this_batch_size == 0) {
                // There are no more remaining - do nothing
                $this->emLog(USERID . "'s request for new batch cannot be filled since there are no unassigned records available to assign");
            } else {
                // Transfer batch to this user
                $records = array_slice($available_records,0, $this_batch_size);

                $record_ids = array();
                foreach ($records as $object_name => &$record) {
                    $record_ids[] = $record['record_id'];
                    $record['redcap_data_access_group'] = $this->dag_name;
                }

                $result = REDCap::saveData('json', json_encode($records));
                $this->emLog("Moving " . $this_batch_size . " records to DAG $this->dag_name / " . $this->group_id);
                if (!empty($result['errors'])) $this->emError("Error assigning to DAG", $result);

                $this->emDebug("Batch record ids:", $record_ids);

                // Jump to the next score
                $_POST['score_next'] = 1;
            }
        }

        // Handle 'go next'
        if (isset($_POST['score_next']) && $_POST['score_next'] == 1) {
            // Lets redirect to the next record for this user
            $this->emDebug("Score next called for group " . $this->dag_name);
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

            // Load the current record's data
            $record_data = $this->getRecord($record);

            // Get questions to lock on this record
            $locked_questions = $this->getLockedQuestions($record_data);

            // We are on the review form - inject!
            $this->emDebug("Injecting custom css/js in " . __FUNCTION__ . " on $instrument");

            // Add custom CSS and JS
            ?>
                <style><?php echo file_get_contents($this->getModulePath() . "css/data_entry_index.css")?></style>
                <style><?php echo file_get_contents($this->getModulePath() . "css/ekg_viewer.css")?></style>

            <?php
                // Do not permit reviews if the project has been suspended
                if ($this->getProjectSetting('deactivate-reviews') == 1) {
                    echo "<h3>EKG Reviews have been suspended</h3>";
                    $this->exitAfterHook();
                    return;
                }
            ?>

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
                    EKGEM['locked']     = <?php echo json_encode($locked_questions) ?>;
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
     * Returns an array of the question field_names that should be locked
     * @param $record_data
     * @return array    (q1, q2, ... q6, ...)
     */
    function getLockedQuestions($record_data) {
        $locked_questions = [];

        // TIE BREAKER
        if ($record_data['object_version'] == "3") {

            foreach(self::TB_FIELDS as $tb_field) {
                $field = substr($tb_field,3);

                if (isset($record_data[$tb_field]) && $record_data[$tb_field] == '99') {
                    // This field should be locked!
                    array_push($locked_questions, $field);
                }
            }
        }

        // COMMITTEE TIE BREAKER
        if ($record_data['object_version'] == "4") {

            foreach(self::TB_FIELDS as $tb_field) {
                $field = substr($tb_field,3);

                if (isset($record_data[$tb_field]) && $record_data[$tb_field] == '99') {
                    // This field should be locked!
                    array_push($locked_questions, $field);
                }
            }
        }

        return $locked_questions;
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
        $this->emDebug("There are " . count($next_records) . " remaining for group " . $group_id . "...");
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

        if ($this->isDagUser()) {

            if (empty($_GET['__reqmsgpre'])) {
                // Get recent record
                $fields = REDCap::getFieldNames('ekg_review');
                $q = REDCap::getData($project_id, 'json', array($record), $fields);
                $results = json_decode($q,true);
                $this->emDebug("Saving record:",$results);

                if (isset($results[0]['end_time']) && empty($results[0]['end_time'])) {
                    // Set end-time and mark record as complete if it was empty
                    $results[0]['end_time'] = Date('Y-m-d H:i:s');
                    $this->emDebug("Updating end_time/status");
                }

                if (isset($results[0]['ekg_review_complete']) && $results[0]['ekg_review_complete']==0) {
                    $results[0]['ekg_review_complete'] = 2;
                    $this->emDebug("Marking ekg_review complete");
                }

                // Save record
                $q = REDCap::saveData('json', json_encode($results));
                $this->emDebug("Save Result", $q, json_encode($results[0]));

                // Save and redirect to next record!
                $this->redirectToNextRecord($project_id, $this->group_id);
                $this->exitAfterHook();
            } else {
                $this->emDebug("Validation issue remains with $record");
            }
        } else {
            // Not a DAG User
        }
    }


    /**
     * Compare versions 1-3 to determine result of tie-breaker
     * @param $versions
     * @return bool|string
     */
    function doTieBreaker($versions) {

        // Logic:  for each question - determine:
        //  if it was locked - if so, set tb_xxx equal to 99
        //  if same as v1, then 1
        //  if same as v2, then 2
        //  if different than both, 3.
        // For checkbox, we are treating ANY difference as a DIFFERENCE but can evaluate one at a time.

        $r1 = $versions[1];
        $r2 = $versions[2];
        $r3 = $versions[3];

        // Dont do anything if version 3 isn't complete
        if ($r3[self::EKG_FORM . '_complete'] != 2) return false;

        // If tie breaker form is complete, then we are done.
        if ($r3[self::TB_FORM . '_complete'] == 2) return false;

        // Only put something in the update array if it is necessary
        $data = [];

        // To determine the overall result, we need to keep track of each question's result
        $counts = [
            '1' => 0,
            '2' => 0,
            '3' => 0,
            '99' => 0
        ];

        // Because there are multiple checkbox questions and we are treating 'any' difference as an overall difference,
        // we need to go all options first before we can make the final determination
        $checkboxResult = "";
        $checkboxMatches = ["1" => 0, "2" => 0];

        foreach (self::QUESTION_FIELDS as $field) {

            $r1_val = $r1[$field];
            $r2_val = $r2[$field];
            $r3_val = $r3[$field];
            $result = null;
            $isCheckbox = in_array($field, self::TB_LOCK_CBX_FIELDS);
            $tb_field = $isCheckbox ? 'tb_q6' : 'tb_' . $field;

            // Skip any fields that we aren't tracking
            if (! in_array($tb_field, $this::TB_FIELDS)) continue;

            if ($r3[$tb_field] == "99") {
                // This was a locked field - no need to change anything
                $result = 99;
            } elseif ($isCheckbox) {
                // For checkboxes, we need to track if ALL questions match 1 or 2 or neither.
                if ($r3_val == $r1_val)                         $checkboxMatches[1]++;
                if ($r3_val == $r2_val)                         $checkboxMatches[2]++;
                if ($r3_val != $r1_val AND $r3_val != $r2_val)  $checkboxMatches[3]++;
            } else {
                // For non-checkbox questions we can just check
                if ($r3_val == $r1_val) {
                    $result = 1;
                } elseif ($r3_val == $r2_val) {
                    $result = 2;
                } else {
                    // Did not match either v1 or v2
                    $result = 3;
                }
                $this->emDebug("Comparing " . $r3['record_id'] . " $field: " . $r1_val . " vs " . $r2_val . " | " . $r3_val . " => result: " . $result);

                $counts[$result]++;

                // Make the result field for non-checkbox fields
                if ($r3[$tb_field] != $result) {
                    $data['tb_' . $field] = $result;
                }
            }
        }

        // Do checkbox analysis after we have looked at each checkbox value
        if ($r3['tb_q6'] != "99") {
            $boxCount = count(self::TB_LOCK_CBX_FIELDS );
            if ($checkboxMatches[1] == $boxCount) {
                $result = 1;
            } elseif ($checkboxMatches[2] == $boxCount) {
                $result = 2;
            } else {
                // We don't have a clean match
                $result = 3;
            }
            $this->emDebug("Comparing " . $r3['record_id'] . " tb_q6: " . json_encode($checkboxMatches) . " => " . $result);
            $data['tb_q6'] = $result;
            $counts[$result]++;
        }


        // Let's get the 'global' result
        $score = '';
        if ($counts[3] > 0) {
            // Entered entirely new answers that are different than reviewer 1 and 2
            $score = 4;
        } elseif ($counts[1] > 0  && $counts[2] == 0) {
            $score = 1;
        } elseif ($counts[1] == 0 && $counts[2] > 0) {
            $score = 2;
        } elseif ($counts[1] > 0 && $counts[2] > 0) {
            // Agreed with a mix of reviewers 1 and 2
            $score = 3;
        } else {
            $this->emError("Unable to score: ", $counts);
        }
        $this->emDebug("final result: " . $score . " : " . json_encode($counts));

        // Update if different
        if ($r3['tb_results'] != $score)               $data['tb_results'] = $score;

        // Set form as done!
        if ($r3[self::TB_FORM . '_complete'] != "2")   $data[self::TB_FORM . '_complete'] = "2";

        // Return if there is no data to update
        if (empty($data)) return false;

        // Update
        $update = array_merge($r3, $data);

        // Remove form-status fields from not-used forms so they don't change red in REDCap
        unset($update[self::ADJ_FORM . "_complete"]);
        unset($update[self::QC_FORM . "_complete"]);

        $this->emDebug('Updating Tie Breaker', json_encode($data)); //, $update);

        $q = REDCap::saveData('json', json_encode(array($update)));
        if (!empty($q['errors'])) {
            $this->emError("Error updating tiebreaker", $update, $q);
            return "Error updating tiebreaker for " . $r3['record_id'];
        } else {
            return "#" . $r3['record_id'] . " Tiebreaker Updated as $score"; // . json_encode($data);
        }
    }


    /**
     * Update the records after we have two different versions to compare
     * @param $r1
     * @param $r2
     * @param $type
     * @return mixed
     */
    function updateDifferences(&$r1,&$r2,$type) {
        if ($type == "qc") {
            $pair_field   = 'qc_pair_record_id';
            $result_field = 'qc_results';
            $detail_field = 'qc_results_detail';
            $form         = 'internal_qc_complete';
        } elseif ($type == "adjudication") {
            $pair_field   = 'cross_reviewer_pair_record_id';
            $result_field = 'cross_reviewer_results';
            $detail_field = 'cross_reviewer_results_detail';
            $form         = 'cross_review_complete';
        } else {
            $this->emError("Invalid Type:", $type);
            return false;
        }

        $update = false;
        $updates = [];

        // In all cases, we will make sure their pair record ids are identified
        if ( $r1[$pair_field] != $r2['record_id'] || $r2[$pair_field] != $r1['record_id'] ) {
            $r1[$pair_field] = $r2['record_id'];
            $r2[$pair_field] = $r1['record_id'];

            $this->emDebug("Updating $pair_field");

            $update     = true;
            $updates[]  = "Updating pair records";
        };

        // If both records are complete, then we will look at their differences
        if ($r1['ekg_review_complete'] == '2' && $r2['ekg_review_complete'] == '2') {

            // SEE IF THIS COMPARISON HAS ALREADY BEEN DONE
            // To trigger a re-comparison, just uncheck the 'done' box for one of the records
            if ($r1[$result_field . "___" . "done"] != "1"
                    || $r2[$result_field . "___" . "done"] != "1"
                    || $r1[$form] != "2"
                    || $r2[$form] != "2"
            ) {
                // UPDATE THE DONE CHECKBOX
                $results = [
                    $result_field . "___" . "done"  => "1",
                    $form                           => "2"
                ];

                $update = true;
                $updates[] = "Both Done";

                // LETS BUILD THE DETAIL CHECKBOX FIELD AND KEEP TRACK OF DIFFERENCES
                $differences = [];
                foreach (self::QUESTION_FIELDS as $opt) {
                    $key = $result_field . "___" . $opt;

                    // Sanity check to make sure the field is defined (should always be true)
                    if (!isset($r1[$key])) {
                        $this->emError("Missing option: $key");
                        continue;
                    }

                    // Make sure we are comparing this field - for example, we might not want to compare
                    // Numerical results from the 9b and 10b questions
                    if (!in_array($opt, self::COMPARE_FIELDS)) {
                        $this->emDebug("Skipping option $opt as it is not defined in COMPARE_FIELDS");
                        continue;
                    }

                    // Compare as boolean result
                    $match = ($r1[$opt] === $r2[$opt]);

                    // Record a list of differences
                    if (!$match) $differences[] = $opt;

                    // Convert match to string (same as redcap)
                    $match_val = strval((int)$match);   // "1" for True, "0" for false

                    //See if we need to update
                    if ( $r1[$key] != $match_val || $r2[$key] != $match_val ) {
                        $r1[$key] = $r2[$key] = $match_val;
                        $update = true;
                        $updates[] = $opt . "=" . $match_val;
                    }
                }

                $detail_val = implode(",",$differences);

                if ( $r1[$detail_field] != $detail_val || $r2[$detail_field] != $detail_val ) {
                    $results[$detail_field] = $detail_val;
                    $update = true;
                    $updates[] = "detail_field updated";
                }

                // Merge updates into $r1 and $r2
                $r1 = array_merge($r1, $results);
                $r2 = array_merge($r2, $results);
            }
        }

        // Update database if records changed
        if ($update) {
            $data = [ $r1, $r2 ];
            $q = REDCap::saveData('json', json_encode($data));
            if (!empty($q['errors'])) $this->emError("Error updating $type", $data, $r1, $r2, $q);

            $this->emDebug("Update results",$q);

            return "#" . $r1['record_id'] . " & #" . $r2['record_id'] . "\t" . implode(", ", $updates);
        } else {
            return false;
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
        $this->emDebug("Found " . count($records) . " records (with filter = $filter)");
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
     * Return an array from json of the current record
     * @param $record
     * @return mixed
     */
    function getRecord($record) {
        $q = REDCap::getData('json', array($record));
        $results = json_decode($q,true);
        $result = $results[0];
        return $result;
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
        //$this->emDebug("Got Bucket: " . $bucket->name());

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
     * Get all CSV files from the google bucket
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


    /** A recursive ksort */
    function ksort_recursive(&$array)
    {
        if (is_array($array)) {
            ksort($array);
            array_walk($array, 'ksort_recursive');
        }
    }

}