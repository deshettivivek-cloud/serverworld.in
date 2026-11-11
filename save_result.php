<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='OPTIONS'){ exit(0); }

$input = json_decode(file_get_contents('php://input'), true);
if(!$input){ $input = $_POST; }
if(empty($input['name']) || empty($input['roll'])){ 
    echo json_encode(['status'=>'error','msg'=>'Name and Roll required']); 
    exit; 
}

$file = __DIR__.'/results.json';
if(!file_exists($file)){ file_put_contents($file,'[]'); }
$fp = fopen($file, 'c+'); 
if(!$fp){ echo json_encode(['status'=>'error','msg'=>'Could not open results storage']); exit; }

flock($fp, LOCK_EX);
$content = stream_get_contents($fp); 
$data = json_decode($content, true);
if(!is_array($data)) $data = [];

require_once __DIR__ . '/db.php';

$score = intval($input['score'] ?? 0);
$total = intval($input['total'] ?? 10);
if($total <= 0) $total = 10;
$accuracy = isset($input['accuracy']) ? intval($input['accuracy']) : round(($score / $total) * 100);
$correct = intval($input['correct'] ?? $score);
$incorrect = intval($input['incorrect'] ?? 0);
$unanswered = intval($input['unanswered'] ?? max(0, $total - $correct - $incorrect));
$timeTakenSeconds = intval($input['timeTakenSeconds'] ?? 0);
$timeTakenFormatted = htmlspecialchars(trim($input['timeTaken'] ?? ($timeTakenSeconds > 0 ? floor($timeTakenSeconds/60).'m '.($timeTakenSeconds%60).'s' : '0m 0s')), ENT_QUOTES, 'UTF-8');
$examTitle = htmlspecialchars(trim($input['examTitle'] ?? 'ServerWorld Online Exam'), ENT_QUOTES, 'UTF-8');
$examId = isset($input['examId']) ? intval($input['examId']) : 0;
$userAnswers = is_array($input['userAnswers'] ?? null) ? $input['userAnswers'] : [];
$sectionAnalysis = is_array($input['sectionAnalysis'] ?? null) ? $input['sectionAnalysis'] : [];

// Check single attempt constraint for Live Tests
$targetExam = $examId > 0 ? ExamDB::getExamById($examId) : null;
$testType = $targetExam['testType'] ?? ($input['testType'] ?? 'mock');
$isSingleAttempt = ($testType === 'live') || !empty($targetExam['single_attempt']);
$rollLower = strtolower(trim($input['roll']));

if ($isSingleAttempt) {
    foreach ($data as $existing) {
        $exId = intval($existing['examId'] ?? 0);
        $exRoll = strtolower(trim($existing['roll'] ?? ''));
        if ($exId === $examId && $exRoll === $rollLower) {
            flock($fp, LOCK_UN);
            fclose($fp);
            echo json_encode([
                'status' => 'error',
                'alreadySubmitted' => true,
                'msg' => 'You have already submitted this Live Test. Multiple attempts are strictly prohibited.',
                'result' => $existing
            ]);
            exit;
        }
    }
}

// Calculate real rank among actual attempts for this exam in results.json
$examAttempts = [];
foreach($data as $r){
    if(($r['examTitle'] ?? '') === $examTitle){
        $examAttempts[] = $r;
    }
}

// Real rank is 1 + number of submissions that had a higher score (or equal score with faster time)
$rank = 1;
foreach($examAttempts as $att){
    $attScore = intval($att['score'] ?? 0);
    $attTime = intval($att['timeTakenSeconds'] ?? 999999);
    if($attScore > $score){
        $rank++;
    } elseif($attScore === $score && $timeTakenSeconds > 0 && $attTime < $timeTakenSeconds){
        $rank++;
    }
}
$totalCandidates = count($examAttempts) + 1;

$tabSwitchCount = intval($input['tabSwitchCount'] ?? 0);
$fullscreenExitCount = intval($input['fullscreenExitCount'] ?? 0);
$focusEvents = is_array($input['focusEvents'] ?? null) ? $input['focusEvents'] : [];
$focusStatus = ($tabSwitchCount === 0 && $fullscreenExitCount === 0) ? 'CLEAN' : 'FLAGGED';

$newEntry = [
    'name' => htmlspecialchars(trim($input['name']), ENT_QUOTES, 'UTF-8'),
    'roll' => htmlspecialchars(trim($input['roll']), ENT_QUOTES, 'UTF-8'),
    'score' => $score,
    'total' => $total,
    'correct' => $correct,
    'incorrect' => $incorrect,
    'unanswered' => $unanswered,
    'allIndiaRank' => $rank,
    'totalCandidates' => $totalCandidates,
    'accuracy' => $accuracy,
    'timeTaken' => $timeTakenFormatted,
    'timeTakenSeconds' => $timeTakenSeconds,
    'examTitle' => $examTitle,
    'examId' => $examId,
    'tabSwitchCount' => $tabSwitchCount,
    'fullscreenExitCount' => $fullscreenExitCount,
    'focusEvents' => $focusEvents,
    'focusStatus' => $focusStatus,
    'sectionAnalysis' => $sectionAnalysis,
    'userAnswers' => $userAnswers,
    'solutions' => is_array($input['solutions'] ?? null) ? $input['solutions'] : [],
    'photo' => $input['photo'] ?? ($input['verifiedPhoto'] ?? ''),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'timestamp' => date('Y-m-d H:i:s')
];

$data[] = $newEntry;

ftruncate($fp, 0); 
rewind($fp); 
fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); 
flock($fp, LOCK_UN); 
fclose($fp);

echo json_encode([
    'status' => 'ok',
    'saved' => true,
    'rank' => $rank,
    'totalCandidates' => $totalCandidates,
    'result' => $newEntry
]);
?>