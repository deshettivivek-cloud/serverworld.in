<?php
require_once "db.php";
$pdfPath = "uploads/1794161941_TSPSC_Practice_Question_Paper.pdf";
$data = file_get_contents($pdfPath);
$pos = 0;
$count = 0;
while(($pos = strpos($data, "/Subtype /Image", $pos)) !== false){
  $count++;
  $sStart = strpos($data, "stream", $pos) + 6;
  if($data[$sStart] === "\r") $sStart++;
  if($data[$sStart] === "\n") $sStart++;
  $sEnd = strpos($data, "endstream", $sStart);
  $raw = substr($data, $sStart, $sEnd - $sStart);
  $dec = ExamDB::a85Decode($raw);
  echo "Image $count decoded length: " . strlen($dec) . " header: " . bin2hex(substr($dec, 0, 4)) . PHP_EOL;
  $pos = $sEnd + 9;
}
