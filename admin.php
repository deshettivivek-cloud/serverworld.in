<?php
if(session_status() === PHP_SESSION_NONE){
    @session_start();
}
$PASS = 'admin123';
if(isset($_POST['pass'])){ if($_POST['pass']===$PASS){ $_SESSION['admin']=true; } }
if(isset($_GET['logout'])){ session_destroy(); header('Location: admin.php'); exit; }
$isAuth = !empty($_SESSION['admin']);

require_once __DIR__ . '/db.php';

$resultsFile = __DIR__.'/results.json';
$examsFile = __DIR__.'/exams.json';
$profilesFile = __DIR__.'/profiles.json';
$attemptsFile = __DIR__.'/attempts.json';
$coursesFile = __DIR__.'/courses.json';

$results = ExamDB::getAllResults();
$exams = ExamDB::getAllExams();
$attempts = ExamDB::getAllAttempts();
$profiles = file_exists($profilesFile) ? json_decode(file_get_contents($profilesFile), true) : [];
if(!is_array($profiles)) $profiles=[];
$courses = file_exists($coursesFile) ? json_decode(file_get_contents($coursesFile), true) : [];
if(!is_array($courses)) $courses=[];

$flash = null;
$previewQuestions = null;
$previewExamId = null;
$previewExamTitle = null;

$allowedExtensions = ['csv', 'txt', 'pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];

// EXPORT RESULTS TO EXCEL / CSV
if($isAuth && isset($_GET['action']) && $_GET['action'] === 'export_results_excel'){
    $filterExam = trim($_GET['exam_id'] ?? '');
    $filterRoll = trim($_GET['roll'] ?? $_GET['student'] ?? '');
    $filterAttempt = trim($_GET['attempt_id'] ?? $_GET['attempt'] ?? '');
    $filterIdx = isset($_GET['idx']) && $_GET['idx'] !== '' ? intval($_GET['idx']) : null;
    $allResults = ExamDB::getAllResults();
    $exportResults = $allResults;

    if($filterAttempt !== ''){
        $exportResults = array_filter($exportResults, function($r) use ($filterAttempt){
            return strval($r['attemptId'] ?? '') === $filterAttempt;
        });
    }

    if(!empty($filterExam) && strtolower($filterExam) !== 'all'){
        $filterExamClean = strtolower(trim($filterExam));
        $exportResults = array_filter($exportResults, function($r) use ($filterExam, $filterExamClean){
            $rExamId = trim(strval($r['examId'] ?? ''));
            $rCode = strtolower(trim(strval($r['accessCode'] ?? '')));
            $rTitle = strtolower(trim(strval($r['examTitle'] ?? '')));
            return $rExamId === $filterExam 
                || $rCode === $filterExamClean 
                || $rTitle === $filterExamClean
                || (strlen($filterExamClean) > 2 && (strpos($rTitle, $filterExamClean) !== false || strpos($filterExamClean, $rTitle) !== false));
        });
    }

    if(!empty($filterRoll) && $filterRoll !== '-'){
        $filterRollClean = strtolower(trim($filterRoll));
        $exportResults = array_filter($exportResults, function($r) use ($filterRollClean){
            $rRoll = strtolower(trim(strval($r['roll'] ?? '')));
            $rName = strtolower(trim(strval($r['name'] ?? '')));
            return $rRoll === $filterRollClean || $rName === $filterRollClean || (strlen($filterRollClean) > 2 && strpos($rName, $filterRollClean) !== false);
        });
    }

    $exportResults = array_values($exportResults);
    if(empty($exportResults) && $filterIdx !== null && isset($allResults[$filterIdx])){
        $exportResults = [$allResults[$filterIdx]];
    }

    usort($exportResults, function($a, $b){
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    $suffix = 'All';
    if(!empty($filterAttempt)){
        $suffix = 'Attempt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $filterAttempt);
    } elseif(!empty($filterRoll) && !empty($filterExam)){
        $suffix = preg_replace('/[^A-Za-z0-9_-]/', '', $filterRoll) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $filterExam);
    } elseif(!empty($filterRoll) && $filterRoll !== '-'){
        $suffix = 'Student_' . preg_replace('/[^A-Za-z0-9_-]/', '', $filterRoll);
    } elseif(!empty($filterExam) && strtolower($filterExam) !== 'all'){
        $suffix = 'Exam_' . preg_replace('/[^A-Za-z0-9_-]/', '', $filterExam);
    }

    $filename = 'ServerWorld_Results_' . $suffix . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Rank',
        'Candidate Name',
        'Candidate Roll / URN',
        'Exam Title',
        'Access Code',
        'Score Obtained',
        'Total Questions',
        'Max Marks',
        'Correct Answers',
        'Incorrect Answers',
        'Unanswered Questions',
        'Accuracy (%)',
        'Time Taken',
        'Integrity / Tab Switches',
        'Pass / Fail Status',
        'Submission Timestamp'
    ]);

    if(empty($exportResults)){
        fputcsv($output, ['-', 'No records found matching the requested filter', '-', '-', '-', '0', '0', '0', '0', '0', '0', '0%', '-', 'Clean (0)', '-', date('Y-m-d H:i:s')]);
    } else {
        $rank = 1;
        foreach($exportResults as $r){
            $score = floatval($r['score'] ?? 0);
            $total = floatval($r['totalMarks'] ?? $r['total'] ?? 10);
            $pct = $total > 0 ? round(($score / $total) * 100, 1) : 0;
            $status = $pct >= 60 ? 'PASS' : 'FAIL';
            $tabSw = intval($r['tabSwitchCount'] ?? 0);
            $fsExit = intval($r['fullscreenExitCount'] ?? 0);
            $integrity = ($tabSw + $fsExit === 0) ? 'Clean (0)' : ($tabSw . ' tab switches, ' . $fsExit . ' exits');

            fputcsv($output, [
                '#' . $rank++,
                $r['name'] ?? 'Candidate',
                $r['roll'] ?? '-',
                $r['examTitle'] ?? 'Online Assessment',
                $r['accessCode'] ?? '-',
                $score,
                $r['total'] ?? count($r['solutions'] ?? []),
                $total,
                $r['correct'] ?? 0,
                $r['incorrect'] ?? 0,
                $r['unanswered'] ?? 0,
                $pct . '%',
                $r['timeTaken'] ?? '-',
                $integrity,
                $status,
                $r['timestamp'] ?? $r['date'] ?? ''
            ]);
        }

        // If an individual submission result is exported, also append question-by-question response breakdown
        if(count($exportResults) === 1 && !empty($exportResults[0]['solutions']) && is_array($exportResults[0]['solutions'])){
            $single = $exportResults[0];
            fputcsv($output, []); // Empty row separator
            fputcsv($output, ['=== INDIVIDUAL CANDIDATE QUESTION-BY-QUESTION ATTEMPT BREAKDOWN ===']);
            fputcsv($output, ['Q#', 'Question Text', 'Candidate Selected Option', 'Correct Answer', 'Result Status', 'Topic / Subject', 'Explanation']);

            foreach($single['solutions'] as $qIdx => $sol){
                $qNum = $sol['num'] ?? ($qIdx + 1);
                $qText = $sol['q'] ?? '';
                $opts = $sol['opts'] ?? [];
                $userAnsIdx = $sol['userAns'] ?? null;
                $userAnsText = 'Not Attempted';
                if($userAnsIdx !== null && isset($opts[$userAnsIdx])){
                    $userAnsText = chr(65 + intval($userAnsIdx)) . '. ' . $opts[$userAnsIdx];
                } elseif($userAnsIdx !== null && $userAnsIdx !== ''){
                    $userAnsText = 'Option ' . $userAnsIdx;
                }

                $corrAns = $sol['correctAns'] ?? '';
                $corrAnsText = $corrAns;
                if(strlen($corrAns) === 1 && ord(strtoupper($corrAns)) >= 65 && ord(strtoupper($corrAns)) <= 90){
                    $cIdx = ord(strtoupper($corrAns)) - 65;
                    if(isset($opts[$cIdx])){
                        $corrAnsText = $corrAns . '. ' . $opts[$cIdx];
                    }
                }

                $status = !empty($sol['isCorrect']) ? 'CORRECT' : ($userAnsIdx === null ? 'UNANSWERED' : 'INCORRECT');

                fputcsv($output, [
                    'Q' . $qNum,
                    $qText,
                    $userAnsText,
                    $corrAnsText,
                    $status,
                    $sol['topic'] ?? '',
                    $sol['explanation'] ?? ''
                ]);
            }
        }
    }

    fclose($output);
    exit;
}

// EXPORT CANDIDATE PASSWORDS & CREDENTIALS TO CSV (COURSE FOLDER FILTERED)
if($isAuth && isset($_GET['action']) && $_GET['action'] === 'export_candidates_csv'){
    $filterCourse = trim($_GET['course'] ?? 'all');
    $exportProfiles = $profiles;

    if(!empty($filterCourse) && $filterCourse !== 'all'){
        $exportProfiles = array_values(array_filter($exportProfiles, function($p) use ($filterCourse){
            $assignedTitle = trim($p['assigned_exam_title'] ?? 'General');
            return strcasecmp($assignedTitle, $filterCourse) === 0 || strcasecmp(trim($p['assigned_exam_id'] ?? ''), 'cat_' . $filterCourse) === 0;
        }));
    }

    $suffix = ($filterCourse !== 'all' && !empty($filterCourse)) ? preg_replace('/[^A-Za-z0-9_-]/', '', $filterCourse) : 'All_Courses';
    $filename = 'ServerWorld_Students_' . $suffix . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Candidate Name', 'Roll Number / Username', 'Access Password', 'Assigned Course / Folder', 'Registered Date']);

    foreach($exportProfiles as $p){
        fputcsv($output, [
            $p['name'] ?? 'Candidate',
            $p['roll'] ?? $p['username'] ?? '-',
            $p['password'] ?? '123456',
            $p['assigned_exam_title'] ?? 'All Assessments',
            $p['created'] ?? '-'
        ]);
    }

    fclose($output);
    exit;
}

