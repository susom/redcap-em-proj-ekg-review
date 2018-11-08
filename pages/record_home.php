<?php
/** @var \Stanford\EkgReview\EkgReview $this */


$html = new HtmlPage();

$html->PrintHeaderExt();

$progress = $this->getProgress($project_id,$this->group_id);
$percent = $progress['percent'];

$this->doRecordAnalysis();

//$this->emDebug($progress);

?>

<nav class="navbar  navbar-dark bg-dark navbar-inverse">
    <a class="navbar-brand" href="#"><h3>EKG Review</h3></a>
    <ul class="nav navbar-expand navbar-nav navbar-right navbar-custom">
        <li class="nav-item active">
            <a class="nav-link" href="#">
                <?php echo $progress['text'] ?>
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

        if ($percent != 100) {
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
                                Hint: you can use 'hotkeys' to quickly navigate the ECG with your keyboard.<br>
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
        } elseif (count($this->availableRecords) == 0) {
            // There are no more available records
            ?>
            <div class="alert alert-success text-center">
                <h6 class="text-center">Congratulations - all EKGs have been assigned.  Your work is done!</h6>
            </div>
            <?php
        } else {
            // There are unassigned records remaining

            $max_number_per_dag = $this->getProjectSetting("max-number-per-dag");

            if ($max_number_per_dag == 0 || $this->totalCompleteDag < $max_number_per_dag ) {
                // Still some available
                ?>
                <div class="alert alert-success">
                    <h6 class="text-center">
                        Additional EKGs need to be reviewed.
                    </h6>
                    <h6 class="text-center">
                        Please press the button below to claim the
                        the next batch of records.
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

        ?>

        <br/>

        <p><b>Your Progress:</b> <span class="progress-detail"><?php echo $this->totalCompleteDag . " of " . $this->totalCountDag . " records in your bin have been reviewed" ?></span></p>
        <div class="progress">
            <div class="progress-bar progress-bar-striped progress-black" style="width:<?php echo $percent ?>%">
                <?php echo $this->totalPercentDag ?>%
            </div>
        </div>


        <p><b>Overall Progress:</b> <span class="progress-detail"><?php echo $this->totalComplete . " of " . $this->totalCount . " records are complete" ?></span></p>
        <div class="progress">
            <div class="progress-bar progress-bar-success progress-bar-striped progress-black" style="width:<?php echo $this->totalPercent ?>%">
                <?php echo $this->totalPercent ?>%
            </div>
        </div>


    </div>
</main>



<style>
    div.alert {border-color: #666 !important;}
    .navbar {margin-bottom: 0; }
    .navbar-custom>li { display: inline-block; padding-left: 20px;}
    .navbar-custom .nav-link { font-size: 125%; }
    /*.progress-detail {font-size: 10pt; color: #666}*/
    .progress {height: 25px; border: 1px solid #666; font-size: 125%; }
</style>
