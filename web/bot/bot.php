<?php
require_once("../config.php");

$m = tgparseinput();
if(!$m) httpdie(400, "1");

//error_log(json_encode($m));
if(isset($m['from']) && (!isset($m['text']) || strpos(strtolower($m['text']), '/start') !== 0 )){
    $db = DbConfig::getConnection();    
	tgstart(NULL, $m, $db, $token, FALSE);
}

if(isset($m['text'])){
	$text = $m['text'];
	if(strpos(strtolower($text), '/start') === 0){
		$db = DbConfig::getConnection();
	  	tgstart("Hola!",$m, $db, $token, TRUE);
	}
	else if(strpos(strtolower($text), '/sala') === 0){
        get_cursos_eventos($m, $token);
    }
}
else if(isset($m['new_chat_participant']) && 
	isset($m['new_chat_participant']['username']) &&
	$m['new_chat_participant']['username'] === 'ucampus_uchile_bot'){
	//-> nos metieorn en un grupo
	$db = DbConfig::getConnection();
	tgstartgroup($m, 0,$db,$token);	
}
else if(isset($m['left_chat_participant']) &&
    isset($m['left_chat_participant']['username']) &&
    $m['left_chat_participant']['username'] === 'ucampus_uchile_bot'){
    //-> nos sacaron de un grupo
    $db = DbConfig::getConnection();
    tgstartgroup($m, 1,$db, $token);
}
else if(isset($m['query']) && strlen($m['query'])>2 ){
	$db = DbConfig::getConnection();
	$events = get_events($m['query'], $db);
	if(count($events) == 0) return;
	$options = [];
	foreach($events as $o){
		$salas = "";
		if(isset($o['sala'])){
 			foreach($o['sala'] as $s) $salas .= $s['nombre'].'-'.$s['edificio'].", ";
            if(strlen($salas)>0) $salas = substr($salas, 0, strlen($salas)-2);
           	else $salas = "No hay salas asignadas.";
        }
		else $salas = "No hay salas asignadas.";
		$options[] = [
			'title' => 'S'.$o['curso']['seccion'].'-'.$o['curso']['nombre'],
			'msg' => "<b>".$o['curso']['nombre']." (".$o['curso']['codigo']."-".$o['curso']['seccion'].")</b>\n".$o['tipo']."\n".$salas."\nDesde ".$o['fecha_ini']." a ".$o['fecha_fin']
		];
	}
	tgshowoptions($options, $m['id'], $token);
}

function get_events($search, $db){
	$search = str_clean($search);
    $json = get_eventos($db);
    $out = [];
    foreach($json as $j){
        if(!isset($j['curso'])) continue;
        $cod = $j['curso']['codigo'].'-'.$j['curso']['seccion'];
        if( strpos( str_clean($cod), $search) !== false ||
            strpos( str_clean($j['curso']['nombre']), $search) !== false ){
            $j['fecha_ini'] = fecha($j['fecha_ini']);
            $j['fecha_fin'] = fecha($j['fecha_fin']);
            $out[] = $j;
        }
    }
	return $out;
}

function get_search_txt($txt, $cmd){
	$txt = str_replace("@ucampus_uchile_bot","",$txt);
	$txt = trim(str_replace($cmd, "", $txt));
	if(strlen($txt)>0) return $txt;
	else return NULL;
}

function get_cursos_eventos($m, $token){
	$original = get_search_txt($m['text'], '/sala');
	$search = str_clean($original);
	if(!$search || strlen($search) <= 3) {
		error_log($search);
        tgsend_msg("Necesito que me envíes un código o nombre de curso. También debe ser de más de 3 caracteres.", $m['chat']['id'], $token, FALSE);
        return;
    }
    tgchat_action("typing", $m['chat']['id'], $token);
    $db = DbConfig::getConnection();
    save_request($original,$m, $db);
	$out = get_events($search, $db);
    $msg = "";
    if(count($out) == 0) $msg = "Hoy no hay eventos para cursos con el nombre o código '".$original."'";
    else {
        foreach($out as $o){
            $salas = "";
            if(isset($o['sala'])){
				foreach($o['sala'] as $s) $salas .= $s['nombre'].'-'.$s['edificio'].", ";
            	if(strlen($salas)>0) $salas = substr($salas, 0, strlen($salas)-2);
            	else $salas = "No hay salas asignadas.";
			}
			else $salas = "No hay salas asignadas.";
            $msg .= "<b>".$o['curso']['nombre']." (".$o['curso']['codigo']."-".$o['curso']['seccion'].")</b>\n".$o['tipo']."\n".$salas."\nDesde ".$o['fecha_ini']." a ".$o['fecha_fin']."\n\n";
        }
    }
    tgsend_msg($msg, $m['chat']['id'], $token);
}

