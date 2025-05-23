<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);
ini_set('session.gc_maxlifetime', 7200);
ini_set('user_agent', 'PHP_Flickr/1.0');
session_set_cookie_params(7200);
session_start();
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();

set_timezone();
$myDate = date('Y-m-d');
$chart = "Combo-$myDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

$statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
ensure_db_ok($statement2);
$result2 = $statement2->execute();
$todaycount = $result2->fetchArray(SQLITE3_ASSOC);

if(isset($_GET['custom_image'])){
  if(isset($config["CUSTOM_IMAGE"])) {
  ?>
    <br>
    <h3><?php echo $config["CUSTOM_IMAGE_TITLE"]; ?></h3>
    <?php
    $image_data = file_get_contents($config["CUSTOM_IMAGE"]);
    $image_base64 = base64_encode($image_data);
    $img_tag = "<img src='data:image/png;base64," . $image_base64 . "'>";
    echo $img_tag;
  }
  die();
}

if(isset($_GET['blacklistimage'])) {
  ensure_authenticated('You must be authenticated.');
  $imageid = $_GET['blacklistimage'];
  $file_handle = fopen($home."/BirdNET-Pi/scripts/blacklisted_images.txt", 'a+');
  fwrite($file_handle, $imageid . "\n");
  fclose($file_handle);
  unset($_SESSION['images']);
  die("OK");
}

if(isset($_GET['fetch_chart_string']) && $_GET['fetch_chart_string'] == "true") {
  $myDate = date('Y-m-d');
  $chart = "Combo-$myDate.png";
  echo $chart;
  die();
}

