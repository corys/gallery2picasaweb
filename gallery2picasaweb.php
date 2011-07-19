<?php
/*
 **********************************************************
 *                  GALLERY2PICASAWEB v0.1                *
 **********************************************************
 * This script will retrieve albums from a Gallery2 installation on your server 
 * and post the albums, photos and captions to your Picasa Web Albums account.
 * 
 * This script is based on, and borrows heavily from the Gallery 2 to Flickr Import Script written by
 * Taj Morton and found at: http://www.wildgardenseed.com/Taj/Export_Gallery2_to_Flickr.shtml
 *
 * If you have any improvements, please add them to the project. 
 *
 *********************************************************
 * Copyright (c) 2011, Cory Smith coryhsmith@gmail.com
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer 
 * in the documentation and/or other materials provided with the distribution.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, 
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE 
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, 
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR 
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE 
 * USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *********************************************************
 *
 * To use this script:
 * 1) This script requires the ZEND Gdata framework which you can download from here: http://framework.zend.com/download/gdata
 * 2) Set all of the defines listed below
 * 3) Upload this file and the Zend Gdata framework to your webserver that is running Gallery2
 * 3) Open gallery2picasaweb.php in your browser. 
 *
 * When I imported my gallery, this script got killed a few times for a number of reasons.
 * It is now set to catch any Picasa API errors and continue on.  LOOK FOR RED TEXT
 * IN THE BROWSER OUTPUT TO SEE ALBUMS OR PHOTOS THAT WERE SKIPPED.  
 *
 * If you have to restart the script, there is a hacky $g2AlbumsToIgnore array that you
 * can set to ignore albums that have already been transferred.  It will also ignore all child albums.
 *
 *********************************************************
*/

// define Gallery2 parameters
define("DATABASE_HOST","localhost");   		    // $storeConfig['hostname']
define("DATABASE_USER","database_user");      // $storeConfig['username']
define("DATABASE_PASS","database_password");  // $storeConfig['password']
define("DATABASE_DB","database_name");        // $storeConfig['database']
define("DATABASE_TABLE_PREFIX","g2_");        // $storeConfig['tablePrefix']
define("DATABASE_COLUMN_PREFIX","g_");        // $storeConfig['columnPrefix']
define("BASE_DIRECTORY","/path/to/g2data");   // $gallery->setConfig('data.gallery.base',...
define("BASE_ALBUM_PATH_COMPONENT","albums"); // The path component (ie part of the filepath that comes after the BASE_DIRECTORY) for the album to process
define("BASE_ALBUM_ID",7);                    // The album id (g2_itemId found in the album URL) of the album to process.  

//define Picasa parameters
define("PICASA_EMAIL","username@email.com");  // Your Picasa (Google) email
define("PICASA_PASSWORD","password");         // Your Picasa (Google) password
define("PICASA_ALBUM_ACCESS", "private");     // valid values: public or private
define("WAIT_TIME",15);                       // Sometimes a Picasa API call fails because we're hitting it too frequently,  
																							// this sets how many seconds to wait before retrying after a fail.
define("MAX_ATTEMPTS",5); 									  // the number of time to try an API call before giving up.  Keep this number low.

// Zend path
define("ZEND_FRAMEWORK_PATH", "/path/to/Zend/library"); // the path to the Zend framework on your server

// ------------- You shouldn't need to modify anything below this line: -------------
set_include_path(ZEND_FRAMEWORK_PATH);

require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata_Photos');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
Zend_Loader::loadClass('Zend_Gdata_AuthSub');
Zend_Loader::loadClass('Zend_Gdata_Photos_PhotoQuery');

header("Content-type: text/html");

/////////////////////////////
// Authenticate with Google
/////////////////////////////

// Parameters for ClientAuth authentication
$serviceName  = Zend_Gdata_Photos::AUTH_SERVICE_NAME;
 
// Create an authenticated HTTP client
$client = Zend_Gdata_ClientLogin::getHttpClient(PICASA_EMAIL, PICASA_PASSWORD, $serviceName);
 
// Create an instance of the service
$gp = new Zend_Gdata_Photos($client);


/////////////////////////////
// Get G2 Albums
/////////////////////////////

$link = mysql_connect(DATABASE_HOST,DATABASE_USER,DATABASE_PASS);
if (!$link) {
	die("Error: Couldn't connect to database server. MySQL said: ".mysql_error());
}

$db = mysql_select_db(DATABASE_DB,$link);

if (!$db) {
	die("Error: Couldn't select the database. MySQL said: ".mysql_error());
}


// do work, starting with base album
processAlbum($gp, BASE_ALBUM_ID, "", "", BASE_ALBUM_PATH_COMPONENT, BASE_DIRECTORY);


mysql_close($link);


