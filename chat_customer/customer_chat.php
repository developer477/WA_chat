<?php
# vicidial_chat_customer_side.php
#
# Copyright (C) 2016  Joe Johnson, Matt Florell <vicidial@gmail.com>    LICENSE: AGPLv2
#
# The main page of the customer chat interface.  This will display a form for the customer
# to fill out to attempt to initiate a chat with an available agent in the in-group 
# ("department") of their choice.  
#
# Builds:
# 150902-2100 - First build
# 151212-0830 - First Build for customer chat
# 151213-1105 - Added variable filtering
# 151217-1017 - Allow for pre-populating of group_id
# 151219-0850 - Added translation code
# 151220-0959 - Only search for phone number if greater than 4 digits
# 160108-1700 - Added available_agents, status_link button options and validation of active in-group
# 160120-1925 - Fixed missing list_id on vicidial_list inserts, Issue #915. Added show_email option
# 160203-1052 - Added display of chat message after ending it
# 160805-2315 - Added coding to show logos in customer display
# 161108-1720 - Modified by WarmConnect
#

require("dbconnect_mysqli.php");
require("functions.php");
require("country.php");

if (isset($_GET["first_name"]))				{$first_name=$_GET["first_name"];}
	elseif (isset($_POST["first_name"]))	{$first_name=$_POST["first_name"];}
if (isset($_GET["last_name"]))				{$last_name=$_GET["last_name"];}
	elseif (isset($_POST["last_name"]))		{$last_name=$_POST["last_name"];}
if (isset($_GET["group_id"]))				{$group_id=$_GET["group_id"];}
	elseif (isset($_POST["group_id"]))		{$group_id=$_POST["group_id"];}
if (isset($_GET["phone_number"]))			{$phone_number=$_GET["phone_number"];}
	elseif (isset($_POST["phone_number"]))	{$phone_number=$_POST["phone_number"];}
if (isset($_GET["send_request"]))			{$send_request=$_GET["send_request"];}
	elseif (isset($_POST["send_request"]))	{$send_request=$_POST["send_request"];}
if (isset($_GET["email"]))					{$email=$_GET["email"];}
	elseif (isset($_POST["email"]))			{$email=$_POST["email"];}
if (isset($_GET["join_chat"]))				{$join_chat=$_GET["join_chat"];}
	elseif (isset($_POST["join_chat"]))		{$join_chat=$_POST["join_chat"];}
if (isset($_GET["chat_id"]))				{$chat_id=$_GET["chat_id"];}
	elseif (isset($_POST["chat_id"]))		{$chat_id=$_POST["chat_id"];}
if (isset($_GET["lead_id"]))				{$lead_id=$_GET["lead_id"];}
	elseif (isset($_POST["lead_id"]))		{$lead_id=$_POST["lead_id"];}
if (isset($_GET["user"]))					{$user=$_GET["user"];}
	elseif (isset($_POST["user"]))			{$user=$_POST["user"];}
if (isset($_GET["language"]))				{$language=$_GET["language"];}
	elseif (isset($_POST["language"]))		{$language=$_POST["language"];}
if (isset($_GET["stage"]))					{$stage=$_GET["stage"];}
	elseif (isset($_POST["stage"]))			{$stage=$_POST["stage"];}
if (isset($_GET["available_agents"]))			{$available_agents=$_GET["available_agents"];}
	elseif (isset($_POST["available_agents"]))	{$available_agents=$_POST["available_agents"];}
if (isset($_GET["status_link"]))			{$status_link=$_GET["status_link"];}
	elseif (isset($_POST["status_link"]))	{$status_link=$_POST["status_link"];}
if (isset($_GET["show_email"]))				{$show_email=$_GET["show_email"];}
	elseif (isset($_POST["show_email"]))	{$show_email=$_POST["show_email"];}
if (isset($_GET["aopc_f"]))                             {$aopc_f=$_GET["aopc_f"];}
        elseif (isset($_POST["aopc_f"]))        {$aopc_f=$_POST["aopc_f"];}
$PHP_SELF=$_SERVER["PHP_SELF"];
$cookie_name = $_GET["Name"];
$cookie_email = $_GET["Email"];
$cookie_dept = $_GET["Dept"];
$cookie_aopc = $_GET["aopc"];
$remote  = $_SERVER['REMOTE_ADDR'];
$country = ip_info($remote, "Country");
$city = ip_info($remote, "City");
$chat_css_file = "css/custom.css";
$uname_place = "Visitor ".mt_rand(10,1000000000);
$url = $_SERVER["HTTP_REFERER"];
$host = $_SERVER["HTTP_HOST"];
if (strpos($url, $host) == false) {
	setcookie("Ref_Domain", $url, time() + (86400 * 1), "/");
	$flag = 1;
}

if($cookie_name != "" && $cookie_email != "") {
        setcookie("Cook_name", $cookie_name, time() + (86400 * 1), "/");
        setcookie("Cook_email", $cookie_email, time() + (86400 * 1), "/");
}
if($first_name != "" && $email != "") {
        setcookie("Cook_name", $first_name, time() + (86400 * 1), "/");
        setcookie("Cook_email", $email, time() + (86400 * 1), "/");
}
if($cookie_name == "" && $cookie_email == "" && $first_name == "" && $email == "") {
        $cookie_name = $_COOKIE["Cook_name"];
        $first_name = $_COOKIE["Cook_name"];
        $cookie_email = $_COOKIE["Cook_email"];
        $email = $_COOKIE["Cook_email"];
}
if($cookie_dept != "")
        setcookie("Cook_dept", $cookie_dept, time() + (86400 * 1), "/");
if($cookie_dept == "")
        $cookie_dept = $_COOKIE["Cook_dept"];
if($group_id != "" && $cookie_dept != $group_id)
	$cookie_dept = $group_id;
if($group_id == "" && $cookie_dept == "") {
	$stmt_nogrp="SELECT vlia.group_id FROM vicidial_live_inbound_agents vlia, vicidial_inbound_groups vig WHERE vlia.group_id=vig.group_id AND vig.group_handling='CHAT' AND vig.active='Y' limit 1;";
	$rslt_nogrp=mysql_to_mysqli($stmt_nogrp, $link);
	$rows_nogrp=mysqli_fetch_row($rslt_nogrp);
	$any1_nogrp=$rows_nogrp[0];
	if($any1_nogrp) {
		$group_id = $any1_nogrp;
		$cookie_dept = $any1_nogrp;
		setcookie("Cook_dept", $cookie_dept, time() + (86400 * 1), "/");
	}
}

$cook_name = "chat_id_$cookie_dept";
$cookie_chat_id = $_COOKIE["$cook_name"];
$dmn = $_COOKIE["Ref_Domain"];
$dmn_par = parse_url($dmn,PHP_URL_HOST);