if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true" && isset($_GET['previous_detection_identifier'])) {

  $statement4 = $db->prepare('SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 15');
  ensure_db_ok($statement4);
  $result4 = $statement4->execute();
  if(!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
  }
  $iterations = 0;
  $flickr = null;

  // hopefully one of the 5 most recent detections has an image that is valid, we'll use that one as the most recent detection until the newer ones get their images created
  while($mostrecent = $result4->fetchArray(SQLITE3_ASSOC)) {
    $comname = preg_replace('/ /', '_', $mostrecent['Com_Name']);
    $sciname = preg_replace('/ /', '_', $mostrecent['Sci_Name']);
    $comnamegraph = str_replace("'", "\'", $mostrecent['Com_Name']);
    $comname = preg_replace('/\'/', '', $comname);
    $filename = "By_Date/".$mostrecent['Date']."/".$comname."/".$mostrecent['File_Name'];

    // check to make sure the image actually exists, sometimes it takes a minute to be created\
    if(file_exists($home."/BirdSongs/Extracted/".$filename.".png")){
      if($_GET['previous_detection_identifier'] == $filename) { die(); }
      if($_GET['only_name'] == "true") { echo $comname.",".$filename;die(); }

      $iterations++;

      if (!empty($config["FLICKR_API_KEY"])) {
        if ($flickr === null) {
          $flickr = new Flickr();
        }
        if ($_SESSION["FLICKR_FILTER_EMAIL"] !== $flickr->get_uid_from_db()['uid']) {
          if (isset($_SESSION["FLICKR_FILTER_EMAIL"])) {
            $_SESSION['images'] = [];
          }
          $_SESSION["FLICKR_FILTER_EMAIL"] = $flickr->get_uid_from_db()['uid'];
        }

        // if we already searched flickr for this species before, use the previous image rather than doing an unneccesary api call
        $key = array_search($comname, array_column($_SESSION['images'], 0));
        if ($key !== false) {
          $image = $_SESSION['images'][$key];
        } else {
          $flickr_cache = $flickr->get_image($mostrecent['Sci_Name']);
          array_push($_SESSION["images"], array($comname, $flickr_cache["image_url"], $flickr_cache["title"], $flickr_cache["photos_url"], $flickr_cache["author_url"], $flickr_cache["license_url"]));
          $image = $_SESSION['images'][count($_SESSION['images']) - 1];
        }
      }
    ?>
        <style>
        .fade-in {
          opacity: 1;
          animation-name: fadeInOpacity;
          animation-iteration-count: 1;
          animation-timing-function: ease-in;
          animation-duration: 1s;
        }

        @keyframes fadeInOpacity {
          0% {
            opacity: 0;
          }
          100% {
            opacity: 1;
          }
        }
        </style>
        <table class="<?php echo ($_GET['previous_detection_identifier'] == 'undefined') ? '' : 'fade-in';  ?>">
          <h3>Most Recent Detection: <span style="font-weight: normal;"><?php echo $mostrecent['Date']." ".$mostrecent['Time'];?></span></h3>
          <tr>
            <td class="relative"><a target="_blank" href="index.php?filename=<?php echo $mostrecent['File_Name']; ?>"><img class="copyimage" title="Open in new tab" width="25" height="25" src="images/copy.png"></a>
            <div class="centered_image_container" style="margin-bottom: 0px !important;">
              <?php if(!empty($config["FLICKR_API_KEY"]) && strlen($image[2]) > 0) { ?>
                <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image[1]; ?>" class="img1">
              <?php } ?>
              <form action="" method="GET">
                  <input type="hidden" name="view" value="Species Stats">
                  <button type="submit" name="species" value="<?php echo $mostrecent['Com_Name'];?>"><?php echo $mostrecent['Com_Name'];?></button>
                  <br>
                  <i><?php echo $mostrecent['Sci_Name'];?></i>
                  <a href="<?php $info_url = get_info_url($mostrecent['Sci_Name']); $url = $info_url['URL']; echo $url ?>" target="_blank">
                  <img style="width: unset !important; display: inline; height: 1em; cursor: pointer;" title="Info" src="images/info.png" width="25"></a>
                  <a href="https://wikipedia.org/wiki/<?php echo $sciname;?>" target="_blank"><img style="width: unset !important; display: inline; height: 1em; cursor: pointer;" title="Wikipedia" src="images/wiki.png" width="25"></a>
                  <img style="width: unset !important;display: inline;height: 1em;cursor:pointer" title="View species stats" onclick="generateMiniGraph(this, '<?php echo $comnamegraph; ?>')" width=25 src="images/chart.svg">
                  <br>Confidence: <?php echo $percent = round((float)round($mostrecent['Confidence'],2) * 100 ) . '%';?><br></div><br>
                  <div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo $filename.".png";?>"></div>
                  </td></form>
          </tr>
        </table> <?php break;
      }
  }
  if($iterations == 0) {
    if($todaycount['COUNT(*)'] > 0) {
      echo "<h3>Your system is currently processing a backlog of audio. This can take several hours before normal functionality of your BirdNET-Pi resumes.</h3>";
    } else {
      echo "<h3>No Detections For Today.</h3>";
    }
  }
  die();
}

function get_chart_data($db, $force_regen = false) {
  if ($force_regen || !isset($_SESSION['chart_data'])) {
    $statement = $db->prepare('SELECT COUNT(*) FROM detections');
    ensure_db_ok($statement);
    $result = $statement->execute();
    $totalcount = $result->fetchArray(SQLITE3_ASSOC);

    $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
    ensure_db_ok($statement2);
    $result2 = $statement2->execute();
    $todaycount = $result2->fetchArray(SQLITE3_ASSOC);

    $statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')');
    ensure_db_ok($statement3);
    $result3 = $statement3->execute();
    $hourcount = $result3->fetchArray(SQLITE3_ASSOC);

    $statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')');
    ensure_db_ok($statement5);
    $result5 = $statement5->execute();
    $speciestally = $result5->fetchArray(SQLITE3_ASSOC);

    $statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections');
    ensure_db_ok($statement6);
    $result6 = $statement6->execute();
    $totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

    // Store the data in session to be reused by other charts
    $_SESSION['chart_data'] = [
      'totalcount' => $totalcount,
      'todaycount' => $todaycount,
      'hourcount' => $hourcount,
      'speciestally' => $speciestally,
      'totalspeciestally' => $totalspeciestally
    ];
  }

  return $_SESSION['chart_data'];
}

