<?php
require_once('db.php');

function tgparseinput(){
	$postData = file_get_contents('php://input');
	if(!isset($postData)) return NULL;
	$json = json_decode($postData, true);
	//error_log($postData);
	$m = $json;
	if(isset($json['message'])) $m = $json['message'];
	else if(isset($json['location'])) $m = $json;
	else if(isset($json['inline_query'])) $m = $json['inline_query'];
	return $m;
}

function tgstart($msg, $m, $db, $token, $start){
  $f = $m['from'];
  $uid = $db->real_escape_string($f['id']);
  $first_name = $db->real_escape_string(isset($f['first_name'])?$f['first_name']:"undefined");
  $last_name = $db->real_escape_string(isset($f['last_name'])?$f['last_name']:"undefined");
  $username = $db->real_escape_string(isset($f['username'])?$f['username']:"undefined");
  $language_code = $db->real_escape_string(isset($f['language_code'])?$f['language_code']:"AA");

  $sql = "SELECT * FROM tg_users WHERE uid = '$uid'";
  $res = DbConfig::sql($db, $sql);
  if(count($res)==0){
    $sql = "INSERT INTO tg_users (uid, first_name, last_name, username, language_code, start) VALUES ('$uid', '$first_name', '$last_name', '$username', '$language_code', ". $start?1:0 .")";
	if(!DbConfig::update($db, $sql)) {
		error_log($db->error);
	}
  }
  else{
	$sql = "UPDATE tg_users SET first_name='$first_name', last_name='$last_name', username='$username', language_code='$language_code'";
    if($start) $sql .= ' ,start=1';
    $sql .= " WHERE uid='$uid'";
    if(!DbConfig::update($db, $sql)) echo 'error'.$db->error;
  }
  tgsend_msg($msg, $uid, $token);
}
function tgstartgroup($m, $action, $db,$token){
	$who = $db->real_escape_string($m['from']['id']);
	$f = $m['from'];
	$first_name = $db->real_escape_string(isset($f['first_name'])?$f['first_name']:"undefined");
  	$last_name = $db->real_escape_string(isset($f['last_name'])?$f['last_name']:"undefined");
  	$username = $db->real_escape_string(isset($f['username'])?$f['username']:"undefined");
	$whofrom = "$first_name,$last_name,$username";
	$uid = $db->real_escape_string($m['chat']['id']);
	$name = $db->real_escape_string($m['chat']['title']);
	$type = $db->real_escape_string($m['chat']['type']);
	$sql = "INSERT INTO tg_group_log (user_uid, user_name, group_uid, group_name, type, action) VALUES ('$who', '$whofrom', '$uid', '$name', '$type', $action)";
	if(!DbConfig::update($db, $sql)) {
        error_log($db->error);
		tgsend_msg($sql."->".$db->error, "7372677", $token);
    }
}

function tgsend_msg($msg, $uid, $token, $html = TRUE){
	if(strlen($msg) == 0) return;
	$msg = urlencode($msg);
	$parse_mode = $html?"&parse_mode=HTML":"";
	$cmd = "https://api.telegram.org/bot$token/sendMessage?chat_id=$uid&text=$msg".$parse_mode;
	$res = file_get_contents($cmd);
	if(!$res && !strstr($msg,"Demasiadas")){
		tgsend_msg("Demasiadas respuestas, trata de darme una consulta más acotada", $uid, $token, $html);
	}
}

function tgrequest_geo($msg, $uid, $token){
	$msg = urlencode($msg);
	$reply_mark = urlencode(json_encode(
		['one_time_keyboard' => TRUE, 
		 'keyboard' => [[ ['text' => 'Enviar localización', 
							'request_location' => TRUE] ]] ]));
	$cmd = "https://api.telegram.org/bot$token/sendMessage?chat_id=$uid&text=$msg&parse_mode=HTML&reply_markup=$reply_mark";
	$res = file_get_contents($cmd);
}

function tgshow_options($msg, $options, $uid, $token){
	if(!is_array($options) || count($options) <= 0) return;
	$reply_mark = [];
	$reply_mark['one_time_keyboard'] = TRUE;
	$reply_mark['keyboard'] = [];
	foreach($options as $o){
		$reply_mark['keyboard'][] = [['text'=>$o]];
	}
	$reply_mark = urlencode(json_encode($reply_mark));
    $cmd = "https://api.telegram.org/bot$token/sendMessage?chat_id=$uid&text=$msg&parse_mode=HTML&reply_markup=$reply_mark";
	$res = file_get_contents($cmd);
}

function tgchat_action($action, $uid, $token){
	$cmd = "https://api.telegram.org/bot$token/sendChatAction?chat_id=$uid&action=$action";
	file_get_contents($cmd);	
}

function tgshowoptions($options, $qid, $token){
        $res = [];
        foreach($options as $o){
                $rid = md5($qid .
        $o['title'] .
$o['msg']);
                $res[] = [
                        'type' => 'article',
                'id' => $rid,
                'title' => $o['title'],
                'input_message_content' => ['message_text'=>$o['msg'], 'parse_mode' => 'HTML']
                ];
        }
        $results = urlencode(json_encode($res));
        $cmd = "https://api.telegram.org/bot$token/answerInlineQuery?inline_query_id=$qid&results=$results";
    $res = file_get_contents($cmd);
}