$lead_id = preg_replace("/[^0-9]/","",$lead_id);
$chat_id = preg_replace('/[^- \_\.0-9a-zA-Z]/','',$chat_id);
$group_id = preg_replace('/[^- \_0-9a-zA-Z]/','',$group_id);
$language = preg_replace('/[^-\_0-9a-zA-Z]/','',$language);
$available_agents = preg_replace('/[^-\_0-9a-zA-Z]/','',$available_agents);
$status_link = preg_replace('/[^-\_0-9a-zA-Z]/','',$status_link);
$show_email = preg_replace('/[^-\_0-9a-zA-Z]/','',$show_email);

if ($non_latin < 1)
	{
	$user = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/','',$user);
	$first_name = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/','',$first_name);
	$first_name = preg_replace('/\+/',' ',$first_name);
	$last_name = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/','',$last_name);
	$last_name = preg_replace('/\+/',' ',$last_name);
	$email = preg_replace('/[^- \'\+\.\:\/\@\%\_0-9a-zA-Z]/','',$email);
	$email = preg_replace('/\+/',' ',$email);
	$phone_number = preg_replace("/[^0-9]/","",$phone_number);
	}
else
	{
	$user = preg_replace("/\'|\"|\\\\|;/","",$user);
	$first_name = preg_replace("/\"|\\\\|;/","",$first_name);
	$last_name = preg_replace("/\"|\\\\|;/","",$last_name);
	$email = preg_replace("/\'|\"|\\\\|;/","",$email);
	$phone_number = preg_replace('/[^- \'\+\.\:\/\@\%\_0-9a-zA-Z]/','',$email);
	}

#############################################
##### START SYSTEM_SETTINGS LOOKUP #####
$VUselected_language='';
$stmt = "SELECT use_non_latin,enable_languages,language_method,default_language,allow_chats,chat_url FROM system_settings;";
$rslt=mysql_to_mysqli($stmt, $link);
        if ($mel > 0) {mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'00XXX',$user,$server_ip,$session_name,$one_mysql_log);}
if ($DB) {echo "$stmt\n";}
$qm_conf_ct = mysqli_num_rows($rslt);
if ($qm_conf_ct > 0)
	{
	$row=mysqli_fetch_row($rslt);
	$non_latin =			$row[0];
	$SSenable_languages =	$row[1];
	$SSlanguage_method =	$row[2];
	$SSdefault_language =	$row[3];
	$SSallow_chats =		$row[4];
	$SSchat_url =			$row[5];
	}
$VUselected_language = $SSdefault_language;
##### END SETTINGS LOOKUP #####
###########################################

if (strlen($language) > 1)
	{
	$stmt = "SELECT language_code,language_description FROM vicidial_languages where language_id='$language' and active='Y';";
	$rslt=mysql_to_mysqli($stmt, $link);
			if ($mel > 0) {mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'00XXX',$user,$server_ip,$session_name,$one_mysql_log);}
	if ($DB) {echo "$stmt\n";}
	$lang_good_ct = mysqli_num_rows($rslt);
	if ($lang_good_ct > 0)
		{
		$row=mysqli_fetch_row($rslt);
		$language_code =		$row[0];
		$language_description =	$row[1];
		$VUselected_language = $language;
		}
	}
if ($SSallow_chats < 1)
	{
	header ("Content-type: text/html; charset=utf-8");
	if ($status_link == 'Y')
		{echo "<img src=\"./images/"._QXZ("chat_status_button_OFF.gif")."\" width=150 height=37 alt=\""._QXZ("no chat agents available")."\">";}
	else
		{echo _QXZ("Error, chat disabled on this system");}
	exit;
	}
if (strlen($group_id) > 1)
	{
	$group_active='N';
	$group_name='';
	$stmt = "SELECT active,group_name FROM vicidial_inbound_groups where group_id='$group_id';";
	$rslt=mysql_to_mysqli($stmt, $link);
			if ($mel > 0) {mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'00XXX',$user,$server_ip,$session_name,$one_mysql_log);}
	if ($DB) {echo "$stmt\n";}
	$group_good_ct = mysqli_num_rows($rslt);
	if ($group_good_ct > 0)
		{
		$row=mysqli_fetch_row($rslt);
		$group_active =		$row[0];
		$group_name =		$row[1];
		}

	if ($group_active == 'N')
		{
		header ("Content-type: text/html; charset=utf-8");
		if ($status_link == 'Y')
			{echo "<img src=\"./images/"._QXZ("chat_status_button_OFF.gif")."\" width=150 height=37 alt=\""._QXZ("no chat agents available")."\">";}
		else
			{echo _QXZ("Error, group is not active").": $group_id $group_name";}
		exit;
		}

	if ($available_agents == 'WAITING_ONLY')
		{
		$waiting_agents=0;
		$stmt = "SELECT count(*) FROM vicidial_live_agents where closer_campaigns LIKE \"% $group_id %\" and status IN('READY','CLOSER');";
		$rslt=mysql_to_mysqli($stmt, $link);
				if ($mel > 0) {mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'00XXX',$user,$server_ip,$session_name,$one_mysql_log);}
		if ($DB) {echo "$stmt\n";}
		$group_good_ct = mysqli_num_rows($rslt);
		if ($group_good_ct > 0)
			{
			$row=mysqli_fetch_row($rslt);
			$waiting_agents = $row[0];
			}
		if ($waiting_agents < 1)
			{
			header ("Content-type: text/html; charset=utf-8");
			if ($status_link == 'Y')
				{echo "<img src=\"./images/"._QXZ("chat_status_button_OFF.gif")."\" width=150 height=37 alt=\""._QXZ("no chat agents available")."\">";}
			else
				{echo _QXZ("We are sorry, there are no waiting agents at this time. Please try again later.").": $group_id $group_name";}
			exit;
			}
		}
	if ($available_agents == 'LOGGED_IN')
		{
		$loggedin_agents=0;
		$stmt = "SELECT count(*) FROM vicidial_live_agents where closer_campaigns LIKE \"% $group_id %\";";
		$rslt=mysql_to_mysqli($stmt, $link);
				if ($mel > 0) {mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'00XXX',$user,$server_ip,$session_name,$one_mysql_log);}
		if ($DB) {echo "$stmt\n";}
		$group_good_ct = mysqli_num_rows($rslt);
		if ($group_good_ct > 0)
			{
			$row=mysqli_fetch_row($rslt);
			$loggedin_agents = $row[0];
			}
		if ($loggedin_agents < 1)
			{
			header ("Content-type: text/html; charset=utf-8");
			if ($status_link == 'Y')
				{echo "<img src=\"./images/"._QXZ("chat_status_button_OFF.gif")."\" width=150 height=37 alt=\""._QXZ("no chat agents available")."\">";}
			else
				{echo _QXZ("We are sorry, there are no logged in agents at this time. Please try again later.").": $group_id $group_name";}
			exit;
			}
		}

	if ($status_link == 'Y')
		{
		echo "<a href=\"".$PHP_SELF."?group_id=$group_id&language=$language&available_agents=$available_agents&show_email=$show_email\"><img src=\"./images/"._QXZ("chat_status_button_ON.gif")."\" width=150 height=37 border=0 alt=\""._QXZ("Agents Available, click to chat now")."\"></a>";
		exit;
		}
	}



