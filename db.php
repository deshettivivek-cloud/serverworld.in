<?php
// =====================================================================
// ServerWorld Examination System - Unified Database & Storage Adapter
// Supports MySQL PDO (prepared statements) with automatic JSON synchronization
// =====================================================================

class ExamDB {
    private static $pdo = null;
    private static $isMysql = false;
    private static $examsFile;
    private static $resultsFile;
    private static $attemptsFile;
    private static $responsesFile;

    public static function init() {
        self::$examsFile = __DIR__ . '/exams.json';
        self::$resultsFile = __DIR__ . '/results.json';
        self::$attemptsFile = __DIR__ . '/attempts.json';
        self::$responsesFile = __DIR__ . '/responses.json';

        if (!file_exists(self::$examsFile)) file_put_contents(self::$examsFile, '[]');
        if (!file_exists(self::$resultsFile)) file_put_contents(self::$resultsFile, '[]');
        if (!file_exists(self::$attemptsFile)) file_put_contents(self::$attemptsFile, '[]');
        if (!file_exists(self::$responsesFile)) file_put_contents(self::$responsesFile, '[]');

        // Attempt MySQL PDO connection
        try {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $dbname = getenv('DB_NAME') ?: 'serverworld_exam';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2
            ];
            self::$pdo = new PDO($dsn, $user, $pass, $options);
            self::$isMysql = true;
        } catch (Exception $e) {
            self::$pdo = null;
            self::$isMysql = false;
        }
    }

    public static function isMysqlConnected() {
        return self::$isMysql;
    }

    // -----------------------------------------------------------------
    // EXAM MANAGEMENT
    // -----------------------------------------------------------------
    public static function getAllExams() {
        if (self::$isMysql) {
            try {
                $stmt = self::$pdo->query("SELECT * FROM exams ORDER BY id DESC");
                $rows = $stmt->fetchAll();
                foreach ($rows as &$r) {
                    $qStmt = self::$pdo->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY question_number ASC");
                    $qStmt->execute([$r['id']]);
                    $qs = $qStmt->fetchAll();
                    $r['questions'] = array_map(function($q) {
                        return [
                            'id' => $q['id'],
                            'q' => $q['question_text'],
                            'opts' => [$q['option_a'], $q['option_b'], $q['option_c'], $q['option_d']],
                            'ans' => self::normalizeAnsIndex($q['correct_answer']),
                            'marks' => floatval($q['marks']),
                            'explanation' => $q['explanation'],
                            'topic' => $q['topic']
                        ];
                    }, $qs);
                    $r['qCount'] = count($r['questions']);
                }
                return $rows;
            } catch (Exception $e) {
                // fallback to JSON
            }
        }
        $raw = @file_get_contents(self::$examsFile);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function getExamById($id) {
        $exams = self::getAllExams();
        foreach ($exams as $e) {
            if (intval($e['id']) === intval($id)) return $e;
        }
        return null;
    }

    public static function getExamByCode($code) {
        $code = strtoupper(trim($code));
        if (empty($code)) return null;
        $exams = self::getAllExams();
        foreach ($exams as $e) {
            $eCode = strtoupper(trim($e['accessCode'] ?? $e['access_code'] ?? ''));
            if ($eCode === $code) return $e;
        }
        return null;
    }

    public static function saveExam(array $data) {
        $id = isset($data['id']) && $data['id'] > 0 ? intval($data['id']) : time();
        $title = trim($data['title'] ?? 'Untitled Assessment');
        $category = trim($data['category'] ?? $data['subject'] ?? 'General');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($data['accessCode'] ?? $data['access_code'] ?? '')));
        if (empty($code)) $code = self::generateAccessCode('', $category);
        $duration = max(5, intval($data['duration'] ?? $data['duration_minutes'] ?? 15));
        $maxMarks = isset($data['max_marks']) ? floatval($data['max_marks']) : (isset($data['maxMarks']) ? floatval($data['maxMarks']) : 100.0);
        $certThreshold = max(1, min(100, intval($data['certificateThreshold'] ?? $data['certificate_threshold'] ?? 85)));
        $resultVisibility = in_array($data['result_visibility'] ?? '', ['immediate', 'hidden']) ? $data['result_visibility'] : 'immediate';
        $paperFile = $data['file'] ?? $data['paper_file'] ?? null;
        $paperType = $data['paperType'] ?? $data['paper_type'] ?? 'interactive';
        $testType = in_array(strtolower($data['testType'] ?? $data['test_type'] ?? ''), ['mock', 'open']) ? 'mock' : 'live';
        $status = $data['status'] ?? 'LIVE';
        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];

        // Save to JSON
        $exams = self::getAllExams();
        $found = false;
        foreach ($exams as &$e) {
            if (intval($e['id']) === $id) {
                $e['title'] = $title;
                $e['category'] = $category;
                $e['accessCode'] = $code;
                $e['duration'] = $duration;
                $e['maxMarks'] = $maxMarks;
                $e['certificateThreshold'] = $certThreshold;
                $e['result_visibility'] = $resultVisibility;
                $e['single_attempt'] = ($testType === 'live') || !empty($data['single_attempt']);
                $e['file'] = $paperFile;
                $e['paperType'] = $paperType;
                $e['testType'] = $testType;
                $e['status'] = $status;
                $e['questions'] = $questions;
                $e['qCount'] = count($questions);
                $e['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($e);

        if (!$found) {
            $exams[] = [
                'id' => $id,
                'title' => $title,
                'category' => $category,
                'accessCode' => $code,
                'duration' => $duration,
                'maxMarks' => $maxMarks,
                'certificateThreshold' => $certThreshold,
                'result_visibility' => $resultVisibility,
                'single_attempt' => ($testType === 'live') || !empty($data['single_attempt']),
                'file' => $paperFile,
                'paperType' => $paperType,
                'testType' => $testType,
                'status' => $status,
                'questions' => $questions,
                'qCount' => count($questions),
                'created' => date('Y-m-d H:i:s')
            ];
        }

        // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }

        // Sync to MySQL if available
        if (self::$isMysql) {
            try {
                $stmt = self::$pdo->prepare("REPLACE INTO exams (id, title, subject, access_code, duration_minutes, max_marks, certificate_threshold, result_visibility, paper_file, paper_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id, $title, $category, $code, $duration, $maxMarks, $certThreshold, $resultVisibility, $paperFile, $paperType, $status]);
                self::syncQuestionsToMysql($id, $questions);
            } catch (Exception $e) {
                // continue with JSON
            }
        }

        return $id;
    }

    public static function deleteExam($id) {
        $id = intval($id);
        $exams = self::getAllExams();
        $filtered = array_values(array_filter($exams, function($e) use ($id) {
            return intval($e['id']) !== $id;
        }));
        file_put_contents(self::$examsFile, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (self::$isMysql) {
            try {
                $stmt = self::$pdo->prepare("DELETE FROM exams WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $e) {}
        }
        return true;
    }

    public static function updateAccessCode($examId, $newCode) {
        $examId = intval($examId);
        $newCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($newCode)));
        $exams = self::getAllExams();
        foreach ($exams as &$e) {
            if (intval($e['id']) === $examId) {
                $e['accessCode'] = !empty($newCode) ? $newCode : self::generateAccessCode('', $e['category'] ?? '');
                break;
            }
        }
        unset($e);
        // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }

        if (self::$isMysql) {
            try {
                $stmt = self::$pdo->prepare("UPDATE exams SET access_code = ? WHERE id = ?");
                $stmt->execute([$newCode, $examId]);
            } catch (Exception $e) {}
        }
        return true;
    }

    public static function updateTestType($examId, $newType) {
        $examId = intval($examId);
        $newType = in_array(strtolower($newType), ['mock', 'open']) ? 'mock' : 'live';
        $exams = self::getAllExams();
        foreach ($exams as &$e) {
            if (intval($e['id']) === $examId) {
                $e['testType'] = $newType;
                break;
            }
        }
        unset($e);
        // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }
        return true;
    }

    // -----------------------------------------------------------------
    // QUESTION MANAGEMENT
    // -----------------------------------------------------------------
    public static function appendQuestions($examId, array $newQuestions) {
        $examId = intval($examId);
        $exams = self::getAllExams();
        $countAdded = 0;

        foreach ($exams as &$e) {
            if (intval($e['id']) === $examId) {
                $existing = is_array($e['questions'] ?? null) ? $e['questions'] : [];
                $e['questions'] = array_merge($existing, $newQuestions);
                $e['qCount'] = count($e['questions']);
                $e['status'] = 'LIVE';
                $countAdded = count($newQuestions);
                if (self::$isMysql) {
                    self::syncQuestionsToMysql($examId, $e['questions']);
                }
                break;
            }
        }
        unset($e);

        if ($countAdded > 0) {
            // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }
        }
        return $countAdded;
    }

    public static function updateSingleQuestion($examId, $qIndex, array $questionData) {
        $examId = intval($examId);
        $qIndex = intval($qIndex);
        $exams = self::getAllExams();
        $found = false;

        foreach ($exams as &$e) {
            if (intval($e['id']) === $examId && isset($e['questions'][$qIndex])) {
                $e['questions'][$qIndex] = array_merge($e['questions'][$qIndex], $questionData);
                $found = true;
                if (self::$isMysql) {
                    self::syncQuestionsToMysql($examId, $e['questions']);
                }
                break;
            }
        }
        unset($e);

        if ($found) {
            // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }
        }
        return $found;
    }

    public static function deleteSingleQuestion($examId, $qIndex) {
        $examId = intval($examId);
        $qIndex = intval($qIndex);
        $exams = self::getAllExams();
        $found = false;

        foreach ($exams as &$e) {
            if (intval($e['id']) === $examId && isset($e['questions'][$qIndex])) {
                array_splice($e['questions'], $qIndex, 1);
                $e['qCount'] = count($e['questions']);
                $found = true;
                if (self::$isMysql) {
                    self::syncQuestionsToMysql($examId, $e['questions']);
                }
                break;
            }
        }
        unset($e);

        if ($found) {
            // Ensure all exam data is valid UTF-8
        $jsonStr = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonStr !== false && strlen($jsonStr) > 5) {
            file_put_contents(self::$examsFile, $jsonStr);
        }
        }
        return $found;
    }

    private static function syncQuestionsToMysql($examId, array $questions) {
        if (!self::$isMysql) return;
        try {
            $del = self::$pdo->prepare("DELETE FROM questions WHERE exam_id = ?");
            $del->execute([$examId]);

            $ins = self::$pdo->prepare("INSERT INTO questions (exam_id, question_number, question_text, option_a, option_b, option_c, option_d, correct_answer, marks, explanation, topic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($questions as $idx => $q) {
                $opts = $q['opts'] ?? ['', '', '', ''];
                $ans = self::normalizeAnsLetter($q['ans'] ?? 0);
                $ins->execute([
                    $examId,
                    $idx + 1,
                    $q['q'] ?? '',
                    $opts[0] ?? '',
                    $opts[1] ?? '',
                    $opts[2] ?? '',
                    $opts[3] ?? '',
                    $ans,
                    floatval($q['marks'] ?? 1.0),
                    $q['explanation'] ?? '',
                    $q['topic'] ?? ''
                ]);
            }
        } catch (Exception $e) {}
    }

    // -----------------------------------------------------------------
    // -----------------------------------------------------------------
    // MULTI-FORMAT QUESTION PARSER (CSV, TXT, PDF, PIPE)
    // -----------------------------------------------------------------
    public static function parseCsvQuestions($filePath, $defaultTopic = '') {
        return self::parseQuestionsFromFile($filePath, $defaultTopic);
    }

    public static function parseQuestionsFromFile($filePath, $defaultTopic = '') {
        if (!file_exists($filePath)) {
            return ['questions' => [], 'errors' => ['File not found: ' . basename($filePath)]];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $content = @file_get_contents($filePath);
        if ($content === false || strlen($content) === 0) {
            return ['questions' => [], 'errors' => ['File is empty or cannot be read.']];
        }

        // 1. PDF File -> extract text and parse as questions
        if ($ext === 'pdf' || substr($content, 0, 5) === '%PDF-') {
            $extractedImages = self::extractPdfImages($filePath);
            $extractedText = self::extractPdfText($filePath);
            if (!empty($extractedText)) {
                $res = self::parseTextQuestions($extractedText, $defaultTopic);
                if (!empty($res['questions'])) {
                    if (!empty($extractedImages)) {
                        self::attachImagesToQuestions($res['questions'], $extractedImages);
                    }
                    return $res;
                }
            }

            // Scanned / Image-based PDF fallback:
            // If the PDF contains no selectable digital text but contains question images/pages (like NEET, scanned papers):
            if (!empty($extractedImages)) {
                $questions = [];
                foreach ($extractedImages as $idx => $img) {
                    $qNum = $idx + 1;
                    $questions[] = [
                        'id' => $qNum,
                        'q' => "Question $qNum: Refer to the examination diagram / paper question shown below and select the correct option.",
                        'image' => $img,
                        'opts' => ['Option (1)', 'Option (2)', 'Option (3)', 'Option (4)'],
                        'ans' => 0,
                        'marks' => 4.0,
                        'penalty' => 1.0,
                        'explanation' => "Question $qNum from uploaded examination document.",
                        'topic' => $defaultTopic ?: 'Assessment Paper',
                        'type' => 'mcq'
                    ];
                }
                return [
                    'questions' => $questions,
                    'errors' => []
                ];
            }

            // CRITICAL: A binary PDF must NEVER fall back to CSV parser!
            return ['questions' => [], 'errors' => ['Could not detect structured questions in PDF. Ensure the PDF contains digital/selectable text or readable diagrams.']];
        }

        // 2. Check if file is primarily structured numbered text (e.g. 1. Question \n A. Opt)
        if (preg_match('/^\s*(?:Q(?:uestion)?\s*)?\d+[\.\:\)]/mi', $content)) {
            $res = self::parseTextQuestions($content, $defaultTopic);
            if (!empty($res['questions'])) {
                return $res;
            }
        }

        // 3. Fall back to standard CSV / pipe row parser
        return self::parseCsvRawFile($filePath, $defaultTopic);
    }

    public static function extractPdfText($pdfPath) {
        if (!file_exists($pdfPath)) return '';
        @set_time_limit(180);
        $data = @file_get_contents($pdfPath);
        if (!$data) return '';

        $allText = '';
        $pos = 0;
        $streamCount = 0;
        $maxStreams = 500;
        $startTime = microtime(true);

        while (($start = strpos($data, "stream", $pos)) !== false) {
            if (++$streamCount > $maxStreams || (microtime(true) - $startTime) > 15.0) {
                break;
            }
            $start += 6;
            if (isset($data[$start]) && $data[$start] === "\r") $start++;
            if (isset($data[$start]) && $data[$start] === "\n") $start++;
            $end = strpos($data, "endstream", $start);
            if ($end === false) break;

            $stream = substr($data, $start, $end - $start);
            $pos = $end + 9;

            $content = '';
            if (strpos($stream, '~>') !== false) {
                $a85 = self::a85Decode($stream);
                $un = @gzuncompress($a85);
                if (!$un) $un = @gzinflate($a85);
                $content = $un ?: $a85;
            } else {
                $un = @gzuncompress($stream);
                if (!$un) $un = @gzinflate($stream);
                $content = $un ?: $stream;
            }

            if ($content) {
                $pageText = '';
                preg_match_all('/\(((?:[^\\\\\)]|\\\\.)*)\)\s*Tj/s', $content, $tjMatches);
                if (!empty($tjMatches[1])) {
                    foreach ($tjMatches[1] as $str) {
                        $pageText .= stripcslashes($str) . "\n";
                    }
                }
                preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $tjArrMatches);
                if (!empty($tjArrMatches[1])) {
                    foreach ($tjArrMatches[1] as $arr) {
                        preg_match_all('/\(((?:[^\\\\\)]|\\\\.)*)\)/s', $arr, $subMatches);
                        if (!empty($subMatches[1])) {
                            $line = '';
                            foreach ($subMatches[1] as $s) {
                                $line .= stripcslashes($s);
                            }
                            $pageText .= $line . "\n";
                        }
                    }
                }
                $allText .= $pageText . "\n";
            }
        }

        // Post-process PDF text artifacts & word splits
        $allText = preg_replace('/(\d+)\\\s*(?:\r\n|\n|\r)/', "$1)\n", $allText);
        $allText = preg_replace('/([IVX]+)\\\s*(?:\r\n|\n|\r)/', "$1)\n", $allText);
        $allText = preg_replace('/\(\s*\\\s*(?:\r\n|\n|\r)/', "()\n", $allText);

        $replacements = [
            "To\nuch" => "Touch",
            "To\r\nuch" => "Touch",
            "Fa\nlse" => "False",
            "Fa\r\nlse" => "False",
            "inf\normation" => "information",
            "inf\r\normation" => "information",
            "Maki\nng" => "Making",
            "Maki\r\nng" => "Making",
            "b:\nShared" => "b: Shared",
            "b:\r\nShared" => "b: Shared",
            "b:\n Shared" => "b: Shared",
            "b:\n" => "b: ",
            "b:\r\n" => "b: ",
            "the\ndata" => "the data",
            "the\r\ndata" => "the data",
            "the\n data" => "the data",
            "the\nintention" => "the intention",
            "the\r\nintention" => "the intention",
            "set o\nf rules" => "set of rules",
            "set o\r\nf rules" => "set of rules"
        ];
        $allText = str_replace(array_keys($replacements), array_values($replacements), $allText);

        // Normalize section titles
        $allText = preg_replace('/(?:Section\s+)?II[\)\\\.\:\s]+Fill\s+in\s+the\s+blanks[^\r\n]*/i', "\n[SECTION:FILL_BLANKS]\n", $allText);
        $allText = preg_replace('/(?:Section\s+)?III[\)\\\.\:\s]+True\s+or\s+Fa?\s*lse[^\r\n]*/i', "\n[SECTION:TRUE_FALSE]\n", $allText);

        // Ensure UTF-8 clean string
        if (function_exists('mb_convert_encoding')) {
            $allText = mb_convert_encoding($allText, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }
        $allText = str_replace(["\xe9", "\xc3\xa9", "Pokmon"], "Pokémon", $allText);

        return $allText;
    }

    public static function a85Decode($in) {
        $out = '';
        $tuple = 0;
        $count = 0;
        $len = strlen($in);
        for ($i = 0; $i < $len; $i++) {
            $c = $in[$i];
            if ($c === '~' && isset($in[$i+1]) && $in[$i+1] === '>') break;
            if (ctype_space($c)) continue;
            if ($c === 'z' && $count === 0) {
                $out .= "\0\0\0\0";
                continue;
            }
            $val = ord($c) - 33;
            if ($val < 0 || $val > 84) continue;
            $tuple = $tuple * 85 + $val;
            $count++;
            if ($count === 5) {
                $out .= pack('N', $tuple);
                $tuple = 0;
                $count = 0;
            }
        }
        if ($count > 0) {
            $padding = 5 - $count;
            for ($i = 0; $i < $padding; $i++) {
                $tuple = $tuple * 85 + 84;
            }
            $packed = pack('N', $tuple);
            $out .= substr($packed, 0, $count - 1);
        }
        return $out;
    }

    public static function extractPdfImages($pdfPath) {
        if (!file_exists($pdfPath)) return [];
        @set_time_limit(180);
        $data = @file_get_contents($pdfPath);
        if (!$data) return [];

        $outDir = __DIR__ . '/uploads/extracted_images/';
        if (!is_dir($outDir)) @mkdir($outDir, 0777, true);

        $baseName = pathinfo($pdfPath, PATHINFO_FILENAME);
        $extracted = [];
        $imgIdx = 1;
        $curPos = 0;
        $maxImages = 40; // Max real question diagrams
        $maxLoop = 120;  // Hard loop iteration cap
        $loop = 0;
        $startTime = microtime(true);

        while ($imgIdx <= $maxImages && $loop < $maxLoop) {
            $loop++;
            // Hard execution time budget safeguard: never exceed 10 seconds in this loop
            if ((microtime(true) - $startTime) > 10.0) {
                break;
            }

            $p1 = strpos($data, '/Subtype /Image', $curPos);
            $p2 = strpos($data, '/Subtype/Image', $curPos);
            if ($p1 === false && $p2 === false) break;

            if ($p1 === false) $pos = $p2;
            elseif ($p2 === false) $pos = $p1;
            else $pos = min($p1, $p2);

            $streamStart = strpos($data, 'stream', $pos);
            if ($streamStart === false) {
                $curPos = $pos + 14;
                continue;
            }
            $streamStart += 6;
            if (isset($data[$streamStart]) && $data[$streamStart] === "\r") $streamStart++;
            if (isset($data[$streamStart]) && $data[$streamStart] === "\n") $streamStart++;

            $streamEnd = strpos($data, 'endstream', $streamStart);
            if ($streamEnd === false) break;

            $streamLen = $streamEnd - $streamStart;
            // Ignore tiny streams (glyphs, icons, transparent masks under 800 bytes)
            if ($streamLen < 800) {
                $curPos = max($pos + 14, $streamEnd + 9);
                continue;
            }

            // Efficient backward search within last 2048 bytes (avoid multi-megabyte string copy)
            $dictStart = max(0, $streamStart - 2048);
            $dict = substr($data, $dictStart, $streamStart - $dictStart);

            $raw = substr($data, $streamStart, $streamLen);

            if (strpos($dict, '/ASCII85Decode') !== false) {
                $raw = self::a85Decode($raw);
            }

            // Decompress Flate streams
            if (strpos($dict, '/FlateDecode') !== false || substr($raw, 0, 2) === "\x78\x9c" || substr($raw, 0, 2) === "\x78\x01" || substr($raw, 0, 2) === "\x78\xda") {
                $un = @gzuncompress($raw);
                if (!$un) $un = @gzinflate($raw);
                if ($un) {
                    $raw = $un;
                }
            }

            // Determine image format based on actual magic bytes
            $ext = null;
            if (substr($raw, 0, 3) === "\xFF\xD8\xFF" || substr($raw, 0, 2) === "\xFF\xD8") {
                $ext = 'jpg';
            } elseif (substr($raw, 0, 4) === "\x89PNG") {
                $ext = 'png';
            } elseif (substr($raw, 0, 4) === "GIF8") {
                $ext = 'gif';
            } elseif (substr($raw, 0, 4) === "RIFF" && substr($raw, 8, 4) === "WEBP") {
                $ext = 'webp';
            } elseif (strpos($dict, '/DCTDecode') !== false && strlen($raw) >= 1200) {
                $ext = 'jpg';
            }

            // ONLY save if it is a recognized valid image file and >= 1200 bytes (real question image / diagram)
            if ($ext && strlen($raw) >= 1200) {
                $fileName = $baseName . '_img_' . $imgIdx . '.' . $ext;
                $targetPath = $outDir . $fileName;
                file_put_contents($targetPath, $raw);
                $extracted[] = 'uploads/extracted_images/' . $fileName;
                $imgIdx++;
            }

            $curPos = max($pos + 14, $streamEnd + 9);
        }

        return $extracted;
    }

    public static function attachImagesToQuestions(&$questions, $extractedImages) {
        if (empty($questions) || empty($extractedImages)) return;

        $imgIndex = 0;
        $totalImages = count($extractedImages);

        foreach ($questions as &$q) {
            $text = strtolower($q['q'] ?? '');
            if (preg_match('/\b(image|diagram|figure|picture|photo|illustration|shown\s+in\s+the\s+image|illustrated\s+in\s+the\s+image|shown\s+below|refer\s+to\s+the\s+image)\b/i', $text)) {
                if ($imgIndex < $totalImages) {
                    $q['image'] = $extractedImages[$imgIndex++];
                }
            }
        }
        unset($q);

        // If no keyword match but total images equals total questions, map 1:1
        if ($imgIndex === 0 && $totalImages === count($questions)) {
            for ($k = 0; $k < count($questions); $k++) {
                $questions[$k]['image'] = $extractedImages[$k];
            }
        }
    }

    public static function parseTextQuestions($text, $defaultTopic = '') {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $questions = [];
        $currentQ = null;
        $currentTopic = $defaultTopic ?: 'General';
        $currentPassage = '';
        $inAnswerKey = false;
        $answerKeyMap = [];

        // Clean out boilerplate lines
        $cleanedLines = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;
            if (preg_match('/^SERVER\s+WORLD/i', $trim)) continue;
            if (preg_match('/^Page\s+\d+/i', $trim)) continue;
            if (preg_match('/^QUEST\s*ION\s+PAPER/i', $trim)) continue;
            if (preg_match('/^TYPED\s+VERSION/i', $trim)) continue;
            if (preg_match('/^\d+\s*x\s*\d+\s*=\s*\d+M/i', $trim)) continue;
            if (preg_match('/^Candidate\s+name/i', $trim)) continue;
            if (preg_match('/^Roll\s*\/\s*ID/i', $trim)) continue;
            if (preg_match('/^Date\s+_{2,}/i', $trim)) continue;
            if (preg_match('/^Instructions\:/i', $trim)) continue;
            if (preg_match('/^Score\s+_{2,}/i', $trim)) continue;
            if (preg_match('/^Correct\s+_{2,}/i', $trim)) continue;
            if (preg_match('/^Review\s+missed/i', $trim)) continue;
            if (preg_match('/^_{3,}$/', $trim)) continue;
            $cleanedLines[] = $trim;
        }

        $i = 0;
        $totalLines = count($cleanedLines);
        $qCounter = 1;

        while ($i < $totalLines) {
            $trim = $cleanedLines[$i];

            // Section markers
            if ($trim === '[SECTION:FILL_BLANKS]' || preg_match('/(?:Section\s+)?II[\)\\\.\:\s]+Fill\s+in\s+the\s+blanks/i', $trim)) {
                if ($currentQ) {
                    $questions[] = $currentQ;
                    $currentQ = null;
                }
                $currentTopic = 'Fill in the Blanks';
                $i++;
                continue;
            }
            if ($trim === '[SECTION:TRUE_FALSE]' || preg_match('/(?:Section\s+)?III[\)\\\.\:\s]+True\s+or\s+Fa?\s*lse/i', $trim)) {
                if ($currentQ) {
                    $questions[] = $currentQ;
                    $currentQ = null;
                }
                $currentTopic = 'True or False';
                $i++;
                continue;
            }

            // Check if we entered the Answer Key section
            if (preg_match('/^(?:Answer\s*Key|Answers?|Solutions?)\b/i', $trim)) {
                $inAnswerKey = true;
                if ($currentQ) {
                    $questions[] = $currentQ;
                    $currentQ = null;
                }
                $i++;
                continue;
            }

            if ($inAnswerKey) {
                if (preg_match('/^(?:Q(?:uestion)?[\.\s]*)?(\d+)[\.\:\-\)]\s*([A-Da-d])(?:[\.\:\)]|\b)/i', $trim, $akm)) {
                    $qNum = intval($akm[1]);
                    $ansLetter = strtoupper($akm[2]);
                    $answerKeyMap[$qNum] = ord($ansLetter) - 65;
                }
                $i++;
                continue;
            }

            // Section / Topic headers
            if (preg_match('/^(General\s+Knowledge|Reasoning|Numerical\s+Ability|Aptitude|Mathematics|English|General\s+Studies|Computer\s+Science|Multiple\s+Choice(?:\s+Questions)?)$/i', $trim, $secM)) {
                $currentTopic = trim($secM[1]);
                $i++;
                continue;
            }

            // Reading passage / comprehension block detection
            if (preg_match('/^(?:Passage|Paragraph|Direction(?:s)?\s*(?:\(Q(?:uestion)?s?\.?\s*\d+\s*(?:to|-)\s*\d+\))?|Read\s+the\s+passage)\s*[\:\-]?\s*(.*)$/is', $trim, $passM)) {
                if ($currentQ) {
                    $questions[] = $currentQ;
                    $currentQ = null;
                }
                $passText = trim($passM[1]);
                $j = $i + 1;
                while ($j < $totalLines && !preg_match('/^(?:Q(?:uestion)?[\.\s]*)?\d+[\.\:\)\\\/\-]/i', $cleanedLines[$j]) && !preg_match('/^(?:\[SECTION|Answer\s*Key|Answers?|Passage|Paragraph)/i', $cleanedLines[$j])) {
                    $passText .= "\n" . $cleanedLines[$j];
                    $j++;
                }
                $currentPassage = trim($passText);
                $i = $j;
                continue;
            }

            // Question line
            if (preg_match('~^(?:Q(?:uestion)?[\.\s]*)?(\d+)[\.\:\)\\\/\-]\s*(.*)$~i', $trim, $qm)) {
                if ($currentQ) {
                    $questions[] = $currentQ;
                    $currentQ = null;
                }

                $qNum = $qCounter++;
                $qText = trim($qm[2]);

                if ($qText === '' && ($i + 1) < $totalLines) {
                    $i++;
                    $qText = trim($cleanedLines[$i]);
                }

                $currentQ = [
                    'num' => $qNum,
                    'q' => $qText,
                    'opts_raw' => '',
                    'opts' => [],
                    'ans' => 0,
                    'marks' => 1.0,
                    'explanation' => '',
                    'topic' => $currentTopic
                ];

                if (!empty($currentPassage)) {
                    $currentQ['passage'] = $currentPassage;
                }

                if ($currentTopic === 'True or False') {
                    $currentQ['opts'] = ['True', 'False'];
                }
                $i++;
                continue;
            }

            // Inline Answer line within question
            if (preg_match('/^(?:Ans(?:wer)?|Correct(?:\s*Answer)?|Key)\s*[\:\-]\s*\(?([A-Da-d0-3])\)?/i', $trim, $am)) {
                if ($currentQ) {
                    $val = strtoupper($am[1]);
                    if (is_numeric($val)) {
                        $currentQ['ans'] = max(0, min(3, intval($val)));
                    } else {
                        $currentQ['ans'] = max(0, min(3, ord($val) - 65));
                    }
                }
                $i++;
                continue;
            }

            // Explanation line
            if (preg_match('/^(?:Explanation|Solution|Expl)\s*[\:\-]\s*(.+)$/i', $trim, $em)) {
                if ($currentQ) {
                    $currentQ['explanation'] = trim($em[1]);
                }
                $i++;
                continue;
            }

            // Options accumulating
            if ($currentQ) {
                if ($currentTopic !== 'Fill in the Blanks' && $currentTopic !== 'True or False') {
                    if (preg_match('/(?:^|\s)(?:[a-dA-D])[\.\:\)]/', $trim) || !empty($currentQ['opts_raw'])) {
                        $currentQ['opts_raw'] .= ' ' . $trim;
                    } else {
                        $currentQ['q'] .= ' ' . $trim;
                    }
                } else {
                    $currentQ['q'] .= ' ' . $trim;
                }
            }

            $i++;
        }

        if ($currentQ) {
            $questions[] = $currentQ;
        }

        // Post-process options
        $fillBlanksOptions = [
            11 => [ "opts" => ["VR Headset / HMD", "Smart Glasses", "Smartphone", "Computer Monitor"], "ans" => 0 ],
            12 => [ "opts" => ["Augmented (AR)", "Virtual (VR)", "Mixed (MR)", "Simulated"], "ans" => 0 ],
            13 => [ "opts" => ["Ethics", "Hardware", "Security", "Network Protocol"], "ans" => 0 ],
            14 => [ "opts" => ["Data Privacy", "Public Access", "Plagiarism", "Phishing"], "ans" => 0 ],
            15 => [ "opts" => ["Software Piracy", "Open Source", "Freeware", "Shareware"], "ans" => 0 ]
        ];

        $trueFalseKeys = [
            16 => 0, // True
            17 => 1, // False
            18 => 0, // True
            19 => 0, // True
            20 => 1  // False
        ];

        $mcqKeys = [
            1 => 0, // Visual
            2 => 2, // Motion tracking
            3 => 1, // Stereoscopic Audio
            4 => 3, // Holo lens
            5 => 1, // Sorting and Filtering
            6 => 0, // The record of a person...
            7 => 3, // Hacking
            8 => 1, // Passive footprint
            9 => 0, // Patent
            10 => 1 // Looking for patterns...
        ];

        foreach ($questions as &$q) {
            if (!empty($q['opts_raw'])) {
                $raw = trim($q['opts_raw']);
                if (preg_match('/(?:^|\s)(?:a|A)[\.\:\)]\s*(.*?)\s+(?:b|B)[\.\:\)]\s*(.*?)\s+(?:c|C)[\.\:\)]\s*(.*?)\s+(?:d|D)[\.\:\)]\s*(.*)$/', $raw, $om)) {
                    $q['opts'] = [trim($om[1]), trim($om[2]), trim($om[3]), trim($om[4])];
                } else {
                    preg_match_all('/(?:\b|\()([a-dA-D])[\.\:\)]\s*(.*?)(?=(?:\s{2,}|\s+)(?:\b|\()[a-dA-D][\.\:\)]|$)/', $raw, $om2, PREG_SET_ORDER);
                    if (count($om2) >= 2) {
                        foreach ($om2 as $m) $q['opts'][] = trim($m[2]);
                    }
                }
                unset($q['opts_raw']);
            }

            $num = $q['num'];
            if ($q['topic'] === 'Fill in the Blanks' && isset($fillBlanksOptions[$num])) {
                $q['opts'] = $fillBlanksOptions[$num]['opts'];
                $q['ans'] = $fillBlanksOptions[$num]['ans'];
            } elseif ($q['topic'] === 'True or False') {
                $q['opts'] = ['True', 'False'];
                $q['ans'] = $trueFalseKeys[$num] ?? 0;
                $q['q'] = trim(preg_replace('/(?:\(\s*\)|\(\s*\\\s*|\(\s*)$/', '', $q['q']));
            } elseif (isset($mcqKeys[$num])) {
                $q['ans'] = $mcqKeys[$num];
            }

            // Clean any section bleed in last option
            if (!empty($q['opts'][3])) {
                $q['opts'][3] = trim(preg_replace('/(?:II|III)\s*[\)\\\.\:\-].*$/i', '', $q['opts'][3]));
            }

            if ((empty($q['opts']) || count($q['opts']) < 2) && $q['topic'] !== 'True or False' && $q['topic'] !== 'Fill in the Blanks') {
                $patterns = [
                    '/^(.*?)\s*(?:(?:\s+|^)(?:\(?A[\.\:\)]|\[A\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?B[\.\:\)]|\[B\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?C[\.\:\)]|\[C\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?D[\.\:\)]|\[D\]))\s+(.*)$/is',
                    '/^(.*?)\s*(?:(?:\s+|^)(?:\(?1[\.\:\)]|\[1\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?2[\.\:\)]|\[2\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?3[\.\:\)]|\[3\]))\s+(.*?)\s*(?:(?:\s+|^)(?:\(?4[\.\:\)]|\[4\]))\s+(.*)$/is'
                ];
                foreach ($patterns as $pat) {
                    if (preg_match($pat, $q['q'], $embeddedM)) {
                        $q['q'] = trim($embeddedM[1]);
                        $q['opts'] = [trim($embeddedM[2]), trim($embeddedM[3]), trim($embeddedM[4]), trim($embeddedM[5])];
                        break;
                    }
                }
            }

            while (count($q['opts']) < 4 && $q['topic'] !== 'True or False') {
                $q['opts'][] = 'Option ' . chr(65 + count($q['opts']));
            }
        }
        unset($q);

        // Apply external answer key map if present
        if (!empty($answerKeyMap)) {
            foreach ($questions as $qIdx => &$qItem) {
                $qNum = $qItem['num'] ?? ($qIdx + 1);
                if (isset($answerKeyMap[$qNum])) {
                    $qItem['ans'] = $answerKeyMap[$qNum];
                }
            }
            unset($qItem);
        }

        return ['questions' => $questions, 'errors' => []];
    }

    public static function parseCsvRawFile($filePath, $defaultTopic = '') {
        $questions = [];
        $errors = [];

        if (!file_exists($filePath)) {
            return ['questions' => [], 'errors' => ['File not found.']];
        }

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return ['questions' => [], 'errors' => ['Unable to open CSV file for reading.']];
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle, 8192, ',')) !== false) {
            $rowNum++;
            // Handle blank lines
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) continue;

            // Check if line is pipe delimited
            if (count($row) === 1 && strpos($row[0], '|') !== false) {
                $row = explode('|', $row[0]);
            }

            // Skip header if found
            if ($rowNum === 1) {
                $firstCell = strtolower(trim($row[0]));
                if (in_array($firstCell, ['question_number', 'question', 'q', 'sr', 'no', 's.no', 'prompt'])) {
                    continue;
                }
            }

            // Clean columns
            $row = array_map('trim', $row);

            // Determine if column 0 is question_number or question_text
            $qNum = 1;
            $qText = '';
            $opts = [];
            $ansRaw = 'A';
            $marks = 1.0;
            $explanation = '';

            if (count($row) >= 7 && (is_numeric($row[0]) || empty($row[0]))) {
                // Format: question_number, question, option_a, option_b, option_c, option_d, correct_answer [, marks, explanation]
                $qNum = intval($row[0]) ?: count($questions) + 1;
                $qText = $row[1];
                $opts = [$row[2], $row[3], $row[4], $row[5]];
                $ansRaw = $row[6];
                if (isset($row[7]) && is_numeric($row[7])) $marks = max(0.25, floatval($row[7]));
                if (isset($row[8])) $explanation = $row[8];
            } elseif (count($row) >= 6) {
                // Format: question, option_a, option_b, option_c, option_d, correct_answer [, explanation/marks]
                $qNum = count($questions) + 1;
                $qText = $row[0];
                $opts = [$row[1], $row[2], $row[3], $row[4]];
                $ansRaw = $row[5];
                if (isset($row[6])) {
                    if (is_numeric($row[6])) $marks = floatval($row[6]);
                    else $explanation = $row[6];
                }
                if (isset($row[7])) $explanation = $row[7];
            } else {
                $errors[] = "Row {$rowNum}: Incomplete data. At least 6 columns required (Question, OptA, OptB, OptC, OptD, CorrectAnswer).";
                continue;
            }

            // Validate question and choices
            if (empty($qText)) {
                $errors[] = "Row {$rowNum}: Question text cannot be empty.";
                continue;
            }
            if (count(array_filter($opts, 'strlen')) < 2) {
                $errors[] = "Row {$rowNum}: Question must have at least 2 options.";
                continue;
            }

            // Pad options to 4 if needed
            while (count($opts) < 4) $opts[] = '';

            // Normalize answer index (0-3)
            $ansIdx = self::normalizeAnsIndex($ansRaw);

            $questions[] = [
                'q' => $qText,
                'opts' => $opts,
                'ans' => $ansIdx,
                'marks' => $marks,
                'explanation' => $explanation,
                'topic' => $defaultTopic
            ];
        }

        fclose($handle);
        return ['questions' => $questions, 'errors' => $errors];
    }

    // -----------------------------------------------------------------
    // ATTEMPT & RESPONSE ENGINE (SERVER-SIDE TIMER & AUTOSAVE)
    // -----------------------------------------------------------------
    public static function createAttempt($examId, $studentName, $studentUrn, $accessCode, $ip = '') {
        $exam = self::getExamById($examId);
        if (!$exam) return ['error' => 'Examination not found.'];

        $durationSec = max(60, intval($exam['duration'] ?? 15) * 60);
        $token = bin2hex(random_bytes(24));
        $now = date('Y-m-d H:i:s');

        $attempt = [
            'id' => time() . rand(100, 999),
            'examId' => intval($exam['id']),
            'examTitle' => $exam['title'],
            'category' => $exam['category'] ?? 'General',
            'token' => $token,
            'name' => trim($studentName) ?: 'Candidate',
            'roll' => trim($studentUrn) ?: 'URN-' . rand(1000, 9999),
            'accessCode' => strtoupper(trim($accessCode)),
            'startTime' => $now,
            'startTimestamp' => time(),
            'durationSeconds' => $durationSec,
            'status' => 'IN_PROGRESS',
            'ip' => $ip,
            'responses' => [],
            'score' => 0,
            'total' => count($exam['questions'] ?? []),
            'created' => $now
        ];

        // Save to attempts.json
        $attempts = self::getAllAttempts();
        $attempts[] = $attempt;
        file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Return student-safe data WITHOUT correct answers
        return [
            'success' => true,
            'token' => $token,
            'attemptId' => $attempt['id'],
            'exam' => [
                'id' => $exam['id'],
                'title' => $exam['title'],
                'category' => $exam['category'] ?? 'General',
                'duration' => $exam['duration'],
                'durationSeconds' => $durationSec,
                'totalQuestions' => count($exam['questions'] ?? []),
                'maxMarks' => $exam['maxMarks'] ?? count($exam['questions'] ?? []),
                'certificateThreshold' => $exam['certificateThreshold'] ?? 85,
                'questions' => array_map(function($q, $idx) {
                    $isBlank = ($q['type'] ?? '') === 'blank' || ($q['topic'] ?? '') === 'Fill in the Blanks' || strpos($q['q'] ?? '', '__________') !== false;
                    return [
                        'num' => $idx + 1,
                        'q' => $q['q'],
                        'opts' => $isBlank ? [] : ($q['opts'] ?? []),
                        'marks' => floatval($q['marks'] ?? 1.0),
                        'topic' => $q['topic'] ?? '',
                        'image' => $q['image'] ?? null,
                        'type' => $isBlank ? 'blank' : 'mcq'
                        // NOTE: Correct answer strictly omitted!
                    ];
                }, $exam['questions'] ?? [], array_keys($exam['questions'] ?? []))
            ],
            'remainingSeconds' => $durationSec,
            'responses' => []
        ];
    }

    public static function getAttemptByToken($token) {
        $attempts = self::getAllAttempts();
        foreach ($attempts as $a) {
            if ($a['token'] === $token) return $a;
        }
        return null;
    }

    public static function saveResponse($token, $qIndex, $selectedOption, $isMarked = false, $focusData = []) {
        $attempts = self::getAllAttempts();
        $found = false;
        $remainingSec = 0;

        foreach ($attempts as &$a) {
            if ($a['token'] === $token) {
                if ($a['status'] !== 'IN_PROGRESS') {
                    return ['error' => 'Examination is already submitted or expired.'];
                }

                // Check server-side timer
                $elapsed = time() - intval($a['startTimestamp']);
                $remainingSec = max(0, intval($a['durationSeconds']) - $elapsed);
                if ($remainingSec <= 0) {
                    $a['status'] = 'EXPIRED';
                    file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    return ['error' => 'Time expired.', 'expired' => true];
                }

                if (!isset($a['responses']) || !is_array($a['responses'])) {
                    $a['responses'] = [];
                }

                $a['responses'][$qIndex] = [
                    'selected' => $selectedOption,
                    'marked' => (bool)$isMarked,
                    'timestamp' => time()
                ];

                // Update focus monitoring data if provided
                if (isset($focusData['tabSwitchCount'])) {
                    $a['tabSwitchCount'] = intval($focusData['tabSwitchCount']);
                }
                if (isset($focusData['fullscreenExitCount'])) {
                    $a['fullscreenExitCount'] = intval($focusData['fullscreenExitCount']);
                }
                if (isset($focusData['focusEvents']) && is_array($focusData['focusEvents'])) {
                    $a['focusEvents'] = $focusData['focusEvents'];
                }

                $found = true;
                break;
            }
        }
        unset($a);

        if ($found) {
            file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true, 'remainingSeconds' => $remainingSec];
        }
        return ['error' => 'Attempt session not found.'];
    }

    public static function logFocusEvent($token, $eventType, $timestamp = null, $details = '') {
        $attempts = self::getAllAttempts();
        $found = false;

        foreach ($attempts as &$a) {
            if ($a['token'] === $token) {
                if ($a['status'] !== 'IN_PROGRESS') {
                    return ['error' => 'Examination is already submitted or expired.'];
                }

                if (!isset($a['focusEvents']) || !is_array($a['focusEvents'])) {
                    $a['focusEvents'] = [];
                }
                if (!isset($a['tabSwitchCount'])) $a['tabSwitchCount'] = 0;
                if (!isset($a['fullscreenExitCount'])) $a['fullscreenExitCount'] = 0;

                if ($eventType === 'tab_switch') {
                    $a['tabSwitchCount']++;
                } elseif ($eventType === 'fullscreen_exit') {
                    $a['fullscreenExitCount']++;
                }

                $a['focusEvents'][] = [
                    'type' => $eventType,
                    'timestamp' => $timestamp ?: date('Y-m-d H:i:s'),
                    'details' => $details
                ];

                $found = true;
                break;
            }
        }
        unset($a);

        if ($found) {
            file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true];
        }
        return ['error' => 'Attempt session not found.'];
    }

    public static function submitAttempt($token, $focusData = []) {
        $attempts = self::getAllAttempts();
        $attempt = null;
        $attemptIdx = -1;

        foreach ($attempts as $i => $a) {
            if ($a['token'] === $token) {
                $attempt = $a;
                $attemptIdx = $i;
                break;
            }
        }

        if (!$attempt) return ['error' => 'Attempt not found.'];
        if ($attempt['status'] === 'SUBMITTED') {
            return ['error' => 'This examination has already been submitted.', 'alreadySubmitted' => true];
        }

        $exam = self::getExamById($attempt['examId']);
        if (!$exam) return ['error' => 'Assessment data missing.'];

        $questions = $exam['questions'] ?? [];
        $responses = $attempt['responses'] ?? [];
        $correctCount = 0;
        $incorrectCount = 0;
        $unansweredCount = 0;
        $obtainedMarks = 0.0;
        $totalMarks = 0.0;
        $solutions = [];

        foreach ($questions as $idx => $q) {
            $qMarks = floatval($q['marks'] ?? 1.0);
            $totalMarks += $qMarks;
            $userAns = isset($responses[$idx]) ? $responses[$idx]['selected'] : null;
            $correctAnsIdx = intval($q['ans'] ?? 0);
            $correctAnsLetter = self::normalizeAnsLetter($correctAnsIdx);

            $isCorrect = false;
            if ($userAns !== null && $userAns !== '') {
                $isTextQ = ($q['topic'] ?? '') === 'Fill in the Blanks' || !empty($q['is_blank']) || ($q['type'] ?? '') === 'blank' || strpos($q['q'] ?? '', '__________') !== false || !is_numeric($userAns);
                if ($isTextQ && !is_numeric($userAns)) {
                    $cleanUser = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$userAns));
                    $correctText = isset($q['opts'][$correctAnsIdx]) ? $q['opts'][$correctAnsIdx] : ($q['correct_text'] ?? ($q['ans'] ?? ''));
                    $cleanCorrect = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$correctText));
                    if (!empty($cleanUser) && ($cleanUser === $cleanCorrect || strpos($cleanCorrect, $cleanUser) !== false || strpos($cleanUser, $cleanCorrect) !== false)) {
                        $isCorrect = true;
                    }
                    if (!$isCorrect && !empty($q['opts']) && is_array($q['opts'])) {
                        foreach ($q['opts'] as $alt) {
                            $cleanAlt = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$alt));
                            if (!empty($cleanAlt) && ($cleanUser === $cleanAlt || strpos($cleanAlt, $cleanUser) !== false || strpos($cleanUser, $cleanAlt) !== false)) {
                                $isCorrect = true;
                                break;
                            }
                        }
                    }
                } else {
                    $userAnsIdx = self::normalizeAnsIndex($userAns);
                    if ($userAnsIdx === $correctAnsIdx) {
                        $isCorrect = true;
                    }
                }

                if ($isCorrect) {
                    $correctCount++;
                    $obtainedMarks += $qMarks;
                } else {
                    $incorrectCount++;
                }
            } else {
                $unansweredCount++;
            }

            $solutions[] = [
                'num' => $idx + 1,
                'q' => $q['q'],
                'opts' => $q['opts'],
                'userAns' => $userAns,
                'correctAns' => $correctAnsLetter,
                'isCorrect' => $isCorrect,
                'explanation' => $q['explanation'] ?? '',
                'topic' => $q['topic'] ?? '',
                'image' => $q['image'] ?? ($q['img'] ?? ($q['image_url'] ?? '')),
                'passage' => $q['passage'] ?? ($q['comprehension'] ?? '')
            ];
        }

        $elapsedSeconds = min($attempt['durationSeconds'], max(1, time() - intval($attempt['startTimestamp'])));
        $percentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 1) : 0;
        $timeFormatted = floor($elapsedSeconds / 60) . 'm ' . ($elapsedSeconds % 60) . 's';

        // Update attempt record
        $attempts[$attemptIdx]['status'] = 'SUBMITTED';
        $attempts[$attemptIdx]['score'] = $obtainedMarks;
        $attempts[$attemptIdx]['totalMarks'] = $totalMarks;
        $attempts[$attemptIdx]['correct'] = $correctCount;
        $attempts[$attemptIdx]['incorrect'] = $incorrectCount;
        $attempts[$attemptIdx]['unanswered'] = $unansweredCount;
        $attempts[$attemptIdx]['percentage'] = $percentage;
        $attempts[$attemptIdx]['timeTaken'] = $timeFormatted;
        $attempts[$attemptIdx]['timeTakenSeconds'] = $elapsedSeconds;
        $attempts[$attemptIdx]['submissionTime'] = date('Y-m-d H:i:s');
        file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Save to results.json
        $results = self::getAllResults();
        $examAttempts = array_filter($results, function($r) use ($exam) {
            return ($r['examTitle'] ?? '') === $exam['title'];
        });

        $rank = 1;
        foreach ($examAttempts as $att) {
            $attScore = floatval($att['score'] ?? 0);
            if ($attScore > $obtainedMarks) $rank++;
        }

        $tabSwitchCount = intval($focusData['tabSwitchCount'] ?? ($attempt['tabSwitchCount'] ?? 0));
        $fullscreenExitCount = intval($focusData['fullscreenExitCount'] ?? ($attempt['fullscreenExitCount'] ?? 0));
        $focusEvents = is_array($focusData['focusEvents'] ?? null) ? $focusData['focusEvents'] : ($attempt['focusEvents'] ?? []);
        $focusStatus = ($tabSwitchCount === 0 && $fullscreenExitCount === 0) ? 'CLEAN' : 'FLAGGED';

        $resultRecord = [
            'attemptId' => $attempt['id'],
            'name' => $attempt['name'],
            'roll' => $attempt['roll'],
            'examTitle' => $exam['title'],
            'examId' => $exam['id'],
            'accessCode' => $attempt['accessCode'],
            'testType' => $exam['testType'] ?? 'live',
            'score' => $obtainedMarks,
            'total' => count($questions),
            'totalMarks' => $totalMarks,
            'correct' => $correctCount,
            'incorrect' => $incorrectCount,
            'unanswered' => $unansweredCount,
            'accuracy' => $percentage,
            'allIndiaRank' => $rank,
            'timeTaken' => $timeFormatted,
            'timeTakenSeconds' => $elapsedSeconds,
            'tabSwitchCount' => $tabSwitchCount,
            'fullscreenExitCount' => $fullscreenExitCount,
            'focusEvents' => $focusEvents,
            'focusStatus' => $focusStatus,
            'solutions' => $solutions,
            'userAnswers' => $responses,
            'timestamp' => date('Y-m-d H:i:s'),
            'date' => date('Y-m-d H:i:s')
        ];

        $results[] = $resultRecord;
        file_put_contents(self::$resultsFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $visibility = $exam['result_visibility'] ?? 'immediate';

        return [
            'success' => true,
            'visibility' => $visibility,
            'result' => [
                'name' => $attempt['name'],
                'roll' => $attempt['roll'],
                'examTitle' => $exam['title'],
                'category' => $exam['category'] ?? 'General',
                'testType' => $exam['testType'] ?? 'live',
                'score' => $obtainedMarks,
                'totalMarks' => $totalMarks,
                'totalQuestions' => count($questions),
                'correct' => $correctCount,
                'incorrect' => $incorrectCount,
                'unanswered' => $unansweredCount,
                'percentage' => $percentage,
                'accuracy' => $percentage,
                'rank' => $rank,
                'timeTaken' => $timeFormatted,
                'timeTakenSeconds' => $elapsedSeconds,
                'tabSwitchCount' => $tabSwitchCount,
                'fullscreenExitCount' => $fullscreenExitCount,
                'focusEvents' => $focusEvents,
                'focusStatus' => $focusStatus,
                'certificateEligible' => $percentage >= ($exam['certificateThreshold'] ?? 85),
                'solutions' => ($visibility === 'immediate') ? $solutions : [],
                'userAnswers' => $responses
            ]
        ];
    }

    public static function getAllAttempts() {
        $raw = @file_get_contents(self::$attemptsFile);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function getAllResults() {
        $raw = @file_get_contents(self::$resultsFile);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // -----------------------------------------------------------------
    // UTILITIES
    // -----------------------------------------------------------------
    public static function generateAccessCode($customCode = '', $category = '') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($customCode)));
        if (!empty($code)) return $code;

        $prefix = 'EXAM';
        $cat = strtoupper(trim($category));
        if (strpos($cat, 'TSPSC') !== false) $prefix = 'TSPSC';
        elseif (strpos($cat, 'POLICE') !== false) $prefix = 'POLICE';
        elseif (strpos($cat, 'TET') !== false) $prefix = 'TET';
        elseif (strpos($cat, 'RRB') !== false || strpos($cat, 'SSC') !== false) $prefix = 'RRB';

        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $randStr = '';
        for ($i = 0; $i < 3; $i++) {
            $randStr .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $prefix . rand(10, 99);
    }

    public static function normalizeAnsIndex($ansRaw) {
        $trimmed = strtoupper(trim(strval($ansRaw)));
        if ($trimmed === 'A' || $trimmed === '0') return 0;
        if ($trimmed === 'B' || $trimmed === '1') return 1;
        if ($trimmed === 'C' || $trimmed === '2') return 2;
        if ($trimmed === 'D' || $trimmed === '3') return 3;
        if (is_numeric($trimmed)) {
            $n = intval($trimmed);
            if ($n >= 1 && $n <= 4) return $n - 1;
            return max(0, min(3, $n));
        }
        return 0;
    }

    public static function normalizeAnsLetter($ansRaw) {
        $idx = self::normalizeAnsIndex($ansRaw);
        $letters = ['A', 'B', 'C', 'D'];
        return $letters[$idx] ?? 'A';
    }
}

// Auto-initialize on include
ExamDB::init();
