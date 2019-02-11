<?php
/** @var \Stanford\EkgReview\EkgReview $module */


// SHOW A PROGRESS PAGE

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

//$HtmlPage = new HtmlPage();
//$HtmlPage->PrintHeader();

$module->doRecordAnalysis();

// Get all groups
$dag_names = REDCap::getGroupNames(TRUE);


$module->renderProgressArea( $module->getProgress(), "OVERALL PROGRESS", true);

foreach ($dag_names as $dag_name) {

    $progress = $module->getProgress($dag_name);
    $module->renderProgressArea($progress, "$dag_name Progress", true);
}

?>
<br>
<?php echo count($module->rs[$module::UNASSIGNED]['records']) ?> record(s) are unassigned and can be taken by any reviewer.