# http://192.168.1.2/chat_customer/vicidial_chat_customer_side.php?user=1440723435.60926&lead_id=1079350&group_id=CHAT_TEST_GROUP&chat_id=254&email=joej%40test.com
$phone_number=preg_replace('/[^0-9]/', '', $phone_number);

if ($stage == "join_chat") { # For people invited to an existing chat from an agent.
	$error_msg="";

	if (!$first_name || !$email) {
		#$error_msg=_QXZ("Please enter your first and last name");
		$error_msg=_QXZ("Please enter username and email_id");
	} else { 
		#$chat_member_name="$first_name $last_name";
		$chat_member_name="$first_name";
		$ip_address=$_SERVER['REMOTE_ADDR'];
		$user=time().".".rand(10000,99999);

		# SEARCH FOR CUSTOMER IN DATABASE - IF NOT FOUND BY PHONE OR IP ADDRESS MAKE A NEW USER
		if ($lead_id) { # CAN OCCUR VIA INVITE
			#$upd_stmt="UPDATE vicidial_list set first_name=\"$first_name\", last_name=\"$last_name\", status='WCHAT' where lead_id='$lead_id' limit 1";
			$upd_stmt="UPDATE vicidial_list set first_name=\"$first_name\", email=\"$email\", status='WCHAT' where lead_id='$lead_id' limit 1";
			# update email and security_phrase?
			$upd_rslt=mysql_to_mysqli($upd_stmt, $link);

			# Private message
			$alert_stmt="select chat_creator from vicidial_live_chats where chat_id='$chat_id' and lead_id='$lead_id'";
			$alert_rslt=mysql_to_mysqli($alert_stmt, $link);
			if (mysqli_num_rows($alert_rslt)>0) {
				$alert_row=mysqli_fetch_row($alert_rslt);
				$chat_creator=$alert_row[0];
				$ins_alert_stmt="INSERT INTO vicidial_chat_log(poster, chat_member_name, message_time, message, chat_id, chat_level) select '" . mysqli_real_escape_string($link, $chat_creator) . "', full_name, now(), '" . mysqli_real_escape_string($link, $chat_member_name) . " has joined chat', '" . mysqli_real_escape_string($link, $chat_id) . "', '1' from vicidial_users where user='" . mysqli_real_escape_string($link, $chat_creator) . "'";
				$ins_alert_rslt=mysql_to_mysqli($ins_alert_stmt, $link);
			}
		} else {
			if (strlen($phone_number) > 4) {
				$stmt="SELECT lead_id from vicidial_list where phone_number='$phone_number' order by entry_date desc limit 1;";
				$rslt=mysql_to_mysqli($stmt, $link);
			} else if ($email) {
				$stmt="SELECT lead_id from vicidial_list where email='$email' order by entry_date desc limit 1;";
				$rslt=mysql_to_mysqli($stmt, $link);
			} else {
				$stmt="SELECT lead_id from vicidial_list where email like \"%".$ip_address."%\" order by entry_date desc limit 1;";
				$rslt=mysql_to_mysqli($stmt, $link);
			}

			if (mysqli_num_rows($rslt)>0) {
				$row=mysqli_fetch_row($rslt);
				$lead_id=$row[0];

				# Update to reflect chat request - should I do this?  And use WCHAT status for "waiting for chat"?
				#$upd_stmt="UPDATE vicidial_list set first_name=\"$first_name\", last_name=\"$last_name\", status='WCHAT' where lead_id='$lead_id' limit 1";
				$upd_stmt="UPDATE vicidial_list set first_name=\"$first_name\", email=\"$email\", status='WCHAT' where lead_id='$lead_id' limit 1";
				# update email and security_phrase?
				$upd_rslt=mysql_to_mysqli($upd_stmt, $link);
			} else if (!$error_msg) {
				$stmtA = "SELECT hold_time_option_callback_list_id FROM vicidial_inbound_groups where group_id='" . mysqli_real_escape_string($link, $group_id) . "';";
				$rsltA=mysql_to_mysqli($stmtA, $link);
				if ($DB) {echo "$stmtA\n";}
				$list_ct = mysqli_num_rows($rsltA);
				if ($list_ct > 0)
					{
					$row=mysqli_fetch_row($rsltA);
					$default_list_id =	$row[0];
					}
				# Create lead in vicidial_list table (make special system status for waiting for chat)
				$ins_stmt="INSERT INTO vicidial_list(status, first_name, last_name, email, list_id, phone_number, security_phrase, entry_date) VALUES('WCHAT', '" . mysqli_real_escape_string($link, $first_name) . "', '" . mysqli_real_escape_string($link, $last_name) . "', '" . mysqli_real_escape_string($link, $email) . "', '" . mysqli_real_escape_string($link, $default_list_id) . "', '" . mysqli_real_escape_string($link, $phone_number) . "', '" . mysqli_real_escape_string($link, $group_id) . "',NOW())";
				$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
				$lead_id=mysqli_insert_id($link);
			}
		}

		if (!$lead_id || $lead_id==0) {
			$error_msg.="<BR>\n"._QXZ("Could not find or create entry");
		} else {
			$chat_upd_stmt="UPDATE vicidial_live_chats set lead_id='$lead_id' where chat_id='$chat_id'";
			$chat_upd_rslt=mysql_to_mysqli($chat_upd_stmt, $link);

		}
	}

	if (!$error_msg) {
		if ($chat_id>0) {

			$ins_stmt="INSERT INTO vicidial_chat_participants(chat_id, chat_member, chat_member_name, ping_date, vd_agent) VALUES('" . mysqli_real_escape_string($link, $chat_id) . "', '" . mysqli_real_escape_string($link, $user) . "', '" . mysqli_real_escape_string($link, $chat_member_name) . "', now(), 'N')";
			$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
			if (mysqli_affected_rows($link)==0) {
				$del_stmt="DELETE from vicidial_live_chats where chat_id='$chat_id'";
				$del_rslt=mysql_to_mysqli($del_stmt, $link);
				$error_msg=_QXZ("Chat started, failure to join"); 
				unset($chat_id);
			} else {
				# echo "$chat_id|$lead_id|$ins_stmt";
			}
		} else {
			$error_msg=_QXZ("Chat not started")." - $ins_stmt";
		}
	}
}

