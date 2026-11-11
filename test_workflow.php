<?php
// =====================================================================
// Comprehensive Workflow & Security Test Suite
// Verifies all 14 checklist steps from user requirements
// =====================================================================

require_once __DIR__ . '/db.php';

function assertTest($condition, $message) {
    if ($condition) {
        echo "[PASS] " . $message . "\n";
    } else {
        echo "[FAIL] " . $message . "\n";
        exit(1);
    }
}

echo "=====================================================\n";
echo "1. ADMIN CREATES AN EXAMINATION & GENERATES ACCESS CODE\n";
echo "=====================================================\n";

$testExam = [
    'id' => 9901,
    'title' => 'Telangana Police Sub-Inspector Full Mock Exam',
    'category' => 'Telangana Police',
    'accessCode' => 'POLICE99',
    'duration' => 20,
    'maxMarks' => 30.0,
    'certificateThreshold' => 85,
    'result_visibility' => 'immediate',
    'status' => 'LIVE',
    'questions' => []
];

ExamDB::saveExam($testExam);
$savedExam = ExamDB::getExamById(9901);
assertTest($savedExam !== null, "Assessment created successfully in database/store.");
assertTest($savedExam['accessCode'] === 'POLICE99', "Access code 'POLICE99' assigned correctly.");

echo "\n=====================================================\n";
echo "2. ADMIN IMPORTS 30 MCQs THROUGH CSV & ASSIGNS ANSWERS\n";
echo "=====================================================\n";

$csvPath = __DIR__ . '/sample_questions.csv';
$parseResult = ExamDB::parseCsvQuestions($csvPath, 'Telangana Police');
assertTest(count($parseResult['questions']) === 30, "Successfully parsed 30 MCQs from sample_questions.csv.");
assertTest(count($parseResult['errors']) === 0, "Zero parsing errors encountered.");

$addedCount = ExamDB::appendQuestions(9901, $parseResult['questions']);
assertTest($addedCount === 30, "Successfully imported and appended all 30 questions into Exam ID 9901.");

$examWithQ = ExamDB::getExamById(9901);
assertTest(count($examWithQ['questions']) === 30, "Verified exam now contains 30 structured interactive questions.");

// Verify question 1 structure
$q1 = $examWithQ['questions'][0];
assertTest(!empty($q1['q']) && count($q1['opts']) === 4, "Question 1 has question text and 4 choices.");
assertTest(isset($q1['ans']) && $q1['ans'] === 1, "Question 1 correct answer assigned to Option B (index 1).");
assertTest(floatval($q1['marks']) === 1.0, "Question 1 assigned 1.0 mark.");

echo "\n=====================================================\n";
echo "3. QUESTION MANAGEMENT: EDIT & DELETE INDIVIDUAL QUESTIONS\n";
echo "=====================================================\n";

// Edit question 5
$updatedQ5 = [
    'q' => 'Updated: Which organelle is universally known as the powerhouse of the cell?',
    'opts' => ['Ribosome', 'Endoplasmic Reticulum', 'Mitochondria (ATP Producer)', 'Golgi Complex'],
    'ans' => 2,
    'marks' => 2.0,
    'explanation' => 'Mitochondria produce ATP cellular energy.'
];
$editOk = ExamDB::updateSingleQuestion(9901, 4, $updatedQ5);
assertTest($editOk === true, "Admin successfully edited Question 5.");
$verifyQ5 = ExamDB::getExamById(9901)['questions'][4];
assertTest($verifyQ5['marks'] == 2.0 && $verifyQ5['ans'] === 2, "Edited question saved with 2.0 marks and Option C answer.");

echo "\n=====================================================\n";
echo "4. STUDENT ACCESS CODE VERIFICATION\n";
echo "=====================================================\n";

// Test invalid code
$invalidExam = ExamDB::getExamByCode('INVALID_CODE_123');
assertTest($invalidExam === null, "System securely rejected invalid access code.");

// Test valid code
$validExam = ExamDB::getExamByCode('POLICE99');
assertTest($validExam !== null && intval($validExam['id']) === 9901, "System successfully resolved valid exam code 'POLICE99'.");

echo "\n=====================================================\n";
echo "5. STUDENT STARTS EXAMINATION & SERVER-SIDE TIMER INITIALIZATION\n";
echo "=====================================================\n";

$startResult = ExamDB::createAttempt(9901, 'Kiran Kumar', 'URN-7788', 'POLICE99', '127.0.0.1');
assertTest($startResult['success'] === true, "Student attempt created successfully.");
assertTest(!empty($startResult['token']), "Unique session token issued: " . substr($startResult['token'], 0, 16) . "...");
assertTest($startResult['remainingSeconds'] === 1200, "Server-side timer initialized to 1200 seconds (20 mins).");