if(isset($_GET['ajax_left_chart']) && $_GET['ajax_left_chart'] == "true") {

  // Force the data to regenerate and store it in session
  $chart_data = get_chart_data($db, true);
?>
<table>
  <tr>
    <th>Total</th>
    <td><?php echo $chart_data['totalcount']['COUNT(*)'];?></td>
  </tr>
  <tr>
    <th>Today</th>
    <td><form action="" method="GET"><button type="submit" name="view" value="Todays Detections"><?php echo $chart_data['todaycount']['COUNT(*)'];?></button></td>
    </form>
  </tr>
  <tr>
    <th>Last Hour</th>
    <td><?php echo $chart_data['hourcount']['COUNT(*)'];?></td>
  </tr>
  <tr>
    <th>Species Detected Today</th>
    <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally']['COUNT(DISTINCT(Sci_Name))'];?></button></td>
    </form>
  </tr>
  <tr>
    <th>Total Number of Species</th>
    <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally']['COUNT(DISTINCT(Sci_Name))'];?></button></td>
    </form>
  </tr>
</table>
<?php
  die();
}

if(isset($_GET['ajax_center_chart']) && $_GET['ajax_center_chart'] == "true") {

  // Retrieve the cached data from session without regenerating
  $chart_data = get_chart_data($db);
?>
  <table><tr>
  <th>Total</th>
  <th>Today</th>
  <th>Last Hour</th>
  <th>Species Total</th>
  <th>Species Today</th>
      </tr>
      <tr>
      <td><?php echo $chart_data['totalcount']['COUNT(*)'];?></td>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Todays Detections"><?php echo $chart_data['todaycount']['COUNT(*)'];?></td></form>
      <td><?php echo $chart_data['hourcount']['COUNT(*)'];?></td>
      <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally']['COUNT(DISTINCT(Sci_Name))'];?></button></td></form>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally']['COUNT(DISTINCT(Sci_Name))'];?></button></td></form>
  </tr>
  </table>

<?php
  die();
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Overview</title>
</head>';
}
?>
<div class="overview">
  <dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
    <h1 id="modalHeading"></h1>
    <p id="modalText"></p>
    <button onclick="hideDialog()">Close</button>
    <button style="font-weight:bold;color:blue" onclick="if(confirm('Are you sure you want to blacklist this image?')) { blacklistImage(); }">Blacklist this image</button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
  <script src="static/Chart.bundle.js"></script>
  <script src="static/chartjs-plugin-trendline.min.js"></script>
  <script>
  var last_photo_link;
  var dialog = document.querySelector('dialog');
  dialogPolyfill.registerDialog(dialog);

  function showDialog() {
    document.getElementById('attribution-dialog').showModal();
  }

  function hideDialog() {
    document.getElementById('attribution-dialog').close();
  }

  function blacklistImage() {
    const match = last_photo_link.match(/\d+$/); // match one or more digits
    const result = match ? match[0] : null; // extract the first match or return null if no match is found
    console.log(last_photo_link)
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText.length > 0) {
       location.reload();
      }
    }
    xhttp.open("GET", "overview.php?blacklistimage="+result, true);
    xhttp.send();

  }

  function setModalText(iter, title, text, authorlink, photolink, licenseurl) {
    document.getElementById('modalHeading').innerHTML = "Photo: \""+decodeURIComponent(title.replaceAll("+"," "))+"\" Attribution";
    document.getElementById('modalText').innerHTML = "<div><img style='border-radius:5px;max-height: calc(100vh - 15rem);display: block;margin: 0 auto;' src='"+photolink+"'></div><br><div style='white-space:nowrap'>Image link: <a target='_blank' href="+text+">"+text+"</a><br>Author link: <a target='_blank' href="+authorlink+">"+authorlink+"</a><br>License URL: <a href="+licenseurl+" target='_blank'>"+licenseurl+"</a></div>";
    last_photo_link = text;
    showDialog();
  }
  </script>  