if ($stage == 'send_request' && !($cookie_chat_id)) { # For people requesting a chat with an agent; consider using a special user variable name for this

	$error_msg="";

	if (!$first_name || !$email) {
		#$error_msg=_QXZ("Please enter your first and last name");
		$error_msg=_QXZ("Please enter username and email id");}

	#$chat_member_name="$first_name $last_name";
	$chat_member_name="$first_name";
	$ip_address=$_SERVER['REMOTE_ADDR'];
	$user=time().".".rand(10000,99999);

	# SEARCH FOR CUSTOMER IN DATABASE - IF NOT FOUND BY PHONE OR IP ADDRESS MAKE A NEW USER
	if (strlen($phone_number) > 4) {
		$stmt="SELECT lead_id from vicidial_list where phone_number='$phone_number' order by entry_date desc limit 1;";
		$rslt=mysql_to_mysqli($stmt, $link);
	} else if ($email) {
		$stmt="SELECT lead_id from vicidial_list where email='$email' order by entry_date desc limit 1;";
		$rslt=mysql_to_mysqli($stmt, $link);
	} else {
		$stmt="SELECT lead_id from vicidial_list where email like \"%".$ip_address."%\" order by entry_date desc limit 1;";
		$rslt=mysql_to_mysqli($stmt, $link);
	}
	if (strlen($email)<1)
		{$email = $ip_address;}

	if (mysqli_num_rows($rslt)>0) {
		$row=mysqli_fetch_row($rslt);
		$lead_id=$row[0];

		# Update to reflect chat request - should I do this?  And use WCHAT status for "waiting for chat"?
		#$upd_stmt="UPDATE vicidial_list set first_name='" . mysqli_real_escape_string($link, $first_name) . "', last_name='" . mysqli_real_escape_string($link, $last_name) . "', status='WCHAT' where lead_id='$lead_id' limit 1";
		$upd_stmt="UPDATE vicidial_list set first_name='" . mysqli_real_escape_string($link, $first_name) . "', email='" . mysqli_real_escape_string($link, $email) . "', status='WCHAT' where lead_id='$lead_id' limit 1";
		# update email and security_phrase?
		$upd_rslt=mysql_to_mysqli($upd_stmt, $link);
	} else if (!$error_msg) {
		$stmtA = "SELECT hold_time_option_callback_list_id FROM vicidial_inbound_groups where group_id='" . mysqli_real_escape_string($link, $group_id) . "';";
		$rsltA=mysql_to_mysqli($stmtA, $link);
		if ($DB) {echo "$stmtA\n";}
		$list_ct = mysqli_num_rows($rsltA);
		if ($list_ct > 0)
			{
			$row=mysqli_fetch_row($rsltA);
			$default_list_id =	$row[0];
			}
		# Create lead in vicidial_list table (make special system status for waiting for chat)
		$ins_stmt="INSERT INTO vicidial_list(status, first_name, last_name, email, list_id, phone_number, security_phrase, entry_date) VALUES('WCHAT', '" . mysqli_real_escape_string($link, $first_name) . "', '" . mysqli_real_escape_string($link, $last_name) . "', '" . mysqli_real_escape_string($link, $email) . "', '" . mysqli_real_escape_string($link, $default_list_id) . "', '" . mysqli_real_escape_string($link, $phone_number) . "', '" . mysqli_real_escape_string($link, $group_id) . "',NOW())";
		$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
		$lead_id=mysqli_insert_id($link);
	}

	if (!$lead_id || $lead_id==0) {
		$error_msg.="<BR>\n"._QXZ("Could not find or create entry");
	}

	if (!$error_msg) {
		$ins_stmt="INSERT INTO vicidial_live_chats(status, chat_creator, group_id, lead_id, chat_start_time) VALUES('WAITING', 'NONE', '" . mysqli_real_escape_string($link, $group_id) . "', '" . mysqli_real_escape_string($link, $lead_id) . "', now())";
		$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
		$chat_id=mysqli_insert_id($link);	
        	setcookie($cook_name, $chat_id, time() + (86400 * 1), "/");

		# WEB LOGO
		$chat_color_stmt="select web_logo from vicidial_inbound_groups vig, vicidial_screen_colors v where vig.group_id='$group_id' and vig.customer_chat_screen_colors=v.colors_id limit 1;";
		$color_rslt=mysql_to_mysqli($chat_color_stmt, $link);
		$web_logo=""; $filepath="vicidial/images";
		if(mysqli_num_rows($color_rslt)>0) {
			$color_row=mysqli_fetch_array($color_rslt);
			switch ($color_row["web_logo"]) {
				case "default_new":
					$color_row["web_logo"]=".png";
					break;
				case "default_old";
					$color_row["web_logo"]=".gif";
					$filepath="vicidial";
					break;			
			}
			$web_logo=$color_row["web_logo"];
		}
		if (!preg_match("/\.(jpg|gif|png|bmp)$/", $web_logo)) {$web_logo.=".png";}

		
		if ($chat_id>0) {

			$survey_stmt="select customer_chat_survey_link, customer_chat_survey_text from vicidial_inbound_groups where group_id='$group_id'";
			$survey_rslt=mysql_to_mysqli($survey_stmt, $link);
			$survey_row=mysqli_fetch_array($survey_rslt);
			if (strlen($survey_row["customer_chat_survey_link"])>0) {
				$survey_str="<BR/><BR/><font class='chat_title'><a href='".$survey_row["customer_chat_survey_link"]."' target='_parent'>";
				if (strlen($survey_row["customer_chat_survey_text"])>0) {
					$survey_str.=$survey_row["customer_chat_survey_text"];
				} else {
					$survey_str.=_QXZ("PLEASE TAKE OUR SURVEY");
				}
				$survey_str.="</a>";
			}


			$ins_stmt="INSERT INTO vicidial_chat_participants(chat_id, chat_member, chat_member_name, ping_date, vd_agent) VALUES('$chat_id', '" . mysqli_real_escape_string($link, $user) . "', '" . mysqli_real_escape_string($link, $chat_member_name) . "', now(), 'N')";
			$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
			if (mysqli_affected_rows($link)==0) {
				$del_stmt="DELETE from vicidial_live_chats where chat_id='$chat_id'";
				$del_rslt=mysql_to_mysqli($del_stmt, $link);
				$error_msg=_QXZ("Chat started, failure to join"); 
				unset($chat_id);
			} else {
				# echo "$chat_id|$lead_id|$ins_stmt";
			}
		} else {
			$error_msg=_QXZ("Chat not started")." - $ins_stmt";
		}
		if($country || $city) {
			$upd_stmt="UPDATE vicidial_live_chats SET country='$country - $city - $remote - $dmn_par' WHERE chat_id='$chat_id'";
			mysql_to_mysqli($upd_stmt, $link);
		}
		if($dmn != $url && $flag)
			$dmn_temp = $url;
		else
			$dmn_temp = $dmn;
		$cook_dmn_alt = "User is currently on $dmn_temp";
		$stmt_ref = "SELECT message from vicidial_chat_log where chat_id='$chat_id' and chat_member_name!='COUNTRY AND IP ADDRESS' order by message_time desc limit 1;";
		$rslt_ref = mysql_to_mysqli($stmt_ref, $link);
		$row_ref=mysqli_fetch_row($rslt_ref);
		$row_arr = $row_ref[0];
		if($row_arr != $cook_dmn_alt) {
			$ref_stmt="INSERT IGNORE INTO vicidial_chat_log(chat_id, message, poster, chat_member_name, chat_level) VALUES('$chat_id', '$cook_dmn_alt', '$user', '".mysqli_real_escape_string($link, $chat_member_name)."', '1')";
			$ref_rslt=mysql_to_mysqli($ref_stmt, $link);
			if (mysqli_affected_rows($link)<1)
				echo "<font class='chat_title alert'>"._QXZ("SYSTEM ERROR")."</font><BR/>\n";
		}
	}
}

