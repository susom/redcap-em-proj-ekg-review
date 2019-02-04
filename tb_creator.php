<?php
/** @var \Stanford\EkgReview\EkgReview $module */

/**
 * The purpose of this page is to go create tiebreaker records
 */

use REDCap;



// Step 1 - load all records
$records = $module->getRecords();
$module->emDebug("Found " . count($records) . " records");

$max_id = 0;

// Step 2 - make a map array that has object_name => version => [ $record data ]
$map = [];
foreach ($records as $record) {
    $object_name    = $record['object_name'];
    $object_version = $record['object_version'];

    $max_id = max($max_id, intval($record['record_id']));

    // Make an array for each object to hold versions and records
    if (!isset($map[$object_name])) $map[$object_name] = [];

    // Add the record under object -> version -> records
    $map[$object_name][$object_version] = $record;
}
$debug[] = "Analyzing " . count($map) . " objects...";
$module->emDebug("Made map of " . count($map) . " objects");
$module->emDebug("Max existing ID is $max_id");
//- here are two: ", array_slice($map,0,2));



$tbs = [];
$existing_tbs = 0;
$complete_tbs = 0;

// Starting new records at 3000
$new_id = max($max_id + 1, 30000);

foreach ($map as $object_name => $versions) {

    if (isset($versions[3])) {
        // Already have an existing v3 tiebreaker
        //$module->emDebug($object_name . " already has v3");
        $existing_tbs++;
        if ($versions[3][$module::TB_FORM . "_complete"] == "2") $complete_tbs++;
    } elseif(isset($versions[1]) && isset($versions[2]) && !isset($versions[3])) {

        // We have a potential need for a 3rd check
        // We are currently adjudicating any records that have any difference in questions 1-6,9,10
        // This is saved to both version 1 and version 2 in the cross_reviewer_results checkbox question
        $logic = '[cross_reviewer_results(done)] = "1" AND (
            [cross_reviewer_results(q1)] = "0" OR
            [cross_reviewer_results(q2)] = "0" OR
            [cross_reviewer_results(q3)] = "0" OR
            [cross_reviewer_results(q4)] = "0" OR
            [cross_reviewer_results(q5)] = "0" OR
            [cross_reviewer_results(q6___0)] = "0" OR
            [cross_reviewer_results(q6___1)] = "0" OR
            [cross_reviewer_results(q6___2)] = "0" OR
            [cross_reviewer_results(q6___3)] = "0" OR
            [cross_reviewer_results(q6___4)] = "0" OR
            [cross_reviewer_results(q9)] = "0" OR
            [cross_reviewer_results(q10)] = "0"
        )';

        // So, we should just be able to evaluate this logic against version 1
        $result = REDCap::evaluateLogic($logic, $module->getProjectId(), $versions[1]['record_id']);

        if ($result) {
            // We need to do tie breaker on this one
            $module->emDebug("Need to TieBreak: " . $versions[1]['record_id'] . " & " . $versions[2]['record_id']);

            // Lets make a version 3 csv for import and start with version 1
            $v3 = [
                "record_id"                => $new_id,
                "redcap_data_access_group" => '',
                "object_name"              => $versions[1]['object_name'],
                "object_version"           => 3
            ];

            $new_id++;
            $clearAllCheckboxes = false;
            $lockFields = $module::TB_LOCK_ALWAYS;

            // Copy over question fields
            foreach ($versions[1] as $field => $value) {
                $query = 'cross_reviewer_results';

                if (in_array($field, $module::QUESTION_FIELDS)) {
                    $diff = $versions[1]['cross_reviewer_results___' . $field] == 0;
                    $v3[$field] = $diff ? "" : $value;
                    // $module->emDebug($field, $diff);

                    // IF CHECKBOX, NEED TO CLEAR ALL CHECKBOXES
                    if ($diff && in_array($field,$module::TB_LOCK_CBX_FIELDS)) $clearAllCheckboxes = true;

                    // SET LOCKED FIELDS
                    if (! $diff && in_array($field, $module::TB_FIELDS)) {
                        $lockFields[] = $field;
                    }
                }
            }

            if ($clearAllCheckboxes) {
                // $module->emDebug('clearing all checkboxes');
                foreach ($module::TB_LOCK_CBX_FIELDS as $field) $v3[$field] = 0;
            } else {
                // Lock Q6 since they are the same
                $lockFields[] = 'q6';
            }

            // Set up the rest of the fields
            $v3['ekg_review_complete'] = 0;

            $v3['v1_record_id'] = $versions[1]['record_id'];
            $v3['v1_reviewer']  = $versions[1]['reviewer'];
            $v3['v1_dag']       = $versions[1]['redcap_data_access_group'];
            $v3['v2_record_id'] = $versions[2]['record_id'];
            $v3['v2_reviewer']  = $versions[2]['reviewer'];
            $v3['v2_dag']       = $versions[2]['redcap_data_access_group'];

            // Initialize so we have same columns in all arrays... (ugly)
            foreach ($module::TB_FIELDS as $tb_field) {
                $v3[$tb_field] = '';
            }

            // Update those that should be locked
            foreach ($lockFields as $field) {
                $v3['tb_' . $field] = 99;
                // $module->emDebug("Setting tb_" . $field);
            }

            // Add the record to the list of tie breakers
            $tbs[] = $v3;
            // $module->emDebug($v3);
        }
    }

}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$percent    = $complete_tbs / $existing_tbs;
$progress = array(
    'total'     => $existing_tbs,
    'complete'  => $complete_tbs,
    'width'     => round($percent,0),
    'percent'   => $percent,
    'text'      => $percent . "% ($complete_tbs / $existing_tbs Created TieBreakers Scored)"
);


?>
    <hr>
    <?php $module->renderProgressArea($progress, "Tie Breaker Progress", false); ?>
    <br>
    <h4>Suggested New Tie Breaker Records For Import
        <span class='badge badge-secondary'><?php echo count($tbs) ?> New Suggested Below</span>
        <button class="btn btn-xs btn-primary" type="button" data-toggle="collapse" data-target="#collapseTbs" aria-expanded="false" aria-controls="collapseDebug">
            Toggle Results
        </button>
    </h4>
    <div class="collapse show" id="collapseTbs">

<!--        <pre>--><?php ////echo print_r($tbs,true) ?><!--</pre>-->
<!--        <pre>--><?php ////echo implode("\n", $tbs) ?><!--</pre>-->
        <pre><?php echo arrayToCsv($tbs, true) ?></pre>
    </div>
<?php