<div class="overview-stats">
<div class="left-column">
</div>
<div class="right-column">
<div class="center-column">
</div>
<?php
$statement = $db->prepare("
SELECT d_today.Com_Name, d_today.Sci_Name, d_today.Date, d_today.Time, d_today.Confidence, d_today.File_Name, 
       MAX(d_today.Confidence) as MaxConfidence,
       (SELECT MAX(Date) FROM detections d_prev WHERE d_prev.Sci_Name = d_today.Sci_Name AND d_prev.Date < DATE('now', 'localtime')) as LastSeenDate,
       (SELECT COUNT(*) FROM detections d_occ WHERE d_occ.Sci_Name = d_today.Sci_Name AND d_occ.Date = DATE('now', 'localtime')) as OccurrenceCount
FROM detections d_today
WHERE d_today.Date = DATE('now', 'localtime')
GROUP BY d_today.Sci_Name
");
ensure_db_ok($statement);
$result = $statement->execute();

$new_species = [];
$rare_species = [];
$rare_species_threshold = isset($config['RARE_SPECIES_THRESHOLD']) ? $config['RARE_SPECIES_THRESHOLD'] : 30;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $last_seen_date = $row['LastSeenDate'];
    if ($last_seen_date === NULL) {
        $new_species[] = $row;
    } else {
        $date1 = new DateTime($last_seen_date);
        $date2 = new DateTime('now');
        $interval = $date1->diff($date2);
        $days_ago = $interval->days;
        if ($days_ago > $rare_species_threshold) {
            $row['DaysAgo'] = $days_ago;
            $rare_species[] = $row;
        }
    }
}

if (!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
}
$flickr = null;