if($isAuth && $_SERVER['REQUEST_METHOD']==='POST'){
  $action = $_POST['action'] ?? '';

  // 1. CREATE EXAM
  if($action === 'create_exam'){
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $customCategory = trim($_POST['custom_category'] ?? '');
    if(!empty($customCategory) || $category === '__NEW__'){
      if(!empty($customCategory)){
        $category = $customCategory;
        // Auto-register in courses.json
        $cExists = false;
        foreach($courses as $cc){
          if(strcasecmp(trim($cc['name']), $category) === 0){ $cExists = true; break; }
        }
        if(!$cExists){
          $newCourseObj = [
            'id' => 'course_'.preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($category)).'_'.substr(md5($category), 0, 4),
            'name' => $category,
            'stream' => trim($_POST['custom_stream'] ?? 'Competitive Assessment'),
            'color' => 'blue',
            'icon' => 'academic'
          ];
          $courses[] = $newCourseObj;
          file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
        }
      }
    }
    if(empty($category) || $category === 'General' || $category === '__NEW__'){
      $category = !empty($courses[0]['name']) ? $courses[0]['name'] : 'TSPSC Groups';
    }
    $duration = max(5, intval($_POST['duration'] ?? 15));
    $maxMarks = max(1.0, floatval($_POST['maxMarks'] ?? 100.0));
    $certThreshold = max(1, min(100, intval($_POST['certificateThreshold'] ?? 85)));
    $resultVisibility = in_array($_POST['result_visibility'] ?? '', ['immediate', 'hidden']) ? $_POST['result_visibility'] : 'immediate';
    $accessCode = ExamDB::generateAccessCode($_POST['accessCode'] ?? '', $category);
    $qText = trim($_POST['questions'] ?? '');
    
    if(empty($title)){
      $flash = ['type'=>'error', 'msg'=>'Assessment Title is required.'];
    } else {
      $uploadedFile = null;
      $fileExtension = null;
      $hasFileError = false;

      if(isset($_FILES['examFile']) && $_FILES['examFile']['error'] === UPLOAD_ERR_OK){
        $origName = $_FILES['examFile']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if(!in_array($ext, $allowedExtensions)){
          $flash = ['type'=>'error', 'msg'=>"Unsupported file format (.$ext). Supported: CSV, TXT, PDF, DOCX, JPG, PNG."];
          $hasFileError = true;
        } else {
          $uploadDir = __DIR__.'/uploads/';
          if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
          $safeBase = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($origName));
          $fname = time().'_'.$safeBase;
          if(move_uploaded_file($_FILES['examFile']['tmp_name'], $uploadDir.$fname)){
            $uploadedFile = $fname;
            $fileExtension = $ext;
          } else {
            $flash = ['type'=>'error', 'msg'=>'Failed to save uploaded file to server.'];
            $hasFileError = true;
          }
        }
      }

      if(!$hasFileError){
        $questions = [];
        if($uploadedFile){
          $parseRes = ExamDB::parseQuestionsFromFile(__DIR__.'/uploads/'.$uploadedFile, $category);
          $questions = $parseRes['questions'] ?? [];
        } elseif(!empty($qText)){
          // parse textarea lines (pipe format or standard lines)
          $lines = preg_split("/\r\n|\n|\r/", $qText);
          foreach($lines as $l){
            $l = trim($l);
            if(empty($l)) continue;
            $parts = explode('|', $l);
            if(count($parts) >= 6){
              $questions[] = [
                'q' => trim($parts[0]),
                'opts' => [trim($parts[1]), trim($parts[2]), trim($parts[3]), trim($parts[4])],
                'ans' => ExamDB::normalizeAnsIndex($parts[5]),
                'marks' => floatval($parts[6] ?? 1.0),
                'explanation' => trim($parts[7] ?? ''),
                'topic' => $category
              ];
            }
          }
        }

        $newExamId = time();
        $testType = ($_POST['test_type'] ?? 'live') === 'mock' ? 'mock' : 'live';
        $examRecord = [
          'id' => $newExamId,
          'title' => $title,
          'category' => $category,
          'testType' => $testType,
          'accessCode' => $accessCode,
          'duration' => $duration,
          'maxMarks' => $maxMarks,
          'certificateThreshold' => $certThreshold,
          'single_attempt' => ($testType === 'live'),
          'result_visibility' => $resultVisibility,
          'questions' => $questions,
          'qCount' => count($questions),
          'status' => 'LIVE',
          'created' => date('Y-m-d H:i:s')
        ];

        if($uploadedFile){
          $examRecord['file'] = $uploadedFile;
          $examRecord['paperType'] = (count($questions) > 0) ? 'interactive' : 'document';
        }

        ExamDB::saveExam($examRecord);
        $exams = ExamDB::getAllExams();

        if(count($questions) > 0){
          $flash = ['type'=>'success', 'msg'=>"Assessment '{$title}' created successfully with ".count($questions)." interactive questions from {$fileExtension} upload. Access Code: {$accessCode}."];
        } else {
          $flash = ['type'=>'info', 'msg'=>"Assessment '{$title}' created. Access Code: {$accessCode}. You can now import questions via PDF, CSV, or direct entry."];
        }
      }
    }
  }

  // 2. UPLOAD QUESTION PAPER FILE (PDF, DOCX OR CSV)
  if($action === 'upload_question_paper'){
    $title = trim($_POST['paperTitle'] ?? '');
    $category = trim($_POST['paperCategory'] ?? '');
    if(empty($category) || $category === 'General'){
      $category = !empty($courses[0]['name']) ? $courses[0]['name'] : 'TSPSC Groups';
    }
    $duration = max(5, intval($_POST['paperDuration'] ?? 15));
    $maxMarks = max(1.0, floatval($_POST['paperMaxMarks'] ?? 100.0));
    $paperAccessCode = ExamDB::generateAccessCode($_POST['paperAccessCode'] ?? '', $category);

    if(!isset($_FILES['questionFile']) || $_FILES['questionFile']['error'] !== UPLOAD_ERR_OK){
      $flash = ['type'=>'error', 'msg'=>'Please select a question file to upload.'];
    } else {
      $origName = $_FILES['questionFile']['name'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if(!in_array($ext, $allowedExtensions)){
        $flash = ['type'=>'error', 'msg'=>"Unsupported file format (.$ext). Supported: CSV, TXT, PDF, DOCX, JPG, PNG."];
      } else {
        $uploadDir = __DIR__.'/uploads/';
        if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $safeBase = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($origName));
        $fname = time().'_'.$safeBase;

        if(move_uploaded_file($_FILES['questionFile']['tmp_name'], $uploadDir.$fname)){
          if(empty($title)) $title = pathinfo($origName, PATHINFO_FILENAME);
          
          // Parse questions from uploaded file (CSV, TXT, or PDF)
          $parseResult = ExamDB::parseQuestionsFromFile($uploadDir.$fname, $category);
          $questions = $parseResult['questions'] ?? [];
          $isInteractive = count($questions) > 0;

          $testType = ($_POST['paperTestType'] ?? 'live') === 'mock' ? 'mock' : 'live';
          $newExam = [
            'id' => time(),
            'title' => $title,
            'category' => $category,
            'testType' => $testType,
            'accessCode' => $paperAccessCode,
            'duration' => $duration,
            'maxMarks' => $maxMarks,
            'certificateThreshold' => max(1, min(100, intval($_POST['paperCertThreshold'] ?? 85))),
            'single_attempt' => ($testType === 'live'),
            'file' => $fname,
            'paperType' => $isInteractive ? 'interactive' : 'document',
            'questions' => $questions,
            'qCount' => count($questions),
            'status' => 'LIVE',
            'created' => date('Y-m-d H:i:s')
          ];

          ExamDB::saveExam($newExam);
          $exams = ExamDB::getAllExams();

          if($isInteractive){
            $flash = ['type'=>'success', 'msg'=>"Parsed and saved ".count($questions)." interactive questions into '{$title}'. Access Code: {$paperAccessCode}. Students can now start examination!"];
          } else {
            $flash = ['type'=>'info', 'msg'=>"Reference paper '{$origName}' saved. Access Code: {$paperAccessCode}. You can now import interactive questions anytime."];
          }
        } else {
          $flash = ['type'=>'error', 'msg'=>'Failed to save the uploaded file.'];
        }
      }
    }
  }

  // 3. PREVIEW QUESTIONS (PDF, CSV, OR TXT) BEFORE IMPORT
  if($action === 'preview_csv'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $exam = ExamDB::getExamById($examId);
    if(!$exam){
      $flash = ['type'=>'error', 'msg'=>'Assessment not found.'];
    } elseif(!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK){
      $flash = ['type'=>'error', 'msg'=>'Please select a PDF, CSV, or TXT file to preview.'];
    } else {
      $ext = strtolower(pathinfo($_FILES['csvFile']['name'], PATHINFO_EXTENSION));
      if(!in_array($ext, ['csv', 'txt', 'pdf'])){
        $flash = ['type'=>'error', 'msg'=>'Only PDF, CSV, or TXT format is supported for question import.'];
      } else {
        $tempPath = $_FILES['csvFile']['tmp_name'];
        $parseResult = ExamDB::parseQuestionsFromFile($tempPath, $exam['category'] ?? '');
        if(count($parseResult['questions']) === 0){
          $flash = ['type'=>'error', 'msg'=>'Could not extract questions from file. Please check formatting or ensure the PDF contains searchable text.'];
        } else {
          // Stash in session for confirmation
          $_SESSION['csv_preview_questions'] = $parseResult['questions'];
          $_SESSION['csv_preview_exam_id'] = $examId;
          $previewQuestions = $parseResult['questions'];
          $previewExamId = $examId;
          $previewExamTitle = $exam['title'];
          $flash = ['type'=>'info', 'msg'=>"Previewing ".count($previewQuestions)." questions from '{$_FILES['csvFile']['name']}'. Review below and click 'Confirm & Import'."];
        }
      }
    }
  }

  // 4. CONFIRM QUESTION IMPORT
  if($action === 'confirm_csv_import'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $questionsToImport = $_SESSION['csv_preview_questions'] ?? null;

    if($examId > 0 && is_array($questionsToImport) && count($questionsToImport) > 0){
      $added = ExamDB::appendQuestions($examId, $questionsToImport);
      // Automatically upgrade exam paperType to interactive if it was document
      $currExam = ExamDB::getExamById($examId);
      if($currExam && ($currExam['paperType'] ?? '') === 'document'){
        $currExam['paperType'] = 'interactive';
        ExamDB::saveExam($currExam);
      }
      unset($_SESSION['csv_preview_questions']);
      unset($_SESSION['csv_preview_exam_id']);
      $exams = ExamDB::getAllExams();
      $flash = ['type'=>'success', 'msg'=>"Successfully imported {$added} questions into assessment! Students can now start examination."];
    } else {
      $flash = ['type'=>'error', 'msg'=>'No previewed questions available to import. Please select a question file.'];
    }
  }

  // 5. ADD SINGLE QUESTION
  if($action === 'add_single_question'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $qText = trim($_POST['qText'] ?? '');
    $qType = trim($_POST['qType'] ?? 'mcq');
    $passage = trim($_POST['passage'] ?? '');
    $optA = trim($_POST['optA'] ?? '');
    $optB = trim($_POST['optB'] ?? '');
    $optC = trim($_POST['optC'] ?? '');
    $optD = trim($_POST['optD'] ?? '');
    $ans = ExamDB::normalizeAnsIndex($_POST['correctAns'] ?? '0');
    $marks = max(0.25, floatval($_POST['marks'] ?? 1.0));
    $exp = trim($_POST['explanation'] ?? '');
    $imgUrl = trim($_POST['q_image_url'] ?? '');

    // Check if an image file was uploaded
    if(isset($_FILES['q_image_file']) && $_FILES['q_image_file']['error'] === UPLOAD_ERR_OK){
      $ext = strtolower(pathinfo($_FILES['q_image_file']['name'], PATHINFO_EXTENSION));
      if(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])){
        $uDir = __DIR__.'/uploads/';
        if(!is_dir($uDir)) mkdir($uDir, 0777, true);
        $sName = time().'_qimg_'.preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['q_image_file']['name']));
        if(move_uploaded_file($_FILES['q_image_file']['tmp_name'], $uDir.$sName)){
          $imgUrl = 'uploads/'.$sName;
        }
      }
    }

    if(empty($qText)){
      $flash = ['type'=>'error', 'msg'=>'Question text is required.'];
    } else {
      $exam = ExamDB::getExamById($examId);
      $opts = array_values(array_filter([$optA, $optB, $optC, $optD], function($v){ return $v !== ''; }));
      if(empty($opts) && $qType === 'blank'){
        $opts = [$ans];
      }
      $qObj = [
        'q' => $qText,
        'opts' => !empty($opts) ? $opts : [$optA, $optB, $optC, $optD],
        'ans' => $ans,
        'marks' => $marks,
        'explanation' => $exp,
        'topic' => $qType === 'blank' ? 'Fill in the Blanks' : ($exam['category'] ?? 'General'),
        'type' => $qType
      ];
      if(!empty($passage)){
        $qObj['passage'] = $passage;
      }
      if(!empty($imgUrl)){
        $qObj['image'] = $imgUrl;
      }
      ExamDB::appendQuestions($examId, [$qObj]);
      $exams = ExamDB::getAllExams();
      $flash = ['type'=>'success', 'msg'=>'Question added successfully!'];
    }
  }

  // 6. EDIT SINGLE QUESTION
  if($action === 'edit_single_question'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $qIndex = intval($_POST['q_index'] ?? -1);
    $qText = trim($_POST['qText'] ?? '');
    $qType = trim($_POST['qType'] ?? 'mcq');
    $passage = trim($_POST['passage'] ?? '');
    $optA = trim($_POST['optA'] ?? '');
    $optB = trim($_POST['optB'] ?? '');
    $optC = trim($_POST['optC'] ?? '');
    $optD = trim($_POST['optD'] ?? '');
    $ans = ExamDB::normalizeAnsIndex($_POST['correctAns'] ?? '0');
    $marks = max(0.25, floatval($_POST['marks'] ?? 1.0));
    $exp = trim($_POST['explanation'] ?? '');
    $imgUrl = trim($_POST['q_image_url'] ?? '');

    // Check if new image file uploaded
    if(isset($_FILES['q_image_file']) && $_FILES['q_image_file']['error'] === UPLOAD_ERR_OK){
      $ext = strtolower(pathinfo($_FILES['q_image_file']['name'], PATHINFO_EXTENSION));
      if(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])){
        $uDir = __DIR__.'/uploads/';
        if(!is_dir($uDir)) mkdir($uDir, 0777, true);
        $sName = time().'_qimg_'.preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['q_image_file']['name']));
        if(move_uploaded_file($_FILES['q_image_file']['tmp_name'], $uDir.$sName)){
          $imgUrl = 'uploads/'.$sName;
        }
      }
    }

    if($examId > 0 && $qIndex >= 0 && !empty($qText)){
      $qData = [
        'q' => $qText,
        'opts' => [$optA, $optB, $optC, $optD],
        'ans' => $ans,
        'marks' => $marks,
        'explanation' => $exp,
        'type' => $qType,
        'passage' => $passage
      ];
      if($qType === 'blank'){
        $qData['topic'] = 'Fill in the Blanks';
      }
      if(!empty($imgUrl)){
        $qData['image'] = $imgUrl;
      }
      ExamDB::updateSingleQuestion($examId, $qIndex, $qData);
      $exams = ExamDB::getAllExams();
      $flash = ['type'=>'success', 'msg'=>'Question updated successfully.'];
    } else {
      $flash = ['type'=>'error', 'msg'=>'Invalid question data.'];
    }
  }

  // 7. DELETE SINGLE QUESTION
  if($action === 'delete_single_question'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $qIndex = intval($_POST['q_index'] ?? -1);
    if($examId > 0 && $qIndex >= 0){
      ExamDB::deleteSingleQuestion($examId, $qIndex);
      $exams = ExamDB::getAllExams();
      $flash = ['type'=>'info', 'msg'=>'Question deleted.'];
    }
  }

  // 8. UPDATE ACCESS CODE
  if($action === 'update_access_code'){
    $examId = intval($_POST['id'] ?? 0);
    $rawCode = trim($_POST['accessCode'] ?? '');
    ExamDB::updateAccessCode($examId, $rawCode);
    $exams = ExamDB::getAllExams();
    $flash = ['type'=>'success', 'msg'=>'Assessment Access Code updated successfully. Students can now access with this code.'];
  }

  // 9. DELETE EXAM
  if($action === 'delete_exam'){
    $deleteId = intval($_POST['id'] ?? 0);
    ExamDB::deleteExam($deleteId);
    $exams = ExamDB::getAllExams();
    $flash = ['type'=>'info', 'msg'=>'Assessment deleted successfully.'];
  }

  // 10. DELETE RESULT
  if($action === 'delete_result'){
    $idx = intval($_POST['idx'] ?? -1);
    if(isset($results[$idx])){
      unset($results[$idx]);
      $results = array_values($results);
      file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flash = ['type'=>'info', 'msg'=>'Result record removed.'];
    }
  }

  // 11. EXTRACT QUESTIONS FROM ATTACHED FILE
  if($action === 'extract_paper_questions'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $exam = ExamDB::getExamById($examId);
    if($exam && !empty($exam['file']) && file_exists(__DIR__.'/uploads/'.$exam['file'])){
      $filePath = __DIR__.'/uploads/'.$exam['file'];
      $res = ExamDB::parseQuestionsFromFile($filePath, $exam['category'] ?? '');
      if(!empty($res['questions'])){
        ExamDB::appendQuestions($examId, $res['questions']);
        $exams = ExamDB::getAllExams();
        $flash = ['type'=>'success', 'msg'=>'Successfully extracted and imported ' . count($res['questions']) . ' interactive questions from ' . htmlspecialchars($exam['file']) . '!'];
      } else {
        $flash = ['type'=>'error', 'msg'=>'No questions could be extracted from ' . htmlspecialchars($exam['file']) . '.'];
      }
    } else {
      $flash = ['type'=>'error', 'msg'=>'Assessment file not found on server.'];
    }
  }

  // 12. TOGGLE TEST TYPE (LIVE TEST VS MOCK TEST)
  if($action === 'toggle_test_type'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $exam = ExamDB::getExamById($examId);
    if($exam){
      $newType = ($exam['testType'] ?? 'live') === 'live' ? 'mock' : 'live';
      ExamDB::updateTestType($examId, $newType);
      $exams = ExamDB::getAllExams();
      $label = $newType === 'live' ? 'Live Test (Code Required)' : 'Mock Test (Open Access)';
      $flash = ['type'=>'success', 'msg'=>"Updated '{$exam['title']}' classification to: {$label}."];
    }
  }

  // 13. TOGGLE RESULT VISIBILITY (PUBLISH IMMEDIATELY VS PUBLISH LATER)
  if($action === 'toggle_result_visibility'){
    $examId = intval($_POST['exam_id'] ?? 0);
    $exam = ExamDB::getExamById($examId);
    if($exam){
      $currVis = $exam['result_visibility'] ?? 'immediate';
      $newVis = ($currVis === 'hidden') ? 'immediate' : 'hidden';
      $exam['result_visibility'] = $newVis;
      ExamDB::saveExam($exam);
      $exams = ExamDB::getAllExams();
      $label = ($newVis === 'immediate') ? '📢 Published (Students can now view scorecards and solutions)' : '🔒 Hidden (Publish Later / Admin Review)';
      $flash = ['type'=>'success', 'msg'=>"Result visibility for '{$exam['title']}': {$label}."];
    }
  }

  // 14. CREATE SINGLE CANDIDATE
  if($action === 'create_candidate'){
    $cName = trim($_POST['cand_name'] ?? '');
    $cUser = trim($_POST['cand_username'] ?? '');
    $cPass = trim($_POST['cand_password'] ?? '');
    $cExamId = trim($_POST['assigned_exam_id'] ?? '');
    $cEmail = trim($_POST['cand_email'] ?? '');
    $cPhone = trim($_POST['cand_phone'] ?? '');

    $cSelectedCourses = $_POST['cand_courses'] ?? [];
    if(!is_array($cSelectedCourses)){
      $cSelectedCourses = array_filter(array_map('trim', explode(',', (string)$cSelectedCourses)));
    }
    $cSelectedCourses = array_values(array_filter($cSelectedCourses));

    if(!empty($cSelectedCourses)){
      if(in_array('All Assessments', $cSelectedCourses) || in_array('all', $cSelectedCourses)){
        $cExamTitle = 'All Assessments';
        $cExamId = 'all';
        $cAssignedCourses = ['All Assessments'];
      } else {
        $cExamTitle = implode(', ', $cSelectedCourses);
        $cExamId = count($cSelectedCourses) === 1 ? ('cat_' . $cSelectedCourses[0]) : 'multi';
        $cAssignedCourses = $cSelectedCourses;
      }
    } else {
      $cExamTitle = 'All Assessments';
      if(!empty($cExamId) && $cExamId !== 'all'){
        if(strpos($cExamId, 'cat_') === 0){
          $cExamTitle = substr($cExamId, 4);
        } else {
          foreach($exams as $ex){
            if(($ex['id'] ?? '') === $cExamId || (string)($ex['id'] ?? '') === (string)$cExamId){
              $cExamTitle = $ex['title'] ?? 'Selected Exam';
              break;
            }
          }
        }
        $cAssignedCourses = [$cExamTitle];
      } else {
        $cAssignedCourses = ['All Assessments'];
      }
    }

    if(empty($cName) || empty($cUser) || empty($cPass)){
      $flash = ['type'=>'error', 'msg'=>'Candidate Name, Username/Roll No, and Password are required.'];
    } else {
      $dup = false;
      foreach($profiles as $p){
        if(strcasecmp($p['username'] ?? '', $cUser) === 0 || strcasecmp($p['roll'] ?? '', $cUser) === 0){
          $dup = true; break;
        }
      }
      if($dup){
        $flash = ['type'=>'error', 'msg'=>"Username or Roll No '{$cUser}' is already registered."];
      } else {
        $newCand = [
          'id' => 'cand_' . uniqid(),
          'name' => $cName,
          'roll' => $cUser,
          'username' => $cUser,
          'password' => $cPass,
          'assigned_courses' => $cAssignedCourses,
          'assigned_exam_id' => $cExamId ?: 'all',
          'assigned_exam_title' => $cExamTitle,
          'email' => $cEmail,
          'phone' => $cPhone,
          'created' => date('Y-m-d H:i:s')
        ];
        array_unshift($profiles, $newCand);
        file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $flash = ['type'=>'success', 'msg'=>"Candidate '{$cName}' (Username: {$cUser}) created successfully and assigned to '{$cExamTitle}'."];
      }
    }
  }

  // 14b. UPDATE CANDIDATE ENROLLED COURSES (MULTI-COURSE SELECTION)
  if($action === 'update_candidate_courses'){
    $cUser = trim($_POST['cand_username'] ?? '');
    $selectedCourses = $_POST['cand_courses'] ?? [];
    if(!is_array($selectedCourses)){
      $selectedCourses = array_filter(array_map('trim', explode(',', (string)$selectedCourses)));
    }
    $selectedCourses = array_values(array_filter($selectedCourses));

    $found = false;
    foreach($profiles as &$p){
      if(strcasecmp($p['username'] ?? '', $cUser) === 0 || strcasecmp($p['roll'] ?? '', $cUser) === 0){
        if(empty($selectedCourses) || in_array('All Assessments', $selectedCourses) || in_array('all', $selectedCourses)){
          $p['assigned_courses'] = ['All Assessments'];
          $p['assigned_exam_title'] = 'All Assessments';
          $p['assigned_exam_id'] = 'all';
        } else {
          $p['assigned_courses'] = $selectedCourses;
          $p['assigned_exam_title'] = implode(', ', $selectedCourses);
          $p['assigned_exam_id'] = count($selectedCourses) === 1 ? ('cat_' . $selectedCourses[0]) : 'multi';
        }
        $found = true;
        break;
      }
    }
    unset($p);
    if($found){
      file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flash = ['type'=>'success', 'msg'=>"Successfully updated course enrollment for candidate '{$cUser}'."];
    } else {
      $flash = ['type'=>'error', 'msg'=>"Candidate '{$cUser}' not found."];
    }
  }

  // 15. BULK GENERATE CANDIDATE ACCOUNTS
  if($action === 'bulk_generate_candidates'){
    $prefix = strtoupper(trim($_POST['bulk_prefix'] ?? 'CAND'));
    if(empty($prefix)) $prefix = 'CAND';
    $count = max(1, min(100, intval($_POST['bulk_count'] ?? 10)));
    $cExamId = trim($_POST['bulk_exam_id'] ?? 'all');
    $cExamTitle = 'All Assessments';
    if(!empty($cExamId) && $cExamId !== 'all'){
      if(strpos($cExamId, 'cat_') === 0){
        $cExamTitle = substr($cExamId, 4);
      } else {
        foreach($exams as $ex){
          if(($ex['id'] ?? '') === $cExamId || (string)($ex['id'] ?? '') === (string)$cExamId){
            $cExamTitle = $ex['title'] ?? 'Selected Exam';
            break;
          }
        }
      }
    }

    $createdList = [];
    $startNum = 1;
    while(count($createdList) < $count){
      $candidateUser = sprintf("%s-%03d", $prefix, $startNum++);
      $exists = false;
      foreach($profiles as $p){
        if(strcasecmp($p['username'] ?? '', $candidateUser) === 0 || strcasecmp($p['roll'] ?? '', $candidateUser) === 0){
          $exists = true; break;
        }
      }
      if(!$exists){
        $randomPass = substr(str_shuffle('23456789ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 6);
        $newCand = [
          'id' => 'cand_' . uniqid(),
          'name' => 'Candidate ' . $candidateUser,
          'roll' => $candidateUser,
          'username' => $candidateUser,
          'password' => $randomPass,
          'assigned_exam_id' => $cExamId,
          'assigned_exam_title' => $cExamTitle,
          'email' => '',
          'phone' => '',
          'created' => date('Y-m-d H:i:s')
        ];
        $createdList[] = $newCand;
        array_unshift($profiles, $newCand);
      }
    }

    file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $flash = ['type'=>'success', 'msg'=>"Successfully generated {$count} candidate accounts assigned to '{$cExamTitle}'. Passwords ready for distribution."];
  }

  // 15b. BULK IMPORT STUDENTS (CSV / TEXT) WITH COURSE & RANDOM PASSWORDS
  if($action === 'bulk_import_students'){
    $rawText = trim($_POST['import_student_text'] ?? '');
    
    // Check if CSV/TXT file was uploaded
    if(isset($_FILES['import_student_file']) && $_FILES['import_student_file']['error'] === UPLOAD_ERR_OK){
      $fileContent = file_get_contents($_FILES['import_student_file']['tmp_name']);
      if(!empty($fileContent)){
        $rawText = $fileContent . "\n" . $rawText;
      }
    }

    $defaultCourse = trim($_POST['import_default_course'] ?? (!empty($courses[0]['name']) ? $courses[0]['name'] : 'TSPSC Groups'));

    if(empty($rawText)){
      $flash = ['type'=>'error', 'msg'=>'Please provide a CSV file or paste student names and courses.'];
    } else {
      $lines = preg_split('/\r\n|\r|\n/', $rawText);
      $importedCount = 0;
      $updatedCount = 0;
      $importedList = [];

      foreach($lines as $line){
        $line = trim($line);
        if(empty($line)) continue;

        // Support comma, tab, or semicolon separated
        $delims = [',', "\t", ';'];
        $bestDelim = ',';
        $bestCount = 0;
        foreach($delims as $d){
          $c = substr_count($line, $d);
          if($c > $bestCount){ $bestCount = $c; $bestDelim = $d; }
        }

        $parts = array_map('trim', str_getcsv($line, $bestDelim));
        if(empty($parts) || count($parts) < 2) continue;

        // Skip potential header row
        $firstLower = strtolower($parts[0]);
        $secondLower = strtolower($parts[1]);
        if(($firstLower === 'name' || $firstLower === 'student name' || $firstLower === 'candidate name') &&
           ($secondLower === 'roll' || $secondLower === 'roll number' || $secondLower === 'username' || $secondLower === 'id')){
          continue;
        }

        // Determine columns: Name, Roll, Course, (Password)
        $name = '';
        $roll = '';
        $course = '';
        $pass = '';

        if(preg_match('/^[A-Za-z0-9_-]{3,15}$/', $parts[0]) && !preg_match('/^[A-Za-z0-9_-]{3,15}$/', $parts[1])){
          // parts[0] is roll, parts[1] is name
          $roll = $parts[0];
          $name = $parts[1];
          $course = $parts[2] ?? $defaultCourse;
          $pass = $parts[3] ?? '';
        } else {
          // parts[0] is name, parts[1] is roll
          $name = $parts[0];
          $roll = $parts[1];
          $course = $parts[2] ?? $defaultCourse;
          $pass = $parts[3] ?? '';
        }

        if(empty($name) || empty($roll)) continue;
        if(empty($course)) $course = $defaultCourse;

        // Auto-generate random secure password if not provided
        if(empty($pass)){
          $pass = substr(str_shuffle('23456789ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 6);
        }

        // Support multiple courses per student (e.g. "NEET | TSPSC Groups" or "Banking, Police")
        $courseTokens = array_values(array_filter(array_map('trim', preg_split('/[\/|&;+]/', (string)$course))));
        if(empty($courseTokens)) $courseTokens = [$defaultCourse];

        foreach($courseTokens as &$cTok){
          $courseFound = false;
          foreach($courses as $cItem){
            if(strcasecmp($cItem['name'] ?? '', $cTok) === 0){
              $cTok = $cItem['name'];
              $courseFound = true;
              break;
            }
          }
          if(!$courseFound && strcasecmp($cTok, 'All Assessments') !== 0 && strcasecmp($cTok, 'All') !== 0){
            $newCourse = [
              'id' => 'course_' . preg_replace('/[^a-z0-9]/', '_', strtolower($cTok)) . '_' . substr(uniqid(), -4),
              'name' => $cTok,
              'stream' => 'Academic Stream',
              'color' => 'blue',
              'icon' => 'academic'
            ];
            $courses[] = $newCourse;
            file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
          }
        }
        unset($cTok);

        $courseTitle = implode(', ', $courseTokens);
        $assignedId = (in_array('All Assessments', $courseTokens) || in_array('All', $courseTokens)) ? 'all' : (count($courseTokens) === 1 ? ('cat_' . $courseTokens[0]) : 'multi');

        // Check if student exists in profiles
        $foundIdx = -1;
        foreach($profiles as $pIdx => $p){
          if(strcasecmp($p['roll'] ?? '', $roll) === 0 || strcasecmp($p['username'] ?? '', $roll) === 0){
            $foundIdx = $pIdx;
            break;
          }
        }

        if($foundIdx >= 0){
          $profiles[$foundIdx]['name'] = $name;
          $profiles[$foundIdx]['assigned_courses'] = $courseTokens;
          $profiles[$foundIdx]['assigned_exam_id'] = $assignedId;
          $profiles[$foundIdx]['assigned_exam_title'] = $courseTitle;
          if(!empty($pass) && $pass !== 'keep'){
            $profiles[$foundIdx]['password'] = $pass;
          }
          $importedList[] = $profiles[$foundIdx];
          $updatedCount++;
        } else {
          $newCand = [
            'id' => 'cand_' . uniqid(),
            'name' => $name,
            'roll' => $roll,
            'username' => $roll,
            'password' => $pass,
            'assigned_courses' => $courseTokens,
            'assigned_exam_id' => $assignedId,
            'assigned_exam_title' => $courseTitle,
            'email' => '',
            'phone' => '',
            'created' => date('Y-m-d H:i:s')
          ];
          array_unshift($profiles, $newCand);
          $importedList[] = $newCand;
          $importedCount++;
        }
      }

      if($importedCount > 0 || $updatedCount > 0){
        file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $flash = ['type'=>'success', 'msg'=>"Successfully imported {$importedCount} new student(s) and updated {$updatedCount} existing student(s) organized in course folders with randomly assigned passwords."];
        $_SESSION['last_imported_students'] = $importedList;
      } else {
        $flash = ['type'=>'error', 'msg'=>'No valid student rows found. Please check format: Name, Roll Number, Course.'];
      }
    }
  }

  // 16. DELETE CANDIDATE
  if($action === 'delete_candidate'){
    $cUser = trim($_POST['cand_username'] ?? '');
    $origCount = count($profiles);
    $profiles = array_values(array_filter($profiles, function($p) use ($cUser){
      return ($p['username'] ?? '') !== $cUser && ($p['roll'] ?? '') !== $cUser;
    }));
    if(count($profiles) < $origCount){
      file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flash = ['type'=>'success', 'msg'=>"Candidate account '{$cUser}' deleted."];
    }
  }

  // 16b. SAVE CANDIDATE VERIFICATION PHOTO
  if($action === 'save_candidate_photo'){
    $roll = trim($_POST['cand_roll'] ?? '');
    $photo = trim($_POST['cand_photo'] ?? '');
    if(isset($_FILES['cand_photo_file']) && $_FILES['cand_photo_file']['error'] === UPLOAD_ERR_OK){
      $ext = strtolower(pathinfo($_FILES['cand_photo_file']['name'], PATHINFO_EXTENSION));
      if(in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])){
        $uDir = __DIR__.'/uploads/';
        if(!is_dir($uDir)) @mkdir($uDir, 0777, true);
        $sName = 'cand_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $roll).'_'.time().'.'.$ext;
        if(move_uploaded_file($_FILES['cand_photo_file']['tmp_name'], $uDir.$sName)){
          $photo = 'uploads/'.$sName;
        }
      }
    }
    if(!empty($roll) && !empty($photo)){
      foreach($profiles as &$p){
        if(strcasecmp(trim($p['roll'] ?? $p['username'] ?? ''), $roll) === 0){
          $p['photo'] = $photo;
          $p['verified_at'] = date('Y-m-d H:i:s');
          break;
        }
      }
      unset($p);
      file_put_contents($profilesFile, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flash = ['type'=>'success', 'msg'=>"Pre-exam verification photo saved for candidate {$roll}."];
    }
  }

  // 17. CREATE COURSE / CATEGORY STREAM
  if($action === 'create_course'){
    $name = trim($_POST['course_name'] ?? '');
    $stream = trim($_POST['course_stream'] ?? '');
    $color = trim($_POST['course_color'] ?? 'blue');
    $icon = trim($_POST['course_icon'] ?? 'academic');

    if(empty($name)){
      $flash = ['type'=>'error', 'msg'=>'Course / Stream name cannot be empty.'];
    } else {
      $dup = false;
      foreach($courses as $c){
        if(strcasecmp($c['name'] ?? '', $name) === 0){
          $dup = true; break;
        }
      }

      if($dup){
        $flash = ['type'=>'error', 'msg'=>"Course '{$name}' already exists in your library."];
      } else {
        $newCourse = [
          'id' => 'course_' . preg_replace('/[^a-z0-9]/', '_', strtolower($name)) . '_' . substr(uniqid(), -4),
          'name' => $name,
          'stream' => $stream ?: 'Competitive Exam Stream',
          'color' => $color,
          'icon' => $icon
        ];
        $courses[] = $newCourse;
        file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $flash = ['type'=>'success', 'msg'=>"Course '{$name}' created successfully! Its colored card is now active on the Candidate Portal."];
      }
    }
  }

  // 18. DELETE COURSE STREAM
  if($action === 'delete_course'){
    $cId = trim($_POST['course_id'] ?? '');
    $origCount = count($courses);
    $courses = array_values(array_filter($courses, function($c) use ($cId){
      return ($c['id'] ?? '') !== $cId && strcasecmp($c['name'] ?? '', $cId) !== 0;
    }));
    if(count($courses) < $origCount){
      file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flash = ['type'=>'success', 'msg'=>'Course box removed from portal.'];
    }
  }
}

// Analytics calculations
$totalStudents = count($profiles);
$totalExams = count($exams);
$totalResults = count($results);
$avgScore = $totalResults ? round(array_sum(array_column($results,'score')) / $totalResults, 1) : 0;
$topScore = $totalResults ? max(array_column($results,'score')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Server World - Examination Administration</title>
<link rel="icon" type="image/jpeg" href="logo.jpg">
<style>
body{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;margin:0;color:#1e293b}
header{background:#1565D8;color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.1)}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;margin-top:10px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
th,td{padding:12px 14px;border-bottom:1px solid #e2e8f0;font-size:13px;text-align:left}
th{background:#E8F0FE;color:#1e3a8a;font-weight:700}
tr:hover{background:#f1f5f9}
.btn{padding:8px 14px;border-radius:6px;border:0;cursor:pointer;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px}
.btn-blue{background:#1565D8;color:#fff}.btn-orange{background:#FF8F17;color:#fff}.btn-red{background:#ef4444;color:#fff}.btn-green{background:#10B981;color:#fff}.btn-purple{background:#7c3aed;color:#fff}.btn-slate{background:#64748b;color:#fff}
.container{max-width:1280px;margin:24px auto;padding:0 20px}
.card{background:#fff;padding:22px;border-radius:12px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.04);border:1px solid #e2e8f0}
input,textarea,select{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;margin:6px 0;box-sizing:border-box;font-family:inherit;font-size:13px}
input:focus,textarea:focus,select:focus{outline:none;border-color:#1565D8;box-shadow:0 0 0 3px rgba(21,101,216,0.12)}
.grid2{display:grid;grid-template-columns:1.2fr 1fr;gap:20px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}
.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
@media(max-width:1024px){.grid2,.grid3{grid-template-columns:1fr}.grid4{grid-template-columns:1fr 1fr}}
@media(max-width:768px){
  header{
    flex-direction:column;
    align-items:flex-start;
    gap:10px;
    padding:12px 16px;
  }
  header > div:last-child{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }
  .container{
    padding:0 12px;
    margin:14px auto;
  }
  .grid4{
    grid-template-columns:1fr 1fr;
    gap:10px;
  }
  .stat{
    padding:12px;
  }
  .stat b{
    font-size:20px;
  }
  .card{
    padding:16px 14px;
    margin:12px 0;
  }
  .modal-content{
    width:95%;
    padding:16px;
    max-height:90vh;
  }
  table{
    display:block;
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    white-space:nowrap;
  }
  .q-opt-grid{
    grid-template-columns:1fr;
  }
}
.stat{background:#fff;padding:18px;border-radius:12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.04);border:1px solid #e2e8f0}
.stat b{font-size:26px;color:#1565D8}
.alert-box{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;justify-content:space-between}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #86efac}
.alert-error{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}
.alert-info{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc}
.badge{padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700;display:inline-block}
.badge-cbt{background:#dcfce7;color:#15803d;border:1px solid #86efac}
.badge-doc{background:#fef3c7;color:#b45309;border:1px solid #fde68a}
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center}
.modal-content{background:#fff;padding:24px;border-radius:12px;max-width:680px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 40px rgba(0,0,0,.2)}
.search-input{max-width:300px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px}
.q-card-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px}
.q-card-item:hover{border-color:#93c5fd}
.q-opt-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0}
.q-opt{padding:6px 10px;border-radius:6px;background:#ffffff;border:1px solid #e2e8f0;font-size:12px}
.q-opt.correct{background:#dcfce7;border-color:#86efac;font-weight:700;color:#15803d}
.cand-folder-pill{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;color:#334155;font-size:12.5px;font-weight:700;cursor:pointer;transition:all 0.15s ease}
.cand-folder-pill:hover{background:#e2e8f0;border-color:#94a3b8}
.cand-folder-pill.active{background:#2563eb;color:#ffffff;border-color:#1d4ed8}
.cand-folder-pill.active .folder-count{background:#ffffff;color:#1d4ed8}
.cand-folder-pill .folder-count{font-size:12px;padding:1px 7px;border-radius:12px;background:#e2e8f0;color:#475569;font-weight:800}
.cand-tab-btn{padding:8px 16px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc;font-size:12.5px;font-weight:700;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.cand-tab-btn.active{background:#2563eb;color:#fff;border-color:#1d4ed8}
</style>
</head>
<body>
<header style="background:#104085;color:#fff;padding:12px 24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.1)">
  <div style="display:flex;align-items:center;gap:12px">
    <img src="logo.jpg" alt="Server World" style="height:42px;width:auto;object-fit:contain;background:#fff;border-radius:6px;padding:3px">
    <div>
      <div style="font-size:18px;font-weight:900;letter-spacing:-0.2px;line-height:1.2">Server World</div>
      <div style="font-size:12px;color:#f97316;font-weight:800;letter-spacing:0.5px;text-transform:uppercase">Online Exam • Administration Portal</div>
    </div>
  </div>
  <div>
    <?php if($isAuth): ?>
      <a href="index.html" target="_blank" style="color:#fdba74;text-decoration:none;font-weight:700;margin-right:16px" title="Visit Company Homepage">🏢 Main Website</a>
      <a href="candidate_portal.html" target="_blank" style="color:#fff;text-decoration:none;font-weight:600;margin-right:16px">Candidate Portal</a>
      <a href="sample_questions.csv" download style="color:#fde047;text-decoration:none;font-weight:700;margin-right:16px">Download Sample CSV</a>
      <a href="?logout=1" style="color:#fecaca;text-decoration:none;font-weight:700">Sign Out</a>
    <?php endif; ?>
  </div>
</header>

<div class="container">
<?php if(!$isAuth): ?>
  <div class="card" style="max-width:420px;margin:60px auto;text-align:center">
    <img src="logo.jpg" alt="Server World" style="height:76px;object-fit:contain;margin-bottom:12px">
    <h2 style="margin:0 0 4px;color:#104085;font-weight:900">Server World</h2>
    <div style="font-size:13px;color:#ea580c;font-weight:700;margin-bottom:20px;text-transform:uppercase;letter-spacing:0.5px">Administrator Sign In</div>
    <form method="post" style="text-align:left">
      <label style="font-size:13px;font-weight:600">Password</label>
      <input type="password" name="pass" placeholder="Enter admin password (admin123)" required autofocus>
      <button class="btn btn-blue" style="width:100%;margin-top:12px;justify-content:center">Access Control Panel</button>
    </form>
  </div>
<?php else: ?>

  <?php if($flash): ?>
    <div class="alert-box alert-<?= $flash['type'] ?>">
      <span><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></span>
      <button onclick="this.parentElement.style.display='none'" style="background:none;border:none;cursor:pointer;font-weight:bold">&times;</button>
    </div>
  <?php endif; ?>

  <!-- QUICK NAVIGATION JUMP BAR -->
  <div style="display:flex;gap:10px;margin:14px 0 18px;flex-wrap:wrap">
    <a href="#courseManagementCard" class="btn btn-blue" style="font-size:12px;text-decoration:none;background:#0284c7">📚 Manage Courses &amp; Streams (<?= count($courses) ?>)</a>
    <a href="#createExamCard" class="btn btn-blue" style="font-size:12px;text-decoration:none;background:#2563eb">📝 Create Assessment</a>
    <a href="#uploadFileCard" class="btn btn-purple" style="font-size:12px;text-decoration:none">📤 Upload Question Paper</a>
    <a href="#examLibraryCard" class="btn btn-slate" style="font-size:12px;text-decoration:none">📋 Exam Library (<?= count($exams) ?>)</a>
    <a href="#resultsCard" class="btn btn-green" style="font-size:12px;text-decoration:none">📊 Results (<?= count($results) ?>)</a>
    <a href="#candidateManagementCard" class="btn btn-orange" style="font-size:12px;text-decoration:none">👥 Candidate Accounts (<?= count($profiles) ?>)</a>
    <button type="button" class="btn" style="font-size:12px;background:#7c3aed;color:#fff;border:none;font-weight:700" onclick="openAdminVerificationModal('', 'Student Verification Tool')">📷 Pre-Exam Verification (Camera &amp; Photos)</button>
  </div>

  <!-- LIVE CSV PREVIEW BANNER (IF ACTIVE) -->
  <?php if(is_array($previewQuestions) && count($previewQuestions) > 0): ?>
    <div class="card" style="border:2px solid #3b82f6;background:#eff6ff">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h3 style="margin:0;color:#1d4ed8">CSV Import Preview: <?= count($previewQuestions) ?> Questions</h3>
          <p style="margin:4px 0 0 0;font-size:12px;color:#475569">Target Assessment: <b><?= htmlspecialchars($previewExamTitle) ?></b> (ID: <?= $previewExamId ?>)</p>
        </div>
        <form method="post" style="display:flex;gap:8px">
          <input type="hidden" name="action" value="confirm_csv_import">
          <input type="hidden" name="exam_id" value="<?= $previewExamId ?>">
          <button class="btn btn-green">Confirm &amp; Import All <?= count($previewQuestions) ?> Questions</button>
          <a href="admin.php" class="btn btn-slate" style="text-decoration:none">Cancel</a>
        </form>
      </div>

      <div style="max-height:360px;overflow-y:auto;margin-top:14px;background:#fff;border-radius:8px;border:1px solid #bfdbfe">
        <table>
          <thead>
            <tr>
              <th style="width:40px">#</th>
              <th>Question</th>
              <th>Option A</th>
              <th>Option B</th>
              <th>Option C</th>
              <th>Option D</th>
              <th>Ans</th>
              <th>Marks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($previewQuestions as $i => $pq): 
              $opts = $pq['opts'] ?? ['', '', '', ''];
              $letters = ['A','B','C','D'];
            ?>
              <tr>
                <td><b><?= $i + 1 ?></b></td>
                <td><?= htmlspecialchars($pq['q']) ?></td>
                <td><?= htmlspecialchars($opts[0] ?? '') ?></td>
                <td><?= htmlspecialchars($opts[1] ?? '') ?></td>
                <td><?= htmlspecialchars($opts[2] ?? '') ?></td>
                <td><?= htmlspecialchars($opts[3] ?? '') ?></td>
                <td><b style="color:#15803d"><?= $letters[intval($pq['ans'])] ?? 'A' ?></b></td>
                <td><?= floatval($pq['marks'] ?? 1.0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid4">
    <div class="stat"><b><?= $totalStudents ?></b><br><small style="color:#64748b">Registered Candidates</small></div>
    <div class="stat"><b><?= $totalExams ?></b><br><small style="color:#64748b">Active Assessments</small></div>
    <div class="stat"><b><?= $totalResults ?></b><br><small style="color:#64748b">Tests Completed</small></div>
    <div class="stat"><b><?= $avgScore ?></b><br><small style="color:#64748b">Average Score (Top: <?= $topScore ?>)</small></div>
  </div>

  <div class="grid2">
    <!-- 1. CREATE NEW ASSESSMENT (LIVE OR MOCK WITH QUESTIONS) -->
    <div class="card" id="createExamCard">
      <h4 style="margin-top:0;color:#1565D8">1. Create New Assessment (Live Test or Mock Test)</h4>
      <p style="font-size:12px;color:#64748b;margin-bottom:12px">Create a new test with custom timer, live access code, result visibility, and questions.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_exam">
        <label style="font-size:12px;font-weight:600">Assessment Title</label>
        <input name="title" placeholder="e.g. TSPSC Group 1 Mock Test" required>
        <div style="display:flex;gap:8px;margin-bottom:6px">
          <div style="flex:1">
            <label style="font-size:12px;font-weight:600">Classification</label>
            <select name="test_type" style="font-weight:700">
              <option value="live">🔴 Live Test (Code-Protected: Requires Access Code)</option>
              <option value="mock">📘 Mock Test (Open Access: Available Without Code)</option>
            </select>
          </div>
          <div style="width:140px">
            <label style="font-size:12px;font-weight:600">Access Code (Live)</label>
            <input name="accessCode" placeholder="Auto / Custom" style="font-family:monospace;text-transform:uppercase" title="Students must enter this code for Live Tests">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <div style="flex:1">
            <label style="font-size:12px;font-weight:600">Course Stream / Category</label>
            <div style="display:flex;gap:6px">
              <select name="category" id="createExamCategorySelect" onchange="toggleInlineCourseInput(this)" required style="flex:1;font-weight:700">
                <?php foreach($courses as $c): ?>
                  <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['stream']) ?>)</option>
                <?php endforeach; ?>
                <option value="__NEW__">➕ Type New Course Name...</option>
              </select>
              <button type="button" class="btn btn-blue" onclick="revealInlineCourseRow()" style="font-size:12px;padding:6px 10px;white-space:nowrap" title="Type a new course name inline">+ New Course</button>
            </div>
            <div id="inlineNewCourseBox" style="display:none;margin-top:6px;padding:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px">
              <div style="display:flex;gap:8px">
                <input name="custom_category" id="inlineCustomCategory" placeholder="Course Name (e.g. NEET, Banking, UPSC)" style="font-size:12px;padding:6px 8px;flex:1.5;background:#fff">
                <input name="custom_stream" id="inlineCustomStream" placeholder="Stream Subtitle (e.g. Medical Entrance, IBPS)" style="font-size:12px;padding:6px 8px;flex:1;background:#fff">
              </div>
            </div>
          </div>
          <div style="width:90px">
            <label style="font-size:12px;font-weight:600">Duration</label>
            <input name="duration" type="number" value="15" min="5" required>
          </div>
          <div style="width:90px">
            <label style="font-size:12px;font-weight:600">Max Marks</label>
            <input name="maxMarks" type="number" value="100" min="1" required>
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <div style="flex:1">
            <label style="font-size:12px;font-weight:600">Result Visibility</label>
            <select name="result_visibility">
              <option value="immediate">Immediate Scorecard &amp; Solutions</option>
              <option value="hidden">Hidden (Published Later by Admin)</option>
            </select>
          </div>
          <div style="width:120px">
            <label style="font-size:12px;font-weight:600">Cert Min (%)</label>
            <input name="certificateThreshold" type="number" value="85" min="1" max="100" title="Minimum percentage required for Certificate">
          </div>
        </div>
        <label style="font-size:12px;font-weight:600">Upload Question Paper File (PDF / CSV / TXT):</label>
        <input type="file" name="examFile" accept=".csv,.txt,.pdf,.docx">
        <label style="font-size:12px;font-weight:600">Or Paste Questions (Q|OptA|OptB|OptC|OptD|AnsIndex 0-3|Marks|Expl):</label>
        <textarea name="questions" rows="3" placeholder="What is the capital of Telangana?|Hyderabad|Warangal|Nizamabad|Karimnagar|0|1|Hyderabad is capital"></textarea>
        <button class="btn btn-blue" style="width:100%;margin-top:10px;justify-content:center">Create Assessment</button>
      </form>
    </div>

    <!-- 2. IMPORT QUESTIONS WITH PREVIEW INTO EXISTING ASSESSMENT -->
    <div class="card">
      <h4 style="margin-top:0;color:#7c3aed">2. Import Questions into Existing Assessment</h4>
      <p style="font-size:12px;color:#64748b;margin-bottom:12px">Upload PDF question paper, CSV, or TXT with preview before saving.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="preview_csv">
        <label style="font-size:12px;font-weight:600">Select Target Assessment</label>
        <select name="exam_id" required>
          <?php foreach($exams as $ex): ?>
            <option value="<?= intval($ex['id']) ?>"><?= htmlspecialchars($ex['title']) ?> (Code: <?= htmlspecialchars($ex['accessCode'] ?? '') ?>)</option>
          <?php endforeach; ?>
        </select>
        <label style="font-size:12px;font-weight:600">Select Question File (PDF, CSV, or TXT)</label>
        <input type="file" name="csvFile" accept=".csv,.txt,.pdf" required>
        <div style="font-size:12px;color:#64748b;margin:4px 0">Supported: Digital PDF question papers (1. Question, A. Option...), CSV, and text format.</div>
        <button class="btn btn-purple" style="width:100%;margin-top:10px;justify-content:center">Preview Questions &rarr;</button>
      </form>

      <!-- OPTIONAL LEGACY REFERENCE PAPER UPLOAD ACCORDION -->
      <details style="margin-top:18px;border-top:1px solid #e2e8f0;padding-top:12px">
        <summary style="font-size:12px;font-weight:700;color:#d97706;cursor:pointer">+ Upload Standalone Document / Reference Paper</summary>
        <form method="post" enctype="multipart/form-data" style="margin-top:8px">
          <input type="hidden" name="action" value="upload_question_paper">
          <label style="font-size:12px;font-weight:600">Paper Title</label>
          <input name="paperTitle" placeholder="e.g. Reference Question Paper" required>
          <div style="display:flex;gap:6px">
            <div style="flex:1">
              <label style="font-size:12px;font-weight:600">Classification</label>
              <select name="paperTestType">
                <option value="live">Live Test</option>
                <option value="mock">Mock Test</option>
              </select>
            </div>
            <div style="flex:1">
              <label style="font-size:12px;font-weight:600">Course Stream</label>
              <select name="paperCategory" required style="width:100%;font-weight:700">
                <?php foreach($courses as $c): ?>
                  <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['stream']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <label style="font-size:12px;font-weight:600">File Attachment (PDF, DOCX, JPG, PNG)</label>
          <input type="file" name="questionFile" accept=".csv,.txt,.pdf,.docx,.doc,.jpg,.jpeg,.png" required>
          <button class="btn btn-orange" style="width:100%;margin-top:6px;justify-content:center;padding:6px 12px;font-size:12px">Upload Reference File</button>
        </form>
      </details>
    </div>
  </div>

  <!-- PROMINENT PUBLISH ANSWERS BANNER FOR HIDDEN EXAMS -->
  <?php 
    $hiddenExams = array_filter($exams, function($x){ return ($x['result_visibility'] ?? '') === 'hidden'; });
    if(!empty($hiddenExams)):
      foreach($hiddenExams as $hx):
  ?>
    <div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:10px;padding:14px 18px;margin:16px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 2px 4px rgba(0,0,0,0.06)">
      <div>
        <div style="font-size:14px;font-weight:800;color:#92400e;display:flex;align-items:center;gap:6px">
          <span>🔒 Publish Answers Notice:</span>
          <span>"<?= htmlspecialchars($hx['title']) ?>"</span>
        </div>
        <div style="font-size:12px;color:#b45309;margin-top:2px">
          Student attempts have been recorded, but answers, scorecards, and solutions are currently <b>hidden from students</b>.
        </div>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="toggle_result_visibility">
        <input type="hidden" name="exam_id" value="<?= intval($hx['id']) ?>">
        <button type="submit" class="btn btn-green" style="padding:8px 18px;font-size:13px;font-weight:800;box-shadow:0 2px 4px rgba(0,0,0,0.1)">📢 Publish Answers &amp; Solutions Now &rarr;</button>
      </form>
    </div>
  <?php endforeach; endif; ?>

  <!-- ==================== COURSE & STREAM MANAGEMENT ==================== -->
  <div class="card" id="courseManagementCard" style="border-top:3px solid #0284c7">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <h3 style="margin:0;color:#0369a1">📚 Course Streams &amp; Categories (Total: <?= count($courses) ?>)</h3>
        <p style="margin:4px 0 0;font-size:12px;color:#64748b">
          Manage courses shown on the candidate dashboard. Adding a course creates a colored card in "Exam Streams &amp; Categories" on the portal.
        </p>
      </div>
      <div>
        <a href="candidate_portal.html" target="_blank" class="btn btn-blue" style="font-size:12px;text-decoration:none;background:#0284c7">👁️ View on Candidate Portal</a>
      </div>
    </div>

    <!-- Add Course Form -->
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin:16px 0">
      <h4 style="margin:0 0 10px;color:#0369a1">➕ Add New Course / Category Stream</h4>
      <form method="post" style="display:grid;grid-template-columns:1.5fr 1.5fr 1.2fr 1.2fr auto;gap:12px;align-items:end">
        <input type="hidden" name="action" value="create_course">
        <div>
          <label style="font-size:12px;font-weight:700;color:#0369a1">Course Name *</label>
          <input name="course_name" placeholder="e.g. Banking PO, NEET UG, Data Science" required style="margin:4px 0 0;background:#fff">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#0369a1">Stream Subtitle / Description</label>
          <input name="course_stream" placeholder="e.g. SBI • IBPS • RBI, Medical Entrance" style="margin:4px 0 0;background:#fff">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#0369a1">Box Color Theme</label>
          <select name="course_color" style="margin:4px 0 0;background:#fff">
            <option value="blue">Blue</option>
            <option value="orange">Orange</option>
            <option value="green">Green</option>
            <option value="purple">Purple</option>
            <option value="cyan">Cyan / Tech</option>
            <option value="indigo">Indigo</option>
            <option value="rose">Rose</option>
            <option value="amber">Amber</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#0369a1">Icon Symbol</label>
          <select name="course_icon" style="margin:4px 0 0;background:#fff">
            <option value="building">🏛️ Building / Gov</option>
            <option value="shield">🛡️ Shield / Police / Defense</option>
            <option value="academic">🎓 Graduation / Academic</option>
            <option value="train">🚆 Train / Railway / SSC</option>
            <option value="code">💻 Code / Tech / CS</option>
            <option value="book">📖 Book / General</option>
            <option value="chart">📊 Chart / Finance / Business</option>
            <option value="award">🏆 Award / Competitive</option>
          </select>
        </div>
        <div>
          <button class="btn btn-blue" style="height:40px;padding:0 20px;font-weight:800;background:#0284c7">Create Course</button>
        </div>
      </form>
    </div>

    <!-- Courses Grid Display -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:14px;margin-top:14px">
      <?php foreach($courses as $c): 
        $cName = $c['name'] ?? '';
        $testCount = 0;
        foreach($exams as $ex){
          if(strcasecmp(trim($ex['category'] ?? ''), $cName) === 0) $testCount++;
        }
      ?>
        <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:14px;background:#fff;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="display:flex;align-items:center;gap:8px">
              <b style="font-size:14px;color:#0f172a"><?= htmlspecialchars($cName) ?></b>
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:3px"><?= htmlspecialchars($c['stream'] ?? '') ?></div>
            <div style="font-size:12px;color:#0369a1;font-weight:700;margin-top:4px"><?= $testCount ?> <?= $testCount === 1 ? 'Assessment' : 'Assessments' ?> linked</div>
          </div>
          <div>
            <form method="post" onsubmit="return confirm('Remove course stream \'<?= htmlspecialchars(addslashes($cName)) ?>\'? Linked exams will remain safe.');" style="margin:0">
              <input type="hidden" name="action" value="delete_course">
              <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['id'] ?? $cName) ?>">
              <button class="btn btn-red" style="padding:4px 8px;font-size:12px" title="Delete Course">🗑️</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- EXAMS LIST -->
  <div class="card" id="examLibraryCard">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0;color:#1e3a8a">Examination Library (Total: <?= count($exams) ?>)</h3>
      <span style="font-size:12px;color:#64748b">Changes sync directly to candidate portal</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Title</th>
          <th>Stream</th>
          <th>Type</th>
          <th>Access Code</th>
          <th>Format</th>
          <th>Questions</th>
          <th>Duration</th>
          <th>Max Marks</th>
          <th>Results Visibility</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($exams)): ?>
        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:24px">No assessments found.</td></tr>
      <?php else: foreach($exams as $e): 
        $hasQ = !empty($e['questions']) && count($e['questions']) > 0;
        $qLen = $hasQ ? count($e['questions']) : intval($e['qCount'] ?? 0);
        $hasFile = !empty($e['file']);
        $testType = $e['testType'] ?? 'live';
        $isLive = ($testType === 'live');
        $paperType = $e['paperType'] ?? ($hasQ ? 'interactive' : 'document');
        $isInteractive = ($paperType === 'interactive' && $hasQ);
        $vis = $e['result_visibility'] ?? 'immediate';
        $isHidden = ($vis === 'hidden');
      ?>
        <tr>
          <td>
            <b><?= htmlspecialchars($e['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?></b>
            <?php if($hasFile): ?>
              <br><small><a href="uploads/<?= htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color:#0284c7;text-decoration:none">📄 <?= htmlspecialchars(substr($e['file'], 11) ?: $e['file']) ?></a></small>
            <?php endif; ?>
          </td>
          <td><span style="background:#e0f2fe;color:#0284c7;padding:3px 8px;border-radius:4px;font-weight:700;font-size:12px"><?= htmlspecialchars($e['category'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <?php if($isLive): ?>
                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:800;border:1px solid #fca5a5">🔴 Live Test</span>
              <?php else: ?>
                <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-weight:800;border:1px solid #bfdbfe">📘 Mock Test</span>
              <?php endif; ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_test_type">
                <input type="hidden" name="exam_id" value="<?= intval($e['id']) ?>">
                <button type="submit" class="btn" style="padding:2px 6px;font-size:12px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;cursor:pointer" title="Toggle between Live and Mock test">Switch</button>
              </form>
            </div>
          </td>
          <td>
            <?php if($isLive): ?>
              <div style="display:flex;align-items:center;gap:6px">
                <code style="background:#fef3c7;color:#b45309;padding:4px 8px;border-radius:6px;font-weight:800;font-size:12px;font-family:monospace;letter-spacing:1px;border:1px solid #fde68a"><?= htmlspecialchars($e['accessCode'] ?? 'REQUIRED', ENT_QUOTES, 'UTF-8') ?></code>
                <button type="button" class="btn" style="padding:2px 6px;font-size:12px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;cursor:pointer" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($e['accessCode'] ?? '', ENT_QUOTES, 'UTF-8') ?>');alert('Access Code <?= htmlspecialchars($e['accessCode'] ?? '', ENT_QUOTES, 'UTF-8') ?> copied! Share with your students.');" title="Copy code">Copy</button>
                <button type="button" class="btn" style="padding:2px 6px;font-size:12px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;cursor:pointer" onclick="openEditCodeModal(<?= intval($e['id']) ?>, '<?= htmlspecialchars(addslashes($e['title']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($e['accessCode'] ?? '', ENT_QUOTES, 'UTF-8') ?>')" title="Change code">Edit</button>
              </div>
            <?php else: ?>
              <span style="color:#059669;font-weight:700;font-size:12px">🟢 Open (No Code)</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($isInteractive): ?>
              <span class="badge badge-cbt" style="font-weight:800">⚡ Interactive CBT</span>
            <?php elseif($hasFile): ?>
              <span class="badge badge-doc">📄 Reference Paper Only</span>
            <?php else: ?>
              <span class="badge" style="background:#f1f5f9;color:#64748b">Draft (No Questions)</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-blue" style="padding:4px 10px;font-size:12px" onclick="openQuestionManagerModal(<?= intval($e['id']) ?>)">
              <b><?= $qLen ?> Questions</b> (Manage)
            </button>
          </td>
          <td><?= intval($e['duration'] ?? 15) ?> Mins</td>
          <td><b><?= floatval($e['maxMarks'] ?? 100) ?></b></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <?php if($isHidden): ?>
                <span class="badge" style="background:#fef3c7;color:#92400e;font-weight:800;border:1px solid #fde68a" title="Scores & solutions hidden from students until released">🔒 Hidden</span>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle_result_visibility">
                  <input type="hidden" name="exam_id" value="<?= intval($e['id']) ?>">
                  <button type="submit" class="btn btn-green" style="padding:2px 8px;font-size:12px;font-weight:700" title="Publish scorecards and solutions to students now">📢 Publish</button>
                </form>
              <?php else: ?>
                <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:800;border:1px solid #86efac" title="Students can view scorecards and solutions immediately">🟢 Published</span>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle_result_visibility">
                  <input type="hidden" name="exam_id" value="<?= intval($e['id']) ?>">
                  <button type="submit" class="btn" style="padding:2px 6px;font-size:12px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1" title="Hide results from students">Hide</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
          <td style="white-space:nowrap">
            <?php if($hasFile && $qLen === 0): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="extract_paper_questions">
              <input type="hidden" name="exam_id" value="<?= intval($e['id']) ?>">
              <button class="btn btn-green" style="padding:4px 8px;font-size:12px" title="Extract questions from uploaded paper">⚡ Extract</button>
            </form>
            <?php endif; ?>
            <button class="btn btn-purple" style="padding:4px 8px;font-size:12px" onclick="openAddQuestionsModal(<?= intval($e['id']) ?>, '<?= htmlspecialchars(addslashes($e['title']), ENT_QUOTES, 'UTF-8') ?>')">
              Import CSV
            </button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this assessment from library?')">
              <input type="hidden" name="action" value="delete_exam">
              <input type="hidden" name="id" value="<?= intval($e['id']) ?>">
              <button class="btn btn-red" style="padding:4px 8px;font-size:12px">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- RESULTS & STUDENT ATTEMPTS ANALYTICS -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <h3 style="margin:0;color:#1e3a8a">Candidate Examination Submissions &amp; Attempts (Total: <?= count($results) ?>)</h3>
        <p style="margin:4px 0 0 0;font-size:12px;color:#64748b">Verified records of completed tests, calculated percentages, and time logs</p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="admin.php?action=export_results_excel" class="btn btn-green" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;text-decoration:none;font-weight:700;font-size:12px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          📥 Download All Results (.xlsx / .csv)
        </a>
        <select onchange="if(this.value) location.href='admin.php?action=export_results_excel&exam_id='+this.value;" style="padding:7px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff">
          <option value="">Export by Specific Exam...</option>
          <?php foreach($exams as $ex): ?>
            <option value="<?= htmlspecialchars($ex['id']) ?>"><?= htmlspecialchars($ex['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="resultSearchInput" placeholder="Search by Candidate or Roll..." class="search-input" onkeyup="filterResultsTable()">
        <a href="clear.php" onclick="return confirm('Clear all results history?')" style="color:#ef4444;font-size:12px;font-weight:600;text-decoration:none;padding:6px 10px;border:1px solid #fecaca;border-radius:6px">Clear History</a>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table id="resultsTable">
        <thead>
          <tr>
            <th>Rank</th>
            <th>Candidate Name</th>
            <th>📷 Pre-Exam Photo</th>
            <th>Candidate URN</th>
            <th>Exam Title</th>
            <th>Code</th>
            <th>Score / Total</th>
            <th>Accuracy</th>
            <th>Time Taken</th>
            <th>Focus Integrity</th>
            <th>Submission Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        usort($results, function($a, $b){ return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });
        if(empty($results)): ?>
          <tr><td colspan="12" style="text-align:center;color:#64748b;padding:24px">No test results recorded yet.</td></tr>
        <?php else: foreach($results as $i => $r): 
          $score = floatval($r['score'] ?? 0);
          $total = floatval($r['totalMarks'] ?? $r['total'] ?? 10);
          $pct = $total > 0 ? round(($score / $total) * 100) : 0;
          $tabSw = intval($r['tabSwitchCount'] ?? 0);
          $fsExit = intval($r['fullscreenExitCount'] ?? 0);
          $totInt = $tabSw + $fsExit;
          $intTitle = 'Tab Switches: ' . $tabSw . ' | Fullscreen Exits: ' . $fsExit;
        ?>
          <tr class="result-row">
            <td><b>#<?= ($i + 1) ?></b></td>
            <td class="res-name"><?= htmlspecialchars($r['name'] ?? 'Candidate', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php 
                $candPhoto = !empty($r['photo']) ? $r['photo'] : ($profiles[$r['roll']]['photo'] ?? '');
              ?>
              <?php if(!empty($candPhoto)): ?>
                <img src="<?= htmlspecialchars($candPhoto) ?>" style="width:34px;height:34px;border-radius:6px;object-fit:cover;border:1.5px solid #10b981;cursor:pointer" onclick="viewVerificationPhoto('<?= htmlspecialchars($r['name'] ?? 'Candidate', ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($r['roll'] ?? '-', ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($candPhoto) ?>')" title="Click to view verified pre-exam photo">
              <?php else: ?>
                <button type="button" class="btn" style="padding:2px 6px;font-size:12px;background:#f8fafc;color:#64748b;border:1px solid #cbd5e1" onclick="openAdminVerificationModal('<?= htmlspecialchars($r['roll'] ?? '-', ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($r['name'] ?? 'Candidate', ENT_QUOTES, 'UTF-8') ?>', '')" title="Capture / upload pre-exam verification photo">📷 Verify</button>
              <?php endif; ?>
            </td>
            <td class="res-roll"><code><?= htmlspecialchars($r['roll'] ?? '-', ENT_QUOTES, 'UTF-8') ?></code></td>
            <td class="res-exam"><b><?= htmlspecialchars($r['examTitle'] ?? 'Online Exam', ENT_QUOTES, 'UTF-8') ?></b></td>
            <td><code><?= htmlspecialchars($r['accessCode'] ?? '-', ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><b style="color:<?= $pct >= 60 ? '#15803d' : '#b91c1c' ?>"><?= $score ?></b> / <?= $total ?></td>
            <td><b><?= $pct ?>%</b></td>
            <td><?= htmlspecialchars($r['timeTaken'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if($totInt === 0): ?>
                <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700">✓ Clean (0)</span>
              <?php else: ?>
                <span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700" title="<?= htmlspecialchars($intTitle, ENT_QUOTES, 'UTF-8') ?>">⚠️ <?= $totInt ?> Interruption<?= $totInt > 1 ? 's' : '' ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($r['timestamp'] ?? $r['date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td style="white-space:nowrap">
              <button type="button" class="btn btn-blue" style="padding:3px 8px;font-size:12px" onclick="openCandidateReviewModal(<?= $i ?>)">👁️ View Answers</button>
              <button type="button" class="btn btn-green" style="padding:3px 8px;font-size:12px" onclick="printStudentScorecard(<?= $i ?>)" title="Print or Save PDF Scorecard for this student">🖨️ PDF Scorecard</button>
              <a href="admin.php?action=export_results_excel&attempt_id=<?= urlencode($r['attemptId'] ?? '') ?>&roll=<?= urlencode($r['roll'] ?? '') ?>&exam_id=<?= urlencode($r['examId'] ?? '') ?>&idx=<?= $i ?>" class="btn" style="background:#0284c7;color:#fff;padding:3px 8px;font-size:12px;text-decoration:none" title="Download Excel/CSV for this individual exam result">📥 CSV</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this result record?')">
                <input type="hidden" name="action" value="delete_result">
                <input type="hidden" name="idx" value="<?= $i ?>">
                <button class="btn btn-red" style="padding:3px 8px;font-size:12px">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- RESULTS SHOW MORE / SHOW LESS PAGINATION BAR -->
    <div id="resultsPaginationBar" style="display:none;justify-content:center;align-items:center;padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;margin-top:6px;border-radius:0 0 8px 8px;gap:12px;flex-wrap:wrap">
      <button type="button" id="btnToggleResults" class="btn btn-blue" onclick="toggleResultsShowMore()" style="font-size:12px;font-weight:700;padding:8px 22px;border-radius:6px;box-shadow:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <span id="btnToggleResultsText">👁️ Show More (Showing 10 of <?= count($results) ?>) ▼</span>
      </button>
      <span id="resultsCountLabel" style="font-size:12px;color:#64748b;font-weight:600"></span>
    </div>
  </div>

  <!-- CANDIDATE ACCOUNTS & EXAM AUTHORIZATION MANAGEMENT (COURSE FOLDERS & BULK IMPORT) -->
  <div class="card" id="candidateManagementCard" style="border-top:3px solid #2563eb">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
      <div>
        <h3 style="margin:0;color:#1e3a8a">Candidate Accounts &amp; Course Folders (Total: <?= count($profiles) ?>)</h3>
        <p style="margin:4px 0 0 0;font-size:12px;color:#64748b">Organize students course-wise in folders, import student lists, and auto-assign random access passwords</p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn" style="background:#7c3aed;color:#fff;font-size:12px;font-weight:700;padding:6px 14px" onclick="openAdminVerificationModal('', 'Student Webcam Verification Tool')">📷 Student Pre-Exam Photo Verification Tool &amp; Camera</button>
        <span style="background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;padding:6px 12px;border-radius:6px;border:1px solid #bfdbfe">
          🔒 Admin Gated Access Enabled
        </span>
      </div>
    </div>

    <?php if(!empty($_SESSION['last_imported_students'])): 
      $lastList = $_SESSION['last_imported_students'];
      $importedCount = count($lastList);
    ?>
      <!-- RECENT IMPORT SUCCESS SLIP & DOWNLOAD BANNER -->
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:16px;margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
          <div>
            <div style="font-weight:800;color:#166534;font-size:14px;display:flex;align-items:center;gap:6px">
              <span>✓ Successfully Imported <?= $importedCount ?> Student Account(s)</span>
            </div>
            <div style="font-size:12px;color:#15803d;margin-top:2px">
              Random passwords have been generated and students are organized into their respective course folders.
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <button type="button" class="btn btn-green" style="padding:6px 12px;font-size:12px" onclick="printImportedSlips()">🖨️ Print Login Slips</button>
            <a href="admin.php?action=export_candidates_csv&course=all" class="btn" style="background:#15803d;color:#fff;padding:6px 12px;font-size:12px;text-decoration:none">📥 Download CSV</a>
          </div>
        </div>
      </div>
      <script>
        window.lastImportedStudents = <?= json_encode($lastList) ?>;
      </script>
    <?php 
      unset($_SESSION['last_imported_students']);
    endif; ?>

    <!-- CREATION / IMPORT TABS -->
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" class="cand-tab-btn active" id="tabBtnImport" onclick="switchCandCreateTab('import')">
        <span>📥 Bulk Import Students (CSV / Text)</span>
      </button>
      <button type="button" class="cand-tab-btn" id="tabBtnSingle" onclick="switchCandCreateTab('single')">
        <span>👤 Add Individual Student</span>
      </button>
      <button type="button" class="cand-tab-btn" id="tabBtnBulk" onclick="switchCandCreateTab('bulk')">
        <span>⚡ Quick Prefix Generator</span>
      </button>
    </div>

    <!-- PANE 1: BULK IMPORT (CSV / TEXT) -->
    <div id="candPaneImport" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:24px">
      <h4 style="margin:0 0 10px 0;font-size:13px;font-weight:800;color:#1e3a8a;display:flex;align-items:center;gap:6px">
        <span>📥 Import Students &amp; Auto-Assign Random Passwords</span>
      </h4>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="bulk_import_students">
        <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:16px">
          <div>
            <label style="font-size:12px;font-weight:700;color:#1e293b;display:flex;justify-content:space-between;margin-bottom:4px">
              <span>Paste Student Names, Roll Numbers &amp; Course *</span>
              <a href="javascript:void(0)" onclick="insertSampleStudentData()" style="color:#2563eb;text-decoration:none;font-weight:600">📋 Paste Sample Format</a>
            </label>
            <textarea name="import_student_text" id="importStudentTextArea" rows="6" placeholder="Rahul Sharma, SW101, NEET&#10;Priya Patel, SW102, TSPSC Groups&#10;Suresh Kumar, POL-01, Telangana Police" style="width:100%;padding:10px;font-size:12.5px;font-family:monospace;border:1px solid #cbd5e1;border-radius:6px;resize:vertical"></textarea>
            <div style="font-size:12px;color:#64748b;margin-top:4px;line-height:1.5">
              Format: <code>Student Name, Roll Number, Course Name</code> (or tab-separated copied from Excel). Random passwords will be securely generated!
            </div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#1e293b;display:block;margin-bottom:4px">Or Upload CSV / Text File</label>
            <input type="file" name="import_student_file" accept=".csv, .txt" style="font-size:12px;padding:8px;border:1px dashed #cbd5e1;border-radius:6px;width:100%;background:#fff">
            
            <div style="margin-top:10px">
              <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Default Course (if not in row)</label>
              <select name="import_default_course" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff">
                <?php foreach($courses as $c): ?>
                  <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
                <option value="All Assessments">All Assessments</option>
              </select>
            </div>

            <div style="margin-top:14px">
              <button type="submit" class="btn btn-blue" style="width:100%;padding:10px;font-weight:700;font-size:12.5px">
                📥 Import Students &amp; Auto-Assign Passwords
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- PANE 2: SINGLE CANDIDATE (HIDDEN BY DEFAULT) -->
    <div id="candPaneSingle" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:24px">
      <h4 style="margin:0 0 12px 0;font-size:13px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px">
        <span>👤 Add Individual Student</span>
      </h4>
      <form method="post">
        <input type="hidden" name="action" value="create_candidate">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
          <div>
            <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Full Name *</label>
            <input type="text" name="cand_name" required placeholder="e.g. Rahul Sharma" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Username / Roll No *</label>
            <input type="text" name="cand_username" required placeholder="e.g. SW101" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
          <div>
            <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Password *</label>
            <input type="text" name="cand_password" required placeholder="e.g. 123456" value="<?= substr(str_shuffle('23456789ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 6) ?>" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;font-family:monospace;font-weight:700">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#1e293b;display:flex;justify-content:space-between;margin-bottom:3px">
              <span>Assign Course(s) (Select 1, 2, or more) *</span>
              <span style="font-size:12px">
                <a href="javascript:void(0)" onclick="candToggleAllCourses('candCreateBox', true)" style="color:#2563eb;text-decoration:none;font-weight:700">All</a> | 
                <a href="javascript:void(0)" onclick="candToggleAllCourses('candCreateBox', false)" style="color:#64748b;text-decoration:none">Clear</a>
              </span>
            </label>
            <div id="candCreateBox" style="display:flex;flex-wrap:wrap;gap:6px;background:#fff;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;max-height:120px;overflow-y:auto">
              <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;background:#eff6ff;padding:3px 8px;border-radius:4px;border:1px solid #bfdbfe;cursor:pointer">
                <input type="checkbox" name="cand_courses[]" value="All Assessments" checked onchange="candHandleAllToggle(this, 'candCreateBox')">
                <b style="color:#1d4ed8">🌐 All Assessments</b>
              </label>
              <?php foreach($courses as $c): ?>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;background:#f8fafc;padding:3px 8px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer">
                  <input type="checkbox" name="cand_courses[]" value="<?= htmlspecialchars($c['name']) ?>" onchange="candHandleSingleToggle(this, 'candCreateBox')">
                  <span>📁 <?= htmlspecialchars($c['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-blue" style="width:100%;padding:9px;font-weight:700;font-size:12px">+ Create Candidate Credentials</button>
      </form>
    </div>

    <!-- PANE 3: BULK GENERATOR (HIDDEN BY DEFAULT) -->
    <div id="candPaneBulk" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:24px">
      <h4 style="margin:0 0 12px 0;font-size:13px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px">
        <span>⚡ Quick Sequence Generator</span>
      </h4>
      <form method="post">
        <input type="hidden" name="action" value="bulk_generate_candidates">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
          <div>
            <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Username Prefix *</label>
            <input type="text" name="bulk_prefix" required value="TSPSC" placeholder="e.g. TSPSC or POLICE" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;font-weight:700">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Number of Candidates *</label>
            <input type="number" name="bulk_count" min="1" max="100" value="10" required style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px">
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">Assign to Course Folder *</label>
          <select name="bulk_exam_id" style="width:100%;padding:8px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff">
            <option value="all">-- All Active Exams --</option>
            <optgroup label="By Course Stream / Category">
              <?php foreach($courses as $c): ?>
                <option value="cat_<?= htmlspecialchars($c['name']) ?>">Course Folder: <?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        <button type="submit" class="btn btn-green" style="width:100%;padding:9px;font-weight:700;font-size:12px">⚡ Generate Accounts with Random Passwords</button>
      </form>
    </div>

    <?php
      // Group students by Course for folders (Supports multiple courses per student)
      $courseFolderMap = [];
      $allFolderStudentsCount = count($profiles);

      // Pre-populate all active courses into map
      foreach($courses as $c){
        $courseFolderMap[$c['name']] = [];
      }
      $courseFolderMap['All Assessments'] = [];

      foreach($profiles as $p){
        $cList = [];
        if(!empty($p['assigned_courses']) && is_array($p['assigned_courses'])){
          $cList = $p['assigned_courses'];
        } elseif(!empty($p['assigned_exam_title'])){
          $cList = array_values(array_filter(array_map('trim', explode(',', $p['assigned_exam_title']))));
        }
        if(empty($cList) || in_array('all', $cList)) $cList = ['All Assessments'];

        foreach($cList as $sc){
          $sc = trim($sc);
          if(empty($sc) || $sc === 'all') $sc = 'All Assessments';
          if(!isset($courseFolderMap[$sc])){
            $courseFolderMap[$sc] = [];
          }
          $courseFolderMap[$sc][] = $p;
        }
      }
    ?>

    <!-- ==================== COURSE FOLDERS NAVIGATION ==================== -->
    <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-size:14px;font-weight:800;color:#1e3a8a;display:flex;align-items:center;gap:6px">
            <span>📁 Course Folders</span>
            <span style="font-size:12px;font-weight:600;color:#64748b">(Click any folder to view students organized by course)</span>
          </div>
          <div id="candFolderCounterDisplay" style="font-size:12px;color:#0284c7;font-weight:700;margin-top:2px">
            Showing all <?= $allFolderStudentsCount ?> students in All Course Folders
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="text" id="candidateSearchInput" placeholder="🔍 Search name, roll..." onkeyup="filterCandidatesFolder()" style="padding:7px 12px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;width:180px;margin:0">
          <a id="btnExportFolderCsv" href="admin.php?action=export_candidates_csv&course=all" class="btn" style="background:#0284c7;color:#fff;padding:7px 12px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px" title="Export passwords for the selected folder to CSV">
            📥 Export Passwords
          </a>
          <button type="button" class="btn btn-blue" style="padding:7px 12px;font-size:12px;font-weight:700" onclick="printFolderStudentSlips()" title="Print login credential cards for students in this folder">
            🖨️ Print Login Slips
          </button>
        </div>
      </div>

      <!-- FOLDER PILLS -->
      <div style="display:flex;flex-wrap:wrap;gap:8px" id="candFolderPills">
        <button type="button" class="cand-folder-pill active" onclick="selectCourseFolder('all', this)">
          <span style="font-size:14px">📂</span>
          <span>All Students</span>
          <span class="folder-count"><?= $allFolderStudentsCount ?></span>
        </button>
        <?php foreach($courseFolderMap as $fName => $fStudents): 
          $sCount = count($fStudents);
        ?>
          <button type="button" class="cand-folder-pill" onclick="selectCourseFolder('<?= htmlspecialchars($fName, ENT_QUOTES, 'UTF-8') ?>', this)">
            <span style="font-size:14px">📁</span>
            <span><?= htmlspecialchars($fName) ?></span>
            <span class="folder-count"><?= $sCount ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- CANDIDATES TABLE -->
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Candidate Name</th>
            <th>Username / Roll No</th>
            <th>Access Password</th>
            <th>Course Folder</th>
            <th>📷 Pre-Exam Verification</th>
            <th>Exam Status</th>
            <th>Registered Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="candidateTableBody">
        <?php if(empty($profiles)): ?>
          <tr id="noCandidatesRow"><td colspan="8" style="text-align:center;color:#64748b;padding:24px">No candidate accounts created yet. Use the import form above to add students.</td></tr>
        <?php else: foreach($profiles as $p): 
          $u = $p['username'] ?? $p['roll'] ?? '';
          $pName = $p['name'] ?? 'Candidate';
          $cPhoto = $p['photo'] ?? '';
          $hasAttempt = false;
          $latestScore = null;
          foreach($results as $resItem){
            if(strcasecmp($resItem['roll'] ?? '', $u) === 0 || strcasecmp($resItem['name'] ?? '', $pName) === 0){
              $hasAttempt = true;
              $latestScore = ($resItem['score'] ?? 0) . ' / ' . ($resItem['totalMarks'] ?? $resItem['total'] ?? 10);
              break;
            }
          }
          $assignedTitle = $p['assigned_exam_title'] ?? 'All Assessments';
          if(empty($assignedTitle) || $assignedTitle === 'all') $assignedTitle = 'All Assessments';

          $candCourseList = [];
          if(!empty($p['assigned_courses']) && is_array($p['assigned_courses'])){
            $candCourseList = $p['assigned_courses'];
          } elseif(!empty($p['assigned_exam_title'])){
            $candCourseList = array_values(array_filter(array_map('trim', explode(',', $p['assigned_exam_title']))));
          }
          if(empty($candCourseList) || in_array('all', $candCourseList)) $candCourseList = ['All Assessments'];
          $candCourseData = implode('|', $candCourseList);
        ?>
          <tr class="candidate-row" data-course="<?= htmlspecialchars($candCourseData, ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars(strtolower($pName), ENT_QUOTES, 'UTF-8') ?>" data-roll="<?= htmlspecialchars(strtolower($u), ENT_QUOTES, 'UTF-8') ?>" data-pass="<?= htmlspecialchars($p['password'] ?? '123456', ENT_QUOTES, 'UTF-8') ?>">
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if(!empty($cPhoto)): ?>
                  <img src="<?= htmlspecialchars($cPhoto, ENT_QUOTES, 'UTF-8') ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid #10b981">
                <?php else: ?>
                  <span style="font-size:16px">👤</span>
                <?php endif; ?>
                <b><?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?></b>
              </div>
            </td>
            <td><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-weight:700;color:#1e40af"><?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?></code></td>
            <td>
              <span id="pass_<?= md5($u) ?>" style="font-family:monospace;font-weight:700;letter-spacing:1px;background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:4px"><?= htmlspecialchars($p['password'] ?? '123456', ENT_QUOTES, 'UTF-8') ?></span>
              <button type="button" class="btn" style="padding:2px 6px;font-size:12px;margin-left:4px" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($p['password'] ?? '123456', ENT_QUOTES, 'UTF-8') ?>');alert('Password copied!');">Copy</button>
            </td>
            <td>
              <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center">
                <?php foreach($candCourseList as $cc): ?>
                  <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px">
                    📁 <?= htmlspecialchars($cc, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                <?php endforeach; ?>
                <button type="button" class="btn" style="padding:2px 7px;font-size:12px;background:#f8fafc;border:1px solid #cbd5e1;color:#0284c7;font-weight:700;margin-left:2px" onclick='openAdminEditStudentCoursesModal(<?= json_encode($u) ?>, <?= json_encode($pName) ?>, <?= json_encode($candCourseList) ?>)' title="Assign or modify enrolled courses for this student">✏️ Edit</button>
              </div>
            </td>
            <td>
              <?php if(!empty($cPhoto)): ?>
                <div style="display:flex;align-items:center;gap:6px">
                  <img src="<?= htmlspecialchars($cPhoto, ENT_QUOTES, 'UTF-8') ?>" style="width:34px;height:34px;border-radius:6px;object-fit:cover;border:1.5px solid #10b981;cursor:pointer" onclick="viewVerificationPhoto('<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($cPhoto, ENT_QUOTES, 'UTF-8') ?>')" title="Click to view full photo">
                  <div>
                    <span style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:700">🟢 Verified</span>
                    <button type="button" class="btn" style="padding:2px 6px;font-size:12px;display:block;margin-top:2px" onclick="openAdminVerificationModal('<?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($cPhoto, ENT_QUOTES, 'UTF-8') ?>')">Update</button>
                  </div>
                </div>
              <?php else: ?>
                <div style="display:flex;align-items:center;gap:6px">
                  <div style="width:34px;height:34px;border-radius:6px;background:#f1f5f9;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:16px">📷</div>
                  <div>
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:600">⚪ Optional / Pending</span>
                    <button type="button" class="btn btn-blue" style="padding:2px 6px;font-size:12px;display:block;margin-top:2px" onclick="openAdminVerificationModal('<?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>', '')">📷 Verify Photo</button>
                  </div>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if($hasAttempt): ?>
                <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700">✓ Completed (Score: <?= $latestScore ?>)</span>
              <?php else: ?>
                <span style="background:#f1f5f9;color:#64748b;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:600">⏳ Not Attempted</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($p['created'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete candidate account <?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>?')">
                <input type="hidden" name="action" value="delete_candidate">
                <input type="hidden" name="cand_username" value="<?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-red" style="padding:3px 8px;font-size:12px">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- CANDIDATES SHOW MORE / SHOW LESS PAGINATION BAR -->
    <div id="candidatesPaginationBar" style="display:none;justify-content:center;align-items:center;padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;margin-top:6px;border-radius:0 0 8px 8px;gap:12px;flex-wrap:wrap">
      <button type="button" id="btnToggleCandidates" class="btn btn-blue" onclick="toggleCandidatesShowMore()" style="font-size:12px;font-weight:700;padding:8px 22px;border-radius:6px;box-shadow:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <span id="btnToggleCandidatesText">👁️ Show More Students (Showing 10 of <?= count($profiles) ?>) ▼</span>
      </button>
      <span id="candidatesCountLabel" style="font-size:12px;color:#64748b;font-weight:600"></span>
    </div>
  </div>

<?php endif; ?>
</div>

<!-- ==================== MODALS ==================== -->

<!-- 1. FULL QUESTION MANAGER MODAL -->
<div id="qManagerModal" class="modal-overlay">
  <div class="modal-content" style="max-width:840px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <div>
        <h3 style="margin:0;color:#1565D8" id="qmExamTitle">Question Management</h3>
        <span style="font-size:12px;color:#64748b" id="qmExamSubtitle">Manage questions, choices, correct answers and marks</span>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <form method="post" style="margin:0" id="qmPublishForm">
          <input type="hidden" name="action" value="toggle_result_visibility">
          <input type="hidden" name="exam_id" id="qmPublishExamId">
          <button type="submit" id="qmPublishBtn" class="btn btn-green" style="padding:6px 14px;font-size:12px;font-weight:700">📢 Publish Answers &amp; Solutions</button>
        </form>
        <button onclick="closeQuestionManagerModal()" style="border:none;background:none;font-size:22px;cursor:pointer">&times;</button>
      </div>
    </div>

    <!-- ADD SINGLE QUESTION QUICK BAR -->
    <details style="background:#f1f5f9;padding:12px;border-radius:8px;margin-bottom:16px">
      <summary style="font-weight:700;font-size:13px;cursor:pointer;color:#1565D8">+ Add Individual Question Manually</summary>
      <form method="post" enctype="multipart/form-data" style="margin-top:10px">
        <input type="hidden" name="action" value="add_single_question">
        <input type="hidden" name="exam_id" id="qmAddExamId">
        
        <div style="display:flex;gap:10px;margin-bottom:8px">
          <div style="flex:1">
            <label style="font-size:12px;font-weight:600">Question Type</label>
            <select name="qType" id="qmAddQType">
              <option value="mcq">Multiple Choice Question (MCQ)</option>
              <option value="blank">Fill in the Blanks (Keyboard Typing Input)</option>
            </select>
          </div>
        </div>

        <label style="font-size:12px;font-weight:600">Question Text</label>
        <textarea name="qText" rows="2" placeholder="Enter question prompt..." required></textarea>

        <label style="font-size:12px;font-weight:600;margin-top:4px;display:block">📖 Reference Passage / Comprehension Paragraph (Optional)</label>
        <textarea name="passage" rows="2" placeholder="Optional: Enter reading passage or case study text that appears above this question in CBT..."></textarea>

        <!-- Image / Diagram Attachment -->
        <div style="background:#f8fafc;border:1px dashed #cbd5e1;padding:10px;border-radius:8px;margin:8px 0">
          <label style="font-size:12px;font-weight:700;color:#334155;display:flex;align-items:center;gap:4px">
            🖼️ Attach Diagram / Image (Optional)
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px">
            <div>
              <label style="font-size:12px;color:#64748b">Upload Image File (PNG, JPG, SVG, WEBP)</label>
              <input type="file" name="q_image_file" accept="image/*" style="padding:4px;font-size:12px">
            </div>
            <div>
              <label style="font-size:12px;color:#64748b">Or Image URL / Filename</label>
              <input type="text" name="q_image_url" placeholder="e.g. uploads/circuit.png or https://..." style="padding:6px 8px;font-size:12px">
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div><label style="font-size:12px;font-weight:600">Option A / Accepted Answer</label><input name="optA" required></div>
          <div><label style="font-size:12px;font-weight:600">Option B (Optional for Blanks)</label><input name="optB"></div>
          <div><label style="font-size:12px;font-weight:600">Option C (Optional for Blanks)</label><input name="optC"></div>
          <div><label style="font-size:12px;font-weight:600">Option D (Optional for Blanks)</label><input name="optD"></div>
        </div>
        <div style="display:flex;gap:10px;margin-top:6px">
          <div style="flex:1">
            <label style="font-size:12px;font-weight:600">Correct Answer / Index</label>
            <select name="correctAns">
              <option value="0">Option A</option>
              <option value="1">Option B</option>
              <option value="2">Option C</option>
              <option value="3">Option D</option>
            </select>
          </div>
          <div style="width:120px">
            <label style="font-size:12px;font-weight:600">Marks</label>
            <input type="number" step="0.25" name="marks" value="1.0" required>
          </div>
        </div>
        <label style="font-size:12px;font-weight:600">Explanation (Optional)</label>
        <input name="explanation" placeholder="Optional answer rationale...">
        <button class="btn btn-blue" style="margin-top:8px">Save Question</button>
      </form>
    </details>

    <!-- QUESTIONS LIST -->
    <div id="qmQuestionsList" style="max-height:55vh;overflow-y:auto"></div>
  </div>
</div>

<!-- 2. EDIT SINGLE QUESTION MODAL -->
<div id="editQModal" class="modal-overlay" style="z-index:10001">
  <div class="modal-content" style="max-width:600px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="margin:0;color:#1565D8">Edit Question</h3>
      <button onclick="closeEditQModal()" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit_single_question">
      <input type="hidden" name="exam_id" id="eqExamId">
      <input type="hidden" name="q_index" id="eqQIndex">
      
      <div style="display:flex;gap:10px;margin-bottom:8px">
        <div style="flex:1">
          <label style="font-size:12px;font-weight:600">Question Type</label>
          <select name="qType" id="eqType">
            <option value="mcq">Multiple Choice Question (MCQ)</option>
            <option value="blank">Fill in the Blanks (Keyboard Typing Input)</option>
          </select>
        </div>
      </div>

      <label style="font-size:12px;font-weight:600">Question Text</label>
      <textarea name="qText" id="eqText" rows="3" required></textarea>

      <label style="font-size:12px;font-weight:600;margin-top:4px;display:block">📖 Reference Passage / Comprehension Paragraph (Optional)</label>
      <textarea name="passage" id="eqPassage" rows="2" placeholder="Optional: Enter reading passage or case study text..."></textarea>

      <!-- Image / Diagram Attachment & Preview -->
      <div style="background:#f8fafc;border:1px dashed #cbd5e1;padding:10px;border-radius:8px;margin:8px 0">
        <label style="font-size:12px;font-weight:700;color:#334155;display:flex;align-items:center;gap:4px">
          🖼️ Diagram / Image Attachment
        </label>
        <div id="eqImagePreviewWrap" style="display:none;margin:8px 0;text-align:center">
          <img id="eqImagePreview" src="" style="max-height:120px;max-width:100%;border-radius:6px;border:1px solid #cbd5e1;background:#fff">
          <div style="font-size:12px;color:#64748b;margin-top:2px">Current Attached Image</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px">
          <div>
            <label style="font-size:12px;color:#64748b">Upload New Image File</label>
            <input type="file" name="q_image_file" accept="image/*" style="padding:4px;font-size:12px">
          </div>
          <div>
            <label style="font-size:12px;color:#64748b">Or Image URL / Filename</label>
            <input type="text" name="q_image_url" id="eqImageUrl" placeholder="e.g. uploads/circuit.png or URL" style="padding:6px 8px;font-size:12px">
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div><label style="font-size:12px;font-weight:600">Option A / Accepted Answer</label><input name="optA" id="eqOptA" required></div>
        <div><label style="font-size:12px;font-weight:600">Option B</label><input name="optB" id="eqOptB"></div>
        <div><label style="font-size:12px;font-weight:600">Option C</label><input name="optC" id="eqOptC"></div>
        <div><label style="font-size:12px;font-weight:600">Option D</label><input name="optD" id="eqOptD"></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <div style="flex:1">
          <label style="font-size:12px;font-weight:600">Correct Answer</label>
          <select name="correctAns" id="eqAns">
            <option value="0">Option A</option>
            <option value="1">Option B</option>
            <option value="2">Option C</option>
            <option value="3">Option D</option>
          </select>
        </div>
        <div style="width:120px">
          <label style="font-size:12px;font-weight:600">Marks</label>
          <input type="number" step="0.25" name="marks" id="eqMarks" required>
        </div>
      </div>
      <label style="font-size:12px;font-weight:600">Explanation</label>
      <input name="explanation" id="eqExp">
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="button" class="btn" style="background:#e2e8f0;color:#1e293b" onclick="closeEditQModal()">Cancel</button>
        <button class="btn btn-blue" style="flex:1;justify-content:center">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- 3. MODAL FOR ADDING QUESTIONS (PDF, CSV OR TEXT) -->
<div id="addQModal" class="modal-overlay">
  <div class="modal-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="margin:0;color:#1565D8" id="modalExamTitle">Import Questions</h3>
      <button onclick="closeAddQuestionsModal()" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="preview_csv">
      <input type="hidden" name="exam_id" id="modalExamId">
      <p style="font-size:12px;color:#64748b;margin-bottom:14px">Select a PDF, CSV, or TXT file to preview and import questions into this examination.</p>
      <label style="font-size:12px;font-weight:600">Upload Question File (PDF / CSV / TXT):</label>
      <input type="file" name="csvFile" accept=".csv,.txt,.pdf" required>
      <div style="font-size:12px;color:#64748b;margin:4px 0 16px 0">Supported: Digital PDF question papers (1. Question, A. Option...), CSV, and text format.</div>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn" style="background:#e2e8f0;color:#1e293b" onclick="closeAddQuestionsModal()">Cancel</button>
        <button class="btn btn-purple" style="flex:1;justify-content:center">Preview Questions &rarr;</button>
      </div>
    </form>
  </div>
</div>

<!-- 4. MODAL FOR EDITING EXAM ACCESS CODE -->
<div id="editCodeModal" class="modal-overlay">
  <div class="modal-content" style="max-width:440px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="margin:0;color:#1565D8" id="editCodeExamTitle">Change Exam Access Code</h3>
      <button onclick="closeEditCodeModal()" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <p style="font-size:12px;color:#64748b;margin-bottom:14px">Students will need to enter this code on the portal to access and start this exam.</p>
    <form method="post">
      <input type="hidden" name="action" value="update_access_code">
      <input type="hidden" name="id" id="editCodeExamId">
      <label style="font-size:12px;font-weight:600">Access Code (Letters &amp; Numbers)</label>
      <input name="accessCode" id="editCodeInput" placeholder="e.g. TSPSC01 or POLICE02" style="font-family:monospace;font-size:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase" required>
      <div style="font-size:12px;color:#64748b;margin-top:2px">Leave blank to auto-generate a random 6-character code.</div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="button" class="btn" style="background:#e2e8f0;color:#1e293b" onclick="closeEditCodeModal()">Cancel</button>
        <button class="btn btn-blue" style="flex:1;justify-content:center">Save Access Code</button>
      </div>
    </form>
  </div>
</div>

<!-- 5. CANDIDATE PAPER & ANSWERS REVIEW MODAL -->
<div id="candidateReviewModal" class="modal-overlay">
  <div class="modal-content" style="max-width:880px;max-height:90vh;display:flex;flex-direction:column">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <div>
        <h3 style="margin:0;color:#1565D8" id="crmCandidateTitle">Candidate Examination Paper Review</h3>
        <span style="font-size:12px;color:#64748b" id="crmCandidateSubtitle">Submitted questions, chosen answers, correct answers and explanations</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button type="button" class="btn btn-green" style="padding:6px 12px;font-size:12px;font-weight:700" onclick="printCurrentCandidateReview()">🖨️ Print / Save PDF</button>
        <button type="button" class="btn" style="background:#0284c7;color:#fff;padding:6px 12px;font-size:12px;font-weight:700" onclick="downloadCurrentCandidateReviewCSV()">📥 Download CSV</button>
        <button onclick="closeCandidateReviewModal()" style="border:none;background:none;font-size:22px;cursor:pointer">&times;</button>
      </div>
    </div>

    <!-- SUMMARY METRICS BAR -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:14px;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Candidate</div>
        <div style="font-size:14px;font-weight:800;color:#1e293b" id="crmMetaName">-</div>
        <code style="font-size:12px;color:#64748b" id="crmMetaRoll">-</code>
      </div>
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Score</div>
        <div style="font-size:16px;font-weight:800;color:#0284c7" id="crmMetaScore">-</div>
      </div>
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Accuracy</div>
        <div style="font-size:16px;font-weight:800;color:#15803d" id="crmMetaAccuracy">-</div>
      </div>
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Time Taken</div>
        <div style="font-size:14px;font-weight:700;color:#334155" id="crmMetaTime">-</div>
      </div>
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Focus Integrity</div>
        <div id="crmMetaFocus">-</div>
      </div>
      <div>
        <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">Pre-Exam Photo</div>
        <div id="crmPhotoBox" style="margin-top:2px;font-size:12px;color:#64748b">
          <span id="crmNoPhotoBadge">⚪ None</span>
          <img id="crmMetaPhoto" src="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;border:1.5px solid #10b981;cursor:pointer;display:none" onclick="viewVerificationPhoto(document.getElementById('crmMetaName').innerText, document.getElementById('crmMetaRoll').innerText, this.src)" title="Click to view enlarged pre-exam photo">
        </div>
      </div>
    </div>

    <!-- FILTER BUTTONS -->
    <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap" id="crmFilterBar"></div>

    <!-- QUESTIONS LIST -->
    <div id="crmQuestionsList" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:12px;padding-right:6px"></div>
  </div>
</div>

<!-- 6. ADMIN PRE-EXAM STUDENT VERIFICATION & LIVE WEBCAM MODAL -->
<div id="adminVerificationModal" class="modal-overlay" style="display:none">
  <div class="modal-content" style="max-width:540px;text-align:center">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <div style="text-align:left">
        <h3 style="margin:0;color:#7c3aed;display:flex;align-items:center;gap:6px">
          <span>📷</span> <span id="avmTitle">Student Pre-Exam Verification</span>
        </h3>
        <div style="font-size:12px;color:#64748b;margin-top:2px">Capture or upload verification photo for examination identity</div>
      </div>
      <button type="button" onclick="closeAdminVerificationModal()" style="border:none;background:none;font-size:24px;cursor:pointer;color:#64748b">&times;</button>
    </div>

    <!-- Target Candidate Info -->
    <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;text-align:left">
      <div>
        <b style="color:#5b21b6;font-size:14px" id="avmCandName">Selected Student</b>
        <div style="font-size:12px;color:#6d28d9">Roll Number: <code id="avmCandRoll" style="font-weight:700">SW001</code></div>
      </div>
      <span id="avmStatusBadge" style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700">Optional</span>
    </div>

    <!-- Live Webcam Video Box & Snapshot Preview -->
    <div style="position:relative;width:240px;height:240px;margin:0 auto 14px;background:#0f172a;border-radius:12px;overflow:hidden;border:2px solid #7c3aed;display:flex;align-items:center;justify-content:center">
      <video id="avmWebcam" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;display:none;transform:scaleX(-1)"></video>
      <img id="avmCapturedPhoto" src="" style="width:100%;height:100%;object-fit:cover;display:none">
      
      <!-- Placeholder when camera is off -->
      <div id="avmPlaceholder" style="color:#94a3b8;font-size:13px;padding:16px;text-align:center">
        <div style="font-size:40px;margin-bottom:6px">📷</div>
        <span>Camera is currently off.<br>Click <b>Turn On Camera</b> below to test live capture.</span>
      </div>

      <!-- Face Guide Overlay -->
      <div id="avmFaceGuide" style="display:none;position:absolute;top:20px;bottom:20px;left:30px;right:30px;border:2px dashed rgba(255,255,255,0.7);border-radius:50%;pointer-events:none"></div>
    </div>

    <!-- Camera Control Buttons -->
    <div style="display:flex;gap:8px;justify-content:center;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" id="btnAvmStartCam" class="btn" style="background:#7c3aed;color:#fff;font-weight:700;font-size:12px;padding:8px 16px" onclick="startAdminWebcam()">
        📹 Turn On Camera
      </button>
      <button type="button" id="btnAvmSnap" class="btn btn-green" style="font-weight:700;font-size:12px;padding:8px 16px;display:none" onclick="captureAdminWebcamPhoto()">
        ⚡ Capture Snapshot Now
      </button>
      <button type="button" id="btnAvmRetake" class="btn" style="background:#e2e8f0;color:#1e293b;font-size:12px;padding:8px 14px;display:none" onclick="retakeAdminWebcam()">
        🔄 Retake
      </button>
    </div>

    <!-- Or Upload File Form -->
    <form method="post" enctype="multipart/form-data" id="avmSaveForm" style="border-top:1px solid #e2e8f0;padding-top:12px;text-align:left">
      <input type="hidden" name="action" value="save_candidate_photo">
      <input type="hidden" name="cand_roll" id="avmFormRoll" value="">
      <input type="hidden" name="cand_photo" id="avmFormPhotoData" value="">

      <div style="margin-bottom:10px">
        <label style="font-size:12px;font-weight:600;color:#475569">Or Upload Photo File (JPG, PNG)</label>
        <input type="file" name="cand_photo_file" accept="image/*" style="font-size:12px;padding:4px;margin-top:2px" onchange="previewAdminPhotoFile(this)">
      </div>

      <div style="display:flex;gap:8px">
        <button type="button" class="btn" style="background:#e2e8f0;color:#1e293b;flex:1" onclick="closeAdminVerificationModal()">Close</button>
        <button type="submit" id="btnAvmSave" class="btn btn-blue" style="flex:1.5;justify-content:center;font-weight:700">✓ Save Verification Photo</button>
      </div>
    </form>
  </div>
</div>

<!-- 7. PHOTO LIGHTBOX PREVIEW MODAL -->
<div id="viewPhotoModal" class="modal-overlay" style="display:none" onclick="closeViewPhotoModal()">
  <div class="modal-content" style="max-width:380px;text-align:center" onclick="event.stopPropagation()">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;border-bottom:1px solid #e2e8f0;padding-bottom:8px">
      <div style="text-align:left">
        <h4 style="margin:0;color:#1e293b" id="vpmTitle">Candidate Photo</h4>
        <div style="font-size:12px;color:#64748b" id="vpmSubtitle">SW001</div>
      </div>
      <button type="button" onclick="closeViewPhotoModal()" style="border:none;background:none;font-size:24px;cursor:pointer">&times;</button>
    </div>
    <div style="width:260px;height:260px;margin:0 auto 12px;background:#f8fafc;border-radius:10px;overflow:hidden;border:1.5px solid #cbd5e1;display:flex;align-items:center;justify-content:center">
      <img id="vpmImage" src="" style="width:100%;height:100%;object-fit:cover">
    </div>
    <div style="font-size:12px;color:#15803d;font-weight:700;margin-bottom:10px">✓ Verified Examination Identity</div>
    <button type="button" class="btn btn-blue" style="width:100%;justify-content:center" onclick="closeViewPhotoModal()">Close Preview</button>
  </div>
</div>

<!-- 8. ADMIN EDIT STUDENT MULTI-COURSE ASSIGNMENT MODAL -->
<div id="adminEditStudentCoursesModal" class="modal-overlay" style="display:none">
  <div class="modal-content" style="max-width:540px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <div>
        <h3 style="margin:0;color:#1e3a8a;font-size:16px;display:flex;align-items:center;gap:6px">
          <span>📚 Assign / Select Courses to Study</span>
        </h3>
        <p style="margin:4px 0 0 0;font-size:12px;color:#64748b" id="editCandCoursesSub">Select two or more courses for this student</p>
      </div>
      <button type="button" onclick="closeAdminEditStudentCoursesModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="update_candidate_courses">
      <input type="hidden" name="cand_username" id="editCandUsernameInput">
      <div style="margin-bottom:14px">
        <label style="font-size:12px;font-weight:700;color:#1e293b;display:flex;justify-content:space-between;margin-bottom:6px">
          <span>Enrolled Course(s) (Select 1, 2, or more) *</span>
          <span style="font-size:12px">
            <a href="javascript:void(0)" onclick="candToggleAllCourses('candEditBox', true)" style="color:#2563eb;text-decoration:none;font-weight:700">All Courses</a> | 
            <a href="javascript:void(0)" onclick="candToggleAllCourses('candEditBox', false)" style="color:#64748b;text-decoration:none">Clear</a>
          </span>
        </label>
        <div id="candEditBox" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8fafc;padding:12px;border:1px solid #cbd5e1;border-radius:8px;max-height:260px;overflow-y:auto">
          <label style="grid-column:1/-1;display:inline-flex;align-items:center;gap:6px;font-size:12px;background:#eff6ff;padding:6px 10px;border-radius:6px;border:1px solid #bfdbfe;cursor:pointer">
            <input type="checkbox" name="cand_courses[]" value="All Assessments" class="edit-course-check" onchange="candHandleAllToggle(this, 'candEditBox')">
            <b style="color:#1d4ed8">🌐 All Assessments (All Courses &amp; Exams)</b>
          </label>
          <?php foreach($courses as $c): ?>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;background:#fff;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer">
              <input type="checkbox" name="cand_courses[]" value="<?= htmlspecialchars($c['name']) ?>" class="edit-course-check" onchange="candHandleSingleToggle(this, 'candEditBox')">
              <span>📁 <?= htmlspecialchars($c['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-top:6px">
          💡 If a student studies two or more courses, select each course above. The student will be able to take tests in any of their enrolled courses.
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="btn" style="background:#e2e8f0;color:#334155;font-size:12px;font-weight:700" onclick="closeAdminEditStudentCoursesModal()">Cancel</button>
        <button type="submit" class="btn btn-blue" style="font-size:12px;font-weight:700;padding:8px 18px">✓ Save Enrolled Courses</button>
      </div>
    </form>
  </div>
</div>

<script>
const allExamsData = <?= json_encode($exams, JSON_UNESCAPED_UNICODE) ?>;
const allSubmissions = <?= json_encode($results, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function toggleInlineCourseInput(sel){
  let box = document.getElementById('inlineNewCourseBox');
  if(!box) return;
  if(sel.value === '__NEW__'){
    box.style.display = 'block';
    let inp = document.getElementById('inlineCustomCategory');
    if(inp) inp.focus();
  } else {
    box.style.display = 'none';
  }
}
function revealInlineCourseRow(){
  let sel = document.getElementById('createExamCategorySelect');
  if(!sel) return;
  sel.value = '__NEW__';
  toggleInlineCourseInput(sel);
}

function openAddQuestionsModal(examId, title){
  document.getElementById('modalExamId').value = examId;
  document.getElementById('modalExamTitle').innerText = 'Import Questions: ' + title;
  document.getElementById('addQModal').style.display = 'flex';
}
function closeAddQuestionsModal(){
  document.getElementById('addQModal').style.display = 'none';
}

function openEditCodeModal(id, title, currentCode){
  document.getElementById('editCodeExamId').value = id;
  document.getElementById('editCodeExamTitle').innerText = 'Access Code: ' + title;
  document.getElementById('editCodeInput').value = currentCode || '';
  document.getElementById('editCodeModal').style.display = 'flex';
}
function closeEditCodeModal(){
  document.getElementById('editCodeModal').style.display = 'none';
}

function openQuestionManagerModal(examId){
  const ex = allExamsData.find(e => parseInt(e.id) === parseInt(examId));
  if(!ex) return alert('Exam not found');
  
  const isHidden = (ex.result_visibility === 'hidden');
  const visBadge = isHidden 
    ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-weight:800;font-size:12px">🔒 Answers Hidden from Students</span>'
    : '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-weight:800;font-size:12px">🟢 Answers Published to Students</span>';

  document.getElementById('qmExamTitle').innerText = ex.title;
  document.getElementById('qmExamSubtitle').innerHTML = (ex.category || 'General') + ' • ' + (ex.questions ? ex.questions.length : 0) + ' Questions • Code: <code>' + (ex.accessCode || 'OPEN') + '</code> • ' + visBadge;
  document.getElementById('qmAddExamId').value = examId;
  document.getElementById('qmPublishExamId').value = examId;

  const pubBtn = document.getElementById('qmPublishBtn');
  if(pubBtn){
    if(isHidden){
      pubBtn.className = 'btn btn-green';
      pubBtn.innerHTML = '📢 Publish Answers &amp; Solutions';
      pubBtn.style.background = '';
      pubBtn.style.color = '';
      pubBtn.style.border = '';
    } else {
      pubBtn.className = 'btn';
      pubBtn.style.background = '#f1f5f9';
      pubBtn.style.color = '#475569';
      pubBtn.style.border = '1px solid #cbd5e1';
      pubBtn.innerHTML = '🔒 Hide Answers from Students';
    }
  }

  const list = document.getElementById('qmQuestionsList');
  if(!ex.questions || ex.questions.length === 0){
    list.innerHTML = '<div style="text-align:center;padding:32px;color:#64748b">No questions added yet. Use the form above to add questions or import a CSV file.</div>';
  } else {
    let html = '';
    const letters = ['A','B','C','D'];
    ex.questions.forEach((q, idx) => {
      const opts = q.opts || ['', '', '', ''];
      const correctIdx = parseInt(q.ans || 0);
      const isBlank = (q.type === 'blank' || q.topic === 'Fill in the Blanks');
      const hasImg = !!(q.image || q.img || q.image_url);
      const imgPath = hasImg ? (q.image || q.img || q.image_url) : '';
      const fullImgSrc = (imgPath.startsWith('http') || imgPath.startsWith('uploads/') || imgPath.startsWith('data:')) ? imgPath : ('uploads/' + imgPath);

      html += `
        <div class="q-card-item">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div style="flex:1;padding-right:12px">
              <span style="font-weight:800;color:#1565D8">Q${idx + 1}.</span>
              <span style="font-weight:600;color:#0f172a;margin-left:4px">${escapeHtml(q.q)}</span>
              ${q.passage ? `<div style="background:#f1f5f9;border-left:3px solid #0284c7;padding:6px 10px;border-radius:4px;margin:6px 0;font-size:12px;color:#334155;white-space:pre-wrap"><b>📖 Reference Passage:</b> ${escapeHtml(q.passage)}</div>` : ''}
              <div style="display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap">
                ${isBlank ? `<span style="background:#e0e7ff;color:#4338ca;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">⌨️ Fill in Blank</span>` : `<span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">Multiple Choice</span>`}
                ${hasImg ? `<a href="${escapeHtml(fullImgSrc)}" target="_blank" style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px">🖼️ Image Attached ↗</a>` : ''}
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0">
              <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">+${q.marks || 1} M</span>
              <button class="btn" style="padding:2px 8px;font-size:12px;background:#f8fafc;border:1px solid #cbd5e1" onclick="openEditQModal(${examId}, ${idx})">Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete question Q${idx + 1}?')">
                <input type="hidden" name="action" value="delete_single_question">
                <input type="hidden" name="exam_id" value="${examId}">
                <input type="hidden" name="q_index" value="${idx}">
                <button class="btn btn-red" style="padding:2px 8px;font-size:12px">Delete</button>
              </form>
            </div>
          </div>
          ${hasImg ? `
            <div style="margin:8px 0;background:#f8fafc;padding:6px;border-radius:6px;display:inline-block;border:1px solid #e2e8f0">
              <img src="${escapeHtml(fullImgSrc)}" alt="Diagram" style="max-height:80px;border-radius:4px;display:block">
            </div>
          ` : ''}
          ${isBlank ? `
            <div style="background:#f1f5f9;border-left:3px solid #4338ca;padding:10px 14px;border-radius:6px;margin:8px 0;font-size:12px">
              <div><b>⌨️ Accepted Typed Answer:</b> <span style="color:#16a34a;font-weight:700;font-size:13px">${escapeHtml((opts && opts[correctIdx]) ? opts[correctIdx] : (opts && opts[0] ? opts[0] : (q.correct_text || q.ans || '')))}</span></div>
              <div style="font-size:12px;color:#64748b;margin-top:3px">💡 <i>Candidate view: No options are shown. Candidates have only a typing input box.</i></div>
            </div>
          ` : `
            <div class="q-opt-grid">
              ${opts.map((opt, oIdx) => `
                <div class="q-opt ${oIdx === correctIdx ? 'correct' : ''}">
                  <b>${letters[oIdx]}.</b> ${escapeHtml(opt)} ${oIdx === correctIdx ? '✓ (Correct)' : ''}
                </div>
              `).join('')}
            </div>
          `}
          ${q.explanation ? `<div style="font-size:12px;color:#64748b;margin-top:4px">💡 <b>Explanation:</b> ${escapeHtml(q.explanation)}</div>` : ''}
        </div>
      `;
    });
    list.innerHTML = html;
  }

  document.getElementById('qManagerModal').style.display = 'flex';
}
function closeQuestionManagerModal(){
  document.getElementById('qManagerModal').style.display = 'none';
}

function openEditQModal(examId, qIndex){
  const ex = allExamsData.find(e => parseInt(e.id) === parseInt(examId));
  if(!ex || !ex.questions || !ex.questions[qIndex]) return;
  const q = ex.questions[qIndex];

  document.getElementById('eqExamId').value = examId;
  document.getElementById('eqQIndex').value = qIndex;
  document.getElementById('eqType').value = (q.type === 'blank' || q.topic === 'Fill in the Blanks') ? 'blank' : 'mcq';
  document.getElementById('eqText').value = q.q || '';
  document.getElementById('eqPassage').value = q.passage || q.paragraph || '';
  document.getElementById('eqOptA').value = (q.opts && q.opts[0]) ? q.opts[0] : '';
  document.getElementById('eqOptB').value = (q.opts && q.opts[1]) ? q.opts[1] : '';
  document.getElementById('eqOptC').value = (q.opts && q.opts[2]) ? q.opts[2] : '';
  document.getElementById('eqOptD').value = (q.opts && q.opts[3]) ? q.opts[3] : '';
  document.getElementById('eqAns').value = q.ans !== undefined ? q.ans : 0;
  document.getElementById('eqMarks').value = q.marks || 1.0;
  document.getElementById('eqExp').value = q.explanation || '';

  const img = q.image || q.img || q.image_url || '';
  document.getElementById('eqImageUrl').value = img;
  const pWrap = document.getElementById('eqImagePreviewWrap');
  const pImg = document.getElementById('eqImagePreview');
  if(img){
    const fullSrc = (img.startsWith('http') || img.startsWith('uploads/') || img.startsWith('data:')) ? img : ('uploads/' + img);
    pImg.src = fullSrc;
    pWrap.style.display = 'block';
  } else {
    pWrap.style.display = 'none';
  }

  document.getElementById('editQModal').style.display = 'flex';
}
function closeEditQModal(){
  document.getElementById('editQModal').style.display = 'none';
}

let currentReviewIndex = -1;
let currentReviewFilter = 'ALL';

function openCandidateReviewModal(idx){
  currentReviewIndex = idx;
  currentReviewFilter = 'ALL';
  const sub = allSubmissions[idx];
  if(!sub) return alert('Submission record not found.');

  document.getElementById('crmCandidateTitle').innerText = (sub.name || 'Candidate') + ' • ' + (sub.examTitle || 'Examination');
  document.getElementById('crmCandidateSubtitle').innerText = 'Submitted on: ' + (sub.timestamp || sub.date || '-');
  document.getElementById('crmMetaName').innerText = sub.name || 'Candidate';
  document.getElementById('crmMetaRoll').innerText = sub.roll || 'SW001';

  const score = parseFloat(sub.score || 0);
  const total = parseFloat(sub.totalMarks || sub.total || 10);
  const acc = sub.accuracy !== undefined ? sub.accuracy : (total > 0 ? Math.round((score/total)*100) : 0);
  document.getElementById('crmMetaScore').innerText = score + ' / ' + total;
  document.getElementById('crmMetaAccuracy').innerText = acc + '%';
  document.getElementById('crmMetaTime').innerText = sub.timeTaken || '-';

  const tabSw = parseInt(sub.tabSwitchCount || 0);
  const fsExit = parseInt(sub.fullscreenExitCount || 0);
  if(tabSw + fsExit === 0){
    document.getElementById('crmMetaFocus').innerHTML = '<span style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:700">✓ Clean (0)</span>';
  } else {
    document.getElementById('crmMetaFocus').innerHTML = `<span style="background:#fee2e2;color:#b91c1c;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:700">⚠️ ${tabSw + fsExit} Events</span>`;
  }

  const pImg = document.getElementById('crmMetaPhoto');
  const pNone = document.getElementById('crmNoPhotoBadge');
  if(sub.photo){
    pImg.src = sub.photo;
    pImg.style.display = 'block';
    if(pNone) pNone.style.display = 'none';
  } else {
    pImg.style.display = 'none';
    if(pNone) pNone.style.display = 'inline-block';
  }

  renderCandidateReviewQuestions();
  document.getElementById('candidateReviewModal').style.display = 'flex';
}

function closeCandidateReviewModal(){
  document.getElementById('candidateReviewModal').style.display = 'none';
}

// ==================== ADMIN PRE-EXAM VERIFICATION WEBCAM HANDLERS ====================
let avmWebcamStream = null;

function openAdminVerificationModal(roll, name, photo){
  document.getElementById('avmCandRoll').innerText = roll || 'SW-STUDENT';
  document.getElementById('avmCandName').innerText = name || 'Student Photo Verification';
  document.getElementById('avmFormRoll').value = roll || '';
  document.getElementById('avmFormPhotoData').value = '';

  const photoImg = document.getElementById('avmCapturedPhoto');
  const placeholder = document.getElementById('avmPlaceholder');
  const badge = document.getElementById('avmStatusBadge');

  if(photo){
    photoImg.src = photo;
    photoImg.style.display = 'block';
    placeholder.style.display = 'none';
    badge.innerText = '🟢 Verified';
    badge.style.background = '#dcfce7';
    badge.style.color = '#15803d';
  } else {
    photoImg.style.display = 'none';
    placeholder.style.display = 'block';
    badge.innerText = '⚪ Optional / Pending';
    badge.style.background = '#f1f5f9';
    badge.style.color = '#64748b';
  }

  document.getElementById('avmWebcam').style.display = 'none';
  document.getElementById('avmFaceGuide').style.display = 'none';
  document.getElementById('btnAvmStartCam').style.display = 'inline-flex';
  document.getElementById('btnAvmSnap').style.display = 'none';
  document.getElementById('btnAvmRetake').style.display = 'none';

  document.getElementById('adminVerificationModal').style.display = 'flex';
}

function closeAdminVerificationModal(){
  stopAdminWebcam();
  document.getElementById('adminVerificationModal').style.display = 'none';
}

async function startAdminWebcam(){
  const video = document.getElementById('avmWebcam');
  const photoImg = document.getElementById('avmCapturedPhoto');
  const placeholder = document.getElementById('avmPlaceholder');
  const guide = document.getElementById('avmFaceGuide');
  const btnStart = document.getElementById('btnAvmStartCam');
  const btnSnap = document.getElementById('btnAvmSnap');
  const btnRetake = document.getElementById('btnAvmRetake');

  try {
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      alert('Camera access is not supported by your browser.');
      return;
    }
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' },
      audio: false
    });
    avmWebcamStream = stream;
    video.srcObject = stream;
    video.style.display = 'block';
    photoImg.style.display = 'none';
    placeholder.style.display = 'none';
    guide.style.display = 'block';

    btnStart.style.display = 'none';
    btnSnap.style.display = 'inline-flex';
    btnRetake.style.display = 'none';
  } catch(err){
    console.error('Camera error:', err);
    alert('Could not access camera: ' + (err.message || 'Permission denied. Please ensure camera permission is granted.'));
  }
}

function captureAdminWebcamPhoto(){
  const video = document.getElementById('avmWebcam');
  const photoImg = document.getElementById('avmCapturedPhoto');
  const guide = document.getElementById('avmFaceGuide');
  const btnSnap = document.getElementById('btnAvmSnap');
  const btnRetake = document.getElementById('btnAvmRetake');

  if(!video || !avmWebcamStream) return;

  const canvas = document.createElement('canvas');
  canvas.width = 320;
  canvas.height = 320;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, 320, 320);
  const dataUrl = canvas.toDataURL('image/jpeg', 0.85);

  stopAdminWebcam();

  video.style.display = 'none';
  guide.style.display = 'none';
  photoImg.src = dataUrl;
  photoImg.style.display = 'block';

  document.getElementById('avmFormPhotoData').value = dataUrl;

  btnSnap.style.display = 'none';
  btnRetake.style.display = 'inline-flex';
}

function retakeAdminWebcam(){
  document.getElementById('avmFormPhotoData').value = '';
  startAdminWebcam();
}

function stopAdminWebcam(){
  if(avmWebcamStream){
    try {
      avmWebcamStream.getTracks().forEach(t => t.stop());
    } catch(e){}
    avmWebcamStream = null;
  }
}

function previewAdminPhotoFile(input){
  if(input.files && input.files[0]){
    const reader = new FileReader();
    reader.onload = function(e){
      const photoImg = document.getElementById('avmCapturedPhoto');
      const placeholder = document.getElementById('avmPlaceholder');
      stopAdminWebcam();
      document.getElementById('avmWebcam').style.display = 'none';
      document.getElementById('avmFaceGuide').style.display = 'none';
      photoImg.src = e.target.result;
      photoImg.style.display = 'block';
      placeholder.style.display = 'none';
      document.getElementById('avmFormPhotoData').value = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function viewVerificationPhoto(name, roll, photo){
  if(!photo) return;
  document.getElementById('vpmTitle').innerText = name || 'Candidate Photo';
  document.getElementById('vpmSubtitle').innerText = roll || 'Verification';
  document.getElementById('vpmImage').src = photo;
  document.getElementById('viewPhotoModal').style.display = 'flex';
}

function closeViewPhotoModal(){
  document.getElementById('viewPhotoModal').style.display = 'none';
}

function renderCandidateReviewQuestions(){
  const sub = allSubmissions[currentReviewIndex];
  if(!sub) return;

  let solutions = sub.solutions || [];

  // Fallback if solutions array was not cached: construct from exam questions & userAnswers
  if(!solutions.length && sub.examId){
    const targetExam = allExamsData.find(e => parseInt(e.id) === parseInt(sub.examId));
    if(targetExam && targetExam.questions){
      const uAnswers = sub.userAnswers || {};
      solutions = targetExam.questions.map((q, qIdx) => {
        const uAns = (uAnswers[qIdx] !== undefined && uAnswers[qIdx] !== null) 
          ? (typeof uAnswers[qIdx] === 'object' ? uAnswers[qIdx].selected : uAnswers[qIdx])
          : null;
        const correctIdx = parseInt(q.ans || 0);
        const letters = ['A','B','C','D'];
        return {
          num: qIdx + 1,
          q: q.q,
          opts: q.opts || [],
          userAns: uAns,
          correctAns: letters[correctIdx] || 'A',
          isCorrect: (uAns !== null && parseInt(uAns) === correctIdx),
          explanation: q.explanation || '',
          topic: q.topic || targetExam.title
        };
      });
    }
  }

  const total = solutions.length;
  let correctCount = 0;
  let incorrectCount = 0;
  let unansCount = 0;

  solutions.forEach(s => {
    if(s.userAns === null || s.userAns === undefined){
      unansCount++;
    } else if(s.isCorrect){
      correctCount++;
    } else {
      incorrectCount++;
    }
  });

  // Render Filter Bar
  const fBar = document.getElementById('crmFilterBar');
  fBar.innerHTML = `
    <button type="button" class="btn" style="padding:4px 10px;font-size:12px;font-weight:700;${currentReviewFilter==='ALL'?'background:#1565D8;color:#fff':'background:#f1f5f9;color:#334155'}" onclick="setReviewFilter('ALL')">All Questions (${total})</button>
    <button type="button" class="btn" style="padding:4px 10px;font-size:12px;font-weight:700;${currentReviewFilter==='CORRECT'?'background:#15803d;color:#fff':'background:#dcfce7;color:#15803d'}" onclick="setReviewFilter('CORRECT')">✓ Correct (${correctCount})</button>
    <button type="button" class="btn" style="padding:4px 10px;font-size:12px;font-weight:700;${currentReviewFilter==='INCORRECT'?'background:#dc2626;color:#fff':'background:#fee2e2;color:#b91c1c'}" onclick="setReviewFilter('INCORRECT')">✗ Incorrect (${incorrectCount})</button>
    <button type="button" class="btn" style="padding:4px 10px;font-size:12px;font-weight:700;${currentReviewFilter==='UNANSWERED'?'background:#475569;color:#fff':'background:#f1f5f9;color:#475569'}" onclick="setReviewFilter('UNANSWERED')">⚪ Unanswered (${unansCount})</button>
  `;

  // Filter questions
  let displayed = solutions.filter(s => {
    const isUn = (s.userAns === null || s.userAns === undefined);
    if(currentReviewFilter === 'CORRECT') return !isUn && s.isCorrect;
    if(currentReviewFilter === 'INCORRECT') return !isUn && !s.isCorrect;
    if(currentReviewFilter === 'UNANSWERED') return isUn;
    return true;
  });

  const listEl = document.getElementById('crmQuestionsList');
  if(!displayed.length){
    listEl.innerHTML = '<div style="text-align:center;padding:32px;color:#64748b">No questions match this filter.</div>';
    return;
  }

  const optLetters = ['A', 'B', 'C', 'D'];
  let html = '';

  displayed.forEach(s => {
    const isUnans = (s.userAns === null || s.userAns === undefined);
    const correctLetter = (s.correctAns || 'A').toUpperCase();
    const correctIndex = optLetters.indexOf(correctLetter) !== -1 ? optLetters.indexOf(correctLetter) : 0;
    const userIndex = isUnans ? null : parseInt(s.userAns);

    let statusBadge = '';
    let cardBorder = '#e2e8f0';

    if(isUnans){
      statusBadge = '<span style="background:#f1f5f9;color:#475569;padding:3px 8px;border-radius:4px;font-weight:700;font-size:12px">⚪ Unanswered</span>';
    } else if(s.isCorrect){
      statusBadge = '<span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:4px;font-weight:700;font-size:12px">✓ Correct (+1 Mark)</span>';
      cardBorder = '#86efac';
    } else {
      statusBadge = '<span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:4px;font-weight:700;font-size:12px">✗ Incorrect (0 Marks)</span>';
      cardBorder = '#fca5a5';
    }

    html += `
      <div style="background:#fff;border:1px solid ${cardBorder};border-radius:8px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,0.04)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div style="font-size:12px;font-weight:800;color:#1e3a8a">Question ${s.num} <span style="font-weight:600;color:#64748b">(${escapeHtml(s.topic || 'General')})</span></div>
          ${statusBadge}
        </div>
        <div style="font-size:14px;font-weight:600;color:#0f172a;margin-bottom:12px;line-height:1.5">${escapeHtml(s.q)}</div>
        
        <div style="display:flex;flex-direction:column;gap:6px">
    `;

    const isBlank = (s.topic === 'Fill in the Blanks' || s.type === 'blank' || (s.q && s.q.includes('__________')));
    if(isBlank){
      const correctTxt = (s.opts && s.opts[0]) ? s.opts[0] : (s.correctText || s.correctAns || '');
      html += `
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;margin-bottom:4px">
          <div style="font-size:12.5px;margin-bottom:6px">
            <b>Candidate Typed Answer:</b> 
            <span style="font-weight:700;color:${s.isCorrect ? '#15803d' : '#b91c1c'};font-size:13px">${isUnans ? '<i>None (Blank)</i>' : escapeHtml(s.userAns)}</span>
            ${isUnans ? '' : (s.isCorrect ? ' <span style="color:#15803d;font-weight:800;font-size:12px">✓ Correct</span>' : ' <span style="color:#b91c1c;font-weight:800;font-size:12px">✗ Wrong</span>')}
          </div>
          <div style="font-size:12.5px;color:#1e293b">
            <b>Expected Correct Answer:</b> <span style="color:#15803d;font-weight:700;font-size:13px">${escapeHtml(correctTxt)}</span>
          </div>
        </div>
      `;
    } else {
      (s.opts || []).forEach((opt, oIdx) => {
        const isChosen = (userIndex === oIdx);
        const isCorrectOpt = (correctIndex === oIdx);

        let optBg = '#f8fafc';
        let optBorder = '#e2e8f0';
        let optColor = '#334155';
        let tag = '';

        if(isChosen && isCorrectOpt){
          optBg = '#dcfce7';
          optBorder = '#15803d';
          optColor = '#14532d';
          tag = '<span style="color:#15803d;font-weight:800;margin-left:auto;font-size:12px">✓ Candidate Answer (Correct)</span>';
        } else if(isChosen && !isCorrectOpt){
          optBg = '#fee2e2';
          optBorder = '#dc2626';
          optColor = '#7f1d1d';
          tag = '<span style="color:#dc2626;font-weight:800;margin-left:auto;font-size:12px">✗ Candidate Answer (Wrong)</span>';
        } else if(isCorrectOpt){
          optBg = '#ecfdf5';
          optBorder = '#86efac';
          optColor = '#065f46';
          tag = '<span style="color:#059669;font-weight:800;margin-left:auto;font-size:12px">✓ Correct Answer Key</span>';
        }

        html += `
          <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:${optBg};border:1px solid ${optBorder};border-radius:6px;font-size:13px;color:${optColor}">
            <b style="min-width:20px">${optLetters[oIdx]}.</b>
            <span style="flex:1">${escapeHtml(opt)}</span>
            ${tag}
          </div>
        `;
      });
    }

    html += `</div>`;

    if(s.explanation){
      html += `
        <div style="margin-top:10px;padding:8px 12px;background:#f0f9ff;border-left:3px solid #0284c7;border-radius:4px;font-size:12px;color:#0369a1">
          <b>Explanation:</b> ${escapeHtml(s.explanation)}
        </div>
      `;
    }

    html += `</div>`;
  });

  listEl.innerHTML = html;
}

function setReviewFilter(f){
  currentReviewFilter = f;
  renderCandidateReviewQuestions();
}

let resultsExpanded = false;

function applyResultsPagination(){
  const searchInput = document.getElementById('resultSearchInput');
  const q = searchInput ? searchInput.value.toLowerCase().trim() : '';
  const rows = Array.from(document.querySelectorAll('#resultsTable .result-row'));
  const paginationBar = document.getElementById('resultsPaginationBar');
  const toggleBtnText = document.getElementById('btnToggleResultsText');
  const countLabel = document.getElementById('resultsCountLabel');

  if(rows.length === 0){
    if(paginationBar) paginationBar.style.display = 'none';
    return;
  }

  const matchingRows = [];
  rows.forEach(r => {
    const text = r.innerText.toLowerCase();
    if(!q || text.includes(q)){
      matchingRows.push(r);
    } else {
      r.style.display = 'none';
    }
  });

  const totalMatching = matchingRows.length;

  if(q){
    matchingRows.forEach(r => r.style.display = '');
    if(paginationBar) paginationBar.style.display = 'none';
    return;
  }

  if(totalMatching <= 10){
    matchingRows.forEach(r => r.style.display = '');
    if(paginationBar) paginationBar.style.display = 'none';
    return;
  }

  if(paginationBar) paginationBar.style.display = 'flex';

  if(resultsExpanded){
    matchingRows.forEach(r => r.style.display = '');
    if(toggleBtnText) toggleBtnText.innerHTML = `▲ Show Less (Show First 10)`;
    if(countLabel) countLabel.innerText = `Showing all ${totalMatching} submissions`;
  } else {
    matchingRows.forEach((r, idx) => {
      r.style.display = idx < 10 ? '' : 'none';
    });
    if(toggleBtnText) toggleBtnText.innerHTML = `👁️ Show More Submissions (Showing 10 of ${totalMatching}) ▼`;
    if(countLabel) countLabel.innerText = `Remaining ${totalMatching - 10} hidden`;
  }
}

function toggleResultsShowMore(){
  resultsExpanded = !resultsExpanded;
  applyResultsPagination();
}

function filterResultsTable(){
  applyResultsPagination();
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function printStudentScorecard(idx){
  const sub = allSubmissions[idx];
  if(!sub) return alert('Result record not found.');
  
  const score = parseFloat(sub.score || 0);
  const total = parseFloat(sub.totalMarks || sub.total || 10);
  const acc = sub.accuracy !== undefined ? sub.accuracy : (total > 0 ? Math.round((score/total)*100) : 0);
  const tabSw = parseInt(sub.tabSwitchCount || 0);
  const fsExit = parseInt(sub.fullscreenExitCount || 0);
  const totInt = tabSw + fsExit;
  const rank = sub.allIndiaRank || sub.rank || (idx + 1);

  const printWin = window.open('', '_blank', 'width=880,height=800');
  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Scorecard - ${escapeHtml(sub.name || 'Candidate')} (${escapeHtml(sub.roll || '-')})</title>
      <style>
        body { font-family: Arial, sans-serif; padding: 28px; color: #1e293b; background: #fff; line-height: 1.5; font-size: 14px; }
        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #2563eb; padding-bottom: 16px; margin-bottom: 20px; }
        .logo-title { font-size: 22px; font-weight: 800; color: #1e3a8a; }
        .doc-badge { background: #eff6ff; color: #1d4ed8; padding: 6px 14px; border-radius: 6px; font-weight: 700; font-size: 13px; border: 1px solid #bfdbfe; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .grid-item { font-size: 13px; }
        .grid-item b { color: #0f172a; }
        .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
        .metric-card { background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 14px; text-align: center; }
        .metric-val { font-size: 20px; font-weight: 800; margin-top: 4px; }
        .metric-lbl { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .green { color: #15803d; }
        .blue { color: #0284c7; }
        .red { color: #dc2626; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 12px; color: #64748b; }
        .no-print { margin-bottom: 20px; }
        .btn { padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 14px; }
        @media print { .no-print { display: none !important; } body { padding: 0; } }
      </style>
    </head>
    <body>
      <div class="no-print">
        <button class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <button class="btn" style="background:#64748b;margin-left:8px" onclick="window.close()">Close Window</button>
      </div>
      <div class="header">
        <div style="display:flex;align-items:center;gap:14px">
          <img src="logo.jpg" alt="Server World" style="height:54px;width:auto;object-fit:contain">
          <div>
            <div class="logo-title" style="color:#104085;font-weight:900">Server World</div>
            <div style="font-size:13px;color:#ea580c;font-weight:700;letter-spacing:0.3px">Online Exam • Official Candidate Scorecard</div>
          </div>
        </div>
        <div class="doc-badge">✓ Verified Submission</div>
      </div>
      <div class="grid">
        <div class="grid-item">Candidate Name: <b>${escapeHtml(sub.name || 'Candidate')}</b></div>
        <div class="grid-item">Roll Number / URN: <b>${escapeHtml(sub.roll || '-')}</b></div>
        <div class="grid-item">Examination Title: <b>${escapeHtml(sub.examTitle || 'Online Assessment')}</b></div>
        <div class="grid-item">Access Code: <b>${escapeHtml(sub.accessCode || '-')}</b></div>
        <div class="grid-item">Submission Date: <b>${escapeHtml(sub.timestamp || sub.date || '-')}</b></div>
        <div class="grid-item">Proctoring Focus Status: <b>${totInt === 0 ? '✓ Clean (0 interruptions)' : '⚠️ ' + totInt + ' Interruption(s)'}</b></div>
      </div>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-lbl">Score Obtained</div>
          <div class="metric-val ${acc >= 60 ? 'green' : 'red'}">${score} / ${total}</div>
        </div>
        <div class="metric-card">
          <div class="metric-lbl">Accuracy</div>
          <div class="metric-val ${acc >= 60 ? 'green' : 'red'}">${acc}%</div>
        </div>
        <div class="metric-card">
          <div class="metric-lbl">Overall Rank</div>
          <div class="metric-val blue">#${rank}</div>
        </div>
        <div class="metric-card">
          <div class="metric-lbl">Time Taken</div>
          <div class="metric-val" style="color:#0f172a">${escapeHtml(sub.timeTaken || '-')}</div>
        </div>
      </div>
      <div class="footer">
        <div>Generated by Server World CBT Examination System</div>
        <div>Official Record ID: ${escapeHtml(sub.attemptId || 'ATT-' + Date.now())}</div>
      </div>
      <script>
        window.onload = function(){ setTimeout(function(){ window.print(); }, 400); };
      <\/script>
    </body>
    </html>
  `);
  printWin.document.close();
}

function printCurrentCandidateReview(){
  if(currentReviewIndex < 0 || !allSubmissions[currentReviewIndex]) return;
  const sub = allSubmissions[currentReviewIndex];
  const listHtml = document.getElementById('crmQuestionsList').innerHTML;
  const printWin = window.open('', '_blank', 'width=900,height=800');
  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Answers Review - ${escapeHtml(sub.name || 'Candidate')}</title>
      <style>
        body { font-family: Arial, sans-serif; padding: 24px; color: #1e293b; background: #fff; line-height: 1.5; font-size: 14px; }
        .no-print { margin-bottom: 20px; }
        .btn { padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; }
        @media print { .no-print { display: none !important; } }
      </style>
    </head>
    <body>
      <div class="no-print">
        <button class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <button class="btn" style="background:#64748b;margin-left:8px" onclick="window.close()">Close</button>
      </div>
      <div style="border-bottom:2px solid #2563eb;padding-bottom:12px;margin-bottom:16px">
        <h2 style="margin:0;color:#1e3a8a">Server World - Candidate Examination Paper &amp; Answers Review</h2>
        <div style="font-size:13px;color:#64748b;margin-top:4px">
          Candidate: <b>${escapeHtml(sub.name || 'Candidate')}</b> (${escapeHtml(sub.roll || '-')}) • Exam: <b>${escapeHtml(sub.examTitle || 'Online Exam')}</b> • Score: <b>${sub.score || 0}/${sub.totalMarks || sub.total || 10}</b>
        </div>
      </div>
      <div>${listHtml}</div>
      <script>
        window.onload = function(){ setTimeout(function(){ window.print(); }, 400); };
      <\/script>
    </body>
    </html>
  `);
  printWin.document.close();
}

function downloadCurrentCandidateReviewCSV(){
  if(currentReviewIndex < 0 || !allSubmissions[currentReviewIndex]) return alert('No submission selected.');
  const sub = allSubmissions[currentReviewIndex];
  const attemptId = sub.attemptId || '';
  const roll = sub.roll || '';
  const examId = sub.examId || '';
  location.href = 'admin.php?action=export_results_excel&attempt_id=' + encodeURIComponent(attemptId) + '&roll=' + encodeURIComponent(roll) + '&exam_id=' + encodeURIComponent(examId) + '&idx=' + encodeURIComponent(currentReviewIndex);
}

// ===== CANDIDATE COURSE FOLDERS & BULK IMPORT SCRIPTS =====
function switchCandCreateTab(tabId){
  const tabs = ['import', 'single', 'bulk'];
  tabs.forEach(t => {
    const pane = document.getElementById('candPane' + t.charAt(0).toUpperCase() + t.slice(1));
    const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
    if(pane) pane.style.display = (t === tabId) ? 'block' : 'none';
    if(btn) {
      if(t === tabId) btn.classList.add('active');
      else btn.classList.remove('active');
    }
  });
}

function insertSampleStudentData(){
  const ta = document.getElementById('importStudentTextArea');
  if(!ta) return;
  ta.value = "Rahul Sharma, SW101, NEET\n" +
             "Priya Patel, SW102, TSPSC Groups\n" +
             "Kavita Reddy, SW103, NEET\n" +
             "Suresh Kumar, POL-01, Telangana Police\n" +
             "Anil Varma, CS-501, Computer Science";
}

let currentSelectedFolder = 'all';

function selectCourseFolder(courseName, btn){
  currentSelectedFolder = courseName;
  document.querySelectorAll('.cand-folder-pill').forEach(b => b.classList.remove('active'));
  if(btn) btn.classList.add('active');

  const exportLink = document.getElementById('btnExportFolderCsv');
  if(exportLink){
    exportLink.href = 'admin.php?action=export_candidates_csv&course=' + encodeURIComponent(courseName);
  }

  filterCandidatesFolder();
}

let candidatesExpanded = false;

function applyCandidatesPagination(){
  const query = (document.getElementById('candidateSearchInput')?.value || '').toLowerCase().trim();
  const rows = Array.from(document.querySelectorAll('.candidate-row'));
  const paginationBar = document.getElementById('candidatesPaginationBar');
  const toggleBtnText = document.getElementById('btnToggleCandidatesText');
  const countLabel = document.getElementById('candidatesCountLabel');

  const matchingRows = [];
  rows.forEach(row => {
    const rowCourseStr = (row.getAttribute('data-course') || '');
    const rowCourses = rowCourseStr.toLowerCase().split('|').map(s => s.trim());
    const targetFolder = currentSelectedFolder.toLowerCase();
    const rowName = (row.getAttribute('data-name') || '');
    const rowRoll = (row.getAttribute('data-roll') || '');

    const matchesFolder = (currentSelectedFolder === 'all' || 
      rowCourses.includes(targetFolder) || 
      rowCourses.some(rc => rc.includes(targetFolder) || targetFolder.includes(rc)) ||
      (targetFolder === 'all assessments' && rowCourses.includes('all assessments')));
    const matchesQuery = !query || rowName.includes(query) || rowRoll.includes(query);

    if(matchesFolder && matchesQuery){
      matchingRows.push(row);
    } else {
      row.style.display = 'none';
    }
  });

  const visibleCount = matchingRows.length;
  const counter = document.getElementById('candFolderCounterDisplay');
  if(counter){
    const folderLabel = currentSelectedFolder === 'all' ? 'All Course Folders' : `Course Folder "${currentSelectedFolder}"`;
    counter.innerText = `Showing ${visibleCount} student(s) in ${folderLabel}`;
  }

  if(query || visibleCount <= 10){
    matchingRows.forEach(r => r.style.display = '');
    if(paginationBar) paginationBar.style.display = 'none';
    return;
  }

  if(paginationBar) paginationBar.style.display = 'flex';

  if(candidatesExpanded){
    matchingRows.forEach(r => r.style.display = '');
    if(toggleBtnText) toggleBtnText.innerHTML = `▲ Show Less (Show First 10)`;
    if(countLabel) countLabel.innerText = `Showing all ${visibleCount} students`;
  } else {
    matchingRows.forEach((r, idx) => {
      r.style.display = idx < 10 ? '' : 'none';
    });
    if(toggleBtnText) toggleBtnText.innerHTML = `👁️ Show More Students (Showing 10 of ${visibleCount}) ▼`;
    if(countLabel) countLabel.innerText = `Remaining ${visibleCount - 10} hidden`;
  }
}

function toggleCandidatesShowMore(){
  candidatesExpanded = !candidatesExpanded;
  applyCandidatesPagination();
}

function filterCandidatesFolder(){
  applyCandidatesPagination();
}

function printFolderStudentSlips(){
  const rows = Array.from(document.querySelectorAll('.candidate-row')).filter(r => r.style.display !== 'none');
  if(rows.length === 0){
    alert('No candidates found in the selected folder to print.');
    return;
  }

  let cardsHtml = '';
  rows.forEach(r => {
    const name = r.querySelector('td:nth-child(1) b')?.innerText || 'Candidate';
    const roll = r.querySelector('td:nth-child(2) code')?.innerText || '-';
    const pass = r.querySelector('td:nth-child(3) span')?.innerText || '-';
    const course = r.getAttribute('data-course') || 'General';

    cardsHtml += `
      <div class="slip-card">
        <div class="slip-header">
          <div class="slip-brand">Server World Online Exam</div>
          <div class="slip-course">${escapeHtml(course)}</div>
        </div>
        <div class="slip-body">
          <div class="slip-field"><span class="lbl">Student Name:</span> <b class="val">${escapeHtml(name)}</b></div>
          <div class="slip-field"><span class="lbl">Roll No / User:</span> <b class="val code">${escapeHtml(roll)}</b></div>
          <div class="slip-field"><span class="lbl">Access Password:</span> <b class="val pass">${escapeHtml(pass)}</b></div>
        </div>
        <div class="slip-footer">
          <span>Portal: http://localhost/exam/</span>
          <span>Keep credentials confidential</span>
        </div>
      </div>
    `;
  });

  const printWin = window.open('', '_blank', 'width=950,height=800');
  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Candidate Login Credentials Slips</title>
      <style>
        body { font-family: Arial, sans-serif; padding: 20px; color: #1e293b; background: #fff; line-height: 1.4; font-size: 13px; margin: 0; }
        .no-print { margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #cbd5e1; }
        .btn { padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .slips-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .slip-card { border: 1.5px dashed #0284c7; border-radius: 8px; padding: 14px 16px; background: #fafafa; page-break-inside: avoid; }
        .slip-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 10px; }
        .slip-brand { font-weight: 900; color: #104085; font-size: 14px; }
        .slip-course { background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 800; border: 1px solid #bae6fd; }
        .slip-body { display: grid; grid-template-columns: 1fr; gap: 6px; font-size: 13px; }
        .slip-field { display: flex; justify-content: space-between; }
        .lbl { color: #64748b; font-size: 12px; }
        .val { color: #0f172a; }
        .val.code { font-family: monospace; color: #1d4ed8; font-size: 14px; }
        .val.pass { font-family: monospace; color: #92400e; font-size: 14px; background: #fef3c7; padding: 1px 6px; border-radius: 4px; }
        .slip-footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; }
        @media print {
          .no-print { display: none !important; }
          body { padding: 0; }
          .slip-card { border-color: #94a3b8; }
        }
      </style>
    </head>
    <body>
      <div class="no-print">
        <button class="btn" onclick="window.print()">🖨️ Print Login Slips</button>
        <button class="btn" style="background:#64748b;margin-left:8px" onclick="window.close()">Close</button>
        <span style="margin-left:14px;font-size:13px;color:#475569">Total Slips: <b>${rows.length}</b></span>
      </div>
      <div class="slips-grid">${cardsHtml}</div>
      <script>
        window.onload = function(){ setTimeout(function(){ window.print(); }, 400); };
      <\/script>
    </body>
    </html>
  `);
  printWin.document.close();
}

function downloadCurrentCandidateReviewCSV(){
  if(currentReviewIndex < 0 || !allSubmissions[currentReviewIndex]) return alert('No submission selected.');
  const sub = allSubmissions[currentReviewIndex];
  const attemptId = sub.attemptId || '';
  const roll = sub.roll || '';
  const examId = sub.examId || '';
  location.href = 'admin.php?action=export_results_excel&attempt_id=' + encodeURIComponent(attemptId) + '&roll=' + encodeURIComponent(roll) + '&exam_id=' + encodeURIComponent(examId) + '&idx=' + encodeURIComponent(currentReviewIndex);
}

function printImportedSlips(){
  if(!window.lastImportedStudents || window.lastImportedStudents.length === 0){
    printFolderStudentSlips();
    return;
  }
  let cardsHtml = '';
  window.lastImportedStudents.forEach(p => {
    const name = p.name || 'Candidate';
    const roll = p.roll || p.username || '-';
    const pass = p.password || '-';
    const course = p.assigned_exam_title || 'General';

    cardsHtml += `
      <div class="slip-card">
        <div class="slip-header">
          <div class="slip-brand">Server World Online Exam</div>
          <div class="slip-course">${escapeHtml(course)}</div>
        </div>
        <div class="slip-body">
          <div class="slip-field"><span class="lbl">Student Name:</span> <b class="val">${escapeHtml(name)}</b></div>
          <div class="slip-field"><span class="lbl">Roll No / User:</span> <b class="val code">${escapeHtml(roll)}</b></div>
          <div class="slip-field"><span class="lbl">Access Password:</span> <b class="val pass">${escapeHtml(pass)}</b></div>
        </div>
        <div class="slip-footer">
          <span>Portal: http://localhost/exam/</span>
          <span>Keep credentials confidential</span>
        </div>
      </div>
    `;
  });

  const printWin = window.open('', '_blank', 'width=950,height=800');
  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Newly Imported Student Login Slips</title>
      <style>
        body { font-family: Arial, sans-serif; padding: 20px; color: #1e293b; background: #fff; line-height: 1.4; font-size: 13px; margin: 0; }
        .no-print { margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #cbd5e1; }
        .btn { padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .slips-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .slip-card { border: 1.5px dashed #0284c7; border-radius: 8px; padding: 14px 16px; background: #fafafa; page-break-inside: avoid; }
        .slip-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 10px; }
        .slip-brand { font-weight: 900; color: #104085; font-size: 14px; }
        .slip-course { background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 800; border: 1px solid #bae6fd; }
        .slip-body { display: grid; grid-template-columns: 1fr; gap: 6px; font-size: 13px; }
        .slip-field { display: flex; justify-content: space-between; }
        .lbl { color: #64748b; font-size: 12px; }
        .val { color: #0f172a; }
        .val.code { font-family: monospace; color: #1d4ed8; font-size: 14px; }
        .val.pass { font-family: monospace; color: #92400e; font-size: 14px; background: #fef3c7; padding: 1px 6px; border-radius: 4px; }
        .slip-footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; }
        @media print {
          .no-print { display: none !important; }
          body { padding: 0; }
          .slip-card { border-color: #94a3b8; }
        }
      </style>
    </head>
    <body>
      <div class="no-print">
        <button class="btn" onclick="window.print()">🖨️ Print Login Slips</button>
        <button class="btn" style="background:#64748b;margin-left:8px" onclick="window.close()">Close</button>
        <span style="margin-left:14px;font-size:13px;color:#475569">Total Slips: <b>${window.lastImportedStudents.length}</b></span>
      </div>
      <div class="slips-grid">${cardsHtml}</div>
      <script>
        window.onload = function(){ setTimeout(function(){ window.print(); }, 400); };
      <\/script>
    </body>
    </html>
  `);
  printWin.document.close();
}

function downloadCurrentCandidateReviewCSV(){
  if(currentReviewIndex < 0 || !allSubmissions[currentReviewIndex]) return alert('No submission selected.');
  const sub = allSubmissions[currentReviewIndex];
  const attemptId = sub.attemptId || '';
  const roll = sub.roll || '';
  const examId = sub.examId || '';
  location.href = 'admin.php?action=export_results_excel&attempt_id=' + encodeURIComponent(attemptId) + '&roll=' + encodeURIComponent(roll) + '&exam_id=' + encodeURIComponent(examId) + '&idx=' + encodeURIComponent(currentReviewIndex);
}

function openAdminEditStudentCoursesModal(username, name, coursesList){
  document.getElementById('editCandUsernameInput').value = username;
  document.getElementById('editCandCoursesSub').innerText = 'Student: ' + name + ' (' + username + ')';
  const box = document.getElementById('candEditBox');
  if(box){
    const checks = box.querySelectorAll('input[name="cand_courses[]"]');
    const isAll = !coursesList || coursesList.length === 0 || coursesList.includes('All Assessments') || coursesList.includes('all');
    checks.forEach(chk => {
      if(chk.value === 'All Assessments'){
        chk.checked = isAll;
      } else {
        chk.checked = !isAll && coursesList.includes(chk.value);
      }
    });
  }
  document.getElementById('adminEditStudentCoursesModal').style.display = 'flex';
}

function closeAdminEditStudentCoursesModal(){
  document.getElementById('adminEditStudentCoursesModal').style.display = 'none';
}

function candToggleAllCourses(boxId, selectAll){
  const box = document.getElementById(boxId);
  if(!box) return;
  const checks = box.querySelectorAll('input[name="cand_courses[]"]');
  checks.forEach(chk => {
    if(chk.value === 'All Assessments'){
      chk.checked = selectAll;
    } else {
      chk.checked = false;
    }
  });
}

function candHandleAllToggle(allCheck, boxId){
  const box = document.getElementById(boxId);
  if(!box) return;
  const checks = box.querySelectorAll('input[name="cand_courses[]"]');
  if(allCheck.checked){
    checks.forEach(chk => {
      if(chk !== allCheck) chk.checked = false;
    });
  }
}

function candHandleSingleToggle(singleCheck, boxId){
  const box = document.getElementById(boxId);
  if(!box) return;
  const allCheck = box.querySelector('input[value="All Assessments"]');
  if(singleCheck.checked && allCheck){
    allCheck.checked = false;
  }
  const checked = box.querySelectorAll('input[name="cand_courses[]"]:checked');
  if(checked.length === 0 && allCheck){
    allCheck.checked = true;
  }
}

window.onclick = function(e){
  const modals = ['addQModal', 'editCodeModal', 'qManagerModal', 'editQModal', 'candidateReviewModal', 'adminVerificationModal', 'viewPhotoModal', 'adminEditStudentCoursesModal'];
  modals.forEach(mId => {
    const m = document.getElementById(mId);
    if(m && e.target === m) m.style.display = 'none';
  });
};

// Initialize show more / show less pagination on load
document.addEventListener('DOMContentLoaded', function(){
  applyResultsPagination();
  applyCandidatesPagination();
});
if(document.readyState !== 'loading'){
  applyResultsPagination();
  applyCandidatesPagination();
}
</script>
</body>
</html>
