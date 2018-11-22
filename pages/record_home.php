<?php
/** @var \Stanford\EkgReview\EkgReview $this */


$html = new HtmlPage();

$html->PrintHeaderExt();

$dag_progress       = $this->getProgress($this->dag_name);
$overall_progress   = $this->getProgress();


//$available_records = $this->getAvailableRecords( $this->rs[$this->dag_name]['object_names'], $this->rs['unassigned']['records'] );
//$this->emDebug("AvailRecords", $available_records);

?>

<nav id="ekg_nav" class="navbar navbar-dark bg-dark navbar-inverse hidden">
    <a class="navbar-brand" href="#"><h3>EKG Review</h3></a>
    <ul class="nav navbar-expand navbar-nav navbar-right navbar-custom">
        <li class="nav-item active">
            <a class="nav-link" href="#">
                <?php echo $dag_progress['text'] ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#"><i class="fas fa-user"></i> <?php echo USERID ?></a>
        </li>
        <li class="nav-item mr-sm-2">
            <a class="nav-link" href="/index.php?action=myprojects">
                <i class="far fa-list-alt"></i>  My Projects
            </a>
        </li>
    </ul>
</nav>

<main role="main">

    <div class="jumbotron text-center">

        <div class="text-center">
            <img src="<?php echo $this->getUrl("assets/logo.png") ?>" />
        </div>
        <br>
        <p class="text-center">Thank you for your assistance with the EKG Scoring.
        </p>

        <?php

        if ($dag_progress['percent'] != 100) {
            ?>
                <p class="text-center">
                    You may stop at any time and return to this page to resume.
                </p>
                <p>
                    <div class="alert alert-secondary ml-4 mr-4">
                        <table>
                            <tr>
                                <td>
                                    <h4><i class="fas fa-hand-point-right"></i> </h4>
                                </td>
                                <td class="text-left pl-3">
                                    Hint: you can use 'hotkeys' to quickly navigate the EKG with your keyboard.<br>
                                    Hold the SHIFT and press the ARROW keys - LEFT or RIGHT to pan and UP/DOWN to adjust the gain
                                </td>
                            </tr>
                        </table>
                    </div>
                </p>

                <form method="POST">
                    <div class="text-center">
                        <button name="score_next" value="1" class="btn btn-primary btn-lg">Score Next EKG</button>
                    </div>
                </form>
            <?php
        } else {
            // Get available records
            $available_records = $this->getAvailableRecords();

            if (count($available_records) == 0) {
                // There are no more available records
                ?>
                    <div class="alert alert-success text-center">
                        <div class="d-flex">
                            <div>
                                <i style='font-size: 30pt;' class="fas fa-smile-wink mr-4"></i>
                            </div>
                            <div class="d-flex w-100 flex-grow-1 text-center"><b>Congratulations - there are no more unassigned EKGs at this moment.
                                    Your work is done for now but additional EKGs could be added at a later date.</b>
                            </div>
                        </div>
                    </div>
                <div class="text-center"><b>Click on the 'My Projects' link on the nav-bar to return to REDCap</b></div>
                <?php
            } else {
                // There are unassigned records remaining
                $max_number_per_dag = $this->getProjectSetting("max-number-per-dag");
                $batch_size = $this->getProjectSetting("batch-size");

                if ($max_number_per_dag == 0 || $this->rs[$this->dag_name]['total_complete'] < $max_number_per_dag ) {
                    // Still some available
                    ?>
                        <div class="alert alert-success">
                            <h6 class="text-center">
                                Additional EKGs need to be reviewed.
                            </h6>
                            <h6 class="text-center">
                                Please press the button below to claim the
                                the next batch of up to <?php echo $batch_size?> records.
                            </h6>
                            <br/>
                            <form method="POST">
                                <div class="text-center">
                                    <button name="get_batch" value="1" class="btn btn-success btn-lg">Get the Next Batch</button>
                                </div>
                            </form>
                        </div>
                    <?php
                } else {
                    // Dag has exceeded max allowed
                    ?>
                        <div class="alert alert-success">
                            <p class="text-center">
                                Thank you for reviewing the maximum number of EKGs permitted by your group.
                            </p>
                        </div>
                    <?php
                }
            }
        }
        ?>

        <br/>

        <?php
            // Render progress bars for this user:
            $this->renderProgressArea($dag_progress, "Your Progress");
            $this->renderProgressArea($overall_progress, "Overall Study Progress");
        ?>

    </div>
</main>



<style>

    body { background-color: #e9ecef; }
    .jumbotron { background-color: #ffffff; }
    div.alert {border-color: #666 !important;}
    .navbar {margin-bottom: 0; }
    .navbar-custom>li { display: inline-block; padding-left: 20px;}
    .navbar-custom .nav-link { font-size: 125%; }
    #ekg_nav ul { display:inline-block; float:right;}
    /*.progress-detail {font-size: 10pt; color: #666}*/
    .progress {height: 25px; border: 1px solid #666; font-size: 125%; }
    #pagecontainer { margin-top: 25px;}
</style>

<script>
    $('#ekg_nav').insertBefore('#pagecontainer').show();
</script>