function display_species($species_list, $title, $show_last_seen=false) {
    global $config, $_SESSION, $flickr;
    $species_count = count($species_list);
    if ($species_count > 0): ?>
        <div class="<?php echo strtolower(str_replace(' ', '_', $title)); ?>">
            <h2 style="text-align:center;"><?php echo $species_count; ?> <?php echo strtolower($title); ?> detected today!</h2>
            <?php if ($species_count > 5): ?>
                <table><tr><td style="text-align:center;"><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>">Open Today's recordings page</button></form></td></tr></table>
            <?php else: ?>
                <table>
                    <?php
                    $iterations = 0;
                    foreach($species_list as $todaytable):
                        $iterations++;
                        $comname = preg_replace('/ /', '_', $todaytable['Com_Name']);
                        $comname = preg_replace('/\'/', '', $comname);
                        $comnamegraph = str_replace("'", "\'", $todaytable['Com_Name']);
                        $filename = "/By_Date/".$todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
                        $filename_formatted = $todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
                        $sciname = preg_replace('/ /', '_', $todaytable['Sci_Name']);
                        $engname = get_com_en_name($todaytable['Sci_Name']);
                        $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
                        $info_url = get_info_url($todaytable['Sci_Name']);
                        $url = $info_url['URL'];
                        $url_title = $info_url['TITLE'];

                        $image_url = ""; // Default empty image URL

                        if (!empty($config["FLICKR_API_KEY"])) {
                            if ($flickr === null) {
                                $flickr = new Flickr();
                            }
                            if (isset($_SESSION["FLICKR_FILTER_EMAIL"]) && $_SESSION["FLICKR_FILTER_EMAIL"] !== $flickr->get_uid_from_db()['uid']) {
                                unset($_SESSION['images']);
                                $_SESSION["FLICKR_FILTER_EMAIL"] = $flickr->get_uid_from_db()['uid'];
                            }

                            // Check if the Flickr image has been cached in the session
                            $key = array_search($comname, array_column($_SESSION['images'], 0));
                            if ($key !== false) {
                                $image = $_SESSION['images'][$key];
                            } else {
                                // Retrieve the image from Flickr API and cache it
                                $flickr_cache = $flickr->get_image($todaytable['Sci_Name']);
                                array_push($_SESSION["images"], array($comname, $flickr_cache["image_url"], $flickr_cache["title"], $flickr_cache["photos_url"], $flickr_cache["author_url"], $flickr_cache["license_url"]));
                                $image = $_SESSION['images'][count($_SESSION['images']) - 1];
                            }
                            $image_url = $image[1] ?? ""; // Get the image URL if available
                        }

                        $last_seen_text = "";
                        if ($show_last_seen && isset($todaytable['DaysAgo'])) {
                            $days_ago = $todaytable['DaysAgo'];
                            if ($days_ago > 30) {
                                $months_ago = floor($days_ago / 30);
                                $last_seen_text = "<br><i><span class='text left'>Last seen: </span>{$months_ago}mo ago</i>";
                            } else {
                                $last_seen_text = "<br><i><span class='text left'>Last seen: </span>{$days_ago}d ago</i>";
                            }
                        }

                        $occurrence_text = "";
                        if (isset($todaytable['OccurrenceCount']) && $todaytable['OccurrenceCount'] > 1) {
                            $occurrence_text = " ({$todaytable['OccurrenceCount']}x)";
                        }
                    ?>
                    <tr class="relative" id="<?php echo $iterations; ?>">
                        <td><?php if (!empty($image_url)): ?>
                          <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image_url; ?>" style="max-width: none; height: 50px; width: 50px; border-radius: 5px; cursor: pointer;" class="img1" title="Image from Flickr" />
                        <?php endif; ?></td>
                        <td id="recent_detection_middle_td">
                            <div><form action="" method="GET">
                                    <input type="hidden" name="view" value="Species Stats">
                                    <button class="a2" type="submit" name="species" value="<?php echo $todaytable['Com_Name']; ?>"><?php echo $todaytable['Com_Name']; ?></button>
                                    <br><i><?php echo $todaytable['Sci_Name']; ?><br>
                                        <a href="<?php echo $url; ?>" target="_blank"><img style="height: 1em;cursor:pointer;float:unset;display:inline" title="<?php echo $url_title; ?>" src="images/info.png" width="25"></a>
                                        <a href="https://wikipedia.org/wiki/<?php echo $sciname; ?>" target="_blank"><img style="height: 1em;cursor:pointer;float:unset;display:inline" title="Wikipedia" src="images/wiki.png" width="25"></a>
                                        <?php if ($show_last_seen): ?>
                                            <img style="height: 1em;cursor:pointer;float:unset;display:inline" title="View species stats" onclick="generateMiniGraph(this, '<?php echo $comnamegraph; ?>', 160)" width="25" src="images/chart.svg">
                                        <?php endif; ?>
                                        <a target="_blank" href="index.php?filename=<?php echo $todaytable['File_Name']; ?>"><img style="height: 1em;cursor:pointer;float:unset;display:inline" class="copyimage-mobile" title="Open in new tab" width="16" src="images/copy.png"></a>
                                    </i>
                            </form></div>
                        </td>
                        <td style="white-space: nowrap;"><?php
                                echo '<span class="text left">Max confidence: </span>' . round($todaytable['Confidence'] * 100 ) . '%' . $occurrence_text;
                                echo "<br><span class='text left'>First detection: </span>{$todaytable['Time']}";
                                echo $last_seen_text;
                        ?></td>
                      </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif;
}

display_species($new_species, 'New Species');
display_species($rare_species, 'Rare Species', true);
?>
<div class="chart">
<?php
$refresh = $config['RECORDING_LENGTH'];
$dividedrefresh = $refresh/4;
if($dividedrefresh < 1) { 
  $dividedrefresh = 1;
}
$time = time();
if (file_exists('./Charts/'.$chart)) {
  echo "<img id='chart' src=\"Charts/$chart?nocache=$time\">";
} 
?>
</div>

<div id="most_recent_detection"></div>
<br>
<h3>5 Most Recent Detections</h3>
<div style="padding-bottom:10px;" id="detections_table"><h3>Loading...</h3></div>

