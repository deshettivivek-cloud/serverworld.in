<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ exit(0); }
$input = json_decode(file_get_contents('php://input'), true);
if(!$input){ $input = $_POST; }
$file = __DIR__.'/profiles.json';
if(!file_exists($file)){ file_put_contents($file,'[]'); }
$fp = fopen($file, 'c+'); flock($fp, LOCK_EX);
$content = stream_get_contents($fp); $data = json_decode($content, true);
if(!is_array($data)) $data = [];
$data[] = ['name'=>$input['name']??'','roll'=>$input['roll']??'','username'=>$input['username']??$input['roll']??'','password'=>$input['password']??'','email'=>$input['email']??'','phone'=>$input['phone']??'','created'=>date('Y-m-d H:i:s')];
ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); flock($fp, LOCK_UN); fclose($fp);
echo json_encode(['status'=>'ok','saved'=>true]);
?>
