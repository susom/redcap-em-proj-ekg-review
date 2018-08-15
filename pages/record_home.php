<?php
/** @var \Stanford\EkgReview\EkgReview $this */


$html = new HtmlPage();

$html->PrintHeaderExt();


$progress = $this->getProgress($project_id,$this->group_id);

$this->emDebug($progress);

?>

<nav class="navbar navbar-inverse">
    <div class="container-fluid">
        <div class="navbar-header">
            <a class="navbar-brand" href="#">AHS EKG Review</a>
        </div>
        <ul class="nav navbar-nav navbar-right">
            <li><a href="#"><span class="glyphicon glyphicon-repeat"></span> <?php echo $progress['text'] ?></a></li>
            <li><a href="#"><span class="glyphicon glyphicon-user"></span> <?php echo USERID ?></a></li>
            <li><a class="return-to-redcap" href="/index.php?action=myprojects"><span class="glyphicon glyphicon-log-in"></span> My Projects</a></li>
        </ul>
    </div>
</nav>


<main role="main">

    <div class="jumbotron text-center">

        <div class="text-center">
            <img src="<?php echo $this->getUrl("assets/logo.png") ?>" />
        </div>
        <br>
        <p class="text-center">Thank you for your assistance with the AHS EKG Scoring.
        </p>

        <p class="text-center">
            You may stop at any time and return to this page to resume.
        </p>

        <form method="POST">
            <div class="text-center">
                <button name="score_next" value="1" class="btn btn-primary btn-lg">Score Next EKG</button>
            </div>
        </form>

    </div>
</main>



<style>

    .navbar {margin-bottom: 0px; }

    .logo {
        width: 80%;
        height: 80px;
        /*display: inline-block;*/
        background: url("<?php echo $this->getUrl("assets/logo.png")?>");
        background-repeat: no-repeat;
        background-size: contain;
    }

</style>


<script>
    $('.return-to-redcap').bind('click',function() {
        window.location('https://')
    })

    $('#score').bind('click', function() {

    });



</script>