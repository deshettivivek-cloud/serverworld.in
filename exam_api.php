<?php
// =====================================================================
// ServerWorld Interactive Examination System - Student Exam API
// Handles secure code verification, server-side timer, autosave, and grading
// =====================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/db.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!$input) {
    $input = $_POST;
}

$action = trim($_GET['action'] ?? $input['action'] ?? '');
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ---------------------------------------------------------------------
// 0. SAVE STUDENT PRE-EXAM VERIFICATION PHOTO
// ---------------------------------------------------------------------
if ($action === 'save_verification_photo') {
    $roll = trim($input['roll'] ?? '');
    $photo = $input['photo'] ?? '';
    if (!empty($roll) && !empty($photo)) {
        $profilesFile = __DIR__ . '/profiles.json';
        if (file_exists($profilesFile)) {
            $profiles = json_decode(file_get_contents($profilesFile), true);
            if (is_array($profiles)) {
                $saved = false;
                foreach ($profiles as &$p) {
                    if (strcasecmp(trim($p['roll'] ?? $p['username'] ?? ''), $roll) === 0) {
                        $p['photo'] = $photo;
                        $p['verified_at'] = date('Y-m-d H:i:s');
                        $saved = true;
                        break;
                    }
                }
                unset($p);
                if ($saved) {
                    file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }
        echo json_encode(['success' => true, 'saved' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Roll and photo required.']);
    exit;
}

// ---------------------------------------------------------------------
// 1. VERIFY EXAM ACCESS CODE
// ---------------------------------------------------------------------
if ($action === 'verify_code') {
    $code = strtoupper(trim($input['code'] ?? ''));
    $roll = trim($input['roll'] ?? '');
    if (empty($code)) {
        echo json_encode(['success' => false, 'error' => 'Please enter an examination access code.']);
        exit;
    }

    $exam = ExamDB::getExamByCode($code);
    if (!$exam) {
        echo json_encode(['success' => false, 'error' => "Invalid Access Code: '{$code}'. Please check with your instructor."]);
        exit;
    }

    if (($exam['status'] ?? 'LIVE') !== 'LIVE') {
        echo json_encode(['success' => false, 'error' => "This examination is currently not active (Status: {$exam['status']})."]);
        exit;
    }

    // Check if student has already submitted this Live Test
    $testType = $exam['testType'] ?? 'live';
    $isSingleAttempt = ($testType === 'live') || !empty($exam['single_attempt']);

    if ($isSingleAttempt && !empty($roll)) {
        $results = ExamDB::getAllResults();
        foreach ($results as $r) {
            if (intval($r['examId'] ?? 0) === intval($exam['id']) && strtolower(trim($r['roll'] ?? '')) === strtolower($roll)) {
                echo json_encode([
                    'success' => false,
                    'alreadySubmitted' => true,
                    'exam' => $exam,
                    'result' => $r,
                    'error' => "Access denied: You have already completed and submitted this Live Examination. Multiple attempts are strictly prohibited."
                ]);
                exit;
            }
        }
    }

    $qCount = isset($exam['questions']) ? count($exam['questions']) : intval($exam['qCount'] ?? 0);

    echo json_encode([
        'success' => true,
        'exam' => [
            'id' => $exam['id'],
            'title' => $exam['title'],
            'category' => $exam['category'] ?? 'General',
            'testType' => $exam['testType'] ?? 'live',
            'duration' => intval($exam['duration'] ?? 15),
            'maxMarks' => floatval($exam['maxMarks'] ?? $qCount),
            'totalQuestions' => $qCount,
            'certificateThreshold' => intval($exam['certificateThreshold'] ?? 85),
            'paperFile' => $exam['file'] ?? null,
            'paperType' => $exam['paperType'] ?? 'interactive'
        ]
    ]);
    exit;
}

// ---------------------------------------------------------------------
// 2. START EXAMINATION ATTEMPT (SERVER-SIDE TIMER INITIALIZATION)
// ---------------------------------------------------------------------
if ($action === 'start_attempt') {
    $code = strtoupper(trim($input['code'] ?? ''));
    $examId = intval($input['examId'] ?? 0);
    $name = trim($input['name'] ?? 'Candidate');
    $roll = trim($input['roll'] ?? 'SW001');

    $exam = null;
    if ($examId > 0) {
        $exam = ExamDB::getExamById($examId);
    }
    if (!$exam && !empty($code)) {
        $exam = ExamDB::getExamByCode($code);
    }

    if (!$exam) {
        echo json_encode(['success' => false, 'error' => 'Examination not found.']);
        exit;
    }

    // Check paperType: ONLY tests with structured questions and answers can be started as interactive exams!
    $paperType = $exam['paperType'] ?? 'interactive';
    $qCount = isset($exam['questions']) ? count($exam['questions']) : intval($exam['qCount'] ?? 0);
    if ($paperType === 'document' || $qCount === 0) {
        echo json_encode(['success' => false, 'error' => 'This assessment is a reference document only. Interactive exams require structured questions with choices and answers.']);
        exit;
    }

    // Test Type enforcement: Live Tests require access code; Mock Tests are open!
    $testType = $exam['testType'] ?? 'live';
    if ($testType === 'live') {
        $requiredCode = strtoupper(trim($exam['accessCode'] ?? ''));
        if (empty($code) || $code !== $requiredCode) {
            echo json_encode(['success' => false, 'error' => 'This Live Test requires a valid access code from your instructor to begin.']);
            exit;
        }
    }

    // Single attempt rule check: LIVE tests and single_attempt exams are strictly ONE attempt per student!
    $isSingleAttempt = ($testType === 'live') || !empty($exam['single_attempt']);

    if ($isSingleAttempt) {
        $results = ExamDB::getAllResults();
        foreach ($results as $r) {
            if (intval($r['examId'] ?? 0) === intval($exam['id']) && strtolower(trim($r['roll'] ?? '')) === strtolower($roll)) {
                $subDate = $r['timestamp'] ?? $r['date'] ?? '';
                echo json_encode([
                    'success' => false,
                    'alreadySubmitted' => true,
                    'error' => "You have already completed and submitted this Live Examination" . ($subDate ? " on {$subDate}" : "") . ". Multiple attempts are strictly prohibited."
                ]);
                exit;
            }
        }

        // Also check attempts table for any SUBMITTED attempt
        $attempts = ExamDB::getAllAttempts();
        foreach ($attempts as $att) {
            if (intval($att['examId'] ?? 0) === intval($exam['id']) && strtolower(trim($att['roll'] ?? '')) === strtolower($roll) && ($att['status'] ?? '') === 'SUBMITTED') {
                echo json_encode([
                    'success' => false,
                    'alreadySubmitted' => true,
                    'error' => "You have already completed and submitted this Live Examination. Multiple attempts are strictly prohibited."
                ]);
                exit;
            }
        }
    }

    // Check if an existing IN_PROGRESS attempt exists for this session/candidate to resume
    $existingAttempts = ExamDB::getAllAttempts();
    $resumeToken = null;
    foreach ($existingAttempts as $att) {
        if (intval($att['examId']) === intval($exam['id']) && strtolower(trim($att['roll'])) === strtolower($roll) && ($att['status'] ?? '') === 'IN_PROGRESS') {
            $elapsed = time() - intval($att['startTimestamp']);
            $remaining = intval($att['durationSeconds']) - $elapsed;
            if ($remaining > 0) {
                $resumeToken = $att['token'];
                break;
            }
        }
    }

    if ($resumeToken) {
        $att = ExamDB::getAttemptByToken($resumeToken);
        $elapsed = time() - intval($att['startTimestamp']);
        $remaining = max(0, intval($att['durationSeconds']) - $elapsed);

        echo json_encode([
            'success' => true,
            'token' => $resumeToken,
            'attemptId' => $att['id'],
            'resumed' => true,
            'remainingSeconds' => $remaining,
            'exam' => [
                'id' => $exam['id'],
                'title' => $exam['title'],
                'category' => $exam['category'] ?? 'General',
                'duration' => $exam['duration'],
                'totalQuestions' => count($exam['questions'] ?? []),
                'maxMarks' => $exam['maxMarks'] ?? count($exam['questions'] ?? []),
                'certificateThreshold' => $exam['certificateThreshold'] ?? 85,
                'paperFile' => $exam['file'] ?? null,
                'questions' => array_map(function($q, $idx) {
                    return [
                        'num' => $idx + 1,
                        'q' => $q['q'],
                        'opts' => $q['opts'],
                        'marks' => floatval($q['marks'] ?? 1.0),
                        'topic' => $q['topic'] ?? ''
                        // NOTE: Correct answer strictly omitted!
                    ];
                }, $exam['questions'] ?? [], array_keys($exam['questions'] ?? []))
            ],
            'responses' => $att['responses'] ?? [],
            'tabSwitchCount' => intval($att['tabSwitchCount'] ?? 0),
            'fullscreenExitCount' => intval($att['fullscreenExitCount'] ?? 0),
            'focusEvents' => $att['focusEvents'] ?? []
        ]);
        exit;
    }

    $res = ExamDB::createAttempt($exam['id'], $name, $roll, $code, $clientIp);
    echo json_encode($res);
    exit;
}

// ---------------------------------------------------------------------
// 3. GET ATTEMPT (SERVER-SIDE TIMER & STATE RESTORATION ON REFRESH)
// ---------------------------------------------------------------------
if ($action === 'get_attempt') {
    $token = trim($input['token'] ?? $_GET['token'] ?? '');
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Attempt token required.']);
        exit;
    }

    $attempt = ExamDB::getAttemptByToken($token);
    if (!$attempt) {
        echo json_encode(['success' => false, 'error' => 'Attempt session not found.']);
        exit;
    }

    $elapsed = time() - intval($attempt['startTimestamp']);
    $remaining = max(0, intval($attempt['durationSeconds']) - $elapsed);
    $status = $attempt['status'];

    if ($remaining <= 0 && $status === 'IN_PROGRESS') {
        $status = 'EXPIRED';
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'remainingSeconds' => $remaining,
        'responses' => $attempt['responses'] ?? [],
        'tabSwitchCount' => intval($attempt['tabSwitchCount'] ?? 0),
        'fullscreenExitCount' => intval($attempt['fullscreenExitCount'] ?? 0),
        'focusEvents' => $attempt['focusEvents'] ?? [],
        'studentName' => $attempt['name'],
        'studentRoll' => $attempt['roll']
    ]);
    exit;
}

// ---------------------------------------------------------------------
// 4. LOG FOCUS EVENT (FOCUSED EXAM MODE: TAB SWITCH / FULLSCREEN EXIT)
// ---------------------------------------------------------------------
if ($action === 'log_focus_event') {
    $token = trim($input['token'] ?? '');
    $eventType = trim($input['eventType'] ?? 'tab_switch');
    $timestamp = trim($input['timestamp'] ?? date('Y-m-d H:i:s'));
    $details = trim($input['details'] ?? '');

    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Attempt token required.']);
        exit;
    }

    $res = ExamDB::logFocusEvent($token, $eventType, $timestamp, $details);
    echo json_encode($res);
    exit;
}

// ---------------------------------------------------------------------
// 5. SAVE STUDENT RESPONSE (AUTOSAVE VIA AJAX)
// ---------------------------------------------------------------------
if ($action === 'save_response') {
    $token = trim($input['token'] ?? '');
    $qIndex = intval($input['qIndex'] ?? 0);
    $selectedOption = isset($input['selectedOption']) && $input['selectedOption'] !== '' ? $input['selectedOption'] : null;
    $isMarked = !empty($input['isMarked']);

    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Attempt token required.']);
        exit;
    }

    $focusData = [
        'tabSwitchCount' => isset($input['tabSwitchCount']) ? intval($input['tabSwitchCount']) : null,
        'fullscreenExitCount' => isset($input['fullscreenExitCount']) ? intval($input['fullscreenExitCount']) : null,
        'focusEvents' => is_array($input['focusEvents'] ?? null) ? $input['focusEvents'] : null
    ];

    $res = ExamDB::saveResponse($token, $qIndex, $selectedOption, $isMarked, $focusData);
    echo json_encode($res);
    exit;
}

// ---------------------------------------------------------------------
// 6. SUBMIT EXAMINATION (SERVER-SIDE EVALUATION & SCORING)
// ---------------------------------------------------------------------
if ($action === 'submit_attempt') {
    $token = trim($input['token'] ?? '');
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Attempt token required.']);
        exit;
    }

    $focusData = [
        'tabSwitchCount' => isset($input['tabSwitchCount']) ? intval($input['tabSwitchCount']) : null,
        'fullscreenExitCount' => isset($input['fullscreenExitCount']) ? intval($input['fullscreenExitCount']) : null,
        'focusEvents' => is_array($input['focusEvents'] ?? null) ? $input['focusEvents'] : null
    ];

    $res = ExamDB::submitAttempt($token, $focusData);
    echo json_encode($res);
    exit;
}

// Unknown action
echo json_encode(['success' => false, 'error' => 'Invalid API action.']);
exit;