<h3>Currently Analyzing</h3>
<?php
$refresh = $config['RECORDING_LENGTH'];
$time = time();
echo "<img id=\"spectrogramimage\" src=\"spectrogram.png?nocache=$time\">";

?>

<div id="customimage"></div>
<br>

</div>
</div>
</div>
<script>
// we're passing a unique ID of the currently displayed detection to our script, which checks the database to see if the newest detection entry is that ID, or not. If the IDs don't match, it must mean we have a new detection and it's loaded onto the page
function loadDetectionIfNewExists(previous_detection_identifier=undefined) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    // if there's a new detection that needs to be updated to the page
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections") || previous_detection_identifier == undefined) {
      document.getElementById("most_recent_detection").innerHTML = this.responseText;

      // only going to load left chart & 5 most recents if there's a new detection
      loadLeftChart();
      loadFiveMostRecentDetections();
      refreshTopTen();

      // Now that new HTML is inserted, re-run player init:
      initCustomAudioPlayers();
    }
  }
  xhttp.open("GET", "overview.php?ajax_detections=true&previous_detection_identifier="+previous_detection_identifier, true);
  xhttp.send();
}
function loadLeftChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("left-column")[0].innerHTML = this.responseText;
      loadCenterChart();
    }
  }
  xhttp.open("GET", "overview.php?ajax_left_chart=true", true);
  xhttp.send();
}
function loadCenterChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("center-column")[0].innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "overview.php?ajax_center_chart=true", true);
  xhttp.send();
}
function refreshTopTen() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
  if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections") || previous_detection_identifier == undefined) {
    if (document.getElementById("chart")) {document.getElementById("chart").src = "Charts/"+this.responseText+"?nocache="+Date.now();}
  }
  }
  xhttp.open("GET", "overview.php?fetch_chart_string=true", true);
  xhttp.send();
}
function refreshDetection() {
  if (!document.hidden) {
    const audioPlayers = document.querySelectorAll(".custom-audio-player");
    // If no custom-audio-player elements are found, refresh
    if (audioPlayers.length === 0) {
      loadDetectionIfNewExists();
      return;
    }
    // Check if any custom audio player is currently playing
    let isPlaying = false;
    audioPlayers.forEach((player) => {
      const audioEl = player.querySelector("audio");
      if (audioEl && audioEl.currentTime > 0 && !audioEl.paused && !audioEl.ended && audioEl.readyState > 2) {
        isPlaying = true;
      }
    });
    // If none are playing, refresh detections
    if (!isPlaying) {
      const currentIdentifier = audioPlayers[0]?.dataset.audioSrc || undefined;
      loadDetectionIfNewExists(currentIdentifier);
    }
  }
}
function loadFiveMostRecentDetections() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementById("detections_table").innerHTML= this.responseText;
    }
  }
  if (window.innerWidth > 500) {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=5", true);
  } else {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=5&mobile=true", true);
  }
  xhttp.send();
}
function refreshCustomImage(){
  // Find the customimage element
  var customimage = document.getElementById("customimage");

  function updateCustomImage() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "overview.php?custom_image=true", true);
    xhr.onload = function() {
      customimage.innerHTML = xhr.responseText;
    }
    xhr.send();
  }
  updateCustomImage();
}
function startAutoRefresh() {
    i_fn1 = window.setInterval(function(){
                    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
                    }, <?php echo $refresh; ?>*1000);
    i_fn2 = window.setInterval(refreshDetection, <?php echo intval($dividedrefresh); ?>*1000);
    if (customImage) i_fn3 = window.setInterval(refreshCustomImage, 1000);
}
<?php if(isset($config["CUSTOM_IMAGE"]) && strlen($config["CUSTOM_IMAGE"]) > 2){?>
customImage = true;
<?php } else { ?>
customImage = false;
<?php } ?>
window.addEventListener("load", function(){
  loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
  console.log(document.visibilityState);
  console.log(document.hidden);
  if (document.hidden) {
    clearInterval(i_fn1);
    clearInterval(i_fn2);
    if (customImage) clearInterval(i_fn3);
  } else {
    loadDetectionIfNewExists();
    startAutoRefresh();
  }
});
startAutoRefresh();
</script>

