<?php
/** @var \Stanford\EkgReview\EkgReview $this */


$html = new HtmlPage();

$html->PrintHeaderExt();


$progress = $this->getProgress($project_id,$this->group_id);
$percent = $progress['percent'];

$unassigned = $this->getUnassignedRecords();



//$this->emDebug($progress);

?>

<nav class="navbar navbar-dark bg-dark navbar-inverse">
    <div class="container-fluid">
        <div class="navbar-header">
            <a class="navbar-brand" href="#">EKG Review</a>
        </div>
        <ul class="nav navbar-nav navbar-right">
            <li class="nav-item">
                <a class='nav-link' href="#">
                    <?php echo $progress['text'] ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-user"></i> <?php echo USERID ?>
                </a>
            </li>
            <li>
                <a class="nav-link return-to-redcap" href="/index.php?action=myprojects">
                    <i class="far fa-list-alt"></i>  My Projects
                </a>
            </li>
        </ul>
    </div>
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

            <form method="POST">
                <div class="text-center">
                    <button name="score_next" value="1" class="btn btn-primary btn-lg">Score Next EKG</button>
                </div>
            </form>
            <?php
        } elseif (count($unassigned) == 0) {
            // There are no more unassigned records
            ?>
            <div class="alert alert-success text-center">
                Congratulations - all EKGs have been claimed.  Your work is done!
            </div>
            <?php
        } else {
            // There are unassigned records remaining

            $max_number_per_dag = $this->getProjectSetting("max-number-per-dag");

            if ($max_number_per_dag == 0) {
                // unlimited!
            } elseif ($progress['complete'] < $max_number_per_dag) {
                // Still some available
                ?>

                <div class="alert alert-success">
                    <p class="text-center">
                        Additional EKGs need to be reviewed.
                    </p>
                    <p class="text-center">
                        Please press the button below to claim the
                        the next batch of records.
                    </p>
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

        <p>Your Progress <span class="progress-detail"><?php echo $progress['complete'] . " of " . $progress['total'] . " records in your bin are complete" ?></span></p>
        <div class="progress">
            <div class="progress-bar progress-bar-striped progress-black" style="width:<?php echo $percent ?>%">
                <?php echo $progress['percent'] ?>%
            </div>
        </div>


        <p>Overall Progress <span class="progress-detail"><?php echo $this->totalComplete . " of " . $this->totalCount . " records are complete" ?></span></p>
        <div class="progress">
            <div class="progress-bar progress-bar-success progress-bar-striped progress-black" style="width:<?php echo $this->totalPercent ?>%">
                <?php echo $this->totalPercent ?>%
            </div>
        </div>


    </div>
</main>



<style>
    .navbar {margin-bottom: 0; }
    .nav-item { display: inline-block;}
    .progress-detail {font-size: 10pt; color: #666}
</style>