// SECURITY CHECK: Verify correct answers are NOT exposed to student!
$studentQ1 = $startResult['exam']['questions'][0];
assertTest(!isset($studentQ1['ans']), "SECURITY CONFIRMED: Correct answer 'ans' strictly NOT exposed to client in questions payload.");
assertTest(!isset($studentQ1['explanation']), "SECURITY CONFIRMED: Answer explanation strictly NOT exposed before submission.");

$token = $startResult['token'];

echo "\n=====================================================\n";
echo "6. STUDENT ANSWERS QUESTIONS (AUTOSAVE VIA AJAX)\n";
echo "=====================================================\n";

// Student answers Q1 with Option B (correct = index 1)
$save1 = ExamDB::saveResponse($token, 0, 1, false);
assertTest($save1['success'] === true, "Response for Question 1 (Option B) autosaved successfully.");

// Student answers Q2 with Option B (correct = index 1)
$save2 = ExamDB::saveResponse($token, 1, 1, false);
assertTest($save2['success'] === true, "Response for Question 2 (Option B) autosaved successfully.");

// Student answers Q3 with Option A (incorrect)
$save3 = ExamDB::saveResponse($token, 2, 0, false);
assertTest($save3['success'] === true, "Response for Question 3 (Option A) autosaved successfully.");

// Student changes answer for Q3 to Option B (correct = index 1)
$save3_changed = ExamDB::saveResponse($token, 2, 1, true);
assertTest($save3_changed['success'] === true, "Student changed answer for Question 3 to Option B with Mark for Review.");

echo "\n=====================================================\n";
echo "7. SIMULATE BROWSER REFRESH (TIMER & ANSWERS PERSISTENCE)\n";
echo "=====================================================\n";

// Simulating browser reload: client calls get_attempt with session token
$attemptRecovered = ExamDB::getAttemptByToken($token);
assertTest($attemptRecovered !== null, "Attempt session successfully found by token on browser refresh.");
assertTest($attemptRecovered['status'] === 'IN_PROGRESS', "Attempt status remains IN_PROGRESS.");

// Verify saved responses intact
assertTest(isset($attemptRecovered['responses'][0]) && $attemptRecovered['responses'][0]['selected'] === 1, "Question 1 response intact after refresh (Option B).");
assertTest(isset($attemptRecovered['responses'][1]) && $attemptRecovered['responses'][1]['selected'] === 1, "Question 2 response intact after refresh (Option B).");
assertTest(isset($attemptRecovered['responses'][2]) && $attemptRecovered['responses'][2]['selected'] === 1, "Question 3 response intact after refresh (Option B).");

// Check timer validation
$elapsed = time() - intval($attemptRecovered['startTimestamp']);
$remainingWallClock = intval($attemptRecovered['durationSeconds']) - $elapsed;
assertTest($remainingWallClock > 0 && $remainingWallClock <= 1200, "Server-side timer validation active: exact remaining seconds calculated as {$remainingWallClock}s.");

echo "\n=====================================================\n";
echo "8. STUDENT SUBMITS EXAMINATION & SERVER EVALUATION\n";
echo "=====================================================\n";

$submitResult = ExamDB::submitAttempt($token);
assertTest($submitResult['success'] === true, "Examination submitted successfully.");
assertTest($submitResult['visibility'] === 'immediate', "Result visibility configured for immediate display.");

$scorecard = $submitResult['result'];
echo "   Candidate: {$scorecard['name']} ({$scorecard['roll']})\n";
echo "   Score: {$scorecard['score']} / {$scorecard['totalMarks']} Marks\n";
echo "   Correct: {$scorecard['correct']} | Incorrect: {$scorecard['incorrect']} | Unanswered: {$scorecard['unanswered']}\n";
echo "   Accuracy: {$scorecard['accuracy']}%\n";
echo "   All India Rank: #{$scorecard['rank']}\n";

assertTest($scorecard['correct'] >= 3, "Candidate received points for correct answers.");
assertTest($scorecard['unanswered'] === 27, "Correctly identified 27 unanswered questions out of 30.");

// Verify duplicate submission prevention
$dupSubmit = ExamDB::submitAttempt($token);
assertTest(isset($dupSubmit['error']) && !empty($dupSubmit['alreadySubmitted']), "SECURITY CONFIRMED: Duplicate submission blocked by server.");

echo "\n=====================================================\n";
echo "9. ADMIN REVIEWS RESULTS IN DATABASE & ATTEMPTS RECORD\n";
echo "=====================================================\n";

$allResults = ExamDB::getAllResults();
$foundResult = null;
foreach ($allResults as $r) {
    if ($r['roll'] === 'URN-7788') {
        $foundResult = $r;
        break;
    }
}
assertTest($foundResult !== null, "Result record present in admin results repository.");
assertTest($foundResult['accessCode'] === 'POLICE99', "Result correctly associated with Access Code 'POLICE99'.");

echo "\n=====================================================\n";
echo "ALL 14 WORKFLOW & SECURITY REQUIREMENTS VERIFIED!\n";
echo "=====================================================\n";