function get_sala($m, $token){
	$original = get_search_txt($m['text'], '/sala');
	$search = str_clean($original);
	if(!$search || strlen($search) <= 3) {
		tgsend_msg("Necesito que me envies un código o nombre de curso. También debe ser de más de 3 caracteres.", $m['chat']['id'], $token, FALSE);
		return;
	}
	tgchat_action("typing", $m['chat']['id'], $token);	
	$db = DbConfig::getConnection();
	save_request($original,$m, $db);
	$json = get_data($db);	
	$out = [];
	foreach($json as $j){
		if(!isset($j['eventos']) && count($j['eventos'] == 0)) continue;
		foreach($j['eventos'] as $e){
			$cod = $e['curso_codigo'].'-'.$e['curso_seccion'];
			if( strpos( str_clean($cod), $search) !== false ||
				strpos( str_clean($e['curso_nombre']), $search) !== false ){
				$e['sala'] = $j['nombre'].' en '.$j['edificio'];
				$e['fecha_ini'] = fecha($e['fecha_ini']);
				$e['fecha_fin'] = fecha($e['fecha_fin']);
				$out[] = $e;
			}
		}
	}
	$msg = "";
	if(count($out) == 0) $msg = "Hoy no hay eventos para cursos con el nombre o código '".$original."'";
	else {
		foreach($out as $o){
			$msg .= "<b>".$o['curso_nombre']." (".$o['curso_codigo']."-".$o['curso_seccion'].")</b>\n".$o['tipo_evento']."\n".$o['sala']."\nDesde ".$o['fecha_ini']." a ".$o['fecha_fin']."\n\n";
		}
	}
	tgsend_msg($msg, $m['chat']['id'], $token);
}

function fecha($txt){
	return strftime("%H:%M", $txt);
}

function get_data($db){
	$sql = "SELECT data FROM salas_cache WHERE created_on >= NOW() - INTERVAL 30 MINUTE ORDER BY created_on DESC LIMIT 1";
	$res = DbConfig::sql($db, $sql);
	if($res){
		$json = json_decode($res[0]['data'], TRUE);
		return $json;
	}
	
	$cmd = "https://$ucampus_key@ucampus.uchile.cl/api/0/fcfm_eventos/salas";
    $res = file_get_contents($cmd);
    $json = json_decode($res, TRUE);
	$todb = $db->real_escape_string(json_encode($json));
	$sql = "INSERT INTO salas_cache (data) VALUES ('$todb')";
	if(!DbConfig::update($db, $sql)) {
        error_log($sql);
        error_log($db->error);
    }
	return $json; 
}

function get_eventos($db){
	$sql = "SELECT data FROM eventos_cache WHERE created_on >= NOW() - INTERVAL 30 MINUTE ORDER BY created_on DESC LIMIT 1";
    $res = DbConfig::sql($db, $sql);
    if($res){
        $json = json_decode($res[0]['data'], TRUE);
        return $json;
    }
	$cmd = "https://$ucampus_key@ucampus.uchile.cl/api/0/fcfm_eventos/eventos";
	$res = file_get_contents($cmd);
    $json = json_decode($res, TRUE);
	$todb = $db->real_escape_string(json_encode($json));
    $sql = "INSERT INTO eventos_cache (data) VALUES ('$todb')";
    if(!DbConfig::update($db, $sql)) {
        error_log($sql);
        error_log($db->error);
    }
	return $json;
}

function save_request($original, $m, $db){
	$uid = $db->real_escape_string($m['chat']['id']);
	$search = $db->real_escape_string($original);
	$sql = "INSERT INTO requests (uid, search) VALUES ('$uid', '$search')";
	if(!DbConfig::update($db, $sql)) {
		error_log($sql);
        error_log($db->error);
    }
}

function str_clean($string) {
    return strtolower(trim(preg_replace('~[^0-9a-z]+~i', '-', preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($string, ENT_QUOTES, 'UTF-8'))), ' '));
}
