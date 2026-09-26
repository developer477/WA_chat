<?php
# upload_file.php
#
# Copyright (C) 2016  WarmConnect Solutions Pvt. Ltd., Sumedh Alshi <sumedh@staff.ownmail.com>
# The script uploads the file and sends the link to the user
#

require("dbconnect_mysqli.php");
require("functions.php");

$style_array=array("", "italics", "bold italics");
if (file_exists('/usr/local/bin/cp')) {$cpbin = '/usr/local/bin/cp';}
else if (file_exists('/usr/bin/cp')) {$cpbin = '/usr/bin/cp';}
else {$cpbin= '/bin/cp';}

if (isset($_FILES["sel_file"])) {
	/**Getting sound web directory and creating it id it doen't exists**/
	$WeBServeRRooT  =       "/var/www/htdocs/";
	$stmt = "SELECT sounds_web_directory FROM system_settings;";
	$rslt=mysql_to_mysqli($stmt, $link);
	$ss_conf_ct = mysqli_num_rows($rslt);
	if ($ss_conf_ct > 0) {
		$row=mysqli_fetch_row($rslt);
		$sounds_web_directory = $row[0];
	}

	/**Create a sound_web_directory if there is no sound directory found**/
	if (strlen($sounds_web_directory) < 30) {
		$sounds_web_directory = '';
		$possible = "0123456789cdfghjkmnpqrstvwxyz";
		$i = 0;
		$length = 30;
		while ($i < $length) {
			$char = substr($possible, mt_rand(0, strlen($possible)-1), 1);
			$sounds_web_directory .= $char;
			$i++;
		}
		mkdir("$WeBServeRRooT/$sounds_web_directory");
		chmod("$WeBServeRRooT/$sounds_web_directory", 0766);
		if ($DB > 0) {echo "$WeBServeRRooT/$sounds_web_directory\n";}
		$stmt="UPDATE system_settings set sounds_web_directory='$sounds_web_directory';";
		$rslt=mysql_to_mysqli($stmt, $link);
	}
	$sounds_web_directory_path      =       "$WeBServeRRooT"."$sounds_web_directory"."/";

	$target_file                            = basename($_FILES["sel_file"]["name"]);
	$uploaded_file_type                     = pathinfo($target_file,PATHINFO_EXTENSION);
	$uploaded_file_name_without_extension   = pathinfo($target_file,PATHINFO_FILENAME);
	$uploaded_file_name_without_extension   = preg_replace("/ |@|\#|\\$|\%|\^|\&|\*|\(|\)|\!/",'',$uploaded_file_name_without_extension);
	$tmp_file_location                      = $_FILES["sel_file"]["tmp_name"];
	$tmp_file_location                      = preg_replace("/ /",'\ ',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/@/",'\@',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\(/",'\(',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\)/",'\)',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\#/",'\#',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\&/",'\&',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\*/",'\*',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\!/",'\!',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\%/",'\%',$tmp_file_location);
	$tmp_file_location                      = preg_replace("/\^/",'\^',$tmp_file_location);
	$file_name                              = $_FILES["sel_file"]["name"];
	$file_name                              = preg_replace("/ |@|\#|\\$|\%|\^|\&|\*|\(|\)|\!/",'',$file_name);
	$input_file                             = "$sounds_web_directory_path"."$uploaded_file_name_without_extension."."$uploaded_file_type";

	exec("$cpbin $tmp_file_location $input_file",$oput, $retoput);
	if(!$retoput) {
		if (isset($_GET["chat_id"]))                                            {$chat_id=$_GET["chat_id"];}
		elseif (isset($_POST["chat_id"]))                               {$chat_id=$_POST["chat_id"];}
		if (isset($_GET["chat_level"]))                                         {$chat_level=$_GET["chat_level"];}
		elseif (isset($_POST["chat_level"]))                    {$chat_level=$_POST["chat_level"];}
		if (isset($_GET["chat_member_name"]))                           {$chat_member_name=$_GET["chat_member_name"];}
		elseif (isset($_POST["chat_member_name"]))              {$chat_member_name=$_POST["chat_member_name"];}
		if (isset($_GET["user"]))                                       {$user=$_GET["user"];}
		elseif (isset($_POST["user"]))                  {$user=$_POST["user"];}

		$hostname = exec("grep HOSTNAME /usr/local/ownpages/user_data |awk -F\"=>\" '{print $2}'|tr -d ' \n'",$oput, $retoput);
		if(!$hostname)
			$hostname = exec("curl http://trikon.in/myip.php",$oput, $retoput);
		$input_file_msg = "http://$hostname/"."$sounds_web_directory/"."$uploaded_file_name_without_extension."."$uploaded_file_type";

		$live_stmt="SELECT status from vicidial_live_chats where chat_id='$chat_id'";
		$live_rslt=mysql_to_mysqli($live_stmt, $link);
		if ($chat_id && mysqli_num_rows($live_rslt)>0) {
			$live_row=mysqli_fetch_row($live_rslt);
			$status=$live_row[0];
			if ($status=="LIVE") {
				# Check that the customer is still in the chat.
				$active_stmt="SELECT * from vicidial_chat_participants where chat_id='$chat_id' and chat_member='$user'";
				$active_rslt=mysql_to_mysqli($active_stmt, $link);
				if (mysqli_num_rows($active_rslt)>0) {
					$ins_stmt="INSERT IGNORE INTO vicidial_chat_log(chat_id, message, poster, chat_member_name, chat_level) VALUES('$chat_id', '".mysqli_real_escape_string($link, $input_file_msg)."', '$user', '".mysqli_real_escape_string($link, urldecode($chat_member_name))."', '$chat_level')";
					$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
				}
			}
		}
	}
}