if ($cookie_chat_id) { # To rejoin chat if page is refreshed by sumedh alshi
	$chat_id=$cookie_chat_id;
	#$user=time().".".rand(10000,99999);
	$ip_address=$_SERVER['REMOTE_ADDR'];
	if($chat_id) {
		$rejoin_stmt="SELECT chat_creator,group_id,lead_id FROM vicidial_live_chats WHERE chat_id='$chat_id' and status='LIVE';";
		$rejoin_rslt=mysql_to_mysqli($rejoin_stmt, $link);
		if(mysqli_num_rows($rejoin_rslt)>0) {
			$row=mysqli_fetch_row($rejoin_rslt);
			$chat_creator	=	$row[0];
			$group_id	=	$row[1];
			$lead_id	=	$row[2];
		}
		$rejoin_stmt2="SELECT chat_member,chat_member_name FROM vicidial_chat_participants WHERE chat_id='$chat_id' and vd_agent='N';";
		$rejoin_rslt2=mysql_to_mysqli($rejoin_stmt2, $link);
		if(mysqli_num_rows($rejoin_rslt2)>0) {
			$row2=mysqli_fetch_row($rejoin_rslt2);
			$user			=	$row2[0];
			$chat_member_name	=	$row2[1];
		}
	}
	if($cookie_name)
		$chat_member_name	=	$cookie_name;
	if($dmn != $url && $flag)
		$dmn_temp = $url;
	else
		$dmn_temp = $dmn;
	$cook_dmn_alt = "User is currently on $dmn_temp";
	$stmt_ref = "SELECT message from vicidial_chat_log where chat_id='$chat_id' and chat_member_name!='COUNTRY AND IP ADDRESS' order by message_time desc limit 1;";
	$rslt_ref = mysql_to_mysqli($stmt_ref, $link);
	$row_ref=mysqli_fetch_row($rslt_ref);
	$row_arr = $row_ref[0];
	if($row_arr != $cook_dmn_alt) {
		$ref_stmt="INSERT IGNORE INTO vicidial_chat_log(chat_id, message, poster, chat_member_name, chat_level) VALUES('$chat_id', '$cook_dmn_alt', '$user', '".mysqli_real_escape_string($link, $chat_member_name)."', '1')";
		$ref_rslt=mysql_to_mysqli($ref_stmt, $link);
		if (mysqli_affected_rows($link)<1)
			echo "<font class='chat_title alert'>"._QXZ("SYSTEM ERROR")."</font><BR/>\n";
	}
}

header ("Content-type: text/html; charset=utf-8");
header ("Cache-Control: no-cache, must-revalidate");  // HTTP/1.1
header ("Pragma: no-cache");                          // HTTP/1.0
echo '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
';
?>
<html>
<?php if($error_msg) {
	echo "<title>$error_msg</title>";
}
else {
	echo "<title>Chat - Now</title>";
} ?>
<head>
<link rel="stylesheet" type="text/css" href="css/style.css" />
<!--<link rel="stylesheet" type="text/css" href="css/custom.css" />-->
<?php 
	$filecontents   =       file_get_contents($chat_css_file);
	$filecontents   =       str_ireplace("<THM_DEPT>",$cookie_dept,$filecontents);
	echo "<style>$filecontents</style>";
?>
<link rel="stylesheet" type="text/css" href="css/simpletree.css" />
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.4.0/css/font-awesome.min.css' />
<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />
<meta name='viewport' content='width=device-width, height=device-height, initial-scale=1' />
<meta name='mobile-web-app-capable' content='yes' />
<meta name='apple-mobile-web-app-capable' content='yes' />
<meta name='apple-mobile-web-app-status-bar-style' content='black' />

<script type='text/javascript' src='../js/jquery.js'></script>
<script type='text/javascript' src='../js/jquery-ui-1.8.21.custom.min.js'></script>
<script language="Javascript">
var language='<?php echo $language ?>';
var chat_url='<?php echo $SSchat_url ?>';
var group_id='<?php echo $group_id ?>';
var available_agents='<?php echo $available_agents ?>';
var show_email='<?php echo $show_email ?>';
var aoc='<?php echo $aopc_f ?>';
var refresh = 0;
var textarea = $('#chat_message');
var lastTypedTime = new Date(0); // it's 01/01/1970
var typingDelayMillis = 3000; // how long user can "think about his spelling" before we remove "typing..." from agent screen

$(document).ready(function(){
	$("textarea").keydown(function() {
		lastTypedTime = new Date();
	});
	$("#file-input").on('change',(function(e) {
		var raw_file = this.files[0];
		var name = raw_file.name;

		var type = raw_file.type;
		var re = /(\.jpg|\.jpeg|\.bmp|\.gif|\.png|\.pdf|\.doc|\.docx|\.xls|\.xlsx|\.odt|\.ods|\.csv|\.txt|\.rtf|\.zip|\.rar|\.mp3|\.wav|\.gsm|\.wma|\.flv|\.avi)$/i;
		var disp_re = "|.jpg|.jpeg|.bmp|.gif|.png|.pdf|.doc|.docx|.xls|.xlsx|.odt|.ods|.csv|.txt|.rtf|.zip|.rar|.mp3|.wav|.gsm|.wma|.flv|.avi|";
		if(!re.exec(name)) {
			alert("File extension not supported! Supported extensions are:" + disp_re);
			return false;
		}

		var size = raw_file.size;
		if(size > 10000000) {
			alert("Upload size reached! File should be less than 10MB");
			return false;
		}

		var chat_id = document.getElementById('chat_id').value;
		var user = document.getElementById('user').value;
		var chat_member_name = encodeURIComponent(document.getElementById('chat_member_name').value.trim());
		var formData = new FormData();
		var formData = new FormData($('chat_form')[0]);
		formData.append('chat_id', chat_id);
		formData.append('user', user);
		formData.append('chat_member_name', chat_member_name);
		formData.append('chat_level', '0');
		formData.append('sel_file', raw_file);

		e.preventDefault();
		$('#loading').show();
		$('i').hide();

		$.ajax({
			url : 'upload_file.php',
			type : 'POST',
			data : formData,
			processData: false,  // tell jQuery not to process the data
			contentType: false,  // tell jQuery not to set contentType
			success : function(data) {
				$('#loading').hide();
				$('i').show();
			},
			error : function(data) {
				alert("Sorry! File cannot be uploaded, please try again later.");
				$('#loading').hide();
				$('i').show();
			}
		});
	}));
});
function PleaseWait() {
	document.getElementById('chat_request_span').style.display="none";
	document.getElementById('please_wait_span').style.display="block";
	RequestChat();
}
function ResetScreen() {
	document.getElementById('chat_request_span').style.display="block";
	document.getElementById('please_wait_span').style.display="none";
}
function LeaveChat(chat_id, user, chat_member_name, bleave) {
	if (!chat_id)
		{
		var chat_id=document.getElementById('chat_id').value;
		}
	if (!user)
		{
		var user=document.getElementById('user').value;
		}
	if (!chat_member_name)
		{
		var chat_member_name=document.getElementById('chat_member_name').value;
		}
	if (!bleave)
		{
		var bleave=0;
		}
	else
		{
		var cook_name = '<?php echo $cook_name ?>';
		document.cookie = cook_name+"=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
		}
	var xmlhttp=false;
	if (!xmlhttp && typeof XMLHttpRequest!='undefined')
		{
		xmlhttp = new XMLHttpRequest();
		}
	if (xmlhttp) 
		{ 
		chat_query = "&action=leave_chat&chat_id="+chat_id+"&group_id="+group_id+"&user="+user+"&chat_member_name="+chat_member_name+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"&bleave="+bleave;
		// alert(chat_query);
		xmlhttp.open('POST', 'customer_chat_functions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(chat_query); 
		xmlhttp.onreadystatechange = function() 
			{ 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) 
				{
				//document.getElementById('chat_message_console').innerHTML="<BR/><font class='chat_title'><a href='"+chat_url+"?group_id="+group_id+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"'><?php echo _QXZ("GO BACK TO CHAT FORM") ?></a></font><BR/><BR/>\n<?php echo $survey_str; ?>";
				document.getElementById('chat_message_console').innerHTML="<BR/><font class='chat_title'><a href='"+chat_url+"?group_id="+group_id+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"'><?php echo _QXZ("BACK") ?></a></font><BR/><BR/>\n<?php echo $survey_str; ?>";
				//if (chat_creator==user) {EndChat();}
				}
			}
		delete xmlhttp;
		}
}