<style>
  .tooltip {
  background-color: white;
  border: 1px solid #ccc;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
  padding: 10px;
  transition: opacity 0.2s ease-in-out;
}
</style>
<script src="static/custom-audio-player.js"></script>
<script>
function generateMiniGraph(elem, comname, days = 30) {

  // Make an AJAX call to fetch the number of detections for the bird species
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '/todays_detections.php?comname=' + comname + '&days=' + days);
  xhr.onload = function() {
    if (xhr.status === 200) {
      var detections = JSON.parse(xhr.responseText);

      // Create a div element for the chart window
      if (typeof(window.chartWindow) != 'undefined') {
        document.body.removeChild(window.chartWindow);
        window.chartWindow = undefined;
      }
      var chartWindow = document.createElement('div');
      chartWindow.className = "chartdiv"
      document.body.appendChild(chartWindow);


            // Create a canvas element for the chart
      var canvas = document.createElement('canvas');
      canvas.width = chartWindow.offsetWidth;
      canvas.height = chartWindow.offsetHeight;
      chartWindow.appendChild(canvas);

      // Create a new Chart.js chart
      var ctx = canvas.getContext('2d');
      var chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: detections.map(item => item.date),
          datasets: [{
            label: 'Detections',
            data: detections.map(item => item.count),
            backgroundColor: '#9fe29b',
            borderColor: '#77c487',
            borderWidth: 1,
            lineTension: 0.3, // Add smoothing to the line
            pointRadius: 1, // Make the data points smaller
            pointHitRadius: 10, // Increase the area around data points for mouse events

          trendlineLinear: {
            style: "rgba(55, 99, 64, 0.5)",
            lineStyle: "solid",
            width: 1.5
          }

          }]
        },
        options: {
          layout: {
            padding: {
              right: 10
            }
          },
          title: {
            display: true,
            text: 'Detections Over ' + days + 'd'
          },
          legend: {
            display: false
          },
          scales: {
            xAxes: [{
              display: false,
              gridLines: {
                display: false // Hide the gridlines on the x-axis
              },
              ticks: {
                autoSkip: true,
                maxTicksLimit: 2
              }
            }],
            yAxes: [{
              gridLines: {
                display: false // Hide the gridlines on the y-axis
              },
              ticks: {
                beginAtZero: true,
                precision: 0,
                stepSize: 1
              }
            }]
          }
        }
      });

      var buttonRect = elem.getBoundingClientRect();
      var chartRect = chartWindow.getBoundingClientRect();
      if (window.innerWidth < 700) {
        chartWindow.style.left = 'calc(75% - ' + (chartRect.width / 2) + 'px)';
      } else {
        chartWindow.style.left = (buttonRect.right + 10) + 'px';
      }


      // Calculate the top position of the chart to center it with the button
      var buttonCenter = buttonRect.top + (buttonRect.height / 2);
      var chartHeight = chartWindow.offsetHeight;
      var chartTop = buttonCenter - (chartHeight / 2);
      chartWindow.style.top = chartTop + 'px';

      // Add a close button to the chart window
      var closeButton = document.createElement('button');
      closeButton.id = "chartcb";
      closeButton.innerText = 'X';
      closeButton.style.position = 'absolute';
      closeButton.style.top = '5px';
      closeButton.style.right = '5px';
      closeButton.addEventListener('click', function() {
        document.body.removeChild(chartWindow);
        window.chartWindow = undefined;
      });
      chartWindow.appendChild(closeButton);
      window.chartWindow = chartWindow;
    }
  };
  xhr.send();
}

// Listen for the scroll event on the window object
window.addEventListener('scroll', function() {
  // Get all chart elements
  var charts = document.querySelectorAll('.chartdiv');
  
  // Loop through all chart elements and remove them
  charts.forEach(function(chart) {
    chart.parentNode.removeChild(chart);
    window.chartWindow = undefined;
  });
});

</script>
