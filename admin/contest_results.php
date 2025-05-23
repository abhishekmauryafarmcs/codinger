// Get all students who participated in the contest with their submissions
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.full_name, u.enrollment_number
    FROM users u
    LEFT JOIN submissions s ON u.id = s.user_id AND s.contest_id = ?
    LEFT JOIN problem_access_logs pal ON u.id = pal.user_id AND pal.contest_id = ?
    LEFT JOIN contest_exits ce ON u.id = ce.user_id AND ce.contest_id = ?
    WHERE s.contest_id = ? OR pal.contest_id = ? OR ce.contest_id = ?
    ORDER BY u.full_name
");
$stmt->bind_param("iiiiii", $contest_id, $contest_id, $contest_id, $contest_id, $contest_id, $contest_id);
$stmt->execute();
$students_result = $stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If no students have participated in this contest
if (empty($students)) {
    $leaderboard = [];
} else {
    // Create an array to store student performance
    $leaderboard = [];
    
    // For each student, get their performance data for each problem
    foreach ($students as $student) {
        $student_id = $student['id'];
        $student_data = [
            'id' => $student_id,
            'name' => $student['full_name'],
            'enrollment' => $student['enrollment_number'],
            'total_score' => 0,
            'total_time' => 0,
            'successful_submissions' => 0,
            'problems' => []
        ];
        
        // For each problem, get the student's best submission
        foreach ($problems as $problem) {
            $problem_id = $problem['id'];
            $total_test_cases = $problem['total_test_cases'] > 0 ? $problem['total_test_cases'] : 1;
            $points_per_test_case = $problem['points'] / $total_test_cases;
            
            // Get the student's best submission for this problem
            $submission_query = "
                SELECT s.*, 
                       (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 1) as visible_cases,
                       (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 0) as hidden_cases
                FROM submissions s
                JOIN (
                    SELECT MAX(s2.id) as latest_id
                    FROM submissions s2
                    WHERE s2.user_id = ? 
                    AND s2.problem_id = ?
                    AND s2.contest_id = ?
                ) latest ON s.id = latest.latest_id
                LIMIT 1
            ";
            
            $stmt = $conn->prepare($submission_query);
            $stmt->bind_param("iiiii", $problem_id, $problem_id, $student_id, $problem_id, $contest_id);
            $stmt->execute();
            $submission_result = $stmt->get_result();
            
            if ($submission_result->num_rows > 0) {
                $submission = $submission_result->fetch_assoc();
                
                // Calculate time taken (in seconds from contest start)
                $contest_start = new DateTime($contest['start_time']);
                $submission_time = new DateTime($submission['submitted_at']);
                $time_taken = $contest_start->diff($submission_time);
                $seconds_taken = $time_taken->days * 86400 + $time_taken->h * 3600 + $time_taken->i * 60 + $time_taken->s;
                
                // Use values directly from the submissions table
                $test_cases_passed = $submission['test_cases_passed'] ?? 0;
                $total_test_cases = $submission['total_test_cases'] ?? $total_test_cases;
                $score = $submission['score'] ?? 0;
                
                // Add 50 points bonus if all test cases are passed
                if ($test_cases_passed == $total_test_cases && $total_test_cases > 0) {
                    $score += 50;
                }
                
                $problem_data = [
                    'problem_id' => $problem_id,
                    'score' => $score,
                    'max_score' => $problem['points'],
                    'time_taken' => $seconds_taken,
                    'status' => $submission['status'],
                    'submitted_at' => $submission['submitted_at'],
                    'test_cases_passed' => $test_cases_passed,
                    'total_test_cases' => $total_test_cases
                ];
                
                $student_data['problems'][$problem_id] = $problem_data;
                $student_data['total_score'] += $score;
                $student_data['total_time'] += $seconds_taken;
                
                if ($submission['status'] === 'accepted') {
                    $student_data['successful_submissions']++;
                }
            } else {
                // No submission for this problem
                $student_data['problems'][$problem_id] = [
                    'problem_id' => $problem_id,
                    'score' => 0,
                    'max_score' => $problem['points'],
                    'time_taken' => 0,
                    'status' => 'not_attempted',
                    'submitted_at' => null,
                    'test_cases_passed' => 0,
                    'total_test_cases' => $total_test_cases
                ];
            }
            
            $stmt->close();
        }
        
        $leaderboard[] = $student_data;
    }
} 