function StartRefresh() {
	rInt=window.setInterval(function() {UpdateChatWindow()}, 1000);
}
function UpdateChatWindow() {
	var chat_id=document.getElementById('chat_id').value;
//	var chat_creator=document.getElementById('chat_creator').value;
	var user=document.getElementById('user').value;
	var current_message_field = document.getElementById('current_message_count');
	if (current_message_field == null) {var current_message_count=0;} else {var current_message_count=current_message_field.value;}
	var chat_member_name=document.getElementById('chat_member_name').value;

	if ($("textarea").val() == '')
		$("#upload-fa").show();
	else
		$("#upload-fa").hide();
	if (!$("textarea").is(':focus') || $("textarea").val() == '' || new Date().getTime() - lastTypedTime.getTime() > typingDelayMillis)
		var typing = "";
	else
		var typing = "typing...";

	if (chat_id)
		{
		var xmlhttp=false;
		if (!xmlhttp && typeof XMLHttpRequest!='undefined')
			{
			xmlhttp = new XMLHttpRequest();
			}
		if (xmlhttp) 
			{ 
			//chat_query = "&chat_id="+chat_id+"&group_id="+group_id+"&user="+user+"&current_message_count="+current_message_count+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"&action=update_chat_window&keepalive=1";
			chat_query = "&chat_id="+chat_id+"&group_id="+group_id+"&user="+user+"&current_message_count="+current_message_count+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"&action=update_chat_window&keepalive=1&chat_member_name="+chat_member_name+"&aoc="+aoc+"&typing_status="+typing;
			xmlhttp.open('POST', 'customer_chat_functions.php'); 
			xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
			xmlhttp.send(chat_query); 
			xmlhttp.onreadystatechange = function() 
				{ 
				if (xmlhttp.readyState == 4 && xmlhttp.status == 200) 
					{
					var chat_log_response = xmlhttp.responseText;
					if (chat_log_response.match(/^Error/))
						{
						var cook_name = '<?php echo $cook_name ?>';
						document.cookie = cook_name+"=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
						//document.getElementById('chat_message_console').innerHTML="<font class='chat_title alert'><?php echo _QXZ("Chat does not exist or has been closed") ?></font><BR/><font class='chat_title'><a href='"+chat_url+"?group_id="+group_id+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"'><?php echo _QXZ("GO BACK TO CHAT FORM") ?></a></font><BR/><BR/>\n<?php echo $survey_str; ?>";
						document.getElementById('chat_message_console').innerHTML="<BR/><font class='chat_title'><a href='"+chat_url+"?group_id="+group_id+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"'><?php echo _QXZ("BACK") ?></a></font><BR/><BR/>\n<?php echo $survey_str; ?>";
						}

					var chatlogresponse=chat_log_response.replace(/Error\|/, '');
					var chatlogresponse_ary=chatlogresponse.split("|");
					var chatlogresponse_blk=chatlogresponse.split("||");
					var chatlogresponse_mem=chatlogresponse.split("*~");

					var current_chat_status=chatlogresponse_ary[0];
					if(typeof chatlogresponse_ary[2] == 'undefined')
						var fullchatlog=chatlogresponse_ary[1];
					else
						var fullchatlog=chatlogresponse_ary[1].concat(chatlogresponse_ary[2]);
					var live_msg_cnt=chatlogresponse_ary[2];
					var last_live_msg_cnt=document.getElementById('live_msg_cnt_field');
					var typing_stat = chatlogresponse_ary[3];
					if(typeof typing_stat == 'undefined')
						var typing_stat = '';
					var block_stat = chatlogresponse_blk[1];
					if(typeof block_stat == 'undefined')
						var block_stat = '';
					var chat_mem_name = chatlogresponse_mem[1];
					if(typeof chat_mem_name == 'undefined')
						var chat_mem_name = '';

					document.getElementById('ChatActiveStatus').innerHTML=current_chat_status;
					// var fullchatlog=chat_log_response.replace(/Error\|/, '');
					//document.getElementById('ChatDisplay').innerHTML=fullchatlog;
					if (live_msg_cnt!=last_live_msg_cnt.value || refresh=="0") {
						document.getElementById('ChatDisplay').innerHTML=fullchatlog;
						last_live_msg_cnt.value=live_msg_cnt;
						refresh = 1;
					}
					if(typing_stat != "")
						document.getElementById('typing').innerHTML=typing_stat;
					else
						document.getElementById('typing').innerHTML='';
					if(block_stat == "blocked")
						document.getElementById('chat_message').disabled = true;
					else {
						var elementExists = document.getElementById("chat_message");
						if(elementExists && current_chat_status.includes("WAITING")==0)
							document.getElementById('chat_message').disabled = false;
						else if(elementExists)
							document.getElementById('chat_message').disabled = true;
					}
					if(chat_mem_name)
						document.getElementById('chat_member_name').value = chat_mem_name;
					var current_message_field_update = document.getElementById('current_message_count');
					if (current_message_field_update != null) {var current_message_count_update=current_message_field_update.value;}
					if (current_message_count_update>current_message_count) 
						{
						var myDiv = document.getElementById('ChatDisplay');
						document.getElementById('ChatDisplay').scrollTop = document.getElementById('ChatDisplay').scrollHeight;
						if (!document.getElementById("MuteCustomerChatAlert").checked) {document.getElementById("CustomerChatAudioAlertFile").play();}
						}
					}
				}
			delete xmlhttp;
			}
		}
}
function CustomerSendMessage(chat_id, user, message, chat_member_name) {
	var chat_id=document.getElementById('chat_id').value;
	var user=document.getElementById('user').value;
	var chat_message=encodeURIComponent(document.getElementById('chat_message').value.trim());
	var chat_member_name=encodeURIComponent(document.getElementById('chat_member_name').value.trim());

	if (!chat_message || !user) {return false;}
	if (!chat_member_name) {alert("Please enter a name to chat as.");}
	if (!chat_id) {alert("You have not joined a chat yet.");}
	document.getElementById('chat_message').value='';

	var xmlhttp=false;
	if (!xmlhttp && typeof XMLHttpRequest!='undefined')
		{
		xmlhttp = new XMLHttpRequest();
		}
	if (xmlhttp) 
		{ 
		chat_query = "&chat_message="+chat_message+"&chat_id="+chat_id+"&group_id="+group_id+"&chat_member_name="+chat_member_name+"&user="+user+"&language="+language+"&available_agents="+available_agents+"&show_email="+show_email+"&chat_level=0&action=send_message";
		xmlhttp.open('POST', 'customer_chat_functions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(chat_query); 
		xmlhttp.onreadystatechange = function() 
			{ 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) 
				{
				var posting_response = xmlhttp.responseText;
				if (posting_response) 
					{
					// alert(posting_response);
					}
				else
					{
					UpdateChatWindow();
					}
				}
			}
		delete xmlhttp;
		}
}

