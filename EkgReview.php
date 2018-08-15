<?php
namespace Stanford\EkgReview;

use \REDCap;
use \Project;

class EkgReview extends \ExternalModules\AbstractExternalModule
{

    public $group_id = null;

    const REVIEW_FORM = "ekg_review";


    function __construct()
    {
        parent::__construct();
//        $this->emLog("Construct");
    }

    function redcap_every_page_before_render($project_id) {
        // We are going to prevent certain pages if a user is in a DAG

        if (empty(USERID)) {
            $this->emLog(USERID . " is not present on page " . PAGE);
            return;
        }

        // Get User Rights:
        $result = REDCap::getUserRights(USERID);
        $user_rights = isset($result[USERID]) ? $result[USERID] : NULL;

        // Get Group ID
        $this->group_id = $user_rights['group_id'];
        $this->emLog(__FUNCTION__, PAGE, USERID, $this->group_id);

        if (empty($this->group_id)) {
            // Don't do anything funny if not part of a DAG
            return;
        }


        // Redirect from Project Setup to Home
        if (PAGE == "ProjectSetup/index.php") {
            $this->emDebug("Redirecting from " . PAGE . " to home");
            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id;
            redirect($url);
        }


        // Redirect from Project Index to Home
        if (PAGE == "index.php") {
            $this->emDebug("Redirecting from " . PAGE . " to home");
            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id;
            redirect($url);
        }

        // Take over the home page
        if (PAGE == "DataEntry/record_home.php") {
            if (!empty($_POST)) $this->emDebug("POST", $_POST);

            if (isset($_POST['score_next']) && $_POST['score_next'] == 1) {
                // Lets redirect to the next record for this user
                $this->redirectToNextRecord($project_id, $this->group_id);
            } else {
                include($this->getModulePath() . "pages/record_home.php");
            }
            $this->exitAfterHook();
        }

    }


    function redcap_every_page_top($project_id) {

        $this->emLog(__FUNCTION__, PAGE);

        if (empty($this->group_id)) {
            // No customization
            return;
        }

        // If user in part of a DAG, then we are applying special code:

        if (PAGE == "DataEntry/index.php") {
            // Add custom CSS
            echo "<style>" . file_get_contents($this->getModulePath() . "css/data_entry_index.css") . "</style>";

            // Add custom JS
            echo "<script type='text/javascript' src='" . $this->getUrl("js/data_entry_index.js") . "'></script>";

            // Add progress
            echo "
                <script type='text/javascript'>
                    if (typeof EKGEM === 'undefined') EKGEM = {};
                    EKGEM['progress'] = " . json_encode($this->getProgress($project_id,$this->group_id)) . ";
                </script>";
        }

    }


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

    function redirectToNextRecord($project_id, $group_id) {
        // Determine next record
        $logic = '[ekg_review_complete] = 0';
        $next_records = REDCap::getData($project_id, 'array', null, array('record_id', 'ekg_review_complete'), null, $group_id, false, true, false, $logic);
        $this->emDebug($next_records);
        if (empty($next_records)) {
            // There are none left - lets goto the homepage
            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id . "&msg=" . htmlentities("All Records Complete");
            redirect($url);
        } else {
            $next_record = key($next_records);
            $this->emDebug("Next record is $next_record");
            $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . $project_id . "&page=" . self::REVIEW_FORM . "&id=" . htmlentities($next_record);
            $this->emDebug("Redirect to $url");
            redirect($url);
        }
    }



    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 ) {
        $this->emDebug("Just saved record $record in group $group_id");

        if (!empty($this->group_id)) {
            $this->redirectToNextRecord($project_id, $this->group_id);
            $this->exitAfterHook();
        }


//        // Determine next record
//        $logic = '[ekg_review_complete] = 0';
//        $next_records = REDCap::getData($project_id, 'array', null, array('record_id', 'ekg_review_complete'), null, $this->group_id, false, true, false, $logic);
//        $this->emDebug($next_records);
//        if (empty($next_records)) {
//            // There are none left - lets goto the homepage
//            $url = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . $project_id;
//            redirect($url);
//        } else {
//            $next_record = key($next_records);
//            $this->emDebug("Next record is $next_record");
//            $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . $project_id . "&page=" . self::REVIEW_FORM . "&id=" . htmlentities($next_record);
//            $this->emDebug("Redirect to $url");
//            redirect($url);
//        }
    }



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