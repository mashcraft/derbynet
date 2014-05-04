<?php
session_start();
require_once('data.inc');
require_once('authorize.inc');
require_permission(ASSIGN_RACER_IMAGE_PERMISSION);

require_once('photo-config.inc');

function scan_directory($directory, $pattern) {
  $files = array();
  $dh  = opendir($directory);
  while (($filename = readdir($dh)) !== false) {
	if (preg_match($pattern, $filename) && is_file($directory.'\\'.$filename)) {
	  $files[] = $filename;
	}
  }
  closedir($dh);

  return $files;
}

$allfiles = scan_directory($photoOriginalsDirectory,
						   "/(jpg|jpeg|png|gif|bmp)/i");

// TODO: line-height?  "End of photos" text aligns with thumbnail image bottom.
// *** Both div's are overhanging the bottom by the amount taken up by the banner and refresh button!
// *** height=100% could be at issue.
//
// TODO: Separate requests to bind or remove photo.
//
// TODO: Allow for more than one kind of associated image.
//
// TODO (BUG): Drag an assigned picture back to the dock, re-crop, then drag to racer:
//   tiny image doesn't get updated
//
?>
<!DOCTYPE HTML>
<html>
<head>
<title>Assign Racer Photos</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="jquery-mobile/jquery.mobile-1.4.2.css"/>
<?php require('stylesheet.inc'); ?>
<script type="text/javascript" src="jquery.js"></script>
<script type="text/javascript" src="jquery-ui-1.10.4/ui/jquery-ui.js"></script>
<script type="text/javascript" src="jquery.ui.touch-punch.min.js"></script>
<script type="text/javascript" src="checkin.js"></script>
<script type="text/javascript">
  // target is a jquery for list items that should be made assignable,
  // i.e., can receive new photo assignments.
  function make_assignable(target) {
	  target.droppable({
		scope: "assign",
		hoverClass: "droppableHover",
		drop: function(event, ui) {
			var photo_base_name = ui.draggable.attr("data-image-filename");
			var racer_id = $(this).attr("data-racer-id");

			console.log('drop: photo_base_name = ' + photo_base_name);
			console.log('drop: urlencode(photo_base_name) = ' +
						encodeURIComponent(photo_base_name));
			console.log('drop: racer_id = ' + racer_id);

			changeRacerPhotoAjax(0, racer_id, photo_base_name);
			ui.draggable.closest(".thumbnail").addClass("hidden");
			$(this).prepend('<img class="assigned"' +
							' data-image-filename="' + photo_base_name + '"' +
							' src="photo-fetch.php/tiny/' +
							encodeURIComponent(photo_base_name) + '"/>');
			make_discardable($(this).find(".assigned"));
			// Once dropped, make no longer droppable!
			$(this).droppable("destroy");
		  }
		});
  }

  function make_discardable(target) {
	target.draggable({
		    revert: 'invalid',
			scope: 'undo',

		    helper: 'clone',
		    appendTo: 'body',
		    opacity: 0.5,
			});
  }

  $(function() {
	  make_assignable($(".without-photo"));
	  $(".unassigned-photo").draggable({
		    helper: 'clone',
			appendTo: 'body',
			// When using helper: clone, the clone's top left corner
			// appears to be what controls selection of the droppable
			// target, and I don't see any way to change it to use the
			// mouse cursor instead.  If you can't beat 'em, join 'em:
			// use cursorAt to force the clone's top to match the
			// cursor.  (For picking from the list of racers, we don't
			// care so much about horizontal position.)

			// TODO: Don't know why only top has effect here.
			cursorAt: { top: 0, left: 20 },
			opacity: 0.5,
		    revert: 'invalid',
			scope: "assign",
			});

	  $(".photothumbs").droppable({
		    hoverClass: 'droppableHover',
			scope: 'undo',
			drop: function(event, ui) {
			  // ui.draggable will be the assigned <img> itself
			  var image_filename = ui.draggable.attr("data-image-filename");
			  console.log("discarded image filename = " + image_filename);

			  var list_item = ui.draggable.closest("li");
			  list_item.addClass("without-photo");
			  make_assignable(list_item);

			  ui.draggable.remove();
			  var hidden_thumbnail = $("img[data-image-filename='" + image_filename + "']").closest(".thumbnail");
			  hidden_thumbnail.removeClass("hidden");
			  // A previous drag may have added a style property with
			  // top and left values that will leave the image out of
			  // place; we need to clear them.
			  hidden_thumbnail.find("img")
				.css("top", "")
				.css("left", "");
			  removeRacerPhoto(list_item.attr("data-racer-id"));
		  }
		});

	  make_discardable($(".assigned"));
	});

</script>
<style type="text/css">
/* CSS adapted from
http://blog.stevensanderson.com/2011/10/05/full-height-app-layouts-a-css-trick-to-make-it-easier/
*/