</script>
</head>
<?php
if (!$chat_id && !$cookie_chat_id){
$chat_title= _QXZ("Chat with us"); # This can be modified for customization later
?>
	<body>
	<form action="<?php echo $PHP_SELF; ?>" method="post">
	<input type=hidden name=stage value="send_request">
	<input type=hidden name=language value="<?php echo $language; ?>">
	<input type=hidden name=available_agents value="<?php echo $available_agents; ?>">
	<input type=hidden name=show_email value="<?php echo $show_email ?>">
	<span id="chat_request_span">
		<table align="center" border=0 cellpadding=1 cellspacing=1 height="330">
			<tr>
				<!--<th colspan='2' class='body_small_bold'><?php echo $chat_title; ?></th>-->
                                <div class="chat_head"><?php echo $chat_title; ?></div>
			</tr>
			<tr>
				<!--<td align='right' class='body_small'><?php echo _QXZ("Please enter your name"); ?>:</td>-->
				<td align='left'>
				<!--<input type='text' class="cust_form" name='first_name' id='first_name' size='10' maxlength='30'>&nbsp;<input class="cust_form" type='text' name='last_name' id='last_name' size='15' maxlength='30'>-->
					<?php
                                                if($cookie_name == "") {
                                                        echo "<input class=\"input_name\" id=\"first_name\" name=\"first_name\" placeholder=\"$uname_place\" type=\"text\" required><br>";
                                                }
                                                else {
                                                        echo "<input class=\"input_name\" id=\"first_name\" name=\"first_name\" placeholder=\"$uname_place\" type=\"text\" value=\"$cookie_name\" required><br>";
                                                }
                                                if($cookie_email == "") {
                                                        echo "<input class=\"input_name\" id=\"email\" name=\"email\" placeholder=\"Email\" type=\"email\" required>";
                                                }
                                                else {
                                                        echo "<input class=\"input_name\" id=\"email\" name=\"email\" placeholder=\"Email\" type=\"email\" value=\"$cookie_email\" required>";

                                                }
                                        ?>
				</td>
			</tr>
			<?php
				echo "<tr><td><input type=hidden name=group_id value=\"$cookie_dept\"></td></tr>\n";
			if ( ($show_email=='') or ($show_email=='N') or ($show_email=='Y_WITH_PHONE') )
				{
			?>
			<tr>
				<!--<td align='right' class='body_small'><?php echo _QXZ("Phone number (optional)"); ?>:</td>-->
				<td align='left'>
				<!--<input type='text' class="cust_form" name='phone_number' id='phone_number' size='10' maxlength='20'>-->
				<input type='text' class="input_name" name='phone_number' id='phone_number' size='10' maxlength='20' placeholder="Phone number (optional)">
				</td>
			</tr>
			<?php
				}
			if ( ($show_email=='ONLY') or ($show_email=='Y_WITH_PHONE') )
				{
			?>
			<tr>
				<td align='right' class='body_small'><?php echo _QXZ("Email (optional)"); ?>:</td>
				<td align='left'>
				<input type='text' class="cust_form" name='email' id='email' size='50' maxlength='100'>
				</td>
			</tr>
			<?php
				}
			?>
			<tr>
				<?php
                                        if($cookie_aopc == "")
                                                echo "<th colspan='2'>&nbsp;&nbsp;<input type='submit' class='cust_btn' value='START CHAT' name=\"send_request\"><BR></th>";
                                        else {
                                                echo "<input type=hidden name=aopc_f value=\"$cookie_aopc\">";
                                                echo "<th colspan='2'>&nbsp;&nbsp;<input type='submit' id='sreq' class='cust_btn' value='START CHAT' name=\"send_request\"><BR></th>";
                                                echo "<script>document.getElementById('sreq').click();</script>";
                                        }
                                ?>
			</tr>
			<?php
			if ($error_msg) {
					echo "<tr><th colspan='2' class='queue_text_red'>$error_msg<BR></th></tr>";
			}
			?>
		</table>
	</span>
	</form>
	</body>
<?
} else if ($chat_id && $group_id && (!$first_name || !$email) && !$cookie_chat_id) {
?>
	<body>
	<form action="<?php echo $PHP_SELF; ?>" method="post" name="chat_form" id="chat_form">
	<input type=hidden name=stage value="join_chat">
	<span id="chat_request_span">
		<table algin="center" border=0 cellpadding=3 cellspacing=3 height="330">
			<tr>
				<th colspan='2' class='body_small_bold'><?php echo $chat_title; ?></th>
			</tr>
			<tr>
				<td align='right' class='body_small'><?php echo _QXZ("Please enter your username/name"); ?>:</td>
				<td align='left'>
				<!--<input type='text' class="cust_form" name='first_name' id='first_name' size='10' maxlength='30'>&nbsp;<input class="cust_form" type='text' name='last_name' id='last_name' size='15' maxlength='30'>-->
					<?php
                                                if($cookie_name == "") {
                                                        echo "<input class=\"input_name\" id=\"first_name\" name=\"first_name\" placeholder=\"$uname_place\" type=\"text\" required><br>";
                                                }
                                                else {
                                                        echo "<input class=\"input_name\" id=\"first_name\" name=\"first_name\" placeholder=\"$uname_place\" type=\"text\" value=\"$cookie_name\" required><br>";
                                                }
                                                if($cookie_email == "") {
                                                        echo "<input class=\"input_name\" id=\"email\" name=\"email\" placeholder=\"Email\" type=\"email\" required>";
                                                }
                                                else {
                                                        echo "<input class=\"input_name\" id=\"email\" name=\"email\" placeholder=\"Email\" type=\"email\" value=\"$cookie_email\" required>";

                                                }
                                        ?>
				</td>
			</tr>
			<tr>
				<!--<td align='right' class='body_small'><?php echo _QXZ("Phone number (optional)"); ?>:</td>-->
				<td align='left'>
				<!--<input type='text' class="cust_form" name='phone_number' id='phone_number' size='10' maxlength='20'>-->
				<input type='text' class="input_name" name='phone_number' id='phone_number' size='10' maxlength='20' placeholder="Phone number (optional)">
				</td>
			</tr>
			<tr>
				<th colspan='2'><input type='submit' class='cust_btn' value='<?php echo _QXZ("JOIN CHAT"); ?>' name="join_chat"><BR></th>
			</tr>
			<?php
			if ($error_msg) {
					echo "<tr><th colspan='2' class='queue_text_red'>$error_msg<BR></th></tr>";
			}
			?>
		</table>
	</span>
	<input type="hidden" id="chat_id" name="chat_id" value="<?php echo $chat_id; ?>">
	<input type="hidden" id="group_id" name="group_id" value="<?php echo $group_id; ?>">
	<input type="hidden" id="lead_id" name="lead_id" value="<?php echo $lead_id; ?>">
	<input type="hidden" id="language" name="language" value="<?php echo $language; ?>">
	<input type="hidden" id="available_agents" name="available_agents" value="<?php echo $available_agents; ?>">
	<input type="hidden" id="show_email" name="show_email" value="<?php echo $show_email ?>">
	</form>
	</body>

<?
} else {
?>
	<body onLoad="StartRefresh();" onUnload="javascript:clearInterval(rInt); LeaveChat();">
	<form action='<?php echo $PHP_SELF; ?>' name="chat_form" id="chat_form" enctype="multipart/form-data">
	<table align="center" border='0' align='center' style="table-layout:fixed;">
	<tr>
		<td class="chat_window" height='280' width='300'>
		
		<table border='0' width='100%' style="border-width: 0 0 1px;border-style: solid;border-color: #DACECE;position: relative;bottom:2px;">
			<tr height='35'>
				<td align='left' width='50%' valign='top'>
					<font class='chat_title bold'><?php echo _QXZ("Current chat"); ?>: <span id='ChatActiveStatus'><font color='gray'>WAITING</font></span></font>
				</td>
				<td align='right' width='50%' valign='top'>
				<?php
				if (file_exists("../$filepath/vicidial_admin_web_logo$web_logo")) 
					{
					echo "<img class='small_logo' src='/$filepath/vicidial_admin_web_logo$web_logo'>\n";
					}
				else
					{
					if (file_exists("./images/vicidial_admin_web_logo$web_logo")) 
						{
						echo "<img class='small_logo' src='images/vicidial_admin_web_logo$web_logo'>\n";
						}
					}
				?>
				</td>
			</tr>
		</table>

		<!--<span id='ChatDisplay' name='ChatDisplay' style="position:relative;display:block;width:100%;height:215px;overflow-y:auto;overflow-x:none;z-index:0">-->
		<span lang="en" id='ChatDisplay' name='ChatDisplay' style="display:block;height:80%;width:100%;overflow-y:auto;overflow-x:none;z-index:0;word-break:break-word;-webkit-hyphens:auto;-moz-hyphens:auto;-ms-hyphens:auto;hyphens:auto;">
		</span>
		<div id="typing" style="text-align: center;font-style: italic;font-family: Arial, Helvetica, sans-serif;font-size: 10pt;"></div>
<!--
		<span style="position:fixed;display:block;top:230px;right:25px;z-index:1"><img border="0" src="images/VICIchat_powered_logo.gif" width="123" height="30"></span> 
		<span style="display:inline-block;float:right;z-index:1"><img border="0" src="images/VICIchat_powered_logo.png" width="123" height="30"></span> 
//-->
		</td>
	</tr>
	<tr>
		<td align='center'>
		<table border='0' align='center' border='0' cellpadding='0' cellspacing='0' style="table-layout:fixed;">
			<tr>
				<td align='center' colspan='2' style='width:1%;'>
					<div class="container">
						<span id='chat_message_console' name='chat_message_console' style="font-weight:bold;">
							<textarea border='1' name='chat_message' id='chat_message' class='chat_window' cols='86' rows='3' placeholder="Type your message here" onkeypress="if (event.keyCode==13 && !event.shiftKey) {CustomerSendMessage(this.form.chat_id.value, this.form.user.value, this.form.chat_message.value); return false;}"></textarea>
							<label id="upload-fa" for="file-input"><i class="fa fa-upload" aria-hidden="true" title="Send a file"></i></label>
							<img id="loading" src="images/loading_circle.gif" alt="loading">
							<input id="file-input" name="file-input" type="file" style="display:none;">
						</span>
					</div>
				</td>
			</tr>
			<!--<tr>
				<td align='left' class='chat_message' valign='top'><input class='blue_btn' type='button' style="width:100px" value="<?php echo _QXZ("SEND MESSAGE"); ?>" onClick="CustomerSendMessage(this.form.chat_id.value, this.form.user.value, this.form.chat_message.value)"></td>
				<td align='right' valign='top'><input class='blue_btn' type='button' style="width:100px" value="<?php echo _QXZ("CLEAR"); ?>" onClick="document.getElementById('chat_message').value=''"></td>
			</tr>-->
			<tr>
				<td valign='top' align='left'><BR>
					<input class='red_btn' type='button' style="width:100px" value="<?php echo _QXZ("LEAVE CHAT"); ?>" onClick="LeaveChat(document.getElementById('chat_id').value, document.getElementById('user').value, document.getElementById('chat_member_name').value, 1);">
				</td>
				<td class='chat_message' valign='top' align='right'><BR>
					<input type='checkbox' id='MuteCustomerChatAlert' name='MuteCustomerChatAlert'><?php echo _QXZ("Mute sound"); ?>
				</td>
			</tr>
			<tr>
				<td align='right' colspan='2'><img border="0" style="padding-top: 5px;" src="images/VICIchat_powered_logo.png" width="123" height="30"></td>
			</tr>
		</table>
		</td>
	</tr>
	<?php
	if ($error_msg) 
		{
		echo "<tr><th class='queue_text_red'>$error_msg<BR></th></tr>";
		}
	?>
	</table>
	<input type="hidden" id="user" name="user" value="<?php echo $user; ?>">
	<input type="hidden" id="chat_member_name" name="chat_member_name" value="<?php echo $chat_member_name; ?>">
	<input type="hidden" id="chat_id" name="chat_id" value="<?php echo $chat_id; ?>">
	<input type="hidden" id="chat_creator" name="chat_creator" value="<?php echo $chat_creator; ?>">
	<input type="hidden" id="lead_id" name="lead_id" value="<?php echo $lead_id; ?>">
	<input type="hidden" id="group_id" name="group_id" value="<?php echo $group_id; ?>">
	<input type="hidden" id="language" name="language" value="<?php echo $language; ?>">
	<input type="hidden" id="available_agents" name="available_agents" value="<?php echo $available_agents; ?>">
	<input type="hidden" id="show_email" name="show_email" value="<?php echo $show_email ?>">
	<audio id='CustomerChatAudioAlertFile'><source src="sounds/chat_alert.wav" type="audio/wav"></audio>
	<input type='hidden' id='live_msg_cnt_field' name='live_msg_cnt_field' value='0'>
	</form>
	</body>
<?
}
?>

</html>
