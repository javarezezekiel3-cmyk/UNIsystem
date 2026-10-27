<?php
/**
 * Grade management (Admin and Professor).
 *
 * Professors only see grades recorded under their own Professor ID and may
 * only record grades for courses in their active schedules. Administrators can
 * manage every grade. A student cannot receive two active grades for the same
 * course in the same semester and school year.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role(['Admin', 'Professor']);

$role = current_role();
$userId = current_user_id();
$semesters = ['1st Semester', '2nd Semester', 'Summer'];
$statuses = ['Active', 'Inactive'];

$professorId = null;

if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT professor_id
         FROM users
         WHERE id = ? AND role = 'Professor' AND status = 'Active'
         LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $professorId = (string) ($stmt->get_result()->fetch_assoc()['professor_id'] ?? '');
    $stmt->close();

    if ($professorId === '') {
        abort(403, 'Your account is not linked to a professor profile. Ask an administrator to link it.');
    }
}

/** Fetch one grade row by primary key. */
function find_grade(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM grades WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Has this student already got an active grade for the same course and term? */
function grade_duplicate_exists(
    mysqli $conn,
    string $studentId,
    string $courseCode,
    string $semester,
    string $schoolYear,
    int $excludeId = 0
): bool {
    $sql = "SELECT id FROM grades
            WHERE status = 'Active'
              AND student_id = ?
              AND course_code = ?
              AND semester = ?
              AND school_year = ?";
    $types = 'ssss';
    $params = [$studentId, $courseCode, $semester, $schoolYear];

    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/**
 * One active class: a schedule row joined to its course and professor.
 *
 * The grade sheet treats a class as the combination of course, section and
 * professor, so the first active schedule row for that combination is used.
 */
function find_grade_class(mysqli $conn, int $id, ?string $professorId = null): ?array
{
    $sql = "SELECT s.id, s.course_code, s.section, s.professor_id,
                   c.course_name, p.fullname AS professor_name
            FROM schedules s
            INNER JOIN courses c ON c.course_code = s.course_code
            INNER JOIN professors p ON p.professor_id = s.professor_id
            WHERE s.id = ? AND s.status = 'Active'
              AND c.status = 'Active' AND p.status = 'Active'";

    $types = 'i';
    $params = [$id];

    if ($professorId !== null) {
        $sql .= ' AND s.professor_id = ?';
        $types .= 's';
        $params[] = $professorId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Active students who belong to a section, in name order. */
function sheet_students(mysqli $conn, string $section): array
{
    if ($section === '') {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT student_id, fullname, course, year_level
         FROM students
         WHERE status = 'Active' AND section = ?
         ORDER BY fullname, student_id"
    );
    $stmt->bind_param('s', $section);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();

    return $students;
}

/** Active grades for a course and term, keyed by Student ID. */
function sheet_active_grades(
    mysqli $conn,
    string $courseCode,
    string $semester,
    string $schoolYear,
    array $studentIds
): array {
    if ($studentIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($studentIds), '?'));
    $sql = "SELECT id, student_id, professor_id, grade
            FROM grades
            WHERE status = 'Active'
              AND course_code = ? AND semester = ? AND school_year = ?
              AND student_id IN ({$placeholders})
            ORDER BY id";

    $types = 'sss' . str_repeat('s', count($studentIds));
    $params = array_merge([$courseCode, $semester, $schoolYear], array_values($studentIds));

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $grades = [];
    while ($row = $result->fetch_assoc()) {
        if (!isset($grades[(string) $row['student_id']])) {
            $grades[(string) $row['student_id']] = $row;
        }
    }
    $stmt->close();

    return $grades;
}

/** Read and normalise the grade form payload. */
function grade_payload(string $role, ?string $professorId): array
{
    return [
        'student_id' => post_string('student_id'),
        'course_code' => strtoupper(post_string('course_code')),
        'professor_id' => $role === 'Professor' ? (string) $professorId : post_string('professor_id'),
        'grade' => post_string('grade'),
        'semester' => post_string('semester'),
        'school_year' => post_string('school_year'),
    ];
}

/**
 * Validate a grade payload.
 *
 * On update, a reference that has not changed may stay inactive so existing
 * records remain editable after a course, student, or professor is retired.
 *
 * @return array<int,string>
 */
function grade_errors(
    mysqli $conn,
    array $input,
    string $role,
    ?string $professorId,
    ?array $current
): array {
    $errors = [];

    $studentUnchanged = $current !== null && $input['student_id'] === (string) $current['student_id'];
    if (!$studentUnchanged && !active_student_exists($conn, $input['student_id'])) {
        $errors[] = 'Choose an active student.';
    }

    $courseUnchanged = $current !== null && $input['course_code'] === (string) $current['course_code'];
    if (!$courseUnchanged && !active_course_exists($conn, $input['course_code'])) {
        $errors[] = 'Choose an active course.';
    }

    $professorUnchanged = $current !== null && $input['professor_id'] === (string) $current['professor_id'];
    if (!$professorUnchanged && !active_professor_exists($conn, $input['professor_id'])) {
        $errors[] = 'Choose an active professor.';
    }

    if (!is_numeric($input['grade'])) {
        $errors[] = 'Enter a numeric grade.';
    } else {
        $value = (float) $input['grade'];
        if ($value < 1.00 || $value > 5.00) {
            $errors[] = 'Grade must be between 1.00 and 5.00.';
        }
    }

    if (!in_list($input['semester'], ['1st Semester', '2nd Semester', 'Summer'])) {
        $errors[] = 'Choose a valid semester.';
    }

    if (!preg_match('/^(\d{4})-(\d{4})$/', $input['school_year'], $matches)) {
        $errors[] = 'School year must look like 2026-2027.';
    } elseif ((int) $matches[2] !== (int) $matches[1] + 1) {
        $errors[] = 'The school year must cover two consecutive years.';
    }

    if ($role === 'Professor') {
        if ($professorId === null || $input['professor_id'] !== $professorId) {
            $errors[] = 'You can only record grades under your own Professor ID.';
        } elseif (!$courseUnchanged && !professor_teaches_course($conn, $professorId, $input['course_code'])) {
            $errors[] = 'You can only record grades for courses assigned to you.';
        }
    }

    if ($errors === [] && grade_duplicate_exists(
        $conn,
        $input['student_id'],
        $input['course_code'],
        $input['semester'],
        $input['school_year'],
        (int) ($current['id'] ?? 0)
    )) {
        $errors[] = 'An active grade already exists for that student, course, semester, and school year.';
    }

    return $errors;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_grade') {
        $input = grade_payload($role, $professorId);
        $errors = grade_errors($conn, $input, $role, $professorId, null);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('grades.php');
        }

        try {
            $grade = round((float) $input['grade'], 2);

            $stmt = $conn->prepare(
                "INSERT INTO grades
                    (student_id, course_code, professor_id, grade, semester, school_year, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'Active')"
            );
            $stmt->bind_param(
                'sssdss',
                $input['student_id'],
                $input['course_code'],
                $input['professor_id'],
                $grade,
                $input['semester'],
                $input['school_year']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Grade added successfully.');
        } catch (Throwable $e) {
            error_log('Add grade failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the grade. Please try again.');
        }

        redirect('grades.php');
    }

    if ($action === 'save_sheet') {
        $classId = post_int('class_id');
        $sheetSemester = post_string('sheet_semester');
        $sheetYear = post_string('sheet_year');

        $class = find_grade_class($conn, $classId, $role === 'Professor' ? $professorId : null);

        if ($class === null) {
            flash('danger', 'Choose a valid class before saving grades.');
            redirect('grades.php');
        }

        $returnUrl = 'grades.php?sheet_class=' . $classId
            . '&sheet_semester=' . rawurlencode($sheetSemester)
            . '&sheet_year=' . rawurlencode($sheetYear);

        $errors = [];

        if (!in_list($sheetSemester, $semesters)) {
            $errors[] = 'Choose a valid semester.';
        }

        if (!preg_match('/^(\d{4})-(\d{4})$/', $sheetYear, $yearMatch)
            || (int) $yearMatch[2] !== (int) $yearMatch[1] + 1) {
            $errors[] = 'School year must cover two consecutive years, e.g. 2026-2027.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect($returnUrl);
        }

        $classStudents = sheet_students($conn, (string) $class['section']);
        $existing = sheet_active_grades(
            $conn,
            (string) $class['course_code'],
            $sheetSemester,
            $sheetYear,
            array_column($classStudents, 'student_id')
        );

        $submitted = $_POST['grades'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $toSave = [];

        foreach ($classStudents as $student) {
            $studentId = (string) $student['student_id'];
            $raw = $submitted[$studentId] ?? '';

            if (!is_string($raw)) {
                continue;
            }

            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            if (!is_numeric($raw) || (float) $raw < 1.00 || (float) $raw > 5.00) {
                $errors[] = 'Enter a grade between 1.00 and 5.00 for ' . $student['fullname'] . '.';
                continue;
            }

            $record = $existing[$studentId] ?? null;

            if ($record !== null && $role === 'Professor'
                && (string) $record['professor_id'] !== (string) $professorId) {
                $errors[] = 'The grade for ' . $student['fullname'] . ' was recorded by another professor.';
                continue;
            }

            $toSave[] = [
                'student_id' => $studentId,
                'grade' => round((float) $raw, 2),
                'id' => $record === null ? 0 : (int) $record['id'],
            ];
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect($returnUrl);
        }

        if ($toSave === []) {
            flash('warning', 'No grades were entered. Type at least one grade and save again.');
            redirect($returnUrl);
        }

        try {
            $counts = db_transaction(
                $conn,
                static function () use ($conn, $toSave, $class, $sheetSemester, $sheetYear): array {
                    $courseCode = (string) $class['course_code'];
                    $classProfessorId = (string) $class['professor_id'];
                    $added = 0;
                    $updated = 0;

                    foreach ($toSave as $entry) {
                        if ($entry['id'] > 0) {
                            $stmt = $conn->prepare('UPDATE grades SET grade = ? WHERE id = ?');
                            $stmt->bind_param('di', $entry['grade'], $entry['id']);
                            $stmt->execute();
                            $stmt->close();
                            $updated++;
                            continue;
                        }

                        $studentId = $entry['student_id'];
                        $stmt = $conn->prepare(
                            "INSERT INTO grades
                                (student_id, course_code, professor_id, grade, semester, school_year, status)
                             VALUES (?, ?, ?, ?, ?, ?, 'Active')"
                        );
                        $stmt->bind_param(
                            'sssdss',
                            $studentId,
                            $courseCode,
                            $classProfessorId,
                            $entry['grade'],
                            $sheetSemester,
                            $sheetYear
                        );
                        $stmt->execute();
                        $stmt->close();
                        $added++;
                    }

                    return ['added' => $added, 'updated' => $updated];
                }
            );

            $parts = [];
            if ($counts['added'] > 0) {
                $parts[] = $counts['added'] . ' added';
            }
            if ($counts['updated'] > 0) {
                $parts[] = $counts['updated'] . ' updated';
            }

            flash('success', 'Grades saved: ' . implode(', ', $parts) . '.');
        } catch (Throwable $e) {
            error_log('Save grade sheet failed: ' . $e->getMessage());
            flash('danger', 'Unable to save the grades. Please try again.');
        }

        redirect($returnUrl);
    }

    if ($action === 'update_grade') {
        $id = post_int('id');
        $record = find_grade($conn, $id);

        if ($record === null) {
            flash('danger', 'Grade record not found.');
            redirect('grades.php');
        }

        if ($role === 'Professor' && $record['professor_id'] !== $professorId) {
            abort(403, 'You can only edit your own grade records.');
        }

        $input = grade_payload($role, $professorId);
        $errors = grade_errors($conn, $input, $role, $professorId, $record);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('grades.php');
        }

        try {
            $grade = round((float) $input['grade'], 2);

            $stmt = $conn->prepare(
                'UPDATE grades
                 SET student_id = ?, course_code = ?, professor_id = ?, grade = ?, semester = ?, school_year = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sssdssi',
                $input['student_id'],
                $input['course_code'],
                $input['professor_id'],
                $grade,
                $input['semester'],
                $input['school_year'],
                $id
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Grade updated successfully.');
        } catch (Throwable $e) {
            error_log('Update grade failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the grade. Please try again.');
        }

        redirect('grades.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $record = find_grade($conn, $id);

        if ($record === null) {
            flash('danger', 'Grade record not found.');
            redirect('grades.php');
        }

        if ($role === 'Professor' && $record['professor_id'] !== $professorId) {
            abort(403, 'You can only change your own grade records.');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('grades.php');
        }

        if ($status === 'Active' && grade_duplicate_exists(
            $conn,
            (string) $record['student_id'],
            (string) $record['course_code'],
            (string) $record['semester'],
            (string) $record['school_year'],
            $id
        )) {
            flash('danger', 'Another active grade already exists for that student and term.');
            redirect('grades.php');
        }

        try {
            $stmt = $conn->prepare('UPDATE grades SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $stmt->close();

            flash('success', $status === 'Active' ? 'Grade reactivated.' : 'Grade deactivated.');
        } catch (Throwable $e) {
            error_log('Grade status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the grade status. Please try again.');
        }

        redirect('grades.php');
    }

    flash('danger', 'Unknown action.');
    redirect('grades.php');
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$students = [];
$studentResult = $conn->query(
    "SELECT student_id, fullname, section FROM students WHERE status = 'Active' ORDER BY fullname"
);
while ($row = $studentResult->fetch_assoc()) {
    $students[] = $row;
}

$courses = [];
if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT DISTINCT c.course_code, c.course_name
         FROM schedules s
         INNER JOIN courses c ON c.course_code = s.course_code
         WHERE s.professor_id = ? AND s.status = 'Active' AND c.status = 'Active'
         ORDER BY c.course_code"
    );
    $stmt->bind_param('s', $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query(
        "SELECT course_code, course_name FROM courses WHERE status = 'Active' ORDER BY course_code"
    );
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

$professors = [];
if ($role === 'Admin') {
    $result = $conn->query(
        "SELECT professor_id, fullname FROM professors WHERE status = 'Active' ORDER BY fullname"
    );
    while ($row = $result->fetch_assoc()) {
        $professors[] = $row;
    }
}

// ---------------------------------------------------------------------------
// Grade sheet context
// ---------------------------------------------------------------------------

$currentMonth = (int) date('n');
$startYear = $currentMonth >= 6 ? (int) date('Y') : (int) date('Y') - 1;
$defaultSchoolYear = $startYear . '-' . ($startYear + 1);
$defaultSemester = match (true) {
    $currentMonth >= 6 && $currentMonth <= 10 => '1st Semester',
    $currentMonth === 11 || $currentMonth <= 3 => '2nd Semester',
    default => 'Summer',
};

// One class per course + section + professor, even when it meets several times.
$classes = [];
$classSql = "SELECT x.id, x.course_code, x.section, x.professor_id, x.days,
                    c.course_name, p.fullname AS professor_name
             FROM (
                 SELECT MIN(id) AS id, course_code, section, professor_id,
                        GROUP_CONCAT(DISTINCT day ORDER BY day SEPARATOR ', ') AS days
                 FROM schedules
                 WHERE status = 'Active'"
    . ($role === 'Professor' ? ' AND professor_id = ?' : '') . "
                 GROUP BY course_code, section, professor_id
             ) x
             INNER JOIN courses c ON c.course_code = x.course_code
             INNER JOIN professors p ON p.professor_id = x.professor_id
             WHERE c.status = 'Active' AND p.status = 'Active'
             ORDER BY x.section, x.course_code";

$stmt = $conn->prepare($classSql);
if ($role === 'Professor') {
    $stmt->bind_param('s', $professorId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}
$stmt->close();

$sheetSemester = query_string('sheet_semester', $defaultSemester);
if (!in_list($sheetSemester, $semesters)) {
    $sheetSemester = $defaultSemester;
}

$sheetYear = query_string('sheet_year', $defaultSchoolYear);
if (!preg_match('/^(\d{4})-(\d{4})$/', $sheetYear, $sheetYearMatch)
    || (int) $sheetYearMatch[2] !== (int) $sheetYearMatch[1] + 1) {
    $sheetYear = $defaultSchoolYear;
}

$sheetClassId = (int) query_string('sheet_class', '0');
$sheetClass = null;
$sheetStudents = [];
$sheetGrades = [];

if ($sheetClassId > 0) {
    $sheetClass = find_grade_class($conn, $sheetClassId, $role === 'Professor' ? $professorId : null);

    if ($sheetClass === null) {
        if ($role === 'Professor') {
            abort(403, 'That class is not assigned to you.');
        }
        flash('danger', 'That class is not available.');
    } else {
        $sheetStudents = sheet_students($conn, (string) $sheetClass['section']);
        $sheetGrades = sheet_active_grades(
            $conn,
            (string) $sheetClass['course_code'],
            $sheetSemester,
            $sheetYear,
            array_column($sheetStudents, 'student_id')
        );
    }
}

// ---------------------------------------------------------------------------
// Filters and rows
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'Active');
$semesterFilter = query_string('semester', 'All');

if (!in_list($statusFilter, ['All', 'Active', 'Inactive'])) {
    $statusFilter = 'Active';
}
if (!in_list($semesterFilter, array_merge(['All'], $semesters))) {
    $semesterFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if ($role === 'Professor') {
    $where[] = 'g.professor_id = ?';
    $types .= 's';
    $params[] = $professorId;
}

if ($statusFilter !== 'All') {
    $where[] = 'g.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($semesterFilter !== 'All') {
    $where[] = 'g.semester = ?';
    $types .= 's';
    $params[] = $semesterFilter;
}

if ($search !== '') {
    $where[] = '(g.student_id LIKE ? OR st.fullname LIKE ? OR g.course_code LIKE ? OR c.course_name LIKE ? OR g.professor_id LIKE ? OR p.fullname LIKE ? OR g.school_year LIKE ?)';
    $types .= 'sssssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term, $term, $term);
}

$sql = 'SELECT g.*,
               st.fullname AS student_name,
               c.course_name,
               p.fullname AS professor_name
        FROM grades g
        LEFT JOIN students st ON st.student_id = g.student_id
        LEFT JOIN courses c ON c.course_code = g.course_code
        LEFT JOIN professors p ON p.professor_id = g.professor_id';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY g.school_year DESC, g.semester, g.id DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$grades = [];
while ($row = $result->fetch_assoc()) {
    $grades[] = $row;
}
$stmt->close();

$pageTitle = 'Grades';
$pageSubtitle = $role === 'Professor'
    ? 'Your classes and grade records'
    : 'Class grade sheets and grade records';
$active = 'grades';
require __DIR__ . '/inc/header.php';
?>

<section class="card mb-4" id="grade-sheet">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div>
            <h2 class="h5 mb-1">Enter grades</h2>
            <p class="text-muted small mb-0">
                Pick a class, then type each student's grade. The course, professor, section and term are filled in for you.
            </p>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-toggle="modal" data-bs-target="#addGrade">
            <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Manual entry
        </button>
    </div>

    <div class="card-body">
        <form class="row g-3 align-items-end" method="get" action="grades.php#grade-sheet">
            <div class="col-lg-5">
                <label class="form-label" for="sheet-class">Class</label>
                <select class="form-select" id="sheet-class" name="sheet_class">
                    <option value="">Select a class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= selected($sheetClassId, $class['id']) ?>>
                            <?= e($class['section'] !== '' && $class['section'] !== null ? $class['section'] : 'No section') ?>
                            &middot; <?= e($class['course_code']) ?>
                            &mdash; <?= e($class['professor_name']) ?>
                            <?= ($class['days'] ?? '') !== '' ? ' (' . e($class['days']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="sheet-semester">Semester</label>
                <select class="form-select" id="sheet-semester" name="sheet_semester">
                    <?php foreach ($semesters as $semester): ?>
                        <option value="<?= e($semester) ?>" <?= selected($sheetSemester, $semester) ?>>
                            <?= e($semester) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="sheet-year">School year</label>
                <input type="text" class="form-control" id="sheet-year" name="sheet_year"
                       value="<?= e($sheetYear) ?>" maxlength="9"
                       placeholder="e.g. 2026-2027" pattern="\d{4}-\d{4}">
            </div>

            <div class="col-sm-4 col-lg-3">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="fa-solid fa-arrow-right me-1" aria-hidden="true"></i>Open grade sheet
                </button>
            </div>
        </form>

        <?php if ($classes === []): ?>
            <div class="alert alert-info mt-3 mb-0" role="alert">
                <?= $role === 'Professor'
                    ? 'No active classes are assigned to you yet. Ask an administrator to add you to a schedule.'
                    : 'No classes are scheduled yet. Add a schedule first, then come back here to enter grades.' ?>
            </div>
        <?php elseif ($sheetClass === null): ?>
            <p class="form-hint mt-3 mb-0">
                Choose a class and term, then click <strong>Open grade sheet</strong>.
            </p>
        <?php endif; ?>

        <?php if ($sheetClass !== null): ?>
            <hr class="my-4">

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <span class="form-label d-block">Section</span>
                    <strong><?= e($sheetClass['section'] !== '' && $sheetClass['section'] !== null ? $sheetClass['section'] : 'No section') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <span class="form-label d-block">Course</span>
                    <strong><?= e($sheetClass['course_code']) ?></strong>
                    <div class="text-muted small"><?= e($sheetClass['course_name']) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <span class="form-label d-block">Professor</span>
                    <strong><?= e($sheetClass['professor_name']) ?></strong>
                    <div class="text-muted small mono"><?= e($sheetClass['professor_id']) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <span class="form-label d-block">Term</span>
                    <strong><?= e($sheetSemester) ?></strong>
                    <div class="text-muted small mono"><?= e($sheetYear) ?></div>
                </div>
            </div>

            <?php if ($sheetStudents === []): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
                    <h5>No students in this section</h5>
                    <p>Add students to section <?= e((string) $sheetClass['section']) ?> and they will appear here.</p>
                </div>
            <?php else: ?>
                <form method="post" action="grades.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_sheet">
                    <input type="hidden" name="class_id" value="<?= (int) $sheetClass['id'] ?>">
                    <input type="hidden" name="sheet_semester" value="<?= e($sheetSemester) ?>">
                    <input type="hidden" name="sheet_year" value="<?= e($sheetYear) ?>">

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Student</th>
                                    <th scope="col">Year level</th>
                                    <th scope="col">Current grade</th>
                                    <th scope="col" style="width: 12rem;">Grade to save</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sheetStudents as $student): ?>
                                    <?php
                                    $studentId = (string) $student['student_id'];
                                    $existingGrade = $sheetGrades[$studentId] ?? null;
                                    $existingValue = $existingGrade !== null ? (float) $existingGrade['grade'] : null;
                                    $lockedByOther = $existingGrade !== null
                                        && $role === 'Professor'
                                        && (string) $existingGrade['professor_id'] !== (string) $professorId;
                                    $inputId = 'sheet-grade-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $studentId);
                                    ?>
                                    <tr>
                                        <td>
                                            <label class="fw-semibold d-block mb-0" for="<?= e($inputId) ?>">
                                                <?= e($student['fullname']) ?>
                                            </label>
                                            <span class="text-muted small mono"><?= e($studentId) ?></span>
                                        </td>
                                        <td>
                                            <?= e(($student['year_level'] ?? '') !== '' ? $student['year_level'] : '—') ?>
                                            <?php if (($student['course'] ?? '') !== ''): ?>
                                                <div class="text-muted small"><?= e($student['course']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($existingGrade !== null): ?>
                                                <span class="badge text-bg-<?= $existingValue <= 3.00 ? 'success' : 'danger' ?>">
                                                    <?= e(number_format((float) $existingValue, 2)) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not graded</span>
                                            <?php endif; ?>
                                            <?php if ($lockedByOther): ?>
                                                <div class="text-muted small">Recorded by another professor</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <label class="visually-hidden" for="<?= e($inputId) ?>">
                                                Grade for <?= e($student['fullname']) ?>
                                            </label>
                                            <input type="number" class="form-control" id="<?= e($inputId) ?>"
                                                   name="grades[<?= e($studentId) ?>]"
                                                   value="<?= $existingGrade !== null ? e(number_format((float) $existingValue, 2, '.', '')) : '' ?>"
                                                   min="1" max="5" step="0.01" inputmode="decimal"
                                                   placeholder="—"<?= $lockedByOther ? ' disabled' : '' ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3">
                        <p class="form-hint mb-0">
                            <?= count($sheetStudents) ?> student<?= count($sheetStudents) === 1 ? '' : 's' ?>
                            &middot; <?= count($sheetGrades) ?> already graded this term.
                            Leave a box blank to keep that grade unchanged.
                        </p>
                        <button class="btn btn-primary" type="submit">
                            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>Save grades
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<h2 class="h5 mb-3">Grade records</h2>

<form class="toolbar" method="get" action="grades.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="grade-search">Search grades</label>
        <input type="search" class="form-control" id="grade-search" name="search"
               value="<?= e($search) ?>" placeholder="Search student, course, professor, or school year">
    </div>

    <label class="visually-hidden" for="grade-semester">Filter by semester</label>
    <select class="form-select" id="grade-semester" name="semester">
        <option value="All" <?= selected($semesterFilter, 'All') ?>>All semesters</option>
        <?php foreach ($semesters as $semester): ?>
            <option value="<?= e($semester) ?>" <?= selected($semesterFilter, $semester) ?>><?= e($semester) ?></option>
        <?php endforeach; ?>
    </select>

    <label class="visually-hidden" for="grade-status">Filter by status</label>
    <select class="form-select" id="grade-status" name="status">
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'Active' || $semesterFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="grades.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col">Course</th>
                    <th scope="col">Professor</th>
                    <th scope="col">Grade</th>
                    <th scope="col">Semester</th>
                    <th scope="col">School year</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($grades === []): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                <h5>No grades found</h5>
                                <p>Add a grade or change the filters.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($grades as $grade): ?>
                    <?php
                    $gradeId = (int) $grade['id'];
                    $gradeValue = (float) $grade['grade'];
                    $gradeClass = $gradeValue <= 3.00 ? 'success' : 'danger';
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($grade['student_name'] ?? $grade['student_id']) ?></strong>
                            <div class="text-muted small mono"><?= e($grade['student_id']) ?></div>
                        </td>
                        <td>
                            <strong><?= e($grade['course_code']) ?></strong>
                            <div class="text-muted small"><?= e($grade['course_name'] ?? '') ?></div>
                        </td>
                        <td>
                            <?= e($grade['professor_name'] ?? '') !== '' ? e($grade['professor_name']) : '<span class="text-muted">—</span>' ?>
                            <div class="text-muted small mono"><?= e($grade['professor_id']) ?></div>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e($gradeClass) ?>">
                                <?= e(number_format($gradeValue, 2)) ?>
                            </span>
                        </td>
                        <td><?= e($grade['semester']) ?></td>
                        <td class="mono"><?= e($grade['school_year']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($grade['status'])) ?>">
                                <?= e($grade['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewGrade<?= $gradeId ?>">
                                    View
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editGrade<?= $gradeId ?>">
                                    Edit
                                </button>

                                <?php if ($grade['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate this grade record?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= $gradeId ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= $gradeId ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add grade -->
<div class="modal fade" id="addGrade" tabindex="-1" aria-labelledby="addGradeLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_grade">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addGradeLabel">Add grade</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-grade-student">Student</label>
                            <select class="form-select" id="add-grade-student" name="student_id" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= e($student['student_id']) ?>">
                                        <?= e($student['fullname']) ?> (<?= e($student['student_id']) ?>)
                                        <?= ($student['section'] ?? '') !== '' ? ' — ' . e($student['section']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-grade-course">Course</label>
                            <select class="form-select" id="add-grade-course" name="course_code" required>
                                <option value="">Select course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= e($course['course_code']) ?>">
                                        <?= e($course['course_code']) ?> &middot; <?= e($course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($role === 'Admin'): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="add-grade-professor">Professor</label>
                                <select class="form-select" id="add-grade-professor" name="professor_id" required>
                                    <option value="">Select professor</option>
                                    <?php foreach ($professors as $professor): ?>
                                        <option value="<?= e($professor['professor_id']) ?>">
                                            <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6">
                                <span class="form-label d-block">Professor ID</span>
                                <p class="mono mb-0"><?= e($professorId) ?></p>
                                <p class="form-hint">Grades are recorded under your own Professor ID.</p>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="add-grade-value">Grade</label>
                            <input type="number" class="form-control" id="add-grade-value" name="grade"
                                   min="1" max="5" step="0.01" placeholder="e.g. 1.75" required>
                            <p class="form-hint">Between 1.00 (highest) and 5.00 (failed).</p>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-grade-semester">Semester</label>
                            <select class="form-select" id="add-grade-semester" name="semester" required>
                                <option value="">Select semester</option>
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?= e($semester) ?>"><?= e($semester) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-grade-year">School year</label>
                            <input type="text" class="form-control" id="add-grade-year" name="school_year"
                                   value="<?= e($defaultSchoolYear) ?>" maxlength="9"
                                   placeholder="e.g. 2026-2027" pattern="\d{4}-\d{4}" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($grades as $grade): ?>
    <?php
    $gradeId = (int) $grade['id'];
    $gradeValue = (float) $grade['grade'];
    $gradeClass = $gradeValue <= 3.00 ? 'success' : 'danger';
    ?>

    <!-- View grade -->
    <div class="modal fade" id="viewGrade<?= $gradeId ?>" tabindex="-1"
         aria-labelledby="viewGradeLabel<?= $gradeId ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="viewGradeLabel<?= $gradeId ?>">Grade details</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <?php
                        $details = [
                            'Student' => ($grade['student_name'] ?? '') . ' (' . $grade['student_id'] . ')',
                            'Course' => $grade['course_code'] . (($grade['course_name'] ?? '') !== '' ? ' — ' . $grade['course_name'] : ''),
                            'Professor' => ($grade['professor_name'] ?? '') . ' (' . $grade['professor_id'] . ')',
                            'Grade' => number_format($gradeValue, 2),
                            'Semester' => $grade['semester'],
                            'School year' => $grade['school_year'],
                            'Status' => $grade['status'],
                        ];
                        ?>
                        <?php foreach ($details as $label => $value): ?>
                            <div class="col-6">
                                <span class="form-label d-block"><?= e($label) ?></span>
                                <p class="mb-0"><?= e($value) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit grade -->
    <div class="modal fade" id="editGrade<?= $gradeId ?>" tabindex="-1"
         aria-labelledby="editGradeLabel<?= $gradeId ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_grade">
                    <input type="hidden" name="id" value="<?= $gradeId ?>">
                    <input type="hidden" name="student_id" value="<?= e($grade['student_id']) ?>">
                    <input type="hidden" name="course_code" value="<?= e($grade['course_code']) ?>">
                    <input type="hidden" name="professor_id" value="<?= e($grade['professor_id']) ?>">
                    <input type="hidden" name="semester" value="<?= e($grade['semester']) ?>">
                    <input type="hidden" name="school_year" value="<?= e($grade['school_year']) ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editGradeLabel<?= $gradeId ?>">
                            Edit grade &middot; <?= e($grade['student_name'] ?? $grade['student_id']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <span class="form-label d-block">Student</span>
                                <p class="mb-0"><?= e($grade['student_name'] ?? $grade['student_id']) ?></p>
                                <p class="text-muted small mono mb-0"><?= e($grade['student_id']) ?></p>
                            </div>
                            <div class="col-sm-6">
                                <span class="form-label d-block">Course</span>
                                <p class="mb-0"><?= e($grade['course_code']) ?></p>
                                <p class="text-muted small mb-0"><?= e($grade['course_name'] ?? '') ?></p>
                            </div>
                            <div class="col-sm-6">
                                <span class="form-label d-block">Professor</span>
                                <p class="mb-0"><?= e($grade['professor_name'] ?? '') ?></p>
                                <p class="text-muted small mono mb-0"><?= e($grade['professor_id']) ?></p>
                            </div>
                            <div class="col-sm-6">
                                <span class="form-label d-block">Term</span>
                                <p class="mb-0"><?= e($grade['semester']) ?></p>
                                <p class="text-muted small mono mb-0"><?= e($grade['school_year']) ?></p>
                            </div>
                        </div>

                        <label class="form-label" for="edit-grade-value<?= $gradeId ?>">Grade</label>
                        <input type="number" class="form-control" id="edit-grade-value<?= $gradeId ?>"
                               name="grade" value="<?= e(number_format($gradeValue, 2, '.', '')) ?>"
                               min="1" max="5" step="0.01" required>
                        <p class="form-hint">Between 1.00 (highest) and 5.00 (failed).</p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save grade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