body { margin: 0; }

.body-wrapper { /* body row */
 position: absolute;
 top: 128px;
 bottom: 0;
 left: 0;
 right: 0;
 overflow: hidden;
}

.thumblist {
 position: absolute;
 top: 0;
 bottom: 0;
 left: 0;
 width: 300px;
 overflow-x: hidden;
 overflow-y: auto;
 z-index: 1;
}

.thumblist li { z-index: 2; }

.photothumbs {
 position: absolute;
 top: 0;
 bottom: 0;
 left: 300px;
 right: 0;
   overflow-x: visible;
 overflow-y: auto;
 z-index: 1;
}

img { z-index: 30; }

.droppableHover {
  background-color: #ffffb4;
}

</style>
</head>
<body>
<?php $banner_title = 'Racer Photos'; require('banner.inc'); ?>

<div class="block_buttons">
<form method="link">
  <input type="submit" value="Refresh"/>
</form>
</div>

<div class="body-wrapper">

<div class="thumblist">
<ul data-role="listview" class="ui-listview">
<?php
require_once('data.inc');
$rs = odbc_exec($conn, 'select racerid, lastname, firstname, imagefile, carnumber, class'
				.' from registrationinfo'
				.' inner join classes'
				.' on registrationinfo.classid = classes.classid'
				.' order by lastname, firstname');

$racers_by_photo = array();
while (odbc_fetch_row($rs)) {
  $raw_imagefile = odbc_result($rs, 'imagefile');
  $racer = array('firstname' => odbc_result($rs, 'firstname'),
				 'lastname' => odbc_result($rs, 'lastname'),
				 'class' => odbc_result($rs, 'class'),
				 'racerid' => odbc_result($rs, 'racerid'),
				 'imagefile' => $raw_imagefile);


  $css_classes = 'ui-li-static ui-li-has-thumb';
  if (odbc_result($rs, 'imagefile') !== "") {  // If there's an associated photo...
	$image_filename = basename($raw_imagefile);
	$racers_by_photo[$image_filename] = $racer;
	if (array_search($image_filename, $allfiles) === false) {
	  $css_classes .= ' lost_photo';
	}
  } else {
	$css_classes .= ' without-photo';
  }

  echo '<li data-racer-id="'.$racer['racerid'].'" '
           .' class="'.$css_classes.'"'
    	   .'>';

  if ($raw_imagefile != '') {
	echo "\n".'<img class="assigned"'
	  .' data-image-filename="'.htmlspecialchars($image_filename).'"'
	  .' src="photo-fetch.php/tiny/'.urlencode($image_filename).'"'
	  .'/>';
  }
  echo odbc_result($rs, 'FirstName').' '.odbc_result($rs, 'LastName');
  echo '<p><strong>'.odbc_result($rs, 'carnumber').':</strong> '.odbc_result($rs, 'class').'</p>';
  echo '</li>'."\n";
}

?>
</ul>

</div>

<div class="photothumbs">
<?php
foreach ($allfiles as $imagefile) {
  echo '<div class="thumbnail'.(isset($racers_by_photo[$imagefile]) ? ' hidden' : '').'">';
  echo '<a href="photo-crop.php?name='.urlencode($imagefile).'">';
  echo '<img class="unassigned-photo" data-image-filename="'.htmlspecialchars($imagefile).'" src="photo-fetch.php/thumb/';
  $thumbfile = $photoThumbsDirectory.'\\'.$imagefile;
  if (file_exists($thumbfile)) {
	echo @filemtime($thumbfile).'/';
  }
  echo urlencode($imagefile);
  echo '"/>';
  echo '</a>';
  // echo $imagefile;
  echo '</div>'."\n";
}
?>
</div>

</div>
<script type="text/javascript">

function changeRacerPhotoAjax(previous, racer, photo) {
  console.log('changeRacerPhotoAjax: previous racer = ' + previous
			  + ', new racer = ' + racer + ', photo = ' + photo);

   var xmlhttp = new XMLHttpRequest();
   xmlhttp.open("POST", g_checkin_action_url, /*async*/true);
   xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
   xmlhttp.onreadystatechange = readystate_handler;
   xmlhttp.send("action=photo&racer=" + racer
				+ "&previous=" + previous
				+ "&photo=" + encodeURIComponent("<?php echo addcslashes($photoThumbsDirectory.'\\', '\\\"'); ?>" + photo));
   ajax_add_request();
}

function removeRacerPhoto(previous) {
  changeRacerPhotoAjax(previous, 0, '');
}
</script>
<div id="ajax_working" class="hidden">
  <span id="ajax_num_requests">0</span> request(s) pending.
</div>
</body>
<?php odbc_close($conn); ?>
</html>
