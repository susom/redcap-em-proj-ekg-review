<?php
/** @var \Stanford\EkgReview\EkgReview $module */

/**
 * The purpose of this page is to go create committee records (v4) when tie-breakers (v3) were not definitive
 */

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

// Starting new records at 40000
$new_id = max($max_id + 1, 40000);

foreach ($map as $object_name => $versions) {

    if (isset($versions[4])) {
        // Already have an existing v4 committee tiebreaker
        //$module->emDebug($object_name . " already has v3");
        $existing_tbs++;
        if ($versions[4][$module::TB_FORM . "_complete"] == "2") $complete_tbs++;
    } elseif(isset($versions[1]) && isset($versions[2]) && isset($versions[3])) {

        // We have a potential need for a 4rd check
        // We are currently adjudicating any records that have any difference in questions 1-6,9,10
        // This is saved to both version 1 and version 2 in the cross_reviewer_results checkbox question
        $logic = '([tie_breaker_complete] = "2") AND ([tb_q1] = "3" OR [tb_q2] = "3" OR [tb_q3] = "3" OR [tb_q4] = "3" OR [tb_q5] = "3" OR [tb_q6] = "3" OR [tb_q9] = "3" OR [tb_q10] = "3")';

        // So, we should just be able to evaluate this logic against version 3
        $result = REDCap::evaluateLogic($logic, $module->getProjectId(), $versions[3]['record_id']);

        if ($result) {
            // We need to do tie breaker on this one
            $module->emDebug("Need to Committee TieBreak: " . $versions[3]['record_id']);

            // Start by copying v3
            $v4 = $versions[3];

            // Lets make a version 4 csv for import and start with version 3
            $v4["v3_record_id"]                  = $versions[3]['record_id'];
            $v4["v3_reviewer"]                   = $versions[3]['reviewer'];
            $v4["v3_dag"]                        = $versions[3]['redcap_data_access_group'];

            $v4["record_id"]                     = $new_id;
            $v4["redcap_data_access_group"]      = 'committee';
            $v4["object_version"]                = 4;
            $v4[$module::EKG_FORM . "_complete"] = '0';
            $v4[$module::TB_FORM . "_complete"]  = '0';

            // Remove unnecessary fields from v4
            unset($v4['reviewer'], $v4['start_time'], $v4['end_time']);
            unset($v4['tb_results'], $v4['tb_results_detail']);

            // Make sure we remove unnecessary fields from other forms
            // $module->emDebug("Count before: " . count($v4));
            foreach ($v4 as $k => $v) {
                if (strpos($k,"qc_") === 0 || strpos($k,"cross_review") === 0) unset($v4[$k]);
            }
            unset($v4['internal_qc_complete']);
            // $module->emDebug("Count after: " . count($v4));

            $new_id++;

            $clearAllCheckboxes = false;
            $lockFields = $module::TB_LOCK_ALWAYS;

            // Update question fields
            foreach ($module::TB_FIELDS as $tb_field) {
                $field = substr($tb_field, 3);

                if ($v4[$tb_field] <> 3) {
                    // This was resolved either by this tie breaker or was previously locked to the correct answer
                    $v4[$tb_field] = '99';
                } else {
                    // Clear the result
                    $v4[$tb_field] = '';
                    // Is this a checkbox question
                    if (isset($v4[$field])) {
                        // Blank out the field as this needs to be resolved
                        $v4[$field] = '';
                    } elseif ($tb_field == 'tb_q6') {
                        // Checkbox question - uncheck ALL options
                        foreach ($module::TB_LOCK_CBX_FIELDS as $cbx_field) $v4[$cbx_field] = 0;
                    } else {
                        $module->emError("Unknown field: $field for tb_field $tb_field");
                    }
                }
            }

            // Add the record to the list of tie breakers
            $tbs[] = $v4;
            $module->emDebug("Adding committee: " . $v4['record_id']);
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
    'text'      => $percent . "% ($complete_tbs / $existing_tbs Created Committee TieBreakers Scored)"
);


?>
    <hr>
    <?php $module->renderProgressArea($progress, "Committee Tie Breaker Progress", false); ?>
    <br>
    <h4>Suggested New Committee Tie Breaker Records For Import
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