/////////////////////////////
// Recursively upload photos to an album and create subalbums
/////////////////////////////
function processAlbum($gp, $g2ParentAlbumId, $picasaAlbum, $albumTitle, $pathComponent, $albumPath)
{
	// this is a hack to ignore sub-albums that we don't want to process
	// To use, replace the array values with actual album ids
	$g2AlbumsToIgnore = array("XXXXX", "XXXXX", "XXXXX");
	
	// set the id of the current picasa album, if we have it
	try {
		$pParentAlbumId = $picasaAlbum->gphotoId->text;
	} catch (Zend_Gdata_App_Exception $e) {
	    $pParentAlbumId = "";
	}	
			
	$albumPath = $albumPath."/".$pathComponent;
	
	// I needed to do this the first time I ran the script
	// exec("chmod 755 ".$albumPath."/*.jpg");
	
	// get any photos in this album
	$sql = "SELECT 
		i.".DATABASE_COLUMN_PREFIX."id, 
		i.".DATABASE_COLUMN_PREFIX."title, 
		i.".DATABASE_COLUMN_PREFIX."description,
		i.".DATABASE_COLUMN_PREFIX."originationTimestamp,  
		fse.".DATABASE_COLUMN_PREFIX."pathComponent 
	FROM ".DATABASE_TABLE_PREFIX."Item i 
	INNER JOIN ".DATABASE_TABLE_PREFIX."ChildEntity ce 
		ON i.".DATABASE_COLUMN_PREFIX."id = 
		ce.".DATABASE_COLUMN_PREFIX."id 
	INNER JOIN ".DATABASE_TABLE_PREFIX."PhotoItem pe 
		ON i.".DATABASE_COLUMN_PREFIX."id = pe.".DATABASE_COLUMN_PREFIX."id 
	INNER JOIN ".DATABASE_TABLE_PREFIX."FileSystemEntity fse 
		ON i.".DATABASE_COLUMN_PREFIX."id = fse.".DATABASE_COLUMN_PREFIX."id 
	INNER JOIN ".DATABASE_TABLE_PREFIX."ItemAttributesMap iam 
		ON i.".DATABASE_COLUMN_PREFIX."id = iam.".DATABASE_COLUMN_PREFIX."itemId 
	WHERE ce.".DATABASE_COLUMN_PREFIX."parentId=".$g2ParentAlbumId." 
	ORDER BY iam.".DATABASE_COLUMN_PREFIX."orderWeight ASC";
	
	$photos = mysql_query($sql);
	
	// upload any photos in this album
	
	echo "<ul>";
	$childCount = 0;
	while ($child = mysql_fetch_assoc($photos)) {
		$childCount++;
		
		$username = PICASA_EMAIL;
		$filename = $albumPath."/".$child[DATABASE_COLUMN_PREFIX."pathComponent"];
		$photoName = html_entity_decode($child[DATABASE_COLUMN_PREFIX."title"]);
		$photoCaption = html_entity_decode($child[DATABASE_COLUMN_PREFIX."description"]);
		$photoTags = "";
		
		if ($photoName != "")
		{
			$photoCaption = $photoName." - ".$photoCaption;
		} 
		else
		{
			$photoName = $albumTitle.$childCount;
		}
		
		echo "<li>PHOTO: ".$albumPath."/".$child[DATABASE_COLUMN_PREFIX."pathComponent"]." :: ".$photoCaption." ";
		
		
		$fd = $gp->newMediaFileSource($filename);
		$fd->setContentType("image/jpeg");
		
		// Create a PhotoEntry
		$photoEntry = $gp->newPhotoEntry();
		
		$photoEntry->setMediaSource($fd);
		$photoEntry->setTitle($gp->newTitle($photoName));
		$photoEntry->setSummary($gp->newSummary($photoCaption));
		
		// add some tags
		$keywords = new Zend_Gdata_Media_Extension_MediaKeywords();
		$keywords->setText($photoTags);
		$photoEntry->mediaGroup = new Zend_Gdata_Media_Extension_MediaGroup();
		$photoEntry->mediaGroup->keywords = $keywords;
		
		// We use the AlbumQuery class to generate the URL for the album
		$albumQuery = $gp->newAlbumQuery();
		
		$albumQuery->setUser($username);
		$albumQuery->setAlbumId($pParentAlbumId);
		
		// We insert the photo, and the server returns the entry representing that photo after it is uploaded
		$success = 0;
		$attempt = 0;
		while ($success == 0 && $attempt < MAX_ATTEMPTS)
		{
			try {
				$insertedPhoto = $gp->insertPhotoEntry($photoEntry, $albumQuery->getQueryUrl()); 
				$success = 1;
			} catch (Zend_Gdata_App_Exception $e) {
				$attempt++;
				handleError($e, $attempt);
			}
		}
		if ($success != 1)
		{   
			// I GIVE UP!
			echo "<br/><b style='color:#FF0000'>PHOTO SKIPPED!</b>";
		}	
		echo "</li>";
	}
	
	// if there are no pictures in this album, delete it
	if ($childCount == 0 && $pParentAlbumId != "")
	{
		$picasaAlbum->delete();
		//sleep(WAIT_TIME);
		echo "<li>No photos in this album.  Album deleted.</li>";
	}
	echo "</ul>";				
	
		
	// get subalbums in this album	
	
	$sql = "SELECT 
		i.".DATABASE_COLUMN_PREFIX."title, 
		i.".DATABASE_COLUMN_PREFIX."id, 
		i.".DATABASE_COLUMN_PREFIX."description, 
		i.".DATABASE_COLUMN_PREFIX."originationTimestamp, 
		fse.".DATABASE_COLUMN_PREFIX."pathComponent 
	FROM ".DATABASE_TABLE_PREFIX."Item i 
	INNER JOIN ".DATABASE_TABLE_PREFIX."FileSystemEntity fse 
		ON i.".DATABASE_COLUMN_PREFIX."id = fse.".DATABASE_COLUMN_PREFIX."id 
	INNER JOIN ".DATABASE_TABLE_PREFIX."ChildEntity ce 
		ON i.".DATABASE_COLUMN_PREFIX."id = ce.".DATABASE_COLUMN_PREFIX."id 
	WHERE i.".DATABASE_COLUMN_PREFIX."canContainChildren=1 
		AND ce.".DATABASE_COLUMN_PREFIX."parentId = ".$g2ParentAlbumId."
		AND fse.".DATABASE_COLUMN_PREFIX."pathComponent IS NOT NULL 
	ORDER BY i.".DATABASE_COLUMN_PREFIX."originationTimestamp ASC";
	
	$result = mysql_query($sql);
	

	// Create subalbums in Picasa
	
	echo "<ul>";
	while ($row = mysql_fetch_assoc($result)) {
		echo "<li><b>ALBUM: ".$row[DATABASE_COLUMN_PREFIX."title"]." :: ".$row[DATABASE_COLUMN_PREFIX."id"]."</b> :: Path: ".$row[DATABASE_COLUMN_PREFIX."pathComponent"]." :: 
			 ".date("c",$row[DATABASE_COLUMN_PREFIX."originationTimestamp"]);
		
		// if this is not an album to be ignored, process it
		
		if (!in_array($row[DATABASE_COLUMN_PREFIX."id"], $g2AlbumsToIgnore)) {
			// create the album
			$entry = new Zend_Gdata_Photos_AlbumEntry();
			$entry->setTitle($gp->newTitle($row[DATABASE_COLUMN_PREFIX."title"]));
			$entry->setSummary($gp->newSummary($row[DATABASE_COLUMN_PREFIX."description"]));
			
			$success = 0;
			$attempt = 0;
			while ($success == 0 && $attempt < MAX_ATTEMPTS)
			{
				try {
					$createdAlbum = $gp->insertAlbumEntry($entry);
					$success = 1;
				} catch (Zend_Gdata_App_Exception $e) {
					$attempt++;
					handleError($e, $attempt);
				}
			}
			if ($success != 1)
			{   
				// I GIVE UP!
				echo "<br/><b style='color:#FF0000'>ALBUM SKIPPED!</b>";
				echo "</li>";
				return;
			}	
						
			//print_r($createdAlbum);
			$createdAlbum->gphotoTimestamp->text = $row[DATABASE_COLUMN_PREFIX."originationTimestamp"]."000";
			$createdAlbum->gphotoAccess->text = PICASA_ALBUM_ACCESS; 
			
			$success = 0;
			$attempt = 0;
			while ($success == 0 && $attempt < MAX_ATTEMPTS)
			{
				try {
					$updatedAlbum = $createdAlbum->save();
					$success = 1;
				} catch (Zend_Gdata_App_Exception $e) {
					$attempt++;
					handleError($e, $attempt);
				}
			}
			if ($success != 1)
			{   
				// I GIVE UP!
				echo "<br/><b style='color:#FF0000'>ALBUM DATE AND ACCESS NOT UPDATED!</b>";
			}	
			
			echo "</li>";
			
			// process any subalbums in this album
			
			processAlbum($gp, $row[DATABASE_COLUMN_PREFIX."id"], $updatedAlbum, $row[DATABASE_COLUMN_PREFIX."title"], $row[DATABASE_COLUMN_PREFIX."pathComponent"],$albumPath);
		}
	}
	echo "</ul>";
}

function handleError ($e, $attempt)
{
	echo "<br/><b>ATTEMPT $attempt:</b> ";
	echo $e->getMessage();
	$trace = $e->getTrace();
	foreach ($trace as $function)
	{
		echo "<br/>".$function["file"].": ".$function["function"].", line ".$function["line"];
	}
  sleep(WAIT_TIME);
}
?